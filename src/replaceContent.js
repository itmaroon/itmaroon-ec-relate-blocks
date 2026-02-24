import { displayFormated, slideBlockSwiperInit } from "itmar-block-packages";

const $ = window.jQuery;

/**
 * productData の内容に応じて unit_design_* のテンプレートを選ぶ（元ロジック踏襲）
 * ※同ファイル内に置く（あなたの希望通り）
 */
function selectTemplateUnit(target_block, aspectRatio, itmNum) {
	if (!$) return null;
	if (!target_block || !target_block.jquery) return null;

	// unit_design_* を持つ要素を抽出
	const templateUnits = target_block.find("*").filter(function () {
		const cls = $(this).attr("class");
		if (!cls) return false;
		return cls.split(/\s+/).some((c) => c.startsWith("unit_design_"));
	});

	if (!templateUnits || templateUnits.length === 0) return null;

	let retTemplate;
	if (aspectRatio > 1.2 && itmNum % 2 !== 0) retTemplate = templateUnits.eq(0);
	else if (aspectRatio > 1.2 && itmNum % 2 === 0)
		retTemplate = templateUnits.eq(1);
	else if (aspectRatio < 0.8 && itmNum % 2 !== 0)
		retTemplate = templateUnits.eq(2);
	else if (aspectRatio < 0.8 && itmNum % 2 === 0)
		retTemplate = templateUnits.eq(3);
	else retTemplate = templateUnits.eq(0);

	return retTemplate && retTemplate.length ? retTemplate : templateUnits.eq(0);
}

/**
 * sp_field_* 要素に product データを埋め込む
 * - 元の replaceContent の考え方を踏襲
 * - edges は自動展開
 * - variant(variants.edges[0].node) も参照
 */
function resolveFieldValue(product, fieldKey) {
	// 第一層
	let fieldData = product ? product[fieldKey] : undefined;

	// 第一層に無い場合（元ロジック踏襲）
	if (fieldData === undefined) {
		if (fieldKey === "image") {
			fieldData = product?.media?.edges?.[0]?.node;
		} else if (fieldKey === "images") {
			fieldData = product?.media?.edges;
		} else {
			const variantNode = product?.variants?.edges?.[0]?.node;
			if (variantNode && fieldKey in variantNode) {
				fieldData = variantNode[fieldKey];
			}
		}
	}

	if (fieldData === undefined) return undefined;

	// edges[] を自動展開
	const value = Array.isArray(fieldData?.edges)
		? fieldData.edges.map((e) => e.node)
		: fieldData;

	return value;
}

/**
 * ✅ Shopify 商品/カートデータを Gutenberg のひな型へ流し込む（表示専用）
 * ✅ cart 操作はしない（依存を切る）
 *
 * @param {Array} productData
 * @param {string} wp_user_id
 * @param {string} rawCartId
 * @param {string} cart_icon_id
 * @param {jQuery} target_block jQueryオブジェクト
 */
