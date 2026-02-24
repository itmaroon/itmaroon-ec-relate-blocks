<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

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

            // API SECRET（任意）
            if (isset($p['api_secret']) && $p['api_secret'] !== '') {
                update_option('itmar_shopify_client_secret', sanitize_text_field($p['api_secret']));
            }

            // 必須項目は shop_domain / channel_name のみにする
            foreach (['shop_domain', 'channel_name'] as $k) {
                if (empty($p[$k])) {
                    return $this->fail(
                        new WP_Error(
                            'missing_params',
                            sprintf(
                                /* translators: %s: parameter name */
                                __('Required parameter missing: %s', 'itmaroon-ec-relate-blocks'),
                                sanitize_key($k)
                            ),
                            ['status' => 400]
                        ),
                        400
                    );
                }
            }
            update_option('shopify_shop_domain',      sanitize_text_field($p['shop_domain']));
            update_option('shopify_channel_name',     sanitize_text_field($p['channel_name']));
            // トークンは「空なら既存維持」「初回未設定なら必須」
            $token_map = [
                'admin_token'      => 'shopify_admin_token',
                'storefront_token' => 'shopify_storefront_token',
            ];
            foreach ($token_map as $param_key => $option_key) {
                $incoming = isset($p[$param_key]) ? trim((string) $p[$param_key]) : null;
                $current  = (string) get_option($option_key, '');

                // パラメータが送られていない or 空文字 => 更新しない（既存維持）
                if ($incoming === null || $incoming === '') {
                    // ただし既存も空なら初回設定としてはエラーにする（必要なら）
                    if ($current === '') {
                        return $this->fail(
                            new WP_Error(
                                'missing_params',
                                sprintf(
                                    /* translators: %s: parameter name */
                                    __('Required parameter missing: %s', 'itmaroon-ec-relate-blocks'),
                                    sanitize_key($param_key)
                                ),
                                ['status' => 400]
                            ),
                            400
                        );
                    }
                    continue;
                }

                // 値が入っているときだけ更新
                update_option($option_key, sanitize_text_field($incoming));
            }

            // Stripe
            // if (empty($p['stripe_key'])) {
            //     return $this->fail(new WP_Error('missing_params', __('Required API KEY not available.', 'ec-relate-bloks'), ['status' => 400]), 400);
            // }
            // update_option('stripe_key', sanitize_text_field($p['stripe_key']));

            // 返却
            return $this->ok(['status' => 'ok']);
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
                'admin_token'      => $mask((string) get_option('shopify_admin_token', '')),
                'storefront_token' => $mask((string) get_option('shopify_storefront_token', '')),
                'stripe_key'       => $mask((string) get_option('stripe_key', '')),
            ];
            return $this->ok(['settings' => $data]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }
}
