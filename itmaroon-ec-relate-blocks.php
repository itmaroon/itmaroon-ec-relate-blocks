<?php

/**
 * Plugin Name:       ITMAROON EC RELATE BLOCKS
 * Plugin URI:        https://itmaroon.net
 * Description:       We provide blocks to build EC sites in cooperation with various EC companies.
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Version:           0.1.0
 * Author:            Web Creator ITmaroon
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       itmaroon-ec-relate-blocks
 * Domain Path:       /languages 
 *
 * @package           itmar
 */


//PHPファイルに対する直接アクセスを禁止
if (!defined('ABSPATH')) exit;

// プラグイン情報取得に必要なファイルを読み込む
if (!function_exists('get_plugin_data')) {
	require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

require_once __DIR__ . '/vendor/itmar/loader-package/src/register_autoloader.php';
$ec_relate_blocks_entry = new \Itmar\BlockClassPackage\ItmarEntryClass();

(new \Itmar\ShopifyClassPackage\Bootstrap\Plugin())->boot();

//ブロックの初期登録
add_action('init', function () use ($ec_relate_blocks_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$ec_relate_blocks_entry->block_init($plugin_data['TextDomain'], __FILE__);
});

//Shopify ログインの中継ページの生成
function itmar_create_shopify_auth_callback_page()
{
	if (get_page_by_path('shopify-auth-callback')) {
		return; // 既に存在
	}
	wp_insert_post([
		'post_title'   => 'Shopify Auth Callback',
		'post_name'    => 'shopify-auth-callback',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	]);
}
register_activation_hook(__FILE__, 'itmar_create_shopify_auth_callback_page');


// REST APIエンドポイント登録（ShopifyのWebhook用など）
add_action('rest_api_init', function () {
	//shopifyのwebhookを受け取るエンドポイント
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook', [
		'methods' => 'POST',
		'callback' => 'itmar_shopify_webhook_callback',
		'permission_callback' => 'itmar_verify_shopify_webhook_hmac',
	]);
	//WebHook設定状況のリスト取得
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-list', [
		'methods'  => 'POST',
		'callback' => 'itmar_get_shopify_webhook_list',
		'permission_callback' => function () {
			return current_user_can('edit_posts'); // 管理者 or 編集者に制限
		},
	]);
	//WebHook設定
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-register', [
		'methods'  => 'POST',
		'callback' => 'itmar_register_shopify_webhook',
		'permission_callback' => function () {
			return current_user_can('edit_posts'); // 管理者 or 編集者に制限
		},
	]);
	//WebHook削除
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-delete', [
		'methods'  => 'POST',
		'callback' => 'itmar_delete_shopify_webhook',
		'permission_callback' => function () {
			return current_user_can('edit_posts');
		},
	]);
	//Stripeのユーザー登録
	// register_rest_route('itmar-ec-relate/v1', '/stripe-create-customer', [
	// 	'methods' => 'POST',
	// 	'callback' => 'itmar_stripe_create_customer',
	// 	'permission_callback' => '__return_true',
	// ]);
});

