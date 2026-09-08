<?php

namespace Itmar\ShopifyClassPackage\Support\Security;

final class TokenVault
{
    public const ACCESS_TOKEN_KEY = '_itmar_shopify_access_token';
    public const REFRESH_TOKEN_KEY = '_itmar_shopify_refresh_token';
    public const ID_TOKEN_KEY = '_itmar_shopify_id_token';
    public const EXPIRES_AT_KEY = '_itmar_shopify_access_expires_at';
    public const SHOP_ID_KEY = '_itmar_shopify_shop_id';
    public const CLIENT_ID_KEY = '_itmar_shopify_customer_client_id';
    public const REDIRECT_URI_KEY = '_itmar_shopify_redirect_uri';

    /** 暗号化して user_meta に保存 */
    public static function saveUserSecret(int $userId, string $metaKey, string $plaintext): void
    {
        $enc = Crypto::encrypt($plaintext);
        update_user_meta($userId, $metaKey, $enc);
    }

    /** 復号して取得（存在しない/壊れている場合は null） */
    public static function getUserSecret(int $userId, string $metaKey): ?string
    {
        $enc = get_user_meta($userId, $metaKey, true);
        if (!$enc || !is_string($enc)) return null;
        return Crypto::decrypt($enc);
    }

    /** 秘密の破棄 */
    public static function deleteUserSecret(int $userId, string $metaKey): void
    {
        delete_user_meta($userId, $metaKey);
    }

    /** Customer Account API のセッション一式をサーバー内だけに保存する。 */
    public static function saveCustomerSession(int $userId, array $tokens, array $context = []): void
    {
        if (!empty($tokens['access_token'])) {
            self::saveUserSecret($userId, self::ACCESS_TOKEN_KEY, (string) $tokens['access_token']);
        }
        if (!empty($tokens['refresh_token'])) {
            self::saveUserSecret($userId, self::REFRESH_TOKEN_KEY, (string) $tokens['refresh_token']);
        }
        if (!empty($tokens['id_token'])) {
            self::saveUserSecret($userId, self::ID_TOKEN_KEY, (string) $tokens['id_token']);
        }

        if (array_key_exists('expires_at', $tokens) || array_key_exists('expires_in', $tokens)) {
            $expiresIn = isset($tokens['expires_in']) ? max(0, (int) $tokens['expires_in']) : 0;
            $expiresAt = isset($tokens['expires_at'])
                ? max(0, (int) $tokens['expires_at'])
                : ($expiresIn ? time() + $expiresIn : 0);
            update_user_meta($userId, self::EXPIRES_AT_KEY, $expiresAt);
        }

        foreach ([
            self::SHOP_ID_KEY => 'shop_id',
            self::CLIENT_ID_KEY => 'client_id',
            self::REDIRECT_URI_KEY => 'redirect_uri',
        ] as $metaKey => $contextKey) {
            if (!empty($context[$contextKey])) {
                update_user_meta($userId, $metaKey, sanitize_text_field((string) $context[$contextKey]));
            }
        }
    }

    public static function getCustomerSession(int $userId): array
    {
        return [
            'access_token' => self::getUserSecret($userId, self::ACCESS_TOKEN_KEY),
            'refresh_token' => self::getUserSecret($userId, self::REFRESH_TOKEN_KEY),
            'id_token' => self::getUserSecret($userId, self::ID_TOKEN_KEY),
            'expires_at' => (int) get_user_meta($userId, self::EXPIRES_AT_KEY, true),
            'shop_id' => (string) get_user_meta($userId, self::SHOP_ID_KEY, true),
            'client_id' => (string) get_user_meta($userId, self::CLIENT_ID_KEY, true),
            'redirect_uri' => (string) get_user_meta($userId, self::REDIRECT_URI_KEY, true),
        ];
    }

    public static function deleteCustomerSession(int $userId): void
    {
        foreach ([
            self::ACCESS_TOKEN_KEY,
            self::REFRESH_TOKEN_KEY,
            self::ID_TOKEN_KEY,
            self::EXPIRES_AT_KEY,
            self::SHOP_ID_KEY,
            self::CLIENT_ID_KEY,
            self::REDIRECT_URI_KEY,
        ] as $metaKey) {
            delete_user_meta($userId, $metaKey);
        }
    }
}
