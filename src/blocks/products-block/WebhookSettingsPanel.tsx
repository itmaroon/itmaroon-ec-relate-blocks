import { __ } from "@wordpress/i18n";
import { CheckboxControl, Button, Spinner } from "@wordpress/components";
import { useState, useEffect } from "@wordpress/element";
import { dispatch } from "@wordpress/data";
import type { ShopifyWebhook } from "./types";

interface WebhookSettingsPanelProps {
	callbackUrl: string;
}

interface WebhookResult {
	success: boolean;
	id?: string;
	deleted_id?: string;
	errors?: unknown;
}

interface WebhookListResponse {
	webhooks?: ShopifyWebhook[];
}

interface WebhookMutationResponse {
	success?: boolean;
	id?: string;
	deleted_id?: string;
}

export default function WebhookSettingsPanel({
	callbackUrl,
}: WebhookSettingsPanelProps) {
	const [existingWebhooks, setExistingWebhooks] = useState<ShopifyWebhook[]>([]);
	const [loading, setLoading] = useState(false);

	const topicOptions = [
		{ label: __("Customer Create", "itmaroon-ec-relate-blocks"), topic: "CUSTOMERS_CREATE" },
		{ label: __("Customer Update", "itmaroon-ec-relate-blocks"), topic: "CUSTOMERS_UPDATE" },
		{ label: __("Product Update", "itmaroon-ec-relate-blocks"), topic: "PRODUCTS_UPDATE" },
		{ label: __("Stock Update", "itmaroon-ec-relate-blocks"), topic: "INVENTORY_LEVELS_UPDATE" },
		{ label: __("Orders Create", "itmaroon-ec-relate-blocks"), topic: "ORDERS_CREATE" },
	];

	useEffect(() => {
		void loadExistingWebhooks();
	}, []);

	async function loadExistingWebhooks(): Promise<void> {
		setLoading(true);
		const fetched = await fetchShopifyWebhooks(callbackUrl);
		setExistingWebhooks(fetched);
		setLoading(false);
	}

	function isTopicRegistered(topic: string): boolean {
		return existingWebhooks.some(
			(webhook) =>
				webhook.topic === topic && webhook.callbackUrl === callbackUrl,
		);
	}

	async function handleCheckboxChange(
		topic: string,
		isChecked: boolean,
	): Promise<void> {
		setLoading(true);
		if (isChecked) {
			const result = await registerShopifyWebhook(callbackUrl, topic);
			if (result.success) await loadExistingWebhooks();
		} else {
			const webhook = existingWebhooks.find(
				(item) =>
					item.topic === topic && item.callbackUrl === callbackUrl,
			);
			if (webhook) {
				const result = await deleteShopifyWebhook(webhook.id);
				if (result.success) await loadExistingWebhooks();
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
						onChange={(isChecked: boolean) =>
							void handleCheckboxChange(topic, isChecked)
						}
					/>
				))
			)}
			<Button variant="secondary" onClick={() => void loadExistingWebhooks()}>
				{__("Reload", "itmaroon-ec-relate-blocks")}
			</Button>
		</>
	);
}

async function fetchShopifyWebhooks(
	callbackUrl: string,
): Promise<ShopifyWebhook[]> {
	const res = await fetch("/wp-json/itmar-ec-relate/v1/shopify-webhook-list", {
		method: "POST",
		headers: {
			"Content-Type": "application/json",
			"X-WP-Nonce": itmar_option.nonce,
		},
		body: JSON.stringify({ callbackUrl }),
	});
	const json = (await res.json()) as WebhookListResponse;

	if (res.ok && Array.isArray(json.webhooks)) return json.webhooks;

	dispatch("core/notices").createNotice(
		"error",
		__("Webhook list retrieval error", "itmaroon-ec-relate-blocks"),
		{ type: "snackbar", isDismissible: true },
	);
	return [];
}

async function registerShopifyWebhook(
	callbackUrl: string,
	topic: string,
): Promise<WebhookResult> {
	const res = await fetch(
		"/wp-json/itmar-ec-relate/v1/shopify-webhook-register",
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce,
			},
			body: JSON.stringify({ topic, callbackUrl }),
		},
	);
	const json = (await res.json()) as WebhookMutationResponse;

	if (res.ok && json.success) {
		dispatch("core/notices").createNotice(
			"success",
			__("Webhook registration successful", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return { success: true, id: json.id };
	}

	dispatch("core/notices").createNotice(
		"error",
		__("Webhook registration failed", "itmaroon-ec-relate-blocks"),
		{ type: "snackbar", isDismissible: true },
	);
	return { success: false, errors: json };
}

async function deleteShopifyWebhook(
	webhookId: string,
): Promise<WebhookResult> {
	const res = await fetch(
		"/wp-json/itmar-ec-relate/v1/shopify-webhook-delete",
		{
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce,
			},
			body: JSON.stringify({ webhook_id: webhookId }),
		},
	);
	const json = (await res.json()) as WebhookMutationResponse;

	if (res.ok && json.success) {
		dispatch("core/notices").createNotice(
			"success",
			__("Webhook Delete Success", "itmaroon-ec-relate-blocks"),
			{ type: "snackbar", isDismissible: true },
		);
		return { success: true, deleted_id: json.deleted_id };
	}

	dispatch("core/notices").createNotice(
		"error",
		__("Webhook Delete failed", "itmaroon-ec-relate-blocks"),
		{ type: "snackbar", isDismissible: true },
	);
	return { success: false, errors: json };
}
