import { sendRegistrationRequest } from "itmar-block-packages";
import { textEmbed, getCookie } from "../../front-common";
import { replaceContent } from "../../replaceContent";
import {
	cartLinesRequest,
	bindCartToCustomer,
	normalizeCartContents,
} from "../../cartAction";

const $ = window.jQuery;

// カートのアニメーションを制御するためのクラスを操作する関数
function cartAnimeClass($target_cart, addClass) {
	$target_cart.find(".spinner, .particles").each(function () {
		const $el = $(this);
		const classes = ($el.attr("class") || "").split(/\s+/);
		const keep = classes.filter((c) => c === "spinner" || c === "particles");
		$el.attr("class", `${keep.join(" ")} ${addClass}`.trim());
	});
}

// アニメーションの終了を捕捉する関数
function catchEndedAnime($target_cart, anime_name, add_class) {
	$target_cart.find(".particles").each(function () {
		const $el = $(this);
		$el.one(
			"animationend webkitAnimationEnd oAnimationEnd MSAnimationEnd",
			function (ev) {
				const name = ev.originalEvent
					? ev.originalEvent.animationName
					: ev.animationName;
				if (name && name !== anime_name) return;
				cartAnimeClass($target_cart, add_class);
			},
		);
	});
}

function getCartBlocks() {
	return Array.from(document.querySelectorAll(".wp-block-itmar-cart-block"));
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
}) {
	if (!$) return;

	const $cart_icon = $(
		`.wp-block-itmar-design-title[data-unique_id="${cart_icon_id}"]`,
	);
	if ($cart_icon.length === 0) return;

	// アイコン数
	textEmbed(itemCount ?? 0, $cart_icon);

	// cartId が無いならここまで（匿名カート無し等）
	if (!rawCartId) return;

	const modal_cart_id = $cart_icon.find(".modal_open_btn").data("modal_id");
	const $modal = $(`#${modal_cart_id}`);
	const $cart_block = $modal.find(".wp-block-itmar-cart-block");

	// template 非表示
	$cart_block.find(".unit_hide").hide();
	// 中身描画（replaceContent）
	replaceContent(cartContents, $cart_block);

	// checkout url
	$modal
		.find('button[data-key="go_checkout"]')
		.attr("data-selected_page", checkoutUrl || "");

	// 合計表示（あなたの以前の仕様を踏襲）
	const $subTotal = $modal.find('div[data-unique_id="subtotalAmount"]');
	const $taxTotal = $modal.find('div[data-unique_id="totalTaxAmount"]');
	const $total = $modal.find('div[data-unique_id="totalAmount"]');

	if (estimatedCost?.subtotalAmount?.amount != null)
		textEmbed(estimatedCost.subtotalAmount.amount, $subTotal);
	textEmbed(
		estimatedCost?.totalTaxAmount?.amount
			? estimatedCost.totalTaxAmount.amount
			: 0,
		$taxTotal,
	);
	if (estimatedCost?.totalAmount?.amount != null)
		textEmbed(estimatedCost.totalAmount.amount, $total);
}

/**
 * cart/lines (bind_cart) を叩いて現在の cart 状態を取得し、UI反映
 */
async function refreshCart({
	rawCartId,
	wp_user_id,
	accessToken,
	cart_icon_id,
}) {
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
	console.log(cartId);
	const res = await cartLinesRequest({
		cartId,
		wp_user_id,
		mode: "bind_cart",
		nonce: itmar_option.nonce,
	});

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

		// accessToken があり、buyer 未設定なら「昇格」
		if (accessToken && !res.buyerId) {
			try {
				await bindCartToCustomer({ cartId, accessToken });
				// 昇格成功後に cookie を削除（元仕様）
				document.cookie =
					"shopify_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
			} catch (e) {
				console.warn("[itmar cart] bindCartToCustomer failed:", e);
			}
		}
	} else {
		console.warn("[itmar cart] no cart info");
	}
}
/**
 * cart 操作（into_cart / trush_out / calc_again / soon_buy）
 * ✅ products-block からの submit もここで拾う（＝完全分離）
 */

async function handleCartAction(submitter, $form, ctx) {
	const $button = $(submitter);
	const key = $button.data("key");

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
		if ($target_cart.length && key != "go_shopify") {
			cartAnimeClass($target_cart, "exec");
		}
	}

	// フォーム内のインプット（元コード踏襲）
	const formDataObj = $form
		.find('[class*="unit_design_"]')
		.filter(function () {
			return $(this).closest(".template_unit").length === 0;
		})
		.map(function () {
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
		wp_user_id: ctx.wp_user_id || "",
		nonce: itmar_option.nonce,
	};

	try {
		// REST API（あなたのラッパーを使うなら cartLinesRequest でOK）
		const res = await cartLinesRequest(postData);

		if (key === "soon_buy" || key === "go_shopify") {
			if (res?.checkoutUrl) {
				window.open(res.checkoutUrl, "_blank");
			} else {
				alert("チェックアウトURLの取得に失敗しました。");
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
				alert("カートの作成に失敗しました");
			}
		}
	} catch (err) {
		alert("サーバー通信エラーが発生しました。");
		console.error("サーバー通信エラー:", err);
	} finally {
		// ✅finallyでアニメーションを終了させる方が安全
		if (key !== "soon_buy" && $target_cart.length) {
			cartAnimeClass($target_cart, "done");
		}
	}
}

