import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
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
		{ label: __("Customer Update", "itmaroon-ec-relate-blocks"), topic: "CUSTOMERS_UPDATE" },
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
	try {
		const response = await apiFetch<WebhookListResponse>({
			path: "/itmar-ec-relate/v1/shopify-webhook-list",
			method: "POST",
			data: { callbackUrl },
		});

		if (Array.isArray(response.webhooks)) return response.webhooks;
	} catch (error) {
		console.error("Webhook list retrieval error", error);
	}

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
	let errors: unknown = "request_failed";
	try {
		const response = await apiFetch<WebhookMutationResponse>({
			path: "/itmar-ec-relate/v1/shopify-webhook-register",
			method: "POST",
			data: { topic, callbackUrl },
		});

		if (response.success) {
			dispatch("core/notices").createNotice(
				"success",
				__("Webhook registration successful", "itmaroon-ec-relate-blocks"),
				{ type: "snackbar", isDismissible: true },
			);
			return { success: true, id: response.id };
		}
		errors = response;
	} catch (error) {
		errors = error;
		console.error("Webhook registration failed", error);
	}

	dispatch("core/notices").createNotice(
		"error",
		__("Webhook registration failed", "itmaroon-ec-relate-blocks"),
		{ type: "snackbar", isDismissible: true },
	);
	return { success: false, errors };
}

async function deleteShopifyWebhook(
	webhookId: string,
): Promise<WebhookResult> {
	let errors: unknown = "request_failed";
	try {
		const response = await apiFetch<WebhookMutationResponse>({
			path: "/itmar-ec-relate/v1/shopify-webhook-delete",
			method: "POST",
			data: { webhook_id: webhookId },
		});

		if (response.success) {
			dispatch("core/notices").createNotice(
				"success",
				__("Webhook Delete Success", "itmaroon-ec-relate-blocks"),
				{ type: "snackbar", isDismissible: true },
			);
			return { success: true, deleted_id: response.deleted_id };
		}
		errors = response;
	} catch (error) {
		errors = error;
		console.error("Webhook Delete failed", error);
	}

	dispatch("core/notices").createNotice(
		"error",
		__("Webhook Delete failed", "itmaroon-ec-relate-blocks"),
		{ type: "snackbar", isDismissible: true },
	);
	return { success: false, errors };
}
