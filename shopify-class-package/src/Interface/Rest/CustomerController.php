<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;
use Itmar\ShopifyClassPackage\Support\Security\TokenVault;
use Itmar\ShopifyClassPackage\Support\Security\Crypto;

if (! defined('ABSPATH')) exit;

final class CustomerController extends BaseController
{
    public function registerRest(): void
    {
        register_rest_route($this->ns(), '/customer/oauth-start', [[
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'oauthStart'],
            'permission_callback' => [$this, 'oauthPermission'],
        ]]);

        // フロントから叩く想定：ログイン不要 + REST Nonce 必須
        //$auth = $this->gate(null, 'wp_rest', false);
        register_rest_route($this->ns(), '/customer/create', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'createCustomer'],
            'permission_callback' => $this->pending_cookie_gate(30 * MINUTE_IN_SECONDS),
            // 事前バリデーションは最小限。本文整合性は中でチェックして fail() へ。
            'args' => [
                'form_data' => ['required' => true, 'type' => 'object'],
            ],
        ]]);

        register_rest_route($this->ns(), '/customer/token-exchange', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'exchangeToken'],
            'permission_callback' => [$this, 'oauthPermission'],

        ]]);

        register_rest_route($this->ns(), '/customer/logout-url', [[
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'logoutUrl'],
            'permission_callback' => $this->gate(null, 'wp_rest', true),
        ]]);


        register_rest_route($this->ns(), '/customer/pending-upsert', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'pendingUpsert'],
            'permission_callback' => '__return_true',

        ]]);

        register_rest_route($this->ns(), '/wp-logout-redirect', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'logoutRedirect'],
            // ログイン不要。ただし CSRF 対策に REST ノンスは必須
            'permission_callback' => '__return_true',
            'args' => [
                'redirect_url' => ['required' => false, 'type' => 'string'],
            ],
        ]]);
    }

    public function oauthPermission(WP_REST_Request $request)
    {
        $nonce = $request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('itmar_rest_forbidden', 'Invalid nonce.', ['status' => 403]);
        }
        if (is_user_logged_in()) return true;

        $pendingGate = $this->pending_cookie_gate(30 * MINUTE_IN_SECONDS);
        return $pendingGate($request);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function safeLocalUrl(string $url, string $fallback): string
    {
        return wp_validate_redirect(esc_url_raw($url), $fallback);
    }

    private static function jwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return [];
        $encoded = strtr($parts[1], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode($encoded, true);
        $payload = $json === false ? null : json_decode($json, true);
        return is_array($payload) ? $payload : [];
    }

    private function pendingEmail(): string
    {
        global $wpdb;
        $token = isset($_COOKIE['itmar_pending_token'])
            ? sanitize_text_field(wp_unslash($_COOKIE['itmar_pending_token']))
            : '';
        if (!$token) return '';
        $table = $wpdb->prefix . 'pending_users';
        $email = $wpdb->get_var($wpdb->prepare(
            'SELECT email FROM %i WHERE token = %s AND is_used = 0 LIMIT 1',
            $table,
            $token
        ));
        return sanitize_email((string) $email);
    }

    public function oauthStart(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params() ?: [];
            $shopId = sanitize_text_field((string) ($p['shop_id'] ?? ''));
            $clientId = sanitize_text_field((string) ($p['client_id'] ?? ''));
            $userMail = sanitize_email((string) ($p['user_mail'] ?? ''));
            // Shopify に登録するコールバック先はサイトごとに1つへ固定する。
            // クライアント入力を採用せず、認証後の遷移先は return_url として別管理する。
            $callbackUri = home_url('/shopify-auth-callback/');
            $returnUrl = self::safeLocalUrl((string) ($p['return_url'] ?? ''), home_url('/'));

            if (!$shopId || !$clientId || !$callbackUri) {
                return $this->fail(new WP_Error('missing_params', 'Shopify authentication settings are incomplete.', ['status' => 400]), 400);
            }
            if (!is_user_logged_in()) {
                $pendingEmail = $this->pendingEmail();
                if (!$pendingEmail) {
                    return $this->fail(new WP_Error('invalid_pending_user', 'Pending user could not be verified.', ['status' => 403]), 403);
                }
                $userMail = $pendingEmail;
            } else {
                $current = wp_get_current_user();
                $userMail = sanitize_email((string) $current->user_email);
                $this->ensureShopifyCustomer($current);
            }
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $shopId)) {
                return $this->fail(new WP_Error('invalid_shop_id', 'Invalid Shopify shop ID.', ['status' => 400]), 400);
            }

            $state = self::base64Url(random_bytes(32));
            $verifier = self::base64Url(random_bytes(64));
            $challenge = self::base64Url(hash('sha256', $verifier, true));
            $nonce = self::base64Url(random_bytes(24));
            $transientKey = 'itmar_shopify_oauth_' . hash('sha256', $state);
            set_transient($transientKey, [
                'code_verifier' => Crypto::encrypt($verifier),
                'shop_id' => $shopId,
                'client_id' => $clientId,
                'user_mail' => $userMail,
                'user_id' => get_current_user_id(),
                'callback_uri' => $callbackUri,
                'return_url' => $returnUrl,
                'nonce' => $nonce,
            ], 15 * MINUTE_IN_SECONDS);

            $url = add_query_arg([
                'scope' => 'openid email customer-account-api:full',
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $callbackUri,
                'state' => $state,
                'nonce' => $nonce,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ], 'https://shopify.com/authentication/' . rawurlencode($shopId) . '/oauth/authorize');

            return $this->ok(['authorization_url' => esc_url_raw($url)]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    public function registerAjax(): void
    {
        add_action('wp_ajax_itmar_validate_customer',        [$this, 'ajaxValidateCustomer']);
        add_action('wp_ajax_nopriv_itmar_validate_customer', [$this, 'ajaxValidateCustomer']);
    }

    //shopifyユーザーの登録処理
    /**
     * ログイン中のユーザーに対応する Shopify 顧客を用意する。
     *
     * 顧客が無いまま Shopify の本人確認へ送ると、Shopify 側が氏名の入っていない
     * 顧客を勝手に作る。そうなる前に、WordPress が持っている姓名で顧客を作っておく。
     * 既にある場合は結び付けるだけ。失敗しても本人確認は続ける。
     */
    private function ensureShopifyCustomer($user): void
    {
        if (empty($user->ID)) return;
        if (get_user_meta($user->ID, 'shopify_customer_id', true)) return; // 結び付け済み

        $existing = $this->findShopifyCustomerIdByEmail((string) $user->user_email);
        if ($existing) {
            update_user_meta($user->ID, 'shopify_customer_id', $existing);
            return;
        }

        $result = $this->create_shopify_customer($user, true);
        if (empty($result['success']) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Shopify customer ensure] ' . wp_json_encode($result['data'] ?? []));
        }
    }

    /**
     * メールアドレスから Shopify の顧客IDを引く。見つからなければ 0。
     *
     * 登録し直しのように「Shopify には既に顧客がいるが WordPress にはまだ紐付けが無い」
     * 状態を、作り直さずに結び付けるために使う。
     */
    private function findShopifyCustomerIdByEmail(string $email): int
    {
        $email = sanitize_email($email);
        if ($email === '') return 0;

        $shop_domain = (string) get_option('shopify_shop_domain', '');
        $admin_token = (string) get_option('shopify_admin_token', '');
        if ($shop_domain === '' || $admin_token === '') return 0;

        $url = add_query_arg(
            [
                'query'  => 'email:' . $email,
                'limit'  => 5,
                'fields' => 'id,email',
            ],
            ShopifyApi::adminUrl($shop_domain, 'customers/search.json')
        );

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'X-Shopify-Access-Token' => $admin_token,
                'Accept'                 => 'application/json',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return 0;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        foreach ((array) (is_array($body) ? ($body['customers'] ?? []) : []) as $customer) {
            if (
                isset($customer['id'], $customer['email']) &&
                strtolower((string) $customer['email']) === strtolower($email)
            ) {
                return (int) $customer['id'];
            }
        }
        return 0;
    }

    private function create_shopify_customer($user, $is_save)
    {
        // Shopify 送信用 データ組立
        $shop_domain = get_option('shopify_shop_domain');
        $admin_token = get_option('shopify_admin_token');

        $customer_payload = [
            'first_name' => $user->first_name ?: '',
            'last_name'  => $user->last_name  ?: '',
            'email'      => $user->user_email ?: '',
            'verified_email'   => true,
            'tags'             => 'WP-Site-User', // 任意
        ];


        // 顧客登録 API 呼び出し
        $response = wp_remote_post(ShopifyApi::adminUrl($shop_domain, 'customers.json'), [
            'headers' => [
                'X-Shopify-Access-Token' => $admin_token,
                'Content-Type'           => 'application/json',
            ],
            'body' => json_encode([
                'customer' => $customer_payload
            ])
        ]);

        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $raw    = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $body   = json_decode($raw, true);

        /*
         * 失敗の理由を分けて返す。
         * 以前はどんな失敗でも email_exists（メールアドレスが既に使われている）に
         * まとめていたため、権限不足や通信エラーでも「登録済み」と表示され、
         * 画面からは原因がわからなかった。
         */
        if (!isset($body['customer']['id'])) {
            $errors      = is_array($body) ? ($body['errors'] ?? null) : null;
            $detail      = is_string($errors) ? $errors : wp_json_encode($errors);
            $emailErrors = is_array($errors) ? (array) ($errors['email'] ?? []) : [];
            $isTaken     = (bool) preg_grep('/taken|既に|すでに/u', array_map('strval', $emailErrors));

            if (is_wp_error($response)) {
                $err_code = 'shopify_network';
                $detail   = $response->get_error_message();
            } elseif ($status === 422 && $isTaken) {
                /*
                 * 同じメールの顧客が Shopify に既にいる。作り直さず、その顧客と
                 * 結び付けて先へ進める（登録のやり直しはここで止まらない）。
                 */
                $existing = $this->findShopifyCustomerIdByEmail((string) ($user->user_email ?? ''));
                if ($existing) {
                    if ($is_save && !empty($user->ID)) {
                        update_user_meta((int) $user->ID, 'shopify_customer_id', $existing);
                    }
                    delete_option('itmar_shopify_customer_last_error');
                    return array(
                        'success' => true,
                        'data' => array(
                            'customer_id' => $existing,
                            'linked'      => true, // 既存の顧客に結び付けた
                        )
                    );
                }
                $err_code = 'email_exists';
            } elseif ($status === 401 || $status === 403) {
                // スコープ不足、または「保護された顧客データ」へのアクセス承認が無い
                $err_code = 'shopify_forbidden';
            } else {
                $err_code = 'shopify_error';
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Shopify customer create] HTTP %d %s', $status, $detail ?: $raw));
            }
            update_option('itmar_shopify_customer_last_error', [
                'code'    => $err_code,
                'status'  => $status,
                'message' => (string) ($detail ?: $raw),
                'at'      => time(),
            ], false);

            return array(
                'success' => false,
                'data' => array(
                    'err_code' => $err_code,
                    'status'   => $status,
                    'message'  => (string) ($detail ?: $raw),
                )
            );
        }

        delete_option('itmar_shopify_customer_last_error');

        $customer_id = $body['customer']['id'];

        if ($is_save) { //既にWordPressユーザー登録が終わっている
            //shopifyで登録したユーザーIDをuser_metaに保存
            update_user_meta($user->ID, 'shopify_customer_id', $customer_id);
        }

        return array(
            'success' => true,
            'data' => array(
                'customer_id' => $customer_id
            )
        );
    }
    //仮登録の処理
    public function pendingUpsert(WP_REST_Request $request)
    {
        // 必要に応じて仮登録のテーブル作成
        itmar_create_pending_users_table_if_not_exists();
        //パラメータの捕捉
        $params    = $request->get_json_params() ?: [];
        $form_data = $params['form_data'] ?? [];
        // DB保存
        global $wpdb;
        $table = $wpdb->prefix . 'pending_users';
        // トークン生成（64文字程度の一意な文字列）
        $token = wp_generate_password(48, false, false);
        //サニタイズ
        $email = sanitize_email($form_data['email'] ?? '');
        $first_name = sanitize_text_field($form_data['memberFirstName'] ?? '');
        $last_name  = sanitize_text_field($form_data['memberLastName'] ?? '');
        $display_by_first_only = !empty($form_data['memberDisplayName']);
        $name      = $display_by_first_only
            ? sanitize_text_field($form_data['memberFirstName'] ?? '')
            : ($first_name . $last_name);
        $password  = $form_data['password'] ?? '';

        /*
         * 同じメールアドレスの未使用の仮登録が残っていると、本登録のときに
         * いちばん古い行が拾われ、入れ直したはずの内容（パスワードなど）が
         * 使われない。入れ直しの意味どおり、先に消してから入れる。
         */
        $wpdb->delete($table, ['email' => $email, 'is_used' => 0], ['%s', '%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- 独自テーブル

        //レコードの保存
        $result = $wpdb->insert(
            $table,
            [
                'email' => $email,
                'name'     => $name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'password' => $password,
                'token' => $token,
                'created_at' => current_time('mysql'),
                'is_used' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into custom table; no WP API available.
        // INSERT 成功後
        if ($result) {
            $cookie_name = 'itmar_pending_token';

            setcookie($cookie_name, $token, [
                'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,      // ★JSから読めない
                'samesite' => 'Lax',     // ★CSRF耐性を上げる
            ]);

            // 同一リクエスト内でも参照できるように
            $_COOKIE[$cookie_name] = $token;

            wp_send_json_success([
                'valid'          => true,
                'message'        => 'pending record OK',
            ]);
        } else {
            wp_send_json_error([['err_code' => 'save_error']]);
        }
    }

    //仮登録があるかどうかを判定してルートを通過させるための関数
    protected function pending_cookie_gate(int $ttl_seconds = 1800): callable
    {
        return function (\WP_REST_Request $request) use ($ttl_seconds) {
            global $wpdb;

            $cookie_name = 'itmar_pending_token';
            $token = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
            if ($token === '') {
                return new \WP_Error('itmar_rest_forbidden', 'Missing pending token cookie.', ['status' => 403]);
            }

            $table = $wpdb->prefix . 'pending_users';
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id, email, created_at, is_used FROM %i WHERE token = %s LIMIT 1',
                    $table,
                    $token
                ),
                ARRAY_A
            );

            if (!$row || (int)$row['is_used'] !== 0) {
                return new \WP_Error('itmar_rest_forbidden', 'Invalid or used pending token.', ['status' => 403]);
            }

            $created_ts = strtotime($row['created_at']);
            $now_ts     = current_time('timestamp');
            if (!$created_ts || ($now_ts - $created_ts) > $ttl_seconds) {
                return new \WP_Error('itmar_rest_forbidden', 'Pending token expired.', ['status' => 403]);
            }

            // フォームの email と pending.email が一致するかも確認
            $form = $request->get_param('form_data');
            $email = is_array($form) && isset($form['email']) ? sanitize_email($form['email']) : '';
            if ($email && strtolower($email) !== strtolower($row['email'])) {
                return new \WP_Error('itmar_rest_forbidden', 'Email mismatch.', ['status' => 403]);
            }

            return true;
        };
    }

    //顧客アカウントの作成メソッド
    public function createCustomer(WP_REST_Request $request)
    {
        try {
            $params    = $request->get_json_params() ?: [];
            $form_data = $params['form_data'] ?? [];

            // メール
            $email = sanitize_email($form_data['email'] ?? '');
            if (empty($email)) {
                return $this->fail(new WP_Error('missing_email', 'Missing email', ['status' => 400]), 400);
            }

            // 既存 WP ユーザーを探す
            $user = get_user_by('email', $email);

            if ($user) {
                // 既に Shopify 顧客ID を持っていれば完了
                $customer_id = get_user_meta($user->ID, 'shopify_customer_id', true);
                if ($customer_id) {
                    return $this->ok(['success' => true]);
                }
                // 既存WPユーザー情報でShopify顧客を生成
                $result = $this->create_shopify_customer($user, true);
            } else {
                // フロントからの入力を元に WP_User 風オブジェクトを組み立て
                $first_name = sanitize_text_field($form_data['memberFirstName'] ?? '');
                $last_name  = sanitize_text_field($form_data['memberLastName'] ?? '');
                $display_by_first_only = !empty($form_data['memberDisplayName']);
                $name      = $display_by_first_only
                    ? sanitize_text_field($form_data['memberFirstName'] ?? '')
                    : ($first_name . $last_name);
                $password  = $form_data['password'] ?? '';

                $user = (object) [
                    'ID'               => 0, // 仮登録など、まだユーザーIDがない場合は 0
                    'user_password'    => $password,
                    'user_email'       => $email,
                    'display_name'     => $name,
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'nickname'         => $name,
                    'user_nicename'    => sanitize_title($name),
                    'user_url'         => '',
                    'user_registered'  => current_time('mysql'),
                    'roles'            => ['subscriber'],
                ];

                // Shopify 登録処理
                $result = $this->create_shopify_customer($user, false);
            }

            // そのまま返却（旧挙動を踏襲）
            return $this->ok($result);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }



    //コードからトークンの交換メソッド
    public function exchangeToken(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params() ?: [];
            $code = sanitize_text_field((string) ($p['code'] ?? ''));
            $state = sanitize_text_field((string) ($p['state'] ?? ''));
            if (!$code || !$state) {
                return $this->fail(new WP_Error(
                    'missing_params',
                    '認証コードまたはstateが不足しています',
                    ['status' => 400]
                ), 400);
            }

            $transientKey = 'itmar_shopify_oauth_' . hash('sha256', $state);
            $transaction = get_transient($transientKey);
            if (!is_array($transaction)) {
                return $this->fail(new WP_Error('invalid_oauth_state', '認証の有効期限が切れたか、stateが無効です', ['status' => 400]), 400);
            }

            $expectedUserId = (int) ($transaction['user_id'] ?? 0);
            if ($expectedUserId && get_current_user_id() !== $expectedUserId) {
                return $this->fail(new WP_Error('oauth_user_mismatch', '認証を開始したユーザーと一致しません', ['status' => 403]), 403);
            }

            $client_id = sanitize_text_field((string) ($transaction['client_id'] ?? ''));
            $shop_id = sanitize_text_field((string) ($transaction['shop_id'] ?? ''));
            $user_mail = sanitize_email((string) ($transaction['user_mail'] ?? ''));
            $redirect_uri = self::safeLocalUrl((string) ($transaction['callback_uri'] ?? ''), home_url('/'));
            $return_url = self::safeLocalUrl((string) ($transaction['return_url'] ?? ''), home_url('/'));
            $code_verifier = Crypto::decrypt((string) ($transaction['code_verifier'] ?? ''));

            if (!$code_verifier || !$redirect_uri || !$client_id || !$shop_id) {
                delete_transient($transientKey);
                return $this->fail(new WP_Error('invalid_oauth_transaction', '認証情報が不完全です', ['status' => 400]), 400);
            }

            $token_endpoint = "https://shopify.com/authentication/{$shop_id}/oauth/token";

            $response = wp_remote_post($token_endpoint, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ],
                'body' => http_build_query([
                    'client_id'     => $client_id,
                    'code'          => $code,
                    'code_verifier' => $code_verifier,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirect_uri,
                ]),
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                // WP_Error をそのまま fail へ（500）
                return $this->fail($response, 500);
            }

            $status = (int) wp_remote_retrieve_response_code($response);

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status < 200 || $status >= 300 || !is_array($body) || isset($body['error'])) {
                return $this->fail(new WP_Error(
                    'shopify_token_error',
                    is_array($body) ? (string) ($body['error_description'] ?? $body['error'] ?? 'Token exchange failed') : 'Token exchange failed',
                    ['status' => 400, 'error' => is_array($body) ? ($body['error'] ?? '') : 'invalid_response']
                ), 400);
            }

            $idPayload = self::jwtPayload((string) ($body['id_token'] ?? ''));
            $expectedNonce = (string) ($transaction['nonce'] ?? '');
            $audience = $idPayload['aud'] ?? '';
            $audienceValid = is_array($audience)
                ? in_array($client_id, $audience, true)
                : hash_equals($client_id, (string) $audience);
            if (
                !$idPayload ||
                !$expectedNonce ||
                !isset($idPayload['nonce']) ||
                !hash_equals($expectedNonce, (string) $idPayload['nonce']) ||
                !$audienceValid ||
                (isset($idPayload['exp']) && (int) $idPayload['exp'] <= time())
            ) {
                delete_transient($transientKey);
                return $this->fail(new WP_Error('invalid_id_token', 'Shopify identity response could not be verified.', ['status' => 400]), 400);
            }

            // ログイン確認 → 未ログインなら仮登録トークンから本登録
            $user_id = get_current_user_id();
            if (!$user_id) {
                $user_obj = itmar_pending_user_check($user_mail);
                if ($user_obj) {
                    $res     = itmar_process_token_registration($user_obj->token, true);
                    $user_id = $res['user_ID'] ?? 0;
                } else {
                    return $this->fail(new WP_Error('require_login', 'Require WP login', ['status' => 401]), 401);
                }
            }

            /*
             * WordPress ユーザーと Shopify 顧客の結び付け。
             * 仮登録から作られたユーザーには顧客IDが入っていないので、ここで補う。
             * 顧客更新の Webhook はこのメタからユーザーを引くため、無いと後で困る。
             */
            if ($user_id && !get_user_meta($user_id, 'shopify_customer_id', true)) {
                $linkMail = $user_mail !== '' ? $user_mail : (string) (get_userdata($user_id)->user_email ?? '');
                $customerId = $this->findShopifyCustomerIdByEmail($linkMail);
                if ($customerId) {
                    update_user_meta($user_id, 'shopify_customer_id', $customerId);
                }
            }

            $expires_in = isset($body['expires_in']) ? (int)$body['expires_in'] : 0;
            $body['expires_at'] = $expires_in ? time() + $expires_in : 0;
            TokenVault::saveCustomerSession($user_id, $body, [
                'shop_id' => $shop_id,
                'client_id' => $client_id,
                'redirect_uri' => $redirect_uri,
            ]);
            delete_transient($transientKey);

            return $this->ok([
                'authenticated' => true,
                'expires_at' => (int) $body['expires_at'],
                'redirect_url' => $return_url,
                'rest_nonce' => wp_create_nonce('wp_rest'),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    // CustomerController 内に追加
    public function ajaxValidateCustomer()
    {
        // Nonce 検証（admin-ajax はこれ1行が便利）
        check_ajax_referer('wp_rest', '_wpnonce'); // 送信側は _wpnonce=... を含める

        // パラメータ（URLSearchParams で来る）
        $params = wp_unslash($_POST);
        try {
            // 2) WordPress ユーザー情報（未ログインなら ID=0, メール空）
            $wp_user       = wp_get_current_user();
            $wp_user_id    = (int) ($wp_user->ID ?? 0);
            $wp_user_mail  = $wp_user_id ? ($wp_user->user_email ?? '') : '';
            $shopify_cart_id = $wp_user_id ? get_user_meta($wp_user_id, 'shopify_cart_id', true) : '';

            // Shopifyの認証情報はブラウザーから受け取らず、ログインユーザーの暗号化済みセッションを使う。
            $session = $wp_user_id ? TokenVault::getCustomerSession($wp_user_id) : [];
            $shop_id = sanitize_text_field((string) (($session['shop_id'] ?? '') ?: ($params['shop_id'] ?? '')));
            $client_id = sanitize_text_field((string) (($session['client_id'] ?? '') ?: ($params['client_id'] ?? '')));
            $customer_token = (string) ($session['access_token'] ?? '');
            $shop_domain = sanitize_text_field((string) get_option('shopify_shop_domain', ''));

            if (!$shop_id || !$shop_domain) {
                return $this->fail('shop_id and shop_domain are required', 400);
            }

            // 4) Shopify Customer API 呼び出しクロージャ
            $customer_endpoint = esc_url_raw(ShopifyApi::customerAccountUrl($shop_domain));
            $fetch_customer = static function (string $access_token) use ($customer_endpoint) {

                $query = 'query {
                    customer {
                        id
                        emailAddress { emailAddress }
                        firstName
                        lastName
                    }
                }';

                return wp_remote_post(
                    $customer_endpoint,
                    [
                        'headers'     => [
                            'Content-Type'  => 'application/json; charset=utf-8',
                            'Authorization' => (string) $access_token, // "Bearer xxx" 形式ならそのまま
                        ],
                        'body'        => wp_json_encode(['query' => $query]),
                        'data_format' => 'body',
                        'timeout'     => 20,
                    ]
                );
            };


            // 5) まずはクライアント送信トークンで照会
            $response     = $customer_token ? $fetch_customer($customer_token) : null;
            $need_refresh = false;

            if (is_wp_error($response)) {
                $need_refresh = true;
            } elseif ($response) {
                $code     = (int) wp_remote_retrieve_response_code($response);
                $body     = json_decode(wp_remote_retrieve_body($response), true);
                $customer = $body['data']['customer'] ?? null;

                if (
                    $code === 401 || $code === 403 ||
                    isset($body['errors']) ||
                    (isset($body['error']) && stripos((string)$body['error'], 'invalid') !== false)
                ) {
                    $need_refresh = true;
                } elseif ($customer) {
                    if ($wp_user_id && (empty($session['shop_id']) || empty($session['client_id']))) {
                        TokenVault::saveCustomerSession($wp_user_id, [], [
                            'shop_id' => $shop_id,
                            'client_id' => $client_id,
                        ]);
                    }
                    wp_send_json_success([
                        'valid'         => true,
                        'authenticated' => true,
                        'customer'      => $customer,
                        'wp_user_id'    => $wp_user_id,
                        'wp_user_mail'  => $wp_user_mail,
                        'cart_id'       => $shopify_cart_id,
                    ]);
                    exit;
                } else {
                    $need_refresh = true; // 200 でも customer=null の場合など
                }
            } else {
                $need_refresh = true; // トークン未提示 → リフレッシュへ
            }

            // 6) リフレッシュ試行（サーバ保存の refresh_token 前提）
            if ($need_refresh) {
                //$stored_refresh = $wp_user_id ? itmar_get_encrypted_user_meta($wp_user_id, '_itmar_shopify_refresh_token') : '';
                $stored_refresh = $wp_user_id ? TokenVault::getUserSecret($wp_user_id, TokenVault::REFRESH_TOKEN_KEY) : '';

                if (!$stored_refresh || !$client_id) {
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'Re-login required',
                    ]);
                    exit;
                }

                $token_endpoint = "https://shopify.com/authentication/{$shop_id}/oauth/token";
                $refresh_res = wp_remote_post($token_endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept'       => 'application/json',
                    ],
                    'body'    => http_build_query([
                        'client_id'     => $client_id,
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $stored_refresh,
                    ]),
                    'timeout' => 20,
                ]);

                if (is_wp_error($refresh_res)) {
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'Token refresh failed',
                    ]);
                    exit;
                }

                $rb = json_decode(wp_remote_retrieve_body($refresh_res), true);
                if (isset($rb['error'])) {
                    if ($wp_user_id) {
                        TokenVault::deleteCustomerSession($wp_user_id);
                    }
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'Session expired',
                    ]);
                    exit;
                }

                $new_access = $rb['access_token'] ?? '';
                if (!$new_access) {
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'No access_token in refresh response',
                    ]);
                    exit;
                }

                $rb['expires_at'] = !empty($rb['expires_in']) ? time() + (int) $rb['expires_in'] : 0;
                // 7) 新トークンで再試行
                $response2 = $fetch_customer($new_access);
                if (is_wp_error($response2)) {
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'Shopify API error after refresh',
                    ]);
                    exit;
                }

                $code2     = (int) wp_remote_retrieve_response_code($response2);
                $body2     = json_decode(wp_remote_retrieve_body($response2), true);
                $customer2 = $body2['data']['customer'] ?? null;

                if ($code2 === 200 && $customer2) {
                    TokenVault::saveCustomerSession($wp_user_id, $rb, [
                        'shop_id' => $shop_id,
                        'client_id' => $client_id,
                        'redirect_uri' => (string) ($session['redirect_uri'] ?? ''),
                    ]);
                    wp_send_json_success([
                        'valid'         => true,
                        'customer'      => $customer2,
                        'wp_user_id'    => $wp_user_id,
                        'wp_user_mail'  => $wp_user_mail,
                        'cart_id'       => $shopify_cart_id,
                        'authenticated' => true,
                        'expires_at' => (int) $rb['expires_at'],
                    ]);
                    exit;
                }

                TokenVault::deleteCustomerSession($wp_user_id);
                wp_send_json_success([
                    'valid'          => false,
                    'login_required' => true,
                    'message'        => 'Re-login required',
                ]);
            }

            // ここには来ないはず
            wp_send_json_success(['valid' => false, 'message' => 'Unexpected flow']);
            exit;
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }


    public function logoutUrl(WP_REST_Request $request)
    {
        try {
            $userId = get_current_user_id();
            $session = TokenVault::getCustomerSession($userId);
            $params = $request->get_json_params() ?: [];
            $returnUrl = self::safeLocalUrl((string) ($params['redirect_url'] ?? ''), home_url('/'));
            $idToken = (string) ($session['id_token'] ?? '');
            $shopId = sanitize_text_field((string) ($session['shop_id'] ?? ''));
            $callbackUri = self::safeLocalUrl((string) ($session['redirect_uri'] ?? ''), home_url('/'));

            if (!$idToken || !$shopId) {
                TokenVault::deleteCustomerSession($userId);
                return $this->ok(['logout_url' => html_entity_decode(wp_logout_url($returnUrl), ENT_QUOTES)]);
            }

            $returnKey = self::base64Url(random_bytes(24));
            set_transient('itmar_shopify_logout_' . hash('sha256', $returnKey), $returnUrl, 15 * MINUTE_IN_SECONDS);
            setcookie('itmar_shopify_logout_return', $returnKey, [
                'expires' => time() + 15 * MINUTE_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $postLogout = add_query_arg('shopify_logout_completed', '1', $callbackUri);
            $url = add_query_arg([
                'id_token_hint' => $idToken,
                'post_logout_redirect_uri' => $postLogout,
            ], 'https://shopify.com/authentication/' . rawurlencode($shopId) . '/logout');

            TokenVault::deleteCustomerSession($userId);
            return $this->ok(['logout_url' => esc_url_raw($url)]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }


    //ログアウト処理
    public function logoutRedirect(WP_REST_Request $request)
    {
        try {
            // JSON / form 両対応
            $params = stripos($request->get_header('content-type') ?? '', 'application/json') !== false
                ? ($request->get_json_params() ?: [])
                : $request->get_params();

            $fallback = home_url('/');
            $returnKey = isset($_COOKIE['itmar_shopify_logout_return'])
                ? sanitize_text_field(wp_unslash($_COOKIE['itmar_shopify_logout_return']))
                : '';
            $stored = $returnKey ? get_transient('itmar_shopify_logout_' . hash('sha256', $returnKey)) : '';
            if ($returnKey) delete_transient('itmar_shopify_logout_' . hash('sha256', $returnKey));
            setcookie('itmar_shopify_logout_return', '', [
                'expires' => time() - HOUR_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $safe = self::safeLocalUrl(is_string($stored) ? $stored : '', $fallback);

            // WordPress が nonce 付きのログアウト URL を作成
            $logout_url = wp_logout_url($safe);
            // HTML エンティティを平文化（WP はエスケープ文字を含めることがある）
            $logout_url = html_entity_decode($logout_url, ENT_QUOTES);

            return $this->ok(['logout_url' => $logout_url]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }
}
