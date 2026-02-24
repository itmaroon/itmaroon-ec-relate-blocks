import apiFetch from "@wordpress/api-fetch";
import {
	sendRegistrationRequest,
	registerPickup,
	subscribe,
	setState,
} from "itmar-block-packages";
import { replaceContent } from "../../replaceContent";

/**
 * OAuth / ログアウト関連の URL パラメータ処理。
 * リダイレクト or リロードが発生した場合は true を返して後続処理を止める。
 */
async function handleOAuthRedirectsIfNeeded() {
	const urlParams = new URLSearchParams(window.location.search);

	// ログアウト後の処理（shopify_logout_completed=1 等）
	const logoutCompleted = urlParams.get("shopify_logout_completed");
	if (logoutCompleted) {
		const redirectTo =
			localStorage.getItem("shopify_logout_redirect_to") || "/";

		// クリーンアップ（元コード踏襲）
		if (redirectTo) {
			localStorage.removeItem("shopify_shop_id");
			localStorage.removeItem("shopify_logout_redirect_to");
			localStorage.removeItem("shopify_client_access_token");
			localStorage.removeItem("shopify_client_id_token");
			document.cookie =
				"shopify_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

			try {
				const response = await sendRegistrationRequest(
					"/wp-json/itmar-ec-relate/v1/wp-logout-redirect",
					{ redirect_url: redirectTo, _wpnonce: itmar_option.nonce },
					"rest",
				);

				if (response.success && response.logout_url) {
					window.location.href = response.logout_url;
					return true; // 以降の初期化を止める
				} else {
					console.error("ログアウトURLの取得に失敗しました");
					// 失敗時はそのまま続行（元コードも同様に致命停止していない）
				}
			} catch (error) {
				console.error("ログアウト処理でエラー:", error);
				// 失敗時はそのまま続行
			}
		}
	}

	// ログイン（code/state がある中継ページ）
	const code = urlParams.get("code");
	const state = urlParams.get("state");

	if (!code || !state) {
		// 中継ページではない
		return false;
	}

	// LocalStorage に保存していた値を取り出す（元コード踏襲）
	const shopId = localStorage.getItem("shopify_shop_id");
	const clientId = localStorage.getItem("shopify_client_id");
	const userMail = localStorage.getItem("shopify_user_mail");
	const redirectUri = localStorage.getItem("shopify_redirect_uri");
	const savedState = localStorage.getItem("shopify_state");
	const codeVerifier = localStorage.getItem("shopify_code_verifier");

	// state 検証
	if (state !== savedState || !codeVerifier) {
		console.error("認証コードまたはステートが無効です。");
		return true; // このページでは描画処理を走らせない（元コードは return していた）
	}

	try {
		const tokenChangeUrl =
			"/wp-json/itmar-ec-relate/v1/customer/token-exchange";

		const postData = {
			code,
			code_verifier: codeVerifier,
			redirect_uri: redirectUri,
			shop_id: shopId,
			client_id: clientId,
			user_mail: userMail,
			nonce: itmar_option.nonce,
		};

		const token_res = await sendRegistrationRequest(
			tokenChangeUrl,
			postData,
			"rest",
		);

		if (token_res.success) {
			localStorage.setItem(
				"shopify_client_access_token",
				token_res.token.access_token,
			);
			localStorage.setItem("shopify_client_id_token", token_res.token.id_token);
			localStorage.setItem(
				"shopify_access_expires_at",
				Math.floor(token_res.expires_at / 1000),
			);
		} else {
			alert("トークンの取得に失敗しました。");
			return true; // このページでは描画しない
		}

		// リダイレクト先復元（元コード踏襲）
		const decodedState = JSON.parse(atob(state));
		const redirectTo = decodedState.return_url || "/";

		if (redirectTo) {
			localStorage.removeItem("shopify_code_verifier");
			localStorage.removeItem("shopify_state");
			localStorage.removeItem("shopify_nonce");

			window.location.href = redirectTo;
			return true; // 以降止める
		} else {
			console.log("リダイレクト先が指定されていません。");
			return true;
		}
	} catch (error) {
		console.error("エラーが発生しました:", error);
		return true; // 以降止める（元コードはここで終わっていた）
	}
}

/**
 * products-block のレンダリング初期化（元コードの jQuery ready 部分）
 * ※挙動を変えずに関数化
 */
