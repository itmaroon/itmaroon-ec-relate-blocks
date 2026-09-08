import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";

declare const itmar_option: {
	nonce: string;
};

interface OAuthTokenResponse {
	success: boolean;
	authenticated?: boolean;
	redirect_url?: string;
	rest_nonce?: string;
	logout_url?: string;
}

/**
 * Shopify の固定コールバックページで認証完了またはログアウト完了を処理する。
 * リダイレクトを開始した場合、またはコールバックを処理した場合は true を返す。
 */
export async function handleShopifyAuthCallback(): Promise<boolean> {
	const urlParams = new URLSearchParams(window.location.search);

	// 旧バージョンで localStorage に保存していた認証情報を除去する。
	[
		"shopify_client_access_token",
		"shopify_client_id_token",
		"shopify_access_expires_at",
		"shopify_code_verifier",
		"shopify_state",
		"shopify_nonce",
		"shopify_client_id",
		"shopify_user_mail",
		"shopify_shop_id",
		"shopify_redirect_uri",
		"shopify_logout_redirect_to",
	].forEach((key) => localStorage.removeItem(key));

	if (urlParams.get("shopify_logout_completed")) {
		document.cookie =
			"shopify_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
		try {
			const response = await apiFetch<OAuthTokenResponse>({
				path: "/itmar-ec-relate/v1/wp-logout-redirect",
				method: "POST",
				headers: { "X-WP-Nonce": itmar_option.nonce },
			});

			if (response.success && response.logout_url) {
				window.location.href = response.logout_url;
				return true;
			}
			throw new Error("Logout redirect URL was not returned.");
		} catch (error) {
			console.error("Shopify logout callback failed:", error);
			window.alert(
				__(
					"Logout could not be completed. Please try again later.",
					"itmaroon-ec-relate-blocks",
				),
			);
			return true;
		}
	}

	const code = urlParams.get("code");
	const state = urlParams.get("state");
	if (!code || !state) return false;

	try {
		const response = await apiFetch<OAuthTokenResponse>({
			path: "/itmar-ec-relate/v1/customer/token-exchange",
			method: "POST",
			headers: { "X-WP-Nonce": itmar_option.nonce },
			data: { code, state },
		});

		if (!response.success || !response.authenticated) {
			throw new Error("Shopify authentication was not completed.");
		}
		if (response.rest_nonce) itmar_option.nonce = response.rest_nonce;
		window.location.href = response.redirect_url || "/";
		return true;
	} catch (error) {
		console.error("Shopify authentication callback failed:", error);
		window.alert(
			__(
				"Shopify authentication could not be completed. Please log in again.",
				"itmaroon-ec-relate-blocks",
			),
		);
		return true;
	}
}