//permission_callback 用　HMAC検証
function itmar_verify_shopify_webhook_hmac(WP_REST_Request $request)
{
	$hmac = $request->get_header('x_shopify_hmac_sha256');
	if (is_array($hmac)) $hmac = $hmac[0] ?? '';
	$hmac = trim((string) $hmac);

	if ($hmac === '') {
		return new WP_Error('missing_hmac', 'Missing Shopify HMAC.', ['status' => 401]);
	}

	$secret = (string) get_option('itmar_shopify_api_secret');
	if ($secret === '') {
		return new WP_Error('missing_secret', 'Webhook secret not configured.', ['status' => 500]);
	}

	$body = $request->get_body();
	$calc = base64_encode(hash_hmac('sha256', $body, $secret, true));

	if (!hash_equals($hmac, $calc)) {
		return new WP_Error('invalid_hmac', 'Invalid Shopify HMAC.', ['status' => 401]);
	}

	return true;
}
//Shopifyからの通知をうける
function itmar_shopify_webhook_callback(WP_REST_Request $request)
{

	$topic = $request->get_header('x_shopify_topic');
	if (is_array($topic)) $topic = $topic[0] ?? '';
	$topic = (string) $topic;

	$allowed_topics = ['customers/update'];
	if (!in_array($topic, $allowed_topics, true)) {
		return new WP_REST_Response(['ignored' => true, 'topic' => $topic], 200);
	}

	$customer_data = $request->get_json_params();
	$shopify_customer_id = isset($customer_data['id']) ? absint($customer_data['id']) : 0;
	$state = (string) ($customer_data['state'] ?? '');

	if (!$shopify_customer_id) {
		return new WP_Error('missing_customer_id', 'customer_id が見つかりません', ['status' => 400]);
	}

	if ($state === 'enabled') {
		global $wpdb;
		$pending_table = $wpdb->prefix . 'user_pending';

		$updated = $wpdb->update(
			$pending_table,
			['status' => 'enabled'],
			['shopify_customer_id' => $shopify_customer_id],
			['%s'],
			['%d']
		);

		if ($updated === false) {
			return new WP_Error('db_error', 'Failed to update pending user.', ['status' => 500]);
		}
	}

	return new WP_REST_Response([
		'received' => true,
		'topic'    => $topic,
		'shopify_customer_id' => $shopify_customer_id,
		'state'    => $state,
	], 200);
}



// 依存するプラグインが有効化されているかのアクティベーションフック
register_activation_hook(__FILE__, function () use ($ec_relate_blocks_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$ec_relate_blocks_entry->activation_check($plugin_data, ['block-collections']); // ここでメソッドを呼び出し
});

// 管理画面での通知フック
add_action('admin_notices', function () use ($ec_relate_blocks_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$ec_relate_blocks_entry->show_admin_dependency_notices($plugin_data, ['block-collections']);
});




//登録されているWebhookのリストを取得
function itmar_get_shopify_webhook_list(WP_REST_Request $request)
{
	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	if (!$shop_domain || !$admin_token) {
		return new WP_Error('missing_credentials', 'Shopify認証情報が未設定です', ['status' => 400]);
	}
	//現在のコールバックURL
	$params = $request->get_json_params();
	$current_callback_url = sanitize_text_field($params['callbackUrl'] ?? '');

	$query = '{
      webhookSubscriptions(first: 20) {
        edges {
          node {
            id
            topic
            endpoint {
              __typename
              ... on WebhookHttpEndpoint {
                callbackUrl
              }
            }
          }
        }
      }
    }';

	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/graphql.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type'           => 'application/json',
		],
		'body' => json_encode(['query' => $query]),
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', 'Shopify APIエラー', $response->get_error_message());
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	$edges = $body['data']['webhookSubscriptions']['edges'] ?? [];

	$valid_webhooks = [];

	foreach ($edges as $edge) {
		$id = $edge['node']['id'];
		$topic = $edge['node']['topic'];
		$callback = $edge['node']['endpoint']['callbackUrl'] ?? '';

		if ($callback === $current_callback_url) {
			// 一致するURL → 残す
			$valid_webhooks[] = [
				'id' => $id,
				'topic' => $topic,
				'callbackUrl' => $callback,
			];
		} else {
			// 一致しないURL → 削除
			$url = "https://{$shop_domain}/admin/api/2025-04/graphql.json";
			$delete_query = 'mutation webhookSubscriptionDelete($id: ID!) {
				webhookSubscriptionDelete(id: $id) {
					userErrors { field message }
					deletedWebhookSubscriptionId
				}
			}';

			$payload = [
				'query'     => $delete_query,
				'variables' => [
					'id' => (string) $id,
				],
			];
			$delete_response = wp_remote_post(
				$url,
				[
					'headers' => [
						'X-Shopify-Access-Token' => $admin_token,
						'Content-Type'           => 'application/json; charset=utf-8',
					],
					'body'        => wp_json_encode($payload),
					'data_format' => 'body',
					'timeout'     => 20,
				]
			);
		}
	}

	return [
		'webhooks' => $valid_webhooks,
	];
}
//Webhookを登録
function itmar_register_shopify_webhook(WP_REST_Request $request)
{
	$params      = $request->get_json_params();
	$topic       = sanitize_text_field($params['topic'] ?? '');
	$callbackUrl = esc_url_raw($params['callbackUrl'] ?? '');

	if (empty($topic) || empty($callbackUrl)) {
		return new WP_Error('invalid_params', __("Required parameters are missing", "itmaroon-ec-relate-blocks"), ['status' => 400]);
	}

	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	// REST API の topic は lowercase / slash区切り → 変換
	$topic_rest = strtolower(str_replace('_', '/', $topic));

	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/webhooks.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type'           => 'application/json',
		],
		'body' => json_encode([
			'webhook' => [
				'topic'   => $topic_rest,
				'address' => $callbackUrl,
				'format'  => 'json',
			]
		]),
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', __("Shopify API Error", "itmaroon-ec-relate-blocks"), $response->get_error_message());
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	if (!empty($body['webhook']['id'])) {
		return [
			'success' => true,
			'id'      => $body['webhook']['id'],
		];
	} else {
		return new WP_Error(
			'create_failed',
			'Webhook create failed',
			$body
		);
	}
}

