import { __ } from "@wordpress/i18n";
import { sendRegistrationRequest } from "itmar-block-packages";
import { textEmbed, getCookie } from "../../front-common";
import { replaceContent } from "../../replaceContent";
import {
	cartLinesRequest,
	normalizeCartContents,
} from "../../cartAction";
import type {
	CartActionResponse,
	CartContext,
	EstimatedCost,
} from "./types";
import type { ProductData } from "../../types";

const $ = window.jQuery;

interface CartUiParams {
	cart_icon_id: string | null;
	wp_user_id: string;
	rawCartId: string;
	itemCount?: number;
	estimatedCost?: EstimatedCost | null;
	checkoutUrl?: string;
	cartContents: ProductData[];
}

interface RefreshCartParams {
	rawCartId: string;
	wp_user_id: string;
	cart_icon_id: string | null;
}

interface CustomerValidationResponse {
	success?: boolean;
	data?: {
		authenticated?: boolean;
		wp_user_id?: string;
		cart_id?: string;
		reload?: boolean;
	};
}

function errorMessage(error: unknown): string {
	return error instanceof Error ? error.message : String(error);
}

function loginRequiredMessage(): string {
	return __(
		"To proceed with your purchase, you must log in through this page. Even if you are already logged in, please log out first and then log in again through this page.",
		"itmaroon-ec-relate-blocks",
	);
}

function getStatusRegion($scope: any): any {
	let $region = $scope.find(".itmar-cart-status").first();
	if (!$region.length) {
		$region = $("<div>", {
			class: "itmar-cart-status",
			role: "status",
			"aria-live": "polite",
			"aria-atomic": "true",
			tabindex: "-1",
		}).css({
			position: "absolute",
			width: "1px",
			height: "1px",
			padding: 0,
			margin: "-1px",
			overflow: "hidden",
			clip: "rect(0, 0, 0, 0)",
			whiteSpace: "nowrap",
			border: 0,
		});
		$scope.prepend($region);
	}
	return $region;
}

function announce($scope: any, message: string, isError = false): void {
	const $region = getStatusRegion($scope);
	$region.attr("role", isError ? "alert" : "status").text("");
	window.setTimeout(() => {
		$region.text(message);
		if (isError) $region.trigger("focus");
	}, 0);
}

// カートのアニメーションを制御するためのクラスを操作する関数
function cartAnimeClass($target_cart: any, addClass: string): void {
	$target_cart.find(".spinner, .particles").each(function (this: HTMLElement) {
		const $el = $(this);
		const classes = ($el.attr("class") || "").split(/\s+/);
		const keep = classes.filter((c) => c === "spinner" || c === "particles");
		$el.attr("class", `${keep.join(" ")} ${addClass}`.trim());
	});
}

// アニメーションの終了を捕捉する関数
function catchEndedAnime(
	$target_cart: any,
	anime_name: string,
	add_class: string,
): void {
	$target_cart.find(".particles").each(function (this: HTMLElement) {
		const $el = $(this);

		$el.one(
			"animationend webkitAnimationEnd oAnimationEnd MSAnimationEnd",
			function (ev: any) {
				const name = ev.originalEvent
					? ev.originalEvent.animationName
					: ev.animationName;
				if (name && name !== anime_name) return;
				cartAnimeClass($target_cart, add_class);
			},
		);
	});
}

function getCartBlocks(): HTMLElement[] {
	return Array.from(
		document.querySelectorAll<HTMLElement>(".wp-block-itmar-cart-block"),
	);
}

/**
 * cartIcon → modal → cartBlock を辿って UI 更新
 */