function initProductsBlock($) {
	// WordPressのログインユーザーとShopifyのログインユーザーの取得
	if (!itmar_option.isLoggedIn) {
		localStorage.removeItem("shopify_client_access_token");
	}

	const shopId = localStorage.getItem("shopify_shop_id");
	const clientId = localStorage.getItem("shopify_client_id");
	const accessToken = localStorage.getItem("shopify_client_access_token");
	console.log("customer token: ", accessToken);

	const main_block = $(".wp-block-itmar-product-block");
	if (main_block.length < 1) return;

	const ctx = registerPickup(main_block[0]);

	// ひな型の要素をスケルトンスクリーンでラップ（元コード踏襲）
	main_block
		.find(
			".unit_hide .wp-block-itmar-design-title,.wp-block-itmar-design-button,.wp-block-itmar-design-text-ctrl,.itmar_ex_block",
		)
		.each(function () {
			$(this).wrap('<div class="hide-wrapper"></div>');
			$(this).css("visibility", "hidden");
		});

	let wp_user_id = "";
	let wp_user_email = "";
	let bind_cart_id = "";

	(async () => {
		try {
			if (shopId && accessToken) {
				const targetUrl = window.itmar_option?.ajaxUrl || window.ajaxurl;
				if (!targetUrl) {
					throw new Error("Ajax URL is missing");
				}
				const postData = {
					action: "validate-customer",
					shop_id: shopId,
					client_id: clientId,
					customerAccessToken: accessToken,
					_wpnonce: itmar_option.nonce,
				};

				const res = await sendRegistrationRequest(targetUrl, postData, "ajax");

				// Shopifyログインによるユーザー登録成功 → reload（元コード踏襲）
				if (res.success && res.data?.reload) {
					window.location.reload();
					return; // ✅ 以降走らせない（ムダな描画を避ける）
				}

				if (res.success) {
					wp_user_email = res.data.wp_user_mail;

					const shopify_customer_email = res.data.valid
						? res.data.customer?.emailAddress?.emailAddress
						: "";

					// リフレッシュされた access_token が返れば入れ替え（元コード踏襲）
					if (res.data.access_token) {
						localStorage.setItem(
							"shopify_client_access_token",
							res.data.access_token,
						);
					}

					if (wp_user_email === shopify_customer_email) {
						wp_user_id = res.data.wp_user_id;
						bind_cart_id = res.data.cart_id;
						console.log(wp_user_id);
					}
				}
			}

			//取得するフィールド
			const selected_fields = main_block.data("selected_fields"); // [{ key, label, block }]
			if (!selected_fields) return;
			const field_keys = selected_fields.map((f) => f.key);

			//取得する商品数
			const itemNum = main_block.data("number_of_items");

			// ✅ state 変更を購読して  を実行
			// 3) 購読（fetch条件が変わった時だけ実行）
			let prevKey = null;
			let productData = [];
			subscribe(ctx.id, async (ctxNow) => {
				// テンプレ以外をクリア・テンプレ（待ち状態）表示
				main_block.children().not(".template_unit").remove();
				main_block.find(".unit_hide").show();

				//共有情報（コンテキスト）の取得
				const s = ctxNow.state;
				const pageNum = s.page ?? 0;
				const searchKeyWord = s.searchKeyWord;
				const selectedCategoryIds = s.termQueryObj.map(
					(tqObj) => tqObj.term.id,
				);
				const cursorByPage = s.cursorByPage || { 0: null };

				// targetPage 以下で一番近い anchorPage を探す
				const candidatePages = Object.keys(cursorByPage)
					.map((n) => parseInt(n, 10))
					.filter((n) => Number.isFinite(n) && n <= pageNum);

				const anchorPage = candidatePages.length
					? Math.max(...candidatePages)
					: 0;
				const anchorCursor = cursorByPage[anchorPage] ?? null;

				// ✅ fetch条件キー（productsやtotalは入れない）
				const key = JSON.stringify({
					pageNum,
					itemNum,
					anchorPage,
					anchorCursor,
					fields: field_keys,
					searchKeyWord: searchKeyWord,
					categoryIds: selectedCategoryIds,
				});
				//キーに変更がなければ終了（無限ループ防止に不可欠）
				if (key === prevKey) return;
				prevKey = key;

				//登録されている商品の情報
				productData = await apiFetch({
					path: "/itmar-ec-relate/v1/get-product",
					method: "POST",
					data: {
						fields: field_keys,
						itemNum: itemNum,
						page: pageNum,
						anchorPage, // ★追加
						anchorCursor, // ★追加（今は計算値）
						searchKeyWord: searchKeyWord,
						categoryIds: selectedCategoryIds,
						includeCount: true,
					},
				});

				// ✅ 次ページ先頭カーソルをキャッシュ（戻る/任意ジャンプを速くする）
				const endCursor = productData?.pageInfo?.endCursor ?? null;

				if (endCursor) {
					const next = { ...cursorByPage, [pageNum + 1]: endCursor };
					setState(ctxNow.id, {
						total: productData.count.count,
						cursorByPage: next,
					});
				}

				//商品情報の表示（元コード踏襲）
				replaceContent(productData.products, main_block);
				//ひな型部分は非表示（元コード踏襲）
				main_block.find(".unit_hide").hide();
			});
		} catch (err) {
			alert("顧客関連通信エラーが発生しました。");
			console.error(err.message);
		}
	})();
}

/**
 * ✅ 入口はここだけ（init一本化）
 * - OAuth/ログアウトが必要なページはそこで止める
 * - 通常ページは products-block 初期化へ
 */
(async function bootstrap() {
	// ここで（必要なら）リダイレクト/リロードして終了
	const redirectedOrHandled = await handleOAuthRedirectsIfNeeded();
	if (redirectedOrHandled) return;

	// jQuery前提の描画処理は ready を1回だけ待つ
	const $ = window.jQuery;
	if ($) {
		$(function () {
			initProductsBlock($);
		});
	} else {
		// もし万一jQueryが無いなら、何もしない（現状はjQuery前提）
		console.warn(
			"[itmar] jQuery is missing. products-block cannot initialize.",
		);
	}
})();
