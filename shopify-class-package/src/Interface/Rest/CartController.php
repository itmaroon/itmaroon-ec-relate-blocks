<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use Itmar\ShopifyClassPackage\Support\Validation\Sanitizer;

if (! defined('ABSPATH')) exit;

final class CartController extends BaseController
{
  private Sanitizer $sanitizer;

  public function __construct()
  {
    $this->sanitizer = new Sanitizer();
  }

  public function registerRest(): void
  {
    $routes = [
      [
        'route'   => '/cart/lines',
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'updateLines'],
        'permission_callback' => '__return_true', //Nonce だけ必須（ログイン不要）

      ],
      [
        'route'   => '/cart/bind',
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'customerBind'],
        'permission_callback' => $this->gate(null, 'wp_rest', true), //ログイン必須 + Nonce
      ],

    ];

    foreach ($routes as $r) {
      register_rest_route($this->ns(), $r['route'], [[
        'methods'  => $r['methods'],
        'callback' => $r['callback'],
        'permission_callback' => $r['permission_callback'],
      ]]);
    }
  }

  public function updateLines(WP_REST_Request $request)
  {
    try {
      $params = $request->get_json_params();

      $lineId = sanitize_text_field($params['lineId'] ?? '');
      $variantId = sanitize_text_field($params['productId'] ?? ''); // これは "gid://shopify/ProductVariant/..." の形式であること
      $quantity = absint($params['quantity'] ?? 0);
      $cartId = sanitize_text_field($params['cartId'] ?? null);
      $mode = sanitize_text_field($params['mode'] ?? '');
      $wp_user_id = sanitize_text_field($params['wp_user_id'] ?? '');

      $formDataObj = [];
      if (!empty($params['form_data'])) {
        $decoded = json_decode($params['form_data'], true); // 文字列→配列
        if (is_array($decoded)) {
          foreach ($decoded as $line) {
            $id = isset($line['id']) ? sanitize_text_field($line['id']) : '';
            $quantity = isset($line['quantity']) ? intval($line['quantity']) : 0;

            // 必要ならIDのパターンをバリデーション
            if (! preg_match('#^gid://shopify/CartLine/[a-z0-9\-]+#i', $id)) {
              continue; // 不正な形式ならスキップ
            }

            $formDataObj[] = [
              'id' => $id,
              'quantity' => $quantity,
            ];
          }
        }
      }

      //カート情報がないときはクッキーに残っていないか（ゲストカート）がないか確認
      if (!$cartId && $mode != 'soon_buy') {
        $cartId = null;

        if (isset($_COOKIE['shopify_cart_id'])) {
          $cartId = sanitize_text_field(wp_unslash($_COOKIE['shopify_cart_id']));
        }
      }
      //カート情報の取得用クエリ
      $CART_FIELDS = '
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
                product {
                  id
                  title
                  handle
                  featuredImage { url altText }
                }
              }
            }
          }
        }
      }
      estimatedCost {
        subtotalAmount { amount currencyCode }
        totalAmount    { amount currencyCode }
        totalTaxAmount { amount currencyCode }
        totalDutyAmount { amount currencyCode }
      }';

      $cart_fields = $CART_FIELDS; // 例: 定数にするなら self::CART_FIELDS 推奨
      $variables   = [];

      if ($cartId) {
        if ($mode === 'into_cart' && $variantId) {

          $query = 'mutation CartLinesAdd($cartId: ID!, $lines: [CartLineInput!]!) {
            cartLinesAdd(cartId: $cartId, lines: $lines) {
              cart { ' . $cart_fields . ' }
              userErrors { field message }
            }
          }';

          $variables = [
            'cartId' => (string) $cartId,
            'lines'  => [
              [
                'merchandiseId' => (string) $variantId,
                'quantity'      => (int) $quantity,
              ],
            ],
          ];
        } elseif ($mode === 'trush_out' && $lineId) {

          $query = 'mutation CartLinesRemove($cartId: ID!, $lineIds: [ID!]!) {
            cartLinesRemove(cartId: $cartId, lineIds: $lineIds) {
              cart { ' . $cart_fields . ' }
              userErrors { field message }
            }
          }';

          $variables = [
            'cartId'  => (string) $cartId,
            'lineIds' => [(string) $lineId],
          ];
        } elseif ($mode === 'calc_again') {

          $lines = array_map(
            static function ($line) {
              return [
                'id'       => (string) $line['id'],
                'quantity' => (int) $line['quantity'],
              ];
            },
            (array) $formDataObj
          );

          $query = 'mutation CartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
            cartLinesUpdate(cartId: $cartId, lines: $lines) {
              cart { ' . $cart_fields . ' }
              userErrors { field message code }
              warnings { message }
            }
          }';

          $variables = [
            'cartId' => (string) $cartId,
            'lines'  => $lines,
          ];
        } else {

          $query = 'query CartQuery($cartId: ID!) {
            cart(id: $cartId) {
              ' . $cart_fields . '
            }
          }';

          $variables = [
            'cartId' => (string) $cartId,
          ];
        }
      } else {
        $query = 'mutation CartCreate($lines: [CartLineInput!]!) {
            cartCreate(input: { lines: $lines }) {
              cart { ' . $cart_fields . ' }
              userErrors { field message }
            }
          }';

        $variables = [
          'lines' => [
            [
              'merchandiseId' => (string) $variantId,
              'quantity'      => (int) $quantity,
            ],
          ],
        ];
      }

      // カスタムエンドポイントに問い合わせ
      $shop_domain = sanitize_text_field((string) get_option('shopify_shop_domain'));
      $token       = sanitize_text_field((string) get_option('shopify_storefront_token'));

      $url = esc_url_raw('https://' . $shop_domain . '/api/2025-04/graphql.json');

      $payload = [
        'query'     => $query,
        'variables' => $variables,
      ];

      $response = wp_remote_post(
        $url,
        [
          'headers'     => [
            'X-Shopify-Storefront-Access-Token' => $token,
            'Content-Type'                      => 'application/json; charset=utf-8',
          ],
          'body'        => wp_json_encode($payload),
          'data_format' => 'body',
          'timeout'     => 20,
        ]
      );

      $data = json_decode(wp_remote_retrieve_body($response), true);
      $cart = $data['data']['cartCreate']['cart']
        ?? $data['data']['cartLinesAdd']['cart']
        ?? $data['data']['cartLinesRemove']['cart']
        ?? $data['data']['cartLinesUpdate']['cart']
        ?? $data['data']['cart']
        ?? null;


      // 「すぐに購入」でない場合
      $itemCount = 0;
      if ($mode !== 'soon_buy') {
        if ($wp_user_id) {
          //cartId をuser_metaに保存
          update_user_meta($wp_user_id, 'shopify_cart_id', $cart['id']);
        } else {
          //cartId をcookieに保存
          setcookie('shopify_cart_id', $cart['id'], time() + WEEK_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        }
        // 商品数を合計
        if (!empty($cart['lines']['edges'])) {
          foreach ($cart['lines']['edges'] as $edge) {
            $itemCount += intval($edge['node']['quantity']);
          }
        }
      }

      return $this->ok([
        'success' => true,
        'cartId' => $cart['id'],
        'buyerId' => $cart['buyerIdentity']['customer'],
        'cartContents' => $cart['lines']['edges'],
        'estimatedCost' => $cart['estimatedCost'],
        'checkoutUrl' => $cart['checkoutUrl'],
        'itemCount' => $itemCount,
      ]);
    } catch (\Throwable $e) {
      return $this->fail($e);
    }
  }

  public function customerBind(WP_REST_Request $request)
  {

    //パラメータ取得
    $params = $request->get_json_params();
    $cartId = sanitize_text_field($params['cart_id'] ?? '');
    $customerToken = sanitize_text_field($params['customer_token'] ?? '');
    //パラメータの異常処理
    if (!$cartId) {
      return $this->fail(new \WP_Error(
        'rest_invalid_param',
        'Missing parameter: cartId',
        ['status' => 400, 'param' => 'cartId']
      ));
    }
    if (!$customerToken) {
      return $this->fail(new \WP_Error(
        'rest_invalid_param',
        'Missing parameter: customerToken',
        ['status' => 400, 'param' => 'customerToken']
      ));
    }

    $query = 'mutation cartBuyerIdentityUpdate($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
      cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
        cart {
          id
          buyerIdentity {
            customer {
              id
              email
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }';

    $variables = [
      'cartId'        => (string) $cartId,
      'buyerIdentity' => [
        'customerAccessToken' => (string) $customerToken,
      ],
    ];

    $shop_domain   = sanitize_text_field((string) get_option('shopify_shop_domain'));
    $access_token  = sanitize_text_field((string) get_option('shopify_storefront_token'));

    // エンドポイントは文字列結合して esc_url_raw で安全側に
    $endpoint = esc_url_raw('https://' . $shop_domain . '/api/2025-04/graphql.json');

    $payload = [
      'query'     => $query,
      'variables' => $variables,
    ];

    $response = wp_remote_post(
      $endpoint,
      [
        'headers'     => [
          'Content-Type'                      => 'application/json; charset=utf-8',
          'X-Shopify-Storefront-Access-Token' => $access_token,
        ],
        'body'        => wp_json_encode($payload),
        'data_format' => 'body',
        'timeout'     => 20,
      ]
    );

    if (is_wp_error($response)) {
      return $this->fail($response, 500);
    }


    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $this->ok($body);
  }
}