function updateCartUi({
	cart_icon_id,
	wp_user_id,
	rawCartId,
	itemCount,
	estimatedCost,
	checkoutUrl,
	cartContents,
}: CartUiParams): void {
	if (!$) return;

	const $cart_icon = $(
		`.wp-block-itmar-design-title[data-unique_id="${cart_icon_id}"]`,
	);
	if ($cart_icon.length === 0) return;

	// アイコン数
	textEmbed(itemCount ?? 0, $cart_icon);

	const modal_cart_id = $cart_icon.find(".modal_open_btn").data("modal_id");
	const $modal = $(`#${modal_cart_id}`);
	const $cart_block = $modal.find(".wp-block-itmar-cart-block");

	//カートの空表示の表示切替
	const $emptyUnit = $modal.find("#empty_unit").closest(".itmar-wrap");

	if (cartContents && cartContents.length > 0) {
		$emptyUnit?.hide();
		$cart_block.show();
	} else {
		$emptyUnit?.show();
		$cart_block.hide();
	}

	// 合計表示。空カートではエディタ上のプレースホルダー値を必ず0へ戻す。
	const $subTotal = $modal.find('div[data-unique_id="subtotalAmount"]');
	const $taxTotal = $modal.find('div[data-unique_id="totalTaxAmount"]');
	const $total = $modal.find('div[data-unique_id="totalAmount"]');
	const hasItems = Boolean(cartContents && cartContents.length > 0);

	textEmbed(
		hasItems ? (estimatedCost?.subtotalAmount?.amount ?? 0) : 0,
		$subTotal,
	);
	textEmbed(
		hasItems ? (estimatedCost?.totalTaxAmount?.amount ?? 0) : 0,
		$taxTotal,
	);
	textEmbed(hasItems ? (estimatedCost?.totalAmount?.amount ?? 0) : 0, $total);

	// cartId が無いならここまで
	if (!rawCartId) return;

	// template 非表示
	$cart_block.find(".unit_hide").hide();
	// 中身描画（replaceContent）
	replaceContent(cartContents, $cart_block);

	// checkout URLはクリック直前に取得する。古いURLをDOMへ保持しない。
	$modal
		.find('button[data-key="go_checkout"]')
		.attr("data-selected_page", "");

}

/**
 * cart/lines (bind_cart) を叩いて現在の cart 状態を取得し、UI反映
 */
async function refreshCart({
	rawCartId,
	wp_user_id,
	cart_icon_id,
}: RefreshCartParams): Promise<void> {
	// cartId が無いなら “空カート” 表示だけ
	if (!rawCartId) {
		updateCartUi({
			cart_icon_id,
			wp_user_id,
			rawCartId: "",
			itemCount: 0,
			estimatedCost: null,
			checkoutUrl: "",
			cartContents: [],
		});
		return;
	}

	const cartId = decodeURIComponent(rawCartId);

	// まず Shopify 側の cart を取得（lines）
	const res = (await cartLinesRequest({
		cartId,
		mode: "bind_cart",
		nonce: itmar_option.nonce,
	})) as CartActionResponse;

	if (res?.success) {
		const mergedItems = normalizeCartContents(res.cartContents);

		updateCartUi({
			cart_icon_id,
			wp_user_id,
			rawCartId,
			itemCount: res.itemCount,
			estimatedCost: res.estimatedCost,
			checkoutUrl: res.checkoutUrl,
			cartContents: mergedItems,
		});

	} else {
		console.warn("[itmar cart] no cart info");
	}
}
/**
 * cart 操作（into_cart / trush_out / calc_again / soon_buy）
 * ✅ products-block からの submit もここで拾う（＝完全分離）
 */

