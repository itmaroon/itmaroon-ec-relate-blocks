<?php

namespace Itmar\ShopifyClassPackage\Support;

if (! defined('ABSPATH')) exit;

final class ShopifyGraphQLClient
{
    private string $shopDomain;
    private string $token;
    private string $apiVersion;
    private int    $timeout;

    public function __construct(
        string $shopDomain,
        string $token,
        string $apiVersion = '2025-04',
        int $timeout = 20
    ) {
        $this->shopDomain = $shopDomain;
        $this->token      = $token;
        $this->apiVersion = $apiVersion;
        $this->timeout    = $timeout;
    }

    /** GraphQL 共通呼び出し */
    public function request(string $query, array $variables = []): array
    {
        $url  = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";
        $resp = wp_remote_post($url, [
            'headers' => [
                'X-Shopify-Access-Token' => $this->token,
                'Content-Type'           => 'application/json',
            ],
            'body'    => wp_json_encode([
                'query'     => $query,
                'variables' => $variables,
            ]),
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($resp)) {
            throw new \RuntimeException(
                esc_html(
                    wp_strip_all_tags($resp->get_error_message())
                )
            );
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            throw new \RuntimeException(sprintf(
                /* translators: %d: HTTP status code */
                esc_html__('GraphQL HTTP %d', 'itmaroon-ec-relate-blocks'),
                (int) $code
            ));
        }
        if (isset($body['errors'])) {
            throw new \RuntimeException(
                'GraphQL: ' . wp_json_encode($body['errors'], JSON_UNESCAPED_UNICODE)
            );
        }
        return $body['data'] ?? [];
    }
}
