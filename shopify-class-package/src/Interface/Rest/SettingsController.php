<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use Itmar\ShopifyClassPackage\Support\AdminAccessToken;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if (! defined('ABSPATH')) exit;

final class SettingsController extends BaseController
{
    public function register(): void
    {
        // 保存：管理者のみ / RESTノンス必須 / ログイン必須
        register_rest_route($this->ns(), '/settings/save', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'saveTokens'],
            'permission_callback' => $this->gate('manage_options', 'wp_rest', true),
        ]]);

        // 取得：管理UI用（トークンはマスク）
        register_rest_route($this->ns(), '/settings', [[
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [$this, 'getSettings'],
            'permission_callback' => $this->gate('manage_options', 'wp_rest', true),
        ]]);
    }

    public function saveTokens(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params() ?: [];

            // 投稿タイプ（任意）
            if (isset($p['productPost']) && $p['productPost'] !== '') {
                update_option('itmar_product_post', sanitize_text_field($p['productPost']));
            }

            // API SECRET（任意）。Dev Dashboard のアプリではクライアントシークレットとして
            // Admin API のトークン発行にも使う。
            if (isset($p['api_secret']) && $p['api_secret'] !== '') {
                update_option(AdminAccessToken::CLIENT_SECRET_OPTION, sanitize_text_field($p['api_secret']));
            }

            // クライアント ID（任意）。Dev Dashboard のアプリで使う。空文字が送られたら消す。
            if (isset($p['client_id'])) {
                update_option(AdminAccessToken::CLIENT_ID_OPTION, sanitize_text_field(trim((string) $p['client_id'])));
            }

            // 必須の項目が欠けていても、送られてきた他の値は保存する。
            // 以前は1つでも欠けるとその場でエラーを返し、ドメインやトークンまで保存されなかった
            // （しかも画面には出ず、利用者は保存されたと思い込む）。欠けた項目は missing で返す。
            $missing = [];

            // ショップのドメインと販売チャネル名：空なら既存の値を残す
            foreach (['shop_domain' => 'shopify_shop_domain', 'channel_name' => 'shopify_channel_name'] as $param_key => $option_key) {
                $incoming = isset($p[$param_key]) ? trim((string) $p[$param_key]) : '';
                if ($param_key === 'shop_domain' && $incoming !== '') {
                    // 「https://」付きで入力されてもホスト名だけを保存する
                    $incoming = ShopifyApi::normalizeShopDomain($incoming);
                }
                if ($incoming !== '') {
                    update_option($option_key, sanitize_text_field($incoming));
                } elseif ((string) get_option($option_key, '') === '') {
                    $missing[] = $param_key;
                }
            }

            // トークンは「空なら既存維持」
            $token_map = [
                'admin_token'      => 'shopify_admin_token',
                'storefront_token' => 'shopify_storefront_token',
            ];
            foreach ($token_map as $param_key => $option_key) {
                $incoming = isset($p[$param_key]) ? trim((string) $p[$param_key]) : '';
                if ($incoming !== '') {
                    update_option($option_key, sanitize_text_field($incoming));
                    continue;
                }
                // Admin のトークンは get_option だと発行済みトークンが返るので、保存値そのものを見る
                $current = $param_key === 'admin_token'
                    ? AdminAccessToken::storedStaticToken()
                    : (string) get_option($option_key, '');
                if ($current !== '') continue;
                // Dev Dashboard のアプリ（クライアント ID とシークレット）なら固定トークンは不要
                if ($param_key === 'admin_token' && $this->hasClientCredentials()) continue;
                $missing[] = $param_key;
            }

            // ショップまたは認証情報の変更後は、次のリクエストでカタログを再確認する。
            delete_option('itmar_shopify_catalog_scan_requested_at');
            delete_option('itmar_shopify_catalog_scan_completed_at');

            // Stripe
            // if (empty($p['stripe_key'])) {
            //     return $this->fail(new WP_Error('missing_params', __('Required API KEY not available.', 'ec-relate-bloks'), ['status' => 400]), 400);
            // }
            // update_option('stripe_key', sanitize_text_field($p['stripe_key']));

            // 認証情報が変わった可能性があるので、保存していたトークンを捨てて発行を試す
            AdminAccessToken::forget();
            $connection = ['mode' => $this->authMode()];
            if ($connection['mode'] === 'client_credentials') {
                $connection['ok'] = AdminAccessToken::clientCredentialsToken() !== '';
                if (!$connection['ok']) {
                    $connection['error'] = AdminAccessToken::lastError();
                }
            }

            // 返却
            return $this->ok(['status' => 'ok', 'connection' => $connection, 'missing' => $missing]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    public function getSettings(WP_REST_Request $request)
    {
        try {
            $mask = fn($v) => $v ? substr($v, 0, 4) . str_repeat('*', max(0, strlen($v) - 8)) . substr($v, -4) : '';

            $data = [
                'productPost'      => (string) get_option('itmar_product_post', ''),
                'shop_domain'      => (string) get_option('shopify_shop_domain', ''),
                'channel_name'     => (string) get_option('shopify_channel_name', ''),
                // トークンはマスク
                'api_secret'       => $mask((string) get_option(AdminAccessToken::CLIENT_SECRET_OPTION, '')),
                // クライアント ID は秘密情報ではないのでそのまま返す
                'client_id'        => (string) get_option(AdminAccessToken::CLIENT_ID_OPTION, ''),
                'admin_token'      => $mask(AdminAccessToken::storedStaticToken()),
                'auth_mode'        => $this->authMode(),
                'token_error'      => AdminAccessToken::lastError(),
                'storefront_token' => $mask((string) get_option('shopify_storefront_token', '')),
                'stripe_key'       => $mask((string) get_option('stripe_key', '')),
            ];
            return $this->ok(['settings' => $data]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    /** クライアント ID とシークレットが（今回の送信分か保存済みで）揃っているか */
    private function hasClientCredentials(): bool
    {
        return trim((string) get_option(AdminAccessToken::CLIENT_ID_OPTION, '')) !== ''
            && trim((string) get_option(AdminAccessToken::CLIENT_SECRET_OPTION, '')) !== '';
    }

    /** 接続方式：static（レガシーの固定トークン）/ client_credentials（Dev Dashboard のアプリ）/ none */
    private function authMode(): string
    {
        if (AdminAccessToken::storedStaticToken() !== '') return 'static';
        return $this->hasClientCredentials() ? 'client_credentials' : 'none';
    }
}
