import apiFetch from "@wordpress/api-fetch";
import { registerPickup, subscribe, setState } from "itmar-block-packages/front";
import { replaceContent } from "../../replaceContent";
import type { ProductData } from "../../types";

interface SelectedField {
	key: string;
}

interface ProductResponse {
	products: ProductData[];
	count: { count: number };
	pageInfo?: { endCursor?: string | null };
}

function errorMessage(error: unknown): string {
	return error instanceof Error ? error.message : String(error);
}

function errorStatus(error: unknown): number | undefined {
	if (typeof error !== "object" || error === null || !("data" in error)) {
		return undefined;
	}
	const data = (error as { data?: unknown }).data;
	if (typeof data !== "object" || data === null || !("status" in data)) {
		return undefined;
	}
	return typeof (data as { status?: unknown }).status === "number"
		? (data as { status: number }).status
		: undefined;
}

function showPageMessage(message: string, isError = false): void {
	let region = document.querySelector<HTMLElement>(".itmar-shopify-message");
	if (!region) {
		region = document.createElement("p");
		region.className = "itmar-shopify-message";
		region.tabIndex = -1;
		region.setAttribute("aria-live", "assertive");
		region.style.padding = "0.75rem 1rem";
		region.style.margin = "1rem";
		region.style.border = "1px solid currentColor";
		(document.body || document.documentElement).prepend(region);
	}
	region.setAttribute("role", isError ? "alert" : "status");
	region.textContent = message;
	if (isError) region.focus();
}

/**
 * products-block のレンダリング初期化（元コードの jQuery ready 部分）
 * ※挙動を変えずに関数化
 */
function initProductsBlock($: any): void {
	const main_block = $(".wp-block-itmar-product-block");
	if (main_block.length < 1) return;

	const ctx = registerPickup(main_block[0]);

	// ひな型の要素をスケルトンスクリーンでラップ（元コード踏襲）
	main_block
		.find(
			".unit_hide .wp-block-itmar-design-title,.wp-block-itmar-design-button,.wp-block-itmar-design-text-ctrl,.itmar_ex_block",
		)
		.each(function (this: HTMLElement) {
			$(this).wrap('<div class="hide-wrapper"></div>');
			$(this).css("visibility", "hidden");
		});

	(async () => {
		try {
			//取得するフィールド
			const selected_fields = main_block.data(
				"selected_fields",
			) as SelectedField[] | undefined; // [{ key, label, block }]
			if (!Array.isArray(selected_fields)) return;
			const field_keys = selected_fields.map((f) => f.key);

			//取得する商品数
			const itemNum = main_block.data("number_of_items");

			// ✅ state 変更を購読して  を実行
			// 3) 購読（fetch条件が変わった時だけ実行）
			let prevKey: string | null = null;
			let productData: ProductResponse;
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
				const updatedFrom = s.periodQueryObj?.after || null;
				const updatedTo = s.periodQueryObj?.before || null;
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
					updatedFrom: updatedFrom,
					updatedTo: updatedTo,
					categoryIds: selectedCategoryIds,
				});

				//キーに変更がなければ終了（無限ループ防止に不可欠）
				if (key === prevKey) return;
				prevKey = key;
				try {
					//登録されている商品の情報
					productData = await apiFetch<ProductResponse>({
						path: "/itmar-ec-relate/v1/get-product",
						method: "POST",
						data: {
							fields: field_keys,
							itemNum: itemNum,
							page: pageNum,
							anchorPage, // ★追加
							anchorCursor, // ★追加（今は計算値）
							searchKeyWord: searchKeyWord,
							updatedFrom: updatedFrom,
							updatedTo: updatedTo,
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
					} else {
						setState(ctxNow.id, {
							total: 0,
						});
					}

					//商品情報の表示（元コード踏襲）
					replaceContent(productData.products, main_block);

					//ひな型部分は非表示（元コード踏襲）
					main_block.find(".unit_hide").hide();
				} catch (err) {
					const status = errorStatus(err);
					if (status === 401 || status === 403) {
						console.error(errorMessage(err));
						showPageMessage("商品データが取得できませんでした。", true);
						return;
					}
				}
			});
		} catch (err) {
			showPageMessage("顧客情報を確認できませんでした。時間をおいて再度お試しください。", true);
			console.error(errorMessage(err));
		}
	})();
}

/**
 * products-block の初期化。
 * Shopify の認証コールバックは専用エントリーで処理する。
 */
(function bootstrap() {
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
