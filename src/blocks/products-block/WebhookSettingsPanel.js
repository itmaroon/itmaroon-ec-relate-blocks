import { __ } from "@wordpress/i18n";
import { CheckboxControl, Button, Spinner } from "@wordpress/components";
import { useState, useEffect } from "@wordpress/element";
import { dispatch } from "@wordpress/data";

export default function WebhookSettingsPanel({ callbackUrl }) {
	const [existingWebhooks, setExistingWebhooks] = useState([]);
	const [loading, setLoading] = useState(false);

	const topicOptions = [
		{
			label: __("Customer Create", "itmaroon-ec-relate-blocks"),
			topic: "CUSTOMERS_CREATE",
		},
		{
			label: __("Customer Update", "itmaroon-ec-relate-blocks"),
			topic: "CUSTOMERS_UPDATE",
		},
		{
			label: __("Product Update", "itmaroon-ec-relate-blocks"),
			topic: "PRODUCTS_UPDATE",
		},
		{
			label: __("Stock Update", "itmaroon-ec-relate-blocks"),
			topic: "INVENTORY_LEVELS_UPDATE",
		},
		{
			label: __("Orders Create", "itmaroon-ec-relate-blocks"),
			topic: "ORDERS_CREATE",
		},
	];

	useEffect(() => {
		// マウント時に Webhook一覧取得
		loadExistingWebhooks();
	}, []);

	async function loadExistingWebhooks() {
		setLoading(true);
		const fetched = await fetchShopifyWebhooks(callbackUrl);
		setExistingWebhooks(fetched);
		setLoading(false);
	}

	function isTopicRegistered(topic) {
		return existingWebhooks.some(
			(w) => w.topic === topic && w.callbackUrl === callbackUrl,
		);
	}

	async function handleCheckboxChange(topic, isChecked) {
		setLoading(true);
		if (isChecked) {
			// 登録実行

			const result = await registerShopifyWebhook(callbackUrl, topic);
			if (result.success) {
				await loadExistingWebhooks(); // 更新
			}
		} else {
			// 該当 Webhook ID を探す
			const webhook = existingWebhooks.find(
				(w) => w.topic === topic && w.callbackUrl === callbackUrl,
			);
			if (webhook) {
				const result = await deleteShopifyWebhook(webhook.id);
				if (result.success) {
					await loadExistingWebhooks(); // 更新
				}
			}
		}
		setLoading(false);
	}

	return (
		<>
			{loading ? (
				<Spinner />
			) : (
				topicOptions.map(({ label, topic }) => (
					<CheckboxControl
						key={topic}
						label={label}
						checked={isTopicRegistered(topic)}
						onChange={(isChecked) => handleCheckboxChange(topic, isChecked)}
					/>
				))
			)}
			<Button variant="secondary" onClick={loadExistingWebhooks}>
				{__("Reload", "itmaroon-ec-relate-blocks")}
			</Button>
		</>
	);
}

//WebHooks登録状態の読み取り
async function fetchShopifyWebhooks(callbackUrl) {
	const res = await fetch("/wp-json/itmar-ec-relate/v1/shopify-webhook-list", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-WP-Nonce": itmar_option.nonce,
		},
		body: JSON.stringify({
			callbackUrl: callbackUrl,
		}),
	});

	const json = await res.json();

	if (res.ok && json.webhooks) {
		return json.webhooks;
	} else {
		dispatch("core/notices").createNotice(
			"error",
			__("Webhook list retrieval error", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return [];
	}
}

//Webhookの登録
async function registerShopifyWebhook(callbackUrl, topic) {
	const res = await fetch(
		"/wp-json/itmar-ec-relate/v1/shopify-webhook-register",
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce,
			},
			body: JSON.stringify({
				topic: topic,
				callbackUrl: callbackUrl,
			}),
		},
	);

	const json = await res.json();

	if (res.ok && json.success) {
		dispatch("core/notices").createNotice(
			"success",
			__("Webhook registration successful", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return { success: true, id: json.id };
	} else {
		dispatch("core/notices").createNotice(
			"error",
			__("Webhook registration failed", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);

		return { success: false, errors: json };
	}
}

//Webhookの削除
async function deleteShopifyWebhook(webhook_id) {
	const res = await fetch(
		"/wp-json/itmar-ec-relate/v1/shopify-webhook-delete",
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce,
			},
			body: JSON.stringify({
				webhook_id: webhook_id,
			}),
		},
	);

	const json = await res.json();

	if (res.ok && json.success) {
		dispatch("core/notices").createNotice(
			"success",
			__("Webhook Delete Success", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return { success: true, deleted_id: json.deleted_id };
	} else {
		dispatch("core/notices").createNotice(
			"error",
			__("Webhook Delete failed", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return { success: false, errors: json };
	}
}