export function replaceContent(productData, target_block) {
	if (!$) {
		console.warn("[replaceContent] jQuery is missing");
		return;
	}
	if (!target_block || !target_block.jquery) {
		console.warn("[replaceContent] target_block must be jQuery object");
		return;
	}

	try {
		// テンプレ以外をクリア（元コード踏襲）
		target_block.children().not(".template_unit").remove();

		// productData の件数でテンプレを複製して流し込み
		for (const [i, product] of (productData || []).entries()) {
			// 先頭メディア取得（元コード踏襲）
			const first_media = product?.media?.edges?.[0]?.node;

			const first_media_info =
				first_media?.mediaContentType === "VIDEO"
					? first_media?.sources?.[0]
					: first_media?.mediaContentType === "IMAGE"
					? first_media?.image
					: null;

			const aspectRatio =
				first_media_info?.width && first_media_info?.height
					? first_media_info.width / first_media_info.height
					: 1;

			// テンプレ選択（元コード踏襲）
			const selectUnit = selectTemplateUnit(target_block, aspectRatio, i + 1);
			if (!selectUnit) continue;

			const $template = selectUnit.parent().clone(true); // イベント付きでコピー（元コード踏襲）

			// hide-wrapper の unwrap（元コード踏襲）
			$template
				.find(
					".hide-wrapper > .wp-block-itmar-design-title,.wp-block-itmar-design-button,.wp-block-itmar-design-text-ctrl,.itmar_ex_block",
				)
				.each(function () {
					$(this).css("visibility", "");
					$(this).unwrap();
				});

			// 追加
			target_block.append($template);
			//数量の初期値をセットする
			const $qty = $template.find('input[name="quantity"]').first();
			if ($qty.length) {
				let defaultQty = 1;
				const current = $qty.val();
				if (current === "" || current == null) {
					$qty.val(defaultQty);
				}
			}

			// ✅ ここが「依存切り」：cart 操作はしない
			// ただし cart-block 側が拾えるように data を付与
			const lineId = product?.lineId || "";
			const variantId = product?.variants?.edges?.[0]?.node?.id || "";
			const quantity = product?.quantity ? product.quantity : 1;

			$template.find('[type="submit"]').attr({
				"data-line-id": lineId,
				"data-variant-id": variantId,
				"data-quantity": quantity,
			});

			// ✅ 以降：sp_field_* に埋め込み（ここが省略されると「流れない」）
			$template.find("[class*='sp_field_']").each(function () {
				const $el = $(this);

				const classes = ($el.attr("class") || "").split(/\s+/);
				const fieldClass = classes.find((cls) => cls.startsWith("sp_field_"));
				if (!fieldClass) return;

				const fieldKey = fieldClass.replace("sp_field_", "");
				const value = resolveFieldValue(product, fieldKey);
				if (value === undefined) return;

				const allClassNames = $el.attr("class") || "";

				// 1) design-title（元コード踏襲）
				if (allClassNames.includes("wp-block-itmar-design-title")) {
					const heading = $el.find("h1,h2,h3,h4,h5,h6").first();
					const targetDiv = heading.find("div").first();
					if (targetDiv.length) {
						const text =
							value == null
								? ""
								: typeof value === "object" &&
								  fieldKey === "price" &&
								  value.amount
								? value.amount
								: typeof value === "object" &&
								  fieldKey === "compareAtPrice" &&
								  value.amount
								? value.amount
								: value;

						const displayText =
							value != null
								? displayFormated(
										text,
										$el.data("user_format"),
										$el.data("free_format"),
										$el.data("decimal"),
								  )
								: "";

						targetDiv.text(displayText);
					}
					return;
				}

				// 2) design-text-ctrl（元コード踏襲）
				if (allClassNames.includes("wp-block-itmar-design-text-ctrl")) {
					const input_text = $el.find("input").first();
					if (input_text.length) {
						// quantity 等もここに入る
						input_text.val(value);
					}
					return;
				}

				// 3) 画像（core/image）（元コード踏襲 + featuredImage対応）
				if (allClassNames.includes("wp-block-image")) {
					const img = $el.find("img").first();
					if (!img.length) return;

					// value が media node の場合
					if (value?.mediaContentType === "IMAGE") {
						img.attr("src", value.image?.url || "");
						img.attr("alt", value.image?.altText || "");
						return;
					}
					if (value?.mediaContentType === "VIDEO") {
						const $video = $("<video>", {
							src: value.sources?.[0]?.url || "",
							controls: true,
							autoplay: false,
							muted: true,
							loop: false,
						});

						$video.attr("class", img.attr("class") || "");
						$video.attr("style", img.attr("style") || "");
						$.each(img.data(), function (key, val) {
							$video.attr("data-" + key, val);
						});
						$video.css({
							width: "100%",
							height: "auto",
							display: "block",
							objectFit: "cover",
						});

						img.replaceWith($video);
						return;
					}

					// cart の featuredImage など “画像情報だけ” が来るケース（元コード踏襲）
					if (fieldKey === "featuredImage") {
						img.attr("src", value?.url || "");
						img.attr("alt", value?.altText || "");
						return;
					}

					return;
				}

				// 4) <p>（元コード踏襲）
				if ($el.is("p")) {
					const text =
						typeof value === "object" && value?.amount != null
							? value.amount
							: value;
					$el.text(text);
					return;
				}

				// 5) slide-mv（元コード踏襲）
				if (allClassNames.includes("wp-block-itmar-slide-mv")) {
					const swiperId = `slide-${i}`;
					const clone_swiper = $el.find(".swiper");
					if (!clone_swiper.length) return;

					clone_swiper.removeData("swiper-id");
					clone_swiper.attr("data-swiper-id", swiperId);

					const classPrefixMap = {
						prev: "swiper-button-prev",
						next: "swiper-button-next",
						pagination: "swiper-pagination",
						scrollbar: "swiper-scrollbar",
					};

					Object.entries(classPrefixMap).forEach(([suffix, baseClass]) => {
						const $target = clone_swiper.parent().find(`.${baseClass}`);
						$target.each(function () {
							const currentClasses = ($(this).attr("class") || "").split(/\s+/);
							const filteredClasses = currentClasses.filter(
								(cls) => cls === baseClass,
							);
							filteredClasses.push(`${swiperId}-${suffix}`);
							$(this).attr("class", filteredClasses.join(" "));
						});
					});

					const wrapper = clone_swiper.find(".swiper-wrapper");
					const templateSlide = wrapper.find(".swiper-slide").first();

					clone_swiper.empty();
					const newWrapper = $('<div class="swiper-wrapper"></div>');

					// value は edges 展開済みで node 配列の想定
					(value || []).forEach((imgNode) => {
						const newSlide = templateSlide.clone(true);

						// 不要属性削除（元コード踏襲）
						Array.from(newSlide[0].attributes).forEach((attr) => {
							if (attr.name !== "class") {
								newSlide.removeAttr(attr.name);
							}
						});

						const $img = newSlide.find("img").first();
						const imgData = imgNode?.node || imgNode; // 念のため両対応
						if ($img.length && imgData?.mediaContentType === "IMAGE") {
							$img.attr("src", imgData.image?.url || "");
							$img.attr("alt", imgData.image?.altText || "");
							newWrapper.append(newSlide);
						}
					});

					clone_swiper.append(newWrapper);
					slideBlockSwiperInit(clone_swiper[0]);
					return;
				}
			});
		}
	} catch (error) {
		console.error("replaceContent failed:", error);
	}
}