//Webhookを削除
function itmar_delete_shopify_webhook(WP_REST_Request $request)
{
	$params     = $request->get_json_params();
	$gid        = sanitize_text_field($params['webhook_id'] ?? '');

	if (empty($gid)) {
		return new WP_Error('invalid_params', 'Webhook IDが不足しています', ['status' => 400]);
	}

	// gid://shopify/WebhookSubscription/XXXXXXXXX → 数字だけ抜き出し
	if (preg_match('#WebhookSubscription/(\d+)$#', $gid, $matches)) {
		$webhook_id = (int) $matches[1];
	} else {
		return new WP_Error('invalid_gid', 'Webhook ID形式が正しくありません', ['id' => $gid]);
	}

	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	$response = wp_remote_request("https://{$shop_domain}/admin/api/2025-04/webhooks/{$webhook_id}.json", [
		'method'  => 'DELETE',
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
		],
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', 'Shopify APIエラー', $response->get_error_message());
	}

	if (wp_remote_retrieve_response_code($response) === 200) {
		return [
			'success' => true,
			'deleted_id' => $webhook_id,
		];
	} else {
		return new WP_Error(
			'delete_failed',
			'Webhook削除に失敗しました',
			[
				'status_code' => wp_remote_retrieve_response_code($response),
				'body'        => wp_remote_retrieve_body($response),
			]
		);
	}
}

//Stripeの顧客登録
// function itmar_stripe_create_customer(WP_REST_Request $request)
// {

// 	// POSTデータ受け取り
// 	$params = $request->get_json_params();
// 	$form_data = isset($params['form_data']) ? $params['form_data'] : [];

// 	// 必須項目チェック
// 	if (empty($form_data['email'])) {
// 		return new WP_Error('missing_data', 'Emailが未入力です', array('status' => 400));
// 	}

// 	// Stripe の API キーをセット
// 	$stripeKey = get_option('stripe_key');
// 	\Stripe\Stripe::setApiKey($stripeKey);

// 	try {
// 		// Stripe Customer 作成
// 		$customer = \Stripe\Customer::create([
// 			'email' => sanitize_email($form_data['email']),
// 			'name'  => $form_data['first_name'] . ' ' . $form_data['last_name'],
// 			// 必要なら phone, address なども追加
// 		]);

// 		// 成功時レスポンス
// 		return rest_ensure_response([
// 			'success'     => true,
// 			'customer_id' => $customer->id, // Stripe 側の customer ID
// 			'message'     => 'Stripe customer created successfully.',
// 		]);
// 	} catch (Exception $e) {
// 		// エラー時レスポンス
// 		return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
// 	}
// }