async function handleCartAction(
	submitter: HTMLElement,
	$form: any,
	ctx: CartContext,
): Promise<void> {
	const $button = $(submitter);
	const key = String($button.data("key") ?? "");
	const checkoutKeys = ["soon_buy", "go_shopify", "go_checkout"];
	const $statusScope = $button.closest("form").length
		? $button.closest("form")
		: $(ctx.cartRoot);
	if (
		["into_cart", "soon_buy", "go_shopify", "go_checkout"].includes(key) &&
		(!window.itmar_option?.isLoggedIn || !ctx.shopify_authenticated)
	) {
		window.alert(loginRequiredMessage());
		return;
	}
	$button.prop("disabled", true).attr("aria-busy", "true");
	announce(
		$statusScope,
		checkoutKeys.includes(key)
			? __("Preparing checkout.", "itmaroon-ec-relate-blocks")
			: __("Updating the cart.", "itmaroon-ec-relate-blocks"),
	);

	// カートアイコンDOMを取得
	const $target_cart = ctx?.cart_icon_id
		? $(`[data-unique_id="${ctx.cart_icon_id}"]`)
		: $();

	// アニメ終了捕捉（元コード踏襲）
	if ($target_cart.length) {
		catchEndedAnime($target_cart, "burst", "hold");
	}

	// soon_buy は cartId をクリア（元コード踏襲）
	let cartId = ctx.rawCartId ? decodeURIComponent(ctx.rawCartId) : "";
	if (key === "soon_buy") {
		cartId = "";
	} else {
		// spinner / particle を exec（元コード踏襲）
		if ($target_cart.length && key !== "go_shopify") {
			cartAnimeClass($target_cart, "exec");
		}
	}

	// フォーム内のインプット（元コード踏襲）
	const formDataObj = $form
		.find('[class*="unit_design_"]')
		.filter(function (this: HTMLElement) {
			return $(this).closest(".template_unit").length === 0;
		})
		.map(function (this: HTMLElement) {
			const $el = $(this);
			const id = $el.find('button[data-key="trush_out"]').data("line-id");
			const quantity =
				parseInt($el.find(".sp_field_quantity input").val(), 10) || 0;
			return { id, quantity };
		})
		.get();

	// lineId / variantId / quantity の取得（products/cart 両方から拾えるように）
	const lineId = $button.data("lineId") || $button.data("line-id") || "";
	const variantId =
		$button.data("variantId") || $button.data("variant-id") || "";
	let quantity = Number($button.data("quantity")) || 1;

	// unit 内に sp_field_quantity input がある場合はそっち優先
	const $qtyInput = $button
		.closest('[class*="unit_design_"]')
		.find('.sp_field_quantity input, input[name="quantity"]');
	if ($qtyInput.length) {
		const v = parseInt($qtyInput.val(), 10);
		if (!Number.isNaN(v)) quantity = v;
	}

	const postData = {
		form_data: JSON.stringify(formDataObj),
		lineId: lineId,
		cartId: cartId,
		productId: variantId,
		quantity: quantity,
		mode: key,
		nonce: itmar_option.nonce,
	};

	try {
		// REST API（あなたのラッパーを使うなら cartLinesRequest でOK）
		const res = (await cartLinesRequest(postData)) as CartActionResponse;

		if (checkoutKeys.includes(key)) {
			if (res?.checkoutUrl) {
				announce(
					$statusScope,
					__(
						"Redirecting to the secure checkout page.",
						"itmaroon-ec-relate-blocks",
					),
				);
				window.location.assign(res.checkoutUrl);
			} else {
				announce($statusScope, "チェックアウトURLの取得に失敗しました。", true);
				console.error("Unexpected response:", res);
			}
			return;
		}

		if (key === "into_cart" || key === "trush_out" || key === "calc_again") {
			if (res?.success) {
				// ✅ 元の挙動：ログイン状態で buyer 未設定なら bind
				// if (ctx.wp_user_id && res.cartId && !res.buyerId) {
				// 	// ここは「そのまま呼ぶ」前提
				// 	await cart_bind(res.cartId, ctx.cart_icon_id, ctx.wp_user_id);
				// }

				// cartId を保持（重要）
				ctx.rawCartId = res.cartId || ctx.rawCartId;

				//データの整形
				const mergedItems = normalizeCartContents(res.cartContents);
				// ✅ ここが updateCartInfo 相当（依存切り）
				updateCartUi({
					cart_icon_id: ctx.cart_icon_id,
					wp_user_id: ctx.wp_user_id,
					rawCartId: ctx.rawCartId,
					itemCount: res.itemCount,
					estimatedCost: res.estimatedCost,
					checkoutUrl: res.checkoutUrl,
					cartContents: mergedItems,
				});
			} else {
				announce(
					$statusScope,
					"カートの処理に失敗しました。カートを処理するにはログインが必要です。",
					true,
				);
			}
		}
	} catch (err) {
		const msg = errorMessage(err);
		if (msg.startsWith("HTTP 401")) {
			window.alert(loginRequiredMessage());
			return;
		}
		if (msg.startsWith("HTTP 422")) {
			const detail = msg.replace(/^HTTP 422:\s*/, "");
			announce(
				$statusScope,
				detail || "Shopifyでカートを処理できませんでした。",
				true,
			);
			return;
		}
		announce($statusScope, "サーバー通信エラーが発生しました。時間をおいて再度お試しください。", true);
		console.error("サーバー通信エラー:", err);
	} finally {
		// ✅finallyでアニメーションを終了させる方が安全
		if (key !== "soon_buy" && $target_cart.length) {
			cartAnimeClass($target_cart, "done");
		}
		$button.prop("disabled", false).removeAttr("aria-busy");
	}
}

async function validateCustomerIfPossible(): Promise<CustomerValidationResponse | null> {
	// WPログインしてないなら validate しない（あなたの前提踏襲）
	if (!window.itmar_option?.isLoggedIn) return null;
	const targetUrl = window.itmar_option?.ajaxUrl ?? window.ajaxurl;

	if (!targetUrl) return null;

	const postData = {
		action: "itmar_validate_customer",
		shop_id: String($(".wp-block-itmar-product-block").data("shop_id") || ""),
		client_id: String(
			$(".wp-block-itmar-product-block").data("headless_id") || "",
		),
		_wpnonce: window.itmar_option?.nonce,
	};

	const res = (await sendRegistrationRequest(
		targetUrl,
		postData,
		"ajax",
	)) as CustomerValidationResponse;

	return res || null;
}

/**
 * cart-block を “自走” させる初期化：
 * - products-block が無いページでも cart-block 単体で動くため
 */
