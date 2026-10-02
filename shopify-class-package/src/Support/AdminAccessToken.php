<?php

namespace Itmar\ShopifyClassPackage\Support;

if (! defined('ABSPATH')) exit;

/**
 * Admin API のアクセストークンを供給する。
 *
 * Shopify のカスタムアプリには2種類ある。
 *
 * - レガシーのカスタムアプリ（2026年1月より前に Shopify 管理画面で作成）
 *   管理画面で固定のトークンをコピーでき、`shopify_admin_token` に保存して使う。
 * - Dev Dashboard のアプリ（2026年1月以降はこちらしか作れない）
 *   トークンは管理画面からコピーできない。クライアント ID とシークレットで
 *   クライアント資格情報付与（client credentials grant）を行ってプログラムから発行し、
 *   発行されたトークンは24時間で失効する。この方式はストアと同じ組織のアプリでのみ使える。
 *
 * プラグイン内の Admin API 呼び出しは、どれも `get_option('shopify_admin_token')` で
 * トークンを読んでいる。そこでこのオプションの読み出しにフィルターを掛け、
 * **保存された固定トークンが空のときだけ**、クライアント資格情報で発行したトークンを返す。
 * 呼び出し側は方式の違いを意識しなくてよい。
 *
 * 発行したトークンは失効の少し前まで transient に保存して使い回す。
 * Admin API が 401 を返したら保存分を捨て、次の呼び出しで取り直す。
 */
final class AdminAccessToken
{
    public const CLIENT_ID_OPTION     = 'itmar_shopify_client_id';
    public const CLIENT_SECRET_OPTION = 'itmar_shopify_client_secret';
    public const LAST_ERROR_OPTION    = 'itmar_shopify_token_last_error';

    private const STATIC_OPTION  = 'shopify_admin_token';
    private const CACHE_PREFIX   = 'itmar_shopify_cc_token_';
    private const FAIL_PREFIX    = 'itmar_shopify_cc_fail_';
    // 失効の5分前には新しいトークンに切り替える
    private const REFRESH_MARGIN = 5 * MINUTE_IN_SECONDS;
    // 発行に失敗したら、しばらくは取りに行かない（毎リクエストで Shopify を叩かないため）
    private const FAIL_BACKOFF   = MINUTE_IN_SECONDS;

    /** 保存値そのものを読むときに、フィルターを素通りさせる */
    private static bool $bypass = false;

    public static function register(): void
    {
        // オプションが DB に無いときは option_ ではなく default_option_ が呼ばれるので両方に掛ける
        add_filter('option_' . self::STATIC_OPTION, [self::class, 'filterOption']);
        add_filter('default_option_' . self::STATIC_OPTION, [self::class, 'filterOption']);
        add_filter('http_response', [self::class, 'forgetOnUnauthorized'], 10, 3);
    }

    /**
     * @param mixed $value 保存されている値（または既定値）
     * @return mixed
     */
    public static function filterOption($value)
    {
        if (self::$bypass) return $value;
        if (is_string($value) && $value !== '') return $value;

        $token = self::clientCredentialsToken();
        return $token !== '' ? $token : $value;
    }

    /** 保存されている固定トークン（レガシーのカスタムアプリ）だけを返す。 */
    public static function storedStaticToken(): string
    {
        self::$bypass = true;
        try {
            return (string) get_option(self::STATIC_OPTION, '');
        } finally {
            self::$bypass = false;
        }
    }

    /** クライアント資格情報で接続する設定になっているか。 */
    public static function usesClientCredentials(): bool
    {
        return self::storedStaticToken() === '' && self::credentials() !== null;
    }

    /** 直近の発行エラー（無ければ空）。設定画面での表示用。 */
    public static function lastError(): string
    {
        $error = get_option(self::LAST_ERROR_OPTION, []);
        return is_array($error) ? (string) ($error['message'] ?? '') : '';
    }

    /**
     * クライアント資格情報でトークンを発行する（保存分があればそれを返す）。
     * 設定が揃っていない、または発行に失敗したときは空文字を返す。
     */
    public static function clientCredentialsToken(): string
    {
        $credentials = self::credentials();
        if ($credentials === null) return '';
        [$shop, $clientId, $clientSecret] = $credentials;

        $key    = self::cacheKey($shop, $clientId, $clientSecret);
        $cached = get_transient(self::CACHE_PREFIX . $key);
        if (is_string($cached) && $cached !== '') return $cached;
        if (get_transient(self::FAIL_PREFIX . $key) !== false) return '';

        $response = wp_remote_post(sprintf('https://%s/admin/oauth/access_token', $shop), [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $code  = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body  = is_wp_error($response) ? null : json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';

        if ($code !== 200 || $token === '') {
            if (is_wp_error($response)) {
                $message = $response->get_error_message();
            } else {
                $detail  = is_array($body) ? (string) ($body['error_description'] ?? $body['error'] ?? '') : '';
                $message = trim(sprintf('HTTP %d %s', $code, $detail));
            }
            set_transient(self::FAIL_PREFIX . $key, 1, self::FAIL_BACKOFF);
            update_option(self::LAST_ERROR_OPTION, ['message' => $message, 'at' => time()], false);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Shopify client credentials] ' . $message);
            }
            return '';
        }

        $expiresIn = (int) (is_array($body) ? ($body['expires_in'] ?? DAY_IN_SECONDS) : DAY_IN_SECONDS);
        set_transient(
            self::CACHE_PREFIX . $key,
            $token,
            max(MINUTE_IN_SECONDS, $expiresIn - self::REFRESH_MARGIN)
        );
        delete_option(self::LAST_ERROR_OPTION);

        return $token;
    }

    /** 保存しているトークンと失敗の記録を捨てる。 */
    public static function forget(): void
    {
        $credentials = self::credentials();
        if ($credentials === null) return;
        $key = self::cacheKey(...$credentials);
        delete_transient(self::CACHE_PREFIX . $key);
        delete_transient(self::FAIL_PREFIX . $key);
    }

    /**
     * Admin API が 401 を返したら、保存しているトークンを捨てる（次の呼び出しで取り直す）。
     *
     * @param array|\WP_Error $response
     * @param array           $args
     * @param string          $url
     * @return array|\WP_Error
     */
    public static function forgetOnUnauthorized($response, $args, $url)
    {
        if (!is_string($url) || strpos($url, '/admin/api/') === false) return $response;
        if (is_wp_error($response)) return $response;
        if ((int) wp_remote_retrieve_response_code($response) === 401 && self::usesClientCredentials()) {
            self::forget();
        }
        return $response;
    }

    /** @return array{0:string,1:string,2:string}|null ショップ・クライアント ID・シークレット */
    private static function credentials(): ?array
    {
        $shop         = ShopifyApi::normalizeShopDomain((string) get_option('shopify_shop_domain', ''));
        $clientId     = trim((string) get_option(self::CLIENT_ID_OPTION, ''));
        $clientSecret = trim((string) get_option(self::CLIENT_SECRET_OPTION, ''));
        if ($shop === '' || $clientId === '' || $clientSecret === '') return null;
        return [$shop, $clientId, $clientSecret];
    }

    /** 認証情報のどれかが変われば別のキーになり、古いトークンは使われない。 */
    private static function cacheKey(string $shop, string $clientId, string $clientSecret): string
    {
        return md5($shop . '|' . $clientId . '|' . $clientSecret);
    }
}
