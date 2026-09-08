<?php

namespace Itmar\ShopifyClassPackage\Support\Security;

final class Crypto
{
    /** 32バイト鍵を WP Salt から導出（実運用では将来ローテーションを見据えバージョン化推奨） */
    private static function key(): string
    {
        // secure_auth 用の Salt を元に 32byte
        return hash('sha256', wp_salt('secure_auth'), true);
    }

    /** 平文を AES-256-GCM で暗号化して安全に文字列化 */
    public static function encrypt(string $plaintext): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL not available');
        }
        $key = self::key();

        // GCM は 12byte IV が推奨
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // JSON で束ねて Base64。互換性のため現行形式を踏襲
        $bundle = [
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ct),
        ];
        return base64_encode(json_encode($bundle, JSON_UNESCAPED_SLASHES));
    }

    /** 暗号文（bundle_b64）を復号。失敗時は null を返す */
    public static function decrypt(?string $bundleB64): ?string
    {
        if (!$bundleB64) return null;
        if (!function_exists('openssl_decrypt')) {
            return null;
        }
        $decoded = base64_decode($bundleB64, true);
        if ($decoded === false) return null;

        $bundle = json_decode($decoded, true);
        if (!is_array($bundle)) return null;

        $ivB64 = $bundle['iv'] ?? '';
        $tagB64 = $bundle['tag'] ?? '';
        $ctB64 = $bundle['ct'] ?? '';
        if ($ivB64 === '' || $tagB64 === '' || $ctB64 === '') return null;

        $iv  = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ct  = base64_decode($ctB64, true);
        if ($iv === false || $tag === false || $ct === false) return null;

        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return ($pt === false) ? null : $pt;
    }
}
