<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;
use Itmar\ShopifyClassPackage\Support\Security\TokenVault;

if (! defined('ABSPATH')) exit;

final class CartController extends BaseController
{
    public function registerRest(): void
    {
        foreach ([
            ['/cart/lines', [$this, 'updateLines']],
            ['/cart/bind', [$this, 'customerBind']],
        ] as [$route, $callback]) {
            register_rest_route($this->ns(), $route, [[
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => $callback,
                'permission_callback' => $this->gate(null, 'wp_rest', true),
            ]]);
        }
    }

    private function cartFields(): string
    {
        return '
            id
            buyerIdentity { customer { id email } }
            checkoutUrl
            lines(first: 100) {
                edges {
                    node {
                        id
                        quantity
                        merchandise {
                            ... on ProductVariant {
                                id
                                title
                                quantityAvailable
                                price { amount currencyCode }
                                compareAtPrice { amount currencyCode }
                                product { id title handle featuredImage { url altText } }
                            }
                        }
                    }
                }
            }
            estimatedCost: cost {
                subtotalAmount { amount currencyCode }
                totalAmount { amount currencyCode }
                totalTaxAmount { amount currencyCode }
                totalDutyAmount { amount currencyCode }
            }';
    }

    private function buyerIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function customerToken(int $userId): string
    {
        $session = TokenVault::getCustomerSession($userId);
        $accessToken = (string) ($session['access_token'] ?? '');
        $expiresAt = (int) ($session['expires_at'] ?? 0);
        if ($accessToken && (!$expiresAt || $expiresAt > time() + 60)) return $accessToken;

        $refreshToken = (string) ($session['refresh_token'] ?? '');
        $clientId = sanitize_text_field((string) ($session['client_id'] ?? ''));
        $shopId = sanitize_text_field((string) ($session['shop_id'] ?? ''));
        if (!$refreshToken || !$clientId || !$shopId) return '';

        $response = wp_remote_post(
            'https://shopify.com/authentication/' . rawurlencode($shopId) . '/oauth/token',
            [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query([
                    'client_id' => $clientId,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]),
                'timeout' => 20,
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return '';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token']) || isset($body['error'])) return '';

        $body['expires_at'] = !empty($body['expires_in']) ? time() + (int) $body['expires_in'] : 0;
        TokenVault::saveCustomerSession($userId, $body, [
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'redirect_uri' => (string) ($session['redirect_uri'] ?? ''),
        ]);
        return (string) $body['access_token'];
    }

    private function storefrontRequest(string $query, array $variables): array
    {
        $shopDomain = sanitize_text_field((string) get_option('shopify_shop_domain'));
        $token = sanitize_text_field((string) get_option('shopify_storefront_token'));
        if (!$shopDomain || !$token) throw new \RuntimeException('Shopify Storefront API settings are incomplete.');

        $headers = [
            'X-Shopify-Storefront-Access-Token' => $token,
            'Content-Type' => 'application/json; charset=utf-8',
        ];
        $buyerIp = $this->buyerIp();
        if ($buyerIp) $headers['Shopify-Storefront-Buyer-IP'] = $buyerIp;

        $response = wp_remote_post(
            esc_url_raw(ShopifyApi::storefrontUrl($shopDomain)),
            [
                'headers' => $headers,
                'body' => wp_json_encode(['query' => $query, 'variables' => $variables]),
                'data_format' => 'body',
                'timeout' => 20,
            ]
        );
        if (is_wp_error($response)) throw new \RuntimeException($response->get_error_message());

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new \RuntimeException('Shopify Storefront API request failed.');
        }
        if (!empty($body['errors'])) {
            $messages = array_map(static fn($error) => sanitize_text_field((string) ($error['message'] ?? 'GraphQL error')), (array) $body['errors']);
            throw new \DomainException(implode(' ', $messages));
        }
        return (array) ($body['data'] ?? []);
    }

    private function extractCart(array $data, string $operation): array
    {
        if ($operation === 'cart') {
            $cart = $data['cart'] ?? null;
        } else {
            $payload = $data[$operation] ?? null;
            if (!is_array($payload)) throw new \RuntimeException('Shopify returned an invalid cart response.');
            if (!empty($payload['userErrors'])) {
                $messages = array_map(static fn($error) => sanitize_text_field((string) ($error['message'] ?? 'Cart error')), (array) $payload['userErrors']);
                throw new \DomainException(implode(' ', $messages));
            }
            $cart = $payload['cart'] ?? null;
        }
        if (!is_array($cart) || empty($cart['id'])) throw new \RuntimeException('Shopify cart was not returned.');
        return $cart;
    }

    private function isMissingCartError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, '指定されたカートは存在しません')
            || str_contains($message, 'cart does not exist')
            || str_contains($message, 'cart was not found')
            || str_contains($message, 'could not find cart');
    }

    /**
     * ストアの国コード（ISO 3166-1 alpha-2）。
     *
     * カートを作るのはサーバー（WordPress）なので、国を指定しないと Shopify は
     * リクエスト元から国を推測する。開発機から作ると US のカートになり、
     * 日本向けのマーケットでは「販売不可・在庫0」と判定されて数量が0に落ちる。
     * ストアの国を明示して、その取り違えを防ぐ。
     */
    private function storeCountryCode(): string
    {
        $cached = get_transient('itmar_shopify_store_country');
        if (is_string($cached) && $cached !== '') return $cached;

        $shopDomain = (string) get_option('shopify_shop_domain', '');
        $adminToken = (string) get_option('shopify_admin_token', '');
        if ($shopDomain === '' || $adminToken === '') return '';

        $response = wp_remote_get(
            add_query_arg('fields', 'country_code', ShopifyApi::adminUrl($shopDomain, 'shop.json')),
            [
                'timeout' => 15,
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Accept'                 => 'application/json',
                ],
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return '';

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = strtoupper((string) (is_array($body) ? ($body['shop']['country_code'] ?? '') : ''));
        if (!preg_match('/^[A-Z]{2}$/', $code)) return '';

        set_transient('itmar_shopify_store_country', $code, DAY_IN_SECONDS);
        return $code;
    }

    /** カートの買い手情報（顧客トークンと国）を組み立てる。 */
    private function buyerIdentityInput(string $customerToken): array
    {
        $buyer = [];
        if ($customerToken !== '') $buyer['customerAccessToken'] = $customerToken;
        $country = $this->storeCountryCode();
        if ($country !== '') $buyer['countryCode'] = $country;
        return $buyer;
    }

    private function createCart(string $variantId, int $quantity, string $customerToken): array
    {
        if (!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#', $variantId)) {
            throw new \InvalidArgumentException('Invalid product variant ID.');
        }

        $input = ['lines' => [['merchandiseId' => $variantId, 'quantity' => $quantity]]];
        $buyer = $this->buyerIdentityInput($customerToken);
        if ($buyer) $input['buyerIdentity'] = $buyer;

        $query = 'mutation CartCreate($input: CartInput!) {
            cartCreate(input: $input) { cart { ' . $this->cartFields() . ' } userErrors { field message } }
        }';
        return $this->extractCart(
            $this->storefrontRequest($query, ['input' => $input]),
            'cartCreate'
        );
    }

    private function emptyCartResponse(): array
    {
        return [
            'cartId' => '',
            'buyerId' => null,
            'cartContents' => [],
            'estimatedCost' => null,
            'checkoutUrl' => '',
            'itemCount' => 0,
        ];
    }

    private function bindCart(string $cartId, string $customerToken): array
    {
        $query = 'mutation CartBuyerIdentityUpdate($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
            cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
                cart { ' . $this->cartFields() . ' }
                userErrors { field message }
            }
        }';
        $data = $this->storefrontRequest($query, [
            'cartId' => $cartId,
            'buyerIdentity' => $this->buyerIdentityInput($customerToken),
        ]);
        return $this->extractCart($data, 'cartBuyerIdentityUpdate');
    }

    private function assertCartId(string $cartId): void
    {
        if (!str_starts_with($cartId, 'gid://shopify/Cart/')) throw new \InvalidArgumentException('Invalid cart ID.');
        $saved = (string) get_user_meta(get_current_user_id(), 'shopify_cart_id', true);
        if ($saved && !hash_equals($saved, $cartId)) throw new \DomainException('This cart does not belong to the current user.');
    }

    private function cartResponse(array $cart): array
    {
        $itemCount = 0;
        foreach ((array) ($cart['lines']['edges'] ?? []) as $edge) {
            $itemCount += max(0, (int) ($edge['node']['quantity'] ?? 0));
        }
        return [
            'cartId' => (string) $cart['id'],
            'buyerId' => $cart['buyerIdentity']['customer']['id'] ?? null,
            'cartContents' => (array) ($cart['lines']['edges'] ?? []),
            'estimatedCost' => $cart['estimatedCost'] ?? null,
            'checkoutUrl' => esc_url_raw((string) ($cart['checkoutUrl'] ?? '')),
            'itemCount' => $itemCount,
        ];
    }

    public function updateLines(WP_REST_Request $request)
    {
        try {
            $params = $request->get_json_params() ?: [];
            $mode = sanitize_key((string) ($params['mode'] ?? ''));
            $allowed = ['into_cart', 'trush_out', 'calc_again', 'soon_buy', 'go_shopify', 'go_checkout', 'bind_cart'];
            if (!in_array($mode, $allowed, true)) {
                return $this->fail(new WP_Error('invalid_mode', 'Unsupported cart operation.', ['status' => 400]), 400);
            }

            $userId = get_current_user_id();
            $customerToken = $this->customerToken($userId);
            $checkoutMode = in_array($mode, ['soon_buy', 'go_shopify', 'go_checkout'], true);
            if ($checkoutMode && !$customerToken) {
                return $this->fail(new WP_Error(
                    'shopify_login_required',
                    'Shopifyとの連携が切れています。サイトから一度ログアウトして再ログインしてください。',
                    ['status' => 401, 'login_required' => true]
                ), 401);
            }

            $cartId = sanitize_text_field((string) ($params['cartId'] ?? ''));
            if (!$cartId && $mode !== 'soon_buy') $cartId = (string) get_user_meta($userId, 'shopify_cart_id', true);
            if ($cartId) $this->assertCartId($cartId);

            $variantId = sanitize_text_field((string) ($params['productId'] ?? ''));
            $lineId = sanitize_text_field((string) ($params['lineId'] ?? ''));
            $quantity = max(1, absint($params['quantity'] ?? 1));
            $fields = $this->cartFields();

            if (!$cartId) {
                $cart = $this->createCart($variantId, $quantity, $customerToken);
            } elseif ($checkoutMode || $mode === 'bind_cart') {
                try {
                    if ($customerToken) {
                        $cart = $this->bindCart($cartId, $customerToken);
                    } else {
                        $query = 'query CartQuery($cartId: ID!) { cart(id: $cartId) { ' . $fields . ' } }';
                        $cart = $this->extractCart(
                            $this->storefrontRequest($query, ['cartId' => $cartId]),
                            'cart'
                        );
                    }
                } catch (\Throwable $error) {
                    if ($mode !== 'bind_cart' || !$this->isMissingCartError($error)) throw $error;
                    delete_user_meta($userId, 'shopify_cart_id');
                    return $this->ok($this->emptyCartResponse());
                }
                update_user_meta($userId, 'shopify_cart_id', (string) $cart['id']);
                return $this->ok($this->cartResponse($cart));
            } elseif ($mode === 'into_cart') {
                if (!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#', $variantId)) throw new \InvalidArgumentException('Invalid product variant ID.');
                $query = 'mutation CartLinesAdd($cartId: ID!, $lines: [CartLineInput!]!) {
                    cartLinesAdd(cartId: $cartId, lines: $lines) { cart { ' . $fields . ' } userErrors { field message } }
                }';
                $variables = ['cartId' => $cartId, 'lines' => [['merchandiseId' => $variantId, 'quantity' => $quantity]]];
                $operation = 'cartLinesAdd';
            } elseif ($mode === 'trush_out') {
                if (!str_starts_with($lineId, 'gid://shopify/CartLine/')) throw new \InvalidArgumentException('Invalid cart line ID.');
                $query = 'mutation CartLinesRemove($cartId: ID!, $lineIds: [ID!]!) {
                    cartLinesRemove(cartId: $cartId, lineIds: $lineIds) { cart { ' . $fields . ' } userErrors { field message } }
                }';
                $variables = ['cartId' => $cartId, 'lineIds' => [$lineId]];
                $operation = 'cartLinesRemove';
            } else {
                $decoded = json_decode((string) ($params['form_data'] ?? '[]'), true);
                $lines = [];
                foreach (is_array($decoded) ? $decoded : [] as $line) {
                    $id = sanitize_text_field((string) ($line['id'] ?? ''));
                    if (!str_starts_with($id, 'gid://shopify/CartLine/')) continue;
                    $lines[] = ['id' => $id, 'quantity' => max(0, (int) ($line['quantity'] ?? 0))];
                }
                if (!$lines) throw new \InvalidArgumentException('No valid cart lines were supplied.');
                $query = 'mutation CartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
                    cartLinesUpdate(cartId: $cartId, lines: $lines) {
                        cart { ' . $fields . ' }
                        userErrors { field message code }
                        warnings { message }
                    }
                }';
                $variables = ['cartId' => $cartId, 'lines' => $lines];
                $operation = 'cartLinesUpdate';
            }

            if (!isset($cart)) {
                try {
                    $cart = $this->extractCart($this->storefrontRequest($query, $variables), $operation);
                } catch (\Throwable $error) {
                    if ($mode !== 'into_cart' || !$this->isMissingCartError($error)) throw $error;
                    delete_user_meta($userId, 'shopify_cart_id');
                    $cart = $this->createCart($variantId, $quantity, $customerToken);
                }
            }
            if ($customerToken && empty($cart['buyerIdentity']['customer'])) $cart = $this->bindCart((string) $cart['id'], $customerToken);
            if ($mode !== 'soon_buy') {
                update_user_meta($userId, 'shopify_cart_id', (string) $cart['id']);
                setcookie('shopify_cart_id', '', [
                    'expires' => time() - HOUR_IN_SECONDS,
                    'path' => COOKIEPATH ?: '/',
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            return $this->ok($this->cartResponse($cart));
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    public function customerBind(WP_REST_Request $request)
    {
        try {
            $params = $request->get_json_params() ?: [];
            $cartId = sanitize_text_field((string) ($params['cart_id'] ?? ''));
            if (!$cartId) throw new \InvalidArgumentException('Missing cart ID.');
            $this->assertCartId($cartId);

            $customerToken = $this->customerToken(get_current_user_id());
            if (!$customerToken) {
                return $this->fail(new WP_Error('shopify_login_required', 'Shopify login is required.', ['status' => 401]), 401);
            }
            $cart = $this->bindCart($cartId, $customerToken);
            update_user_meta(get_current_user_id(), 'shopify_cart_id', (string) $cart['id']);
            return $this->ok($this->cartResponse($cart));
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }
}