async function validateCustomerIfPossible(accessToken) {
	// WPログインしてないなら validate しない（あなたの前提踏襲）
	if (!window.itmar_option?.isLoggedIn) return null;
	if (!accessToken) return null;

	const shopId = localStorage.getItem("shopify_shop_id");
	const clientId = localStorage.getItem("shopify_client_id");

	if (!shopId || !clientId) return null;

	const targetUrl = window.itmar_option?.ajaxUrl || window.ajaxurl;

	if (!targetUrl) return null;

	const postData = {
		action: "validate-customer",
		shop_id: shopId,
		client_id: clientId,
		customerAccessToken: accessToken,
		_wpnonce: window.itmar_option?.nonce,
	};

	const res = await sendRegistrationRequest(targetUrl, postData, "ajax");
	return res || null;
}

/**
 * cart-block を “自走” させる初期化：
 * - products-block が無いページでも cart-block 単体で動くため
 */
async function initCartContext(cartRoot) {
	const $root = jQuery(cartRoot);
	// cart-block の data 属性から取得
	const cart_icon_id = $root.data("cart_icon_id") || null;
	const modalCartId = $root.data("cart_id") || null; // モーダルID（#xxx）

	// Shopify access token（localStorage）
	let accessToken = localStorage.getItem("shopify_client_access_token") || null;

	// ✅ WPログインしてないなら accessToken は捨てる
	if (!itmar_option.isLoggedIn) {
		localStorage.removeItem("shopify_client_access_token");
	}

	let wp_user_id = "";
	let bind_cart_id = "";

	// ✅ 1) accessToken があれば validate-customer で確認＆更新
	if (accessToken) {
		try {
			const res = await validateCustomerIfPossible(accessToken);
			console.log(res);
			if (res?.success) {
				// リフレッシュトークン等で access_token が更新された場合は入れ替え(サーバーからは更新された場合のみトークンが送信される)
				if (res.data?.access_token) {
					accessToken = res.data.access_token;
					localStorage.setItem("shopify_client_access_token", accessToken);
				}
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
			// validate が落ちても cart-block 自体は匿名カートで動かす
			console.warn("[cart] validate-customer failed:", e);
		}
	}

	// ✅ 2) rawCartId を確定（bind_cart_id > cookie）
	let rawCartId = "";
	if (bind_cart_id) {
		rawCartId = bind_cart_id;
		// cookie も合わせておく（他ページ・他ブロックでも一致）
		localStorage.setItem("shopify_cart_id", rawCartId);
	} else {
		rawCartId = getCookie("shopify_cart_id") || "";
	}

	// 任意：cart-block 側に将来 data-* を増やす場合のために root も持たせる
	return {
		cartRoot,
		cartRoot,
		modal_id: modalCartId,
		cart_icon_id,
		rawCartId,
		wp_user_id,
		accessToken,
	};
}

(function bootstrapCartBlock() {
	if (!$) return;
	const cartBlocks = getCartBlocks();
	if (cartBlocks.length === 0) return;

	// cart-block ごとに state を持つ（複数対応）
	const ctxByRoot = new WeakMap();

	// ✅ cart-block 単体でも動く：自分で初期化して描画
	(async () => {
		for (const root of cartBlocks) {
			const ctx = await initCartContext(root);
			ctxByRoot.set(root, ctx);
			if (ctx.cart_icon_id) await refreshCart(ctx);
		}
	})();

	// ✅ 完全分離のキモ：product-block の submit も cart-block が拾う
	$(document).on("submit", "form", async function (e) {
		const submitter = e.originalEvent?.submitter;

		if (!submitter) return;

		const $btn = $(submitter);
		const key = $btn.data("key");
		if (!key) return;

		// カート操作キーだけ拾う
		const allowed = [
			"into_cart",
			"trush_out",
			"calc_again",
			"soon_buy",
			"go_shopify",
		];
		if (!allowed.includes(key)) return;

		// 自分のプラグイン領域だけ（安全）
		const modal_id = $(".wp-block-itmar-cart-block").data("cart_id");
		const $scope = $btn.closest(`#${modal_id}, .wp-block-itmar-product-block`);
		if ($scope.length === 0) return;

		e.preventDefault();

		// cartRoot は “最初の cart-block” を使う（複数あるならルール決め可能）
		const cartRoot = cartBlocks[0];
		const ctx = ctxByRoot.get(cartRoot) || (await initCartContext(cartRoot));
		ctxByRoot.set(cartRoot, ctx);

		await handleCartAction(submitter, $(this), ctx);
	});
})();
