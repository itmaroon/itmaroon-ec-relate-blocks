<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_Error;
use WP_REST_Response;
use Itmar\ShopifyClassPackage\Support\ShopifyGraphQLClient;

if (! defined('ABSPATH')) exit;

abstract class BaseController
{
    protected const NAMESPACE = 'itmar-ec-relate';
    protected const VERSION   = 'v1';

    protected ?ShopifyGraphQLClient $gql = null;

    /** GraphQL API バージョンをここで集中管理（必要に応じて変更） */
    protected string $shopifyApiVersion = '2025-04';

    public function __construct() // ★ 追加
    {
        // 設定の取り方はプロジェクトに合わせて調整
        $shopDomain = get_option('shopify_shop_domain');
        $adminToken = get_option('shopify_admin_token');

        if ($shopDomain && $adminToken) {
            $this->gql = new ShopifyGraphQLClient(
                $shopDomain,
                $adminToken,
                $this->shopifyApiVersion,
                20 // timeout
            );
        }
    }

    protected function ns(): string
    {
        return sprintf('%s/%s', static::NAMESPACE, static::VERSION);
    }

    protected function ok(array $data = [], int $code = 200): WP_REST_Response
    {
        return new WP_REST_Response(['success' => true] + $data, $code);
    }

    /**
     * 失敗レスポンスを統一整形
     * - $err: WP_Error | \Throwable | string
     * - $fallback: ステータス指定が無い場合のデフォルト
     */
    protected function fail($err, int $fallback = 400): WP_REST_Response
    {
        $status  = $fallback;
        $message = 'Request failed';
        $details = [];

        if ($err instanceof WP_Error) {
            $message = $err->get_error_message() ?: $message;
            $data    = $err->get_error_data();
            if (is_array($data)) {
                $status  = $data['status'] ?? $status;
                $details = $data;
            } elseif (is_int($data)) {
                $status  = $data;
            }
        } elseif ($err instanceof \Throwable) {
            $message = $err->getMessage() ?: $message;
            // 任意で例外タイプによりステータスを変える
            $status = $this->mapExceptionToStatus($err) ?? $status;
            if (method_exists($err, 'getContext')) {
                $ctx = $err->getContext();
                if (is_array($ctx)) $details = $ctx;
            }
        } elseif (is_string($err)) {
            $message = $err;
        }

        return new WP_REST_Response([
            'success' => false,
            'error'   => $message,
            'details' => $details,
        ], $status);
    }

    /** 例外 → HTTP ステータスの簡易マッピング（必要に応じて拡張） */
    private function mapExceptionToStatus(\Throwable $e): ?int
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => 400,
            $e instanceof \DomainException         => 422, // Unprocessable
            $e instanceof \RuntimeException        => 409, // Conflict 等に
            default                                => null,
        };
    }
    /**
     * Nonce チェック用コールバックを返す
     * register_rest_route() の 'permission_callback' にそのまま渡せる
     */
    protected function gate(?string $capability = null, ?string $nonceAction = null, bool $requireLogin = false): callable
    {
        return function ($request = null) use ($capability, $nonceAction, $requireLogin): bool {
            // Nonce チェック
            if ($nonceAction) {
                $nonce = '';

                if (isset($_SERVER['HTTP_X_WP_NONCE'])) {
                    $nonce = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']));
                } elseif (isset($_REQUEST['_wpnonce'])) {
                    $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
                }

                if (!wp_verify_nonce($nonce, $nonceAction)) {
                    return false;
                }
            }

            // ログイン必須
            if ($requireLogin && !is_user_logged_in()) {
                return false;
            }

            // 権限必須
            if ($capability) {
                return current_user_can($capability);
            }

            // 何も条件が無ければ通す
            return true;
        };
    }

    /**
     * GraphQL 呼び出しの薄いラッパ。
     * - BaseController 派生から `$this->gql->request()` を隠蔽し、将来の差し替えを容易に
     * - 例外はそのまま投げる（呼び出し側で try/catch して fail() を返す）
     */
    protected function gql(string $query, array $variables = []): array
    {
        if (!$this->gql) {
            throw new \RuntimeException('Shopify GraphQL client is not initialized.');
        }
        return $this->gql->request($query, $variables);
    }

    /**
     * REST ハンドラ内で try/catch と ok()/fail() をまとめるユーティリティ
     * 使い方:
     *   return $this->tryOk(function () { ...; return ['data' => $x]; });
     */
    protected function tryOk(callable $fn, int $okCode = 200, int $fallbackFail = 400): WP_REST_Response // ★ 追加
    {
        try {
            $payload = $fn();
            $data = is_array($payload) ? $payload : ['result' => $payload];
            return $this->ok($data, $okCode);
        } catch (\Throwable $e) {
            return $this->fail($e, $fallbackFail);
        }
    }
}