async function initCartContext(
	cartRoot: HTMLElement,
): Promise<CartContext | null> {
	const $root = $(cartRoot);
	// cart-block の data 属性から取得
	const cartIconValue = $root.data("cart_icon_id");
	const modalCartValue = $root.data("cart_id");
	const cart_icon_id =
		typeof cartIconValue === "string" && cartIconValue ? cartIconValue : null;
	const modalCartId =
		typeof modalCartValue === "string" && modalCartValue
			? modalCartValue
			: null; // モーダルID（#xxx）

	let wp_user_id = "";
	let bind_cart_id = "";
	let shopify_authenticated = false;

	// Shopifyセッションはサーバー側で検証・更新する。
	if (itmar_option.isLoggedIn) {
		try {
			const res = await validateCustomerIfPossible();

			if (res?.success) {
				shopify_authenticated = res.data?.authenticated === true;
				// WP側ユーザー情報
				if (res.data?.wp_user_id) wp_user_id = res.data.wp_user_id;

				// ✅ accessToken / customer に紐づいたカートID（これが最優先）
				if (res.data?.cart_id) bind_cart_id = res.data.cart_id;

				// 本登録などで reload 指示があるなら従う（既存踏襲）
				if (res.data?.reload) {
					window.location.reload();
					return null; // reloadするので以降不要
				}
			}
		} catch (e) {
		// 検証に失敗しても商品閲覧は継続し、購入時に画面内で再認証を案内する。
			console.warn("[cart] validate-customer failed:", e);
		}
	}

	// ✅ 2) rawCartId を確定（bind_cart_id > cookie）
	let rawCartId = "";
	if (bind_cart_id) {
		rawCartId = bind_cart_id;
	} else {
		rawCartId = getCookie("shopify_cart_id") || "";
	}

	// 任意：cart-block 側に将来 data-* を増やす場合のために root も持たせる
	return {
		cartRoot,
		modal_id: modalCartId,
		cart_icon_id,
		rawCartId,
		wp_user_id,
		shopify_authenticated,
	};
}

(function bootstrapCartBlock() {
	if (!$) return;
	[
		"shopify_client_access_token",
		"shopify_client_id_token",
		"shopify_access_expires_at",
	].forEach((key) => localStorage.removeItem(key));
	const cartBlocks = getCartBlocks();
	if (cartBlocks.length === 0) return;
	$('button[data-key="go_checkout"]').attr("data-selected_page", "");

	// cart-block ごとに state を持つ（複数対応）
	const ctxByRoot = new WeakMap<HTMLElement, CartContext>();

	// ✅ cart-block 単体でも動く：自分で初期化して描画
	(async () => {
		for (const root of cartBlocks) {
			const ctx = await initCartContext(root);
			if (!ctx) continue;
			ctxByRoot.set(root, ctx);

			if (ctx.cart_icon_id) await refreshCart(ctx);
		}
	})();

	// ✅ 完全分離のキモ：product-block の submit も cart-block が拾う
	$(document).on("submit", "form", async function (this: HTMLFormElement, e: any) {
		const submitter = e.originalEvent?.submitter as HTMLElement | undefined;

		if (!submitter) return;

		const $btn = $(submitter);
		const key = String($btn.data("key") ?? "");
		if (!key) return;

		// カート操作キーだけ拾う
		const allowed = [
			"into_cart",
			"trush_out",
			"calc_again",
			"soon_buy",
			"go_shopify",
			"go_checkout",
		];
		if (!allowed.includes(key)) return;

		// 自分のプラグイン領域だけ（安全）
		const modal_id = $(".wp-block-itmar-cart-block").data("cart_id");
		const $scope = $btn.closest(`#${modal_id}, .wp-block-itmar-product-block`);
		if ($scope.length === 0) return;

		e.preventDefault();

		// cartRoot は “最初の cart-block” を使う（複数あるならルール決め可能）
		const cartRoot = cartBlocks[0];
		if (!cartRoot) return;
		const ctx = ctxByRoot.get(cartRoot) ?? (await initCartContext(cartRoot));
		if (!ctx) return;
		ctxByRoot.set(cartRoot, ctx);

		await handleCartAction(submitter, $(this), ctx);
	});

	// 既存コンテンツでチェックアウトボタンがform外に置かれていても動作させる。
	$(document).on("click", 'button[data-key="go_checkout"]', async function (
		this: HTMLButtonElement,
		e: any,
	) {
		const $button = $(this);
		if ($button.closest("form").length) return;
		e.preventDefault();
		const cartRoot = cartBlocks[0];
		if (!cartRoot) return;
		const ctx = ctxByRoot.get(cartRoot) ?? (await initCartContext(cartRoot));
		if (!ctx) return;
		ctxByRoot.set(cartRoot, ctx);
		await handleCartAction(this, $button.closest(".wp-block-itmar-design-group"), ctx);
	});
})();
