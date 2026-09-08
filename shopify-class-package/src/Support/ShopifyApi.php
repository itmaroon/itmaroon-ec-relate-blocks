<?php

namespace Itmar\ShopifyClassPackage\Support;

if (! defined('ABSPATH')) exit;

/**
 * Shopify API のバージョン、URL、実際に応答したバージョンを一元管理する。
 */
final class ShopifyApi
{
    public const ADMIN_VERSION = '2026-04';
    public const STOREFRONT_VERSION = '2026-07';
    public const CUSTOMER_ACCOUNT_VERSION = '2026-07';

    private const VERSION_STATUS_OPTION = 'itmar_shopify_api_version_status';
    private const STATUS_WRITE_INTERVAL = 6 * HOUR_IN_SECONDS;

    public static function registerMonitoring(): void
    {
        add_filter('http_response', [self::class, 'monitorResponse'], 10, 3);
    }

    public static function adminUrl(string $shopDomain, string $path): string
    {
        return sprintf(
            'https://%s/admin/api/%s/%s',
            self::normalizeShopDomain($shopDomain),
            self::ADMIN_VERSION,
            ltrim($path, '/')
        );
    }

    public static function storefrontUrl(string $shopDomain): string
    {
        return sprintf(
            'https://%s/api/%s/graphql.json',
            self::normalizeShopDomain($shopDomain),
            self::STOREFRONT_VERSION
        );
    }

    /**
     * Shopify の discovery document から Customer Account API の正規URLを取得する。
     * discovery が返す最新版へ無条件追従せず、検証済みの固定バージョンに置換する。
     */
    public static function customerAccountUrl(string $shopDomain): string
    {
        $domain = self::normalizeShopDomain($shopDomain);
        $fallback = sprintf(
            'https://%s/customer/api/%s/graphql',
            $domain,
            self::CUSTOMER_ACCOUNT_VERSION
        );
        if ($domain === '') return $fallback;

        $cacheKey = 'itmar_customer_api_' . md5($domain);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;

        $discoveryUrl = 'https://' . $domain . '/.well-known/customer-account-api';
        $response = wp_remote_get($discoveryUrl, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return $fallback;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $discovered = is_array($body) ? (string) ($body['graphql_api'] ?? '') : '';
        if ($discovered === '' || !wp_http_validate_url($discovered)) return $fallback;

        $versioned = preg_replace(
            '#/customer/api/[^/]+/graphql(?:$|\?)#',
            '/customer/api/' . self::CUSTOMER_ACCOUNT_VERSION . '/graphql',
            $discovered,
            1,
            $count
        );
        if ($count !== 1 || !is_string($versioned) || !wp_http_validate_url($versioned)) {
            return $fallback;
        }

        set_transient($cacheKey, $versioned, 12 * HOUR_IN_SECONDS);
        return $versioned;
    }

    public static function configuredVersions(): array
    {
        return [
            'admin' => self::ADMIN_VERSION,
            'storefront' => self::STOREFRONT_VERSION,
            'customer_account' => self::CUSTOMER_ACCOUNT_VERSION,
        ];
    }

    public static function versionStatus(): array
    {
        $status = get_option(self::VERSION_STATUS_OPTION, []);
        return is_array($status) ? $status : [];
    }

    /**
     * Shopify API 応答ヘッダーと要求URLのバージョンを比較して管理画面用に保存する。
     */
    public static function monitorResponse($response, array $args, string $url)
    {
        if (is_wp_error($response)) return $response;

        $api = '';
        $requested = '';
        if (preg_match('#/admin/api/([0-9]{4}-[0-9]{2})/#', $url, $matches)) {
            $api = 'admin';
            $requested = $matches[1];
        } elseif (preg_match('#/customer/api/([0-9]{4}-[0-9]{2})/graphql#', $url, $matches)
            || preg_match('#/account/customer/api/([0-9]{4}-[0-9]{2})/graphql#', $url, $matches)) {
            $api = 'customer_account';
            $requested = $matches[1];
        } elseif (preg_match('#/api/([0-9]{4}-[0-9]{2})/graphql\.json#', $url, $matches)) {
            $api = 'storefront';
            $requested = $matches[1];
        }
        if ($api === '') return $response;

        $actual = trim((string) wp_remote_retrieve_header($response, 'x-shopify-api-version'));
        $all = self::versionStatus();
        $previous = is_array($all[$api] ?? null) ? $all[$api] : [];
        $now = time();
        $changed = ($previous['requested'] ?? '') !== $requested
            || ($previous['actual'] ?? '') !== $actual
            || (int) ($previous['http_status'] ?? 0) !== (int) wp_remote_retrieve_response_code($response);
        if (!$changed && $now - (int) ($previous['checked_at'] ?? 0) < self::STATUS_WRITE_INTERVAL) {
            return $response;
        }

        $all[$api] = [
            'requested' => $requested,
            'actual' => $actual,
            'matches' => $actual !== '' ? hash_equals($requested, $actual) : null,
            'http_status' => (int) wp_remote_retrieve_response_code($response),
            'checked_at' => $now,
        ];
        update_option(self::VERSION_STATUS_OPTION, $all, false);
        return $response;
    }

    private static function normalizeShopDomain(string $shopDomain): string
    {
        $value = trim($shopDomain);
        if ($value === '') return '';
        $url = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
        $host = wp_parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}
