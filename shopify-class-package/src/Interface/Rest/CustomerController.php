<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;
use Itmar\ShopifyClassPackage\Support\Security\TokenVault;

if (! defined('ABSPATH')) exit;

final class CustomerController extends BaseController
{
    public function registerRest(): void
    {
        // フロントから叩く想定：ログイン不要 + REST Nonce 必須
        //$auth = $this->gate(null, 'wp_rest', false);
        register_rest_route($this->ns(), '/customer/create', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'createCustomer'],
            'permission_callback' => '__return_true',
            // 事前バリデーションは最小限。本文整合性は中でチェックして fail() へ。
            'args' => [
                'form_data' => ['required' => true, 'type' => 'object'],
            ],
        ]]);


        register_rest_route($this->ns(), '/customer/token-exchange', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'exchangeToken'],
            'permission_callback' => '__return_true',

        ]]);

        // register_rest_route($this->ns(), '/customer/validate', [[
        //     'methods'  => \WP_REST_Server::CREATABLE, // POST
        //     'callback' => [$this, 'validateCustomer'],
        //     // ログイン不要だが REST ノンス必須（admin-ajax 相当の保護）
        //     'permission_callback' => $auth,
        // ]]);

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

    public function registerAjax(): void
    {
        add_action('wp_ajax_validate-customer',        [$this, 'ajaxValidateCustomer']);
        add_action('wp_ajax_nopriv_validate-customer', [$this, 'ajaxValidateCustomer']);
    }

    //shopifyユーザーの登録処理
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
        $response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/customers.json", [
            'headers' => [
                'X-Shopify-Access-Token' => $admin_token,
                'Content-Type'           => 'application/json',
            ],
            'body' => json_encode([
                'customer' => $customer_payload
            ])
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);

        //既に登録されているなどのエラー
        if (!isset($body['customer']['id'])) {
            return array(
                'success' => false,
                'data' => array(
                    'err_code' => 'email_exists'
                )
            );
        }

        $customer_id = $body['customer']['id'];

        if ($is_save) { //既にWordPressユーザー登録が終わっている
            //shopifyで登録したユーザーIDをuser_metaに保存
            update_user_meta($user->ID, 'shopify_customer_id', $customer_id);
        } else { //WordPressへの仮登録
            // 必要に応じて仮登録のテーブル作成
            itmar_create_pending_users_table_if_not_exists();
            // DB保存
            global $wpdb;
            $table = $wpdb->prefix . 'pending_users';
            // トークン生成（64文字程度の一意な文字列）
            $token = wp_generate_password(48, false, false);

            $result = $wpdb->insert(
                $table,
                [
                    'email' => $user->user_email ?: '',
                    'name' => $user->display_name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'password' => $user->user_password,
                    'token' => $token,
                    'created_at' => current_time('mysql'),
                    'is_used' => 0,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
            ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into custom table; no WP API available.

            if (!$result) {
                wp_send_json_error([['err_code' => 'save_error']]);
            }
        }

        return array(
            'success' => true,
            'data' => array(
                'customer_id' => $customer_id
            )
        );
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

            $client_id     = isset($p['client_id']) ? trim((string)$p['client_id']) : '';
            $shop_id       = isset($p['shop_id']) ? trim((string)$p['shop_id']) : '';
            $user_mail     = isset($p['user_mail']) ? trim((string)$p['user_mail']) : '';
            $code          = isset($p['code']) ? trim((string)$p['code']) : '';
            $code_verifier = isset($p['code_verifier']) ? trim((string)$p['code_verifier']) : '';
            $redirect_uri  = isset($p['redirect_uri']) ? esc_url_raw((string)$p['redirect_uri']) : '';

            if (!$code || !$code_verifier || !$redirect_uri || !$client_id || !$shop_id) {
                return $this->fail(new WP_Error(
                    'missing_params',
                    '必要なパラメータが不足しています',
                    ['status' => 400]
                ), 400);
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

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['error'])) {
                return $this->fail(new WP_Error(
                    'shopify_token_error',
                    $body['error_description'] ?? $body['error'],
                    ['status' => 400, 'error' => $body['error']]
                ), 400);
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

            // refresh_token をサーバー側に保存（暗号化）
            if ($user_id && !empty($body['refresh_token'])) {
                //itmar_save_encrypted_user_meta($user_id, '_itmar_shopify_refresh_token', (string)$body['refresh_token']);
                TokenVault::saveUserSecret($user_id, '_itmar_shopify_refresh_token', (string)$body['refresh_token']);
            }

            // 短命トークンはフロントへ
            $now        = time();
            $expires_in = isset($body['expires_in']) ? (int)$body['expires_in'] : 0;
            $expires_at = $expires_in ? $now + $expires_in : 0;

            $payload = [
                'access_token' => $body['access_token'] ?? null,
                'id_token'     => $body['id_token'] ?? null,
                'token_type'   => $body['token_type'] ?? 'Bearer',
                'expires_in'   => $expires_in,
                'expires_at'   => $expires_at,
            ];

            return $this->ok(['success' => true, 'token' => $payload]);
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

            // 3) 入力
            $shop_id = isset($params['shop_id'])
                ? sanitize_text_field(wp_unslash((string) $params['shop_id']))
                : '';
            $client_id = isset($params['client_id'])
                ? sanitize_text_field(wp_unslash((string) $params['client_id']))
                : '';

            // フロントで "customerAccessToken" を送ってくる想定（名称は誤解を避けて要リネーム）
            $customer_token = isset($params['customerAccessToken']) ? sanitize_text_field((string)$params['customerAccessToken']) : '';

            if (!$shop_id) {
                return $this->fail('shop_id is required', 400);
            }

            // 4) Shopify Customer API 呼び出しクロージャ
            $fetch_customer = static function (string $access_token) use ($shop_id) {
                $endpoint = esc_url_raw(
                    'https://shopify.com/' . rawurlencode($shop_id) . '/account/customer/api/2025-04/graphql'
                );

                $query = 'query {
                    customer {
                        id
                        emailAddress { emailAddress }
                        firstName
                        lastName
                    }
                }';

                return wp_remote_post(
                    $endpoint,
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
                    wp_send_json_success([
                        'valid'         => true,
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
                $stored_refresh = $wp_user_id ? TokenVault::getUserSecret($wp_user_id, '_itmar_shopify_refresh_token') : '';

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
                        delete_user_meta($wp_user_id, '_itmar_shopify_refresh_token'); // 無効化された refresh は破棄
                    }
                    wp_send_json_success([
                        'valid'          => false,
                        'login_required' => true,
                        'message'        => 'Session expired',
                    ]);
                    exit;
                }

                // ローテーション保存
                if (!empty($rb['refresh_token']) && $wp_user_id) {
                    TokenVault::saveUserSecret($wp_user_id, '_itmar_shopify_refresh_token', (string)$rb['refresh_token']);
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
                    wp_send_json_success([
                        'valid'         => true,
                        'customer'      => $customer2,
                        'wp_user_id'    => $wp_user_id,
                        'wp_user_mail'  => $wp_user_mail,
                        'cart_id'       => $shopify_cart_id,
                        // フロントで短期利用したいなら返してもよい：
                        'access_token' => $new_access,
                    ]);
                    exit;
                }

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


    //ログアウト処理
    public function logoutRedirect(WP_REST_Request $request)
    {
        try {
            // JSON / form 両対応
            $params = stripos($request->get_header('content-type') ?? '', 'application/json') !== false
                ? ($request->get_json_params() ?: [])
                : $request->get_params();

            // リダイレクト先の正規化とオープンリダイレクト対策
            $raw      = isset($params['redirect_url']) ? esc_url_raw((string)$params['redirect_url']) : '';
            $fallback = home_url('/');
            $safe     = wp_validate_redirect($raw, $fallback);

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
