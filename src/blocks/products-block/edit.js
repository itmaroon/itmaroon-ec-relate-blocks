import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import ShopifyFieldSelector from "../../ShopifyFieldSelector";

import {
	PanelBody,
	PanelRow,
	CheckboxControl,
	Notice,
	TextControl,
	RangeControl,
} from "@wordpress/components";
import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from "@wordpress/block-editor";

import { useState, useEffect, useMemo, useRef } from "@wordpress/element";
import { useSelect, useDispatch } from "@wordpress/data";

import {
	ArchiveSelectControl,
	serializeBlockTree,
	createBlockTree,
	useRebuildChangeField,
} from "itmar-block-packages";

import "./editor.scss";

export default function Edit({ attributes, setAttributes, clientId }) {
	const {
		pickupId,
		productPost,
		storeUrl,
		shopId,
		channelName,
		categoryArray,
		headlessId,
		apiSecretMask,
		adminTokenMask,
		storefrontTokenMask,
		callbackUrl,
		stripeKey,
		selectedFields,
		numberOfItems,
		blocksAttributesArray,
	} = attributes;

	// dispatch関数を取得
	const { replaceInnerBlocks } = useDispatch("core/block-editor");

	//スタイルの説明
	const style_disp = [
		__("For landscape images, odd numbers", "itmaroon-ec-relate-blocks"),
		__("For landscape images, even numbers", "itmaroon-ec-relate-blocks"),
		__("For portrait images, odd numbers", "itmaroon-ec-relate-blocks"),
		__("For portrait images, even numbers", "itmaroon-ec-relate-blocks"),
	];

	//インナーブロックのひな型を用意
	const TEMPLATE = [];
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps(blockProps, {
		allowedBlocks: [
			"itmar/design-group",
			"itmar/design-title",
			"core/image",
			"core/paragraph",
			"itmar/design-button",
		],
		template: TEMPLATE,
		templateLock: false,
	});

	//インナーブロックの取得
	const { innerBlocks, parentBlock } = useSelect(
		(select) => {
			const { getBlocks, getBlockParents, getBlock } =
				select("core/block-editor");
			const parentIds = getBlockParents(clientId);

			return {
				innerBlocks: getBlocks(clientId),
				parentBlock:
					parentIds.length > 0
						? getBlock(parentIds[parentIds.length - 1])
						: null,
			};
		},
		[clientId],
	);

	//トークンをサーバに格納
	async function saveTokens() {
		const body_obj = {
			productPost: productPost,
			shop_domain: storeUrl,
			channel_name: channelName,
			api_secret: apiSecret,
			admin_token: adminToken,
			storefront_token: storefrontToken,
			stripe_key: stripeKey,
		};

		const res = await fetch("/wp-json/itmar-ec-relate/v1/settings/save", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce, // ローカルスクリプトで渡す
			},
			credentials: "include",
			body: JSON.stringify(body_obj),
		});

		const json = await res.json();
		if (json.status === "ok") {
			console.log("保存成功");
		} else {
			console.error("保存失敗", json);
		}
	}

	//表示フィールド変更によるインナーブロックの再構成
	const sectionCount = 4;
	const emptyTaxonomies = useMemo(() => [], []);
	const domType = parentBlock?.name === "itmar/slide-mv" ? "div" : "form";
	const insert_id =
		parentBlock?.name === "itmar/slide-mv" ? parentId : clientId;
	useRebuildChangeField(
		blocksAttributesArray,
		selectedFields,
		"product",
		emptyTaxonomies,
		sectionCount,
		domType,
		clientId,
		insert_id,
		"itmaroon_ec_relate_blocks",
	);

	//ブロック属性の更新処理
	const lastSerializedRef = useRef(""); // 前回の内容（文字列）を保持
	useEffect(() => {
		if (!innerBlocks || innerBlocks.length === 0) return;

		const serialized = innerBlocks.map(serializeBlockTree);
		const nextStr = JSON.stringify(serialized);

		// 内容が同じなら setAttributes しない（再レンダリング抑制）
		if (nextStr === lastSerializedRef.current) return;

		lastSerializedRef.current = nextStr;
		setAttributes({ blocksAttributesArray: serialized });
	}, [innerBlocks, setAttributes]);

	//編集中の値を確保するための状態変数
	const [url_editing, setUrlValue] = useState(storeUrl);
	const [store_editing, setStoreValue] = useState(storefrontTokenMask);
	const [shopId_editing, setShopId] = useState(shopId);
	const [channel_editing, setChannel] = useState(channelName);
	const [headless_editing, setHeadlessValue] = useState(headlessId);
	const [api_editing, setApiValue] = useState(apiSecretMask);
	const [admin_editing, setAdminValue] = useState(adminTokenMask);
	//const [callback_editing, setCallbackValue] = useState(callbackUrl);
	//const [stripe_key_editing, setStripeKeyValue] = useState(stripeKey);

	//Noticeのインデックス保持
	const [noticeClickedIndex, setNoticeClickedIndex] = useState(null);
	//貼付け中のフラグ保持
	const [isPastWait, setIsPastWait] = useState(false);
	//ペースト対象のチェック配列
	const [isCopyChecked, setIsCopyChecked] = useState([]);
	//wp_optionに保存するための変数
	const [apiSecret, setApiSecret] = useState("");
	const [adminToken, setAdminToken] = useState("");
	const [storefrontToken, setStorefrontToken] = useState("");
	//CheckBoxのイベントハンドラ
	const handleCheckboxChange = (index, newCheckedValue) => {
		const updatedIsChecked = [...isCopyChecked];
		updatedIsChecked[index] = newCheckedValue;
		setIsCopyChecked(updatedIsChecked);
	};

	//トークン、キー、商品情報ポストタイプの変更があればサーバーに格納
	useEffect(() => {
		saveTokens();
	}, [
		storeUrl,
		apiSecret,
		adminToken,
		storefrontToken,
		stripeKey,
		productPost,
	]);

	//商品カテゴリの取得
	useEffect(() => {
		let alive = true;

		(async () => {
			try {
				const data = await apiFetch({
					path: "/itmar-ec-relate/v1/get-collections",
				});

				if (!alive) return;

				// data が配列じゃないケースも潰す
				const arr = Array.isArray(data) ? data : [];
				setAttributes({ categoryArray: arr });
			} catch (e) {
				// 失敗時のハンドリング（必要なら）
				// console.error(e);
			}
		})();
		return () => {
			alive = false;
		};
	}, []);

	return (
		<>
			<InspectorControls>
				<PanelBody title={__("EC setting", "itmaroon-ec-relate-blocks")}>
					<TextControl
						label={__("Pickup ID", "itmaroon-ec-relate-blocks")}
						value={pickupId}
						onChange={(value) => setAttributes({ pickupId: value })}
					/>
					<ArchiveSelectControl
						selectedSlug={productPost}
						label={__("Select Product Post Type", "itmaroon-ec-relate-blocks")}
						homeUrl={itmar_option.home_url}
						onChange={(postInfo) => {
							if (postInfo) {
								setAttributes({
									productPost: postInfo.slug,
								});
							}
						}}
					/>

					<TextControl
						label={__("Store Site URL", "itmaroon-ec-relate-blocks")}
						value={url_editing}
						onChange={(newVal) => setUrlValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ storeUrl: url_editing });
						}}
					/>
					<TextControl
						label={__("Shop ID", "itmaroon-ec-relate-blocks")}
						value={shopId_editing}
						onChange={(newVal) => setShopId(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ shopId: shopId_editing });
						}}
					/>
					<TextControl
						label={__("Channel Name", "itmaroon-ec-relate-blocks")}
						value={channel_editing}
						onChange={(newVal) => setChannel(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ channelName: channel_editing });
						}}
					/>
					<TextControl
						label={__("Headless Client ID", "itmaroon-ec-relate-blocks")}
						value={headless_editing}
						onChange={(newVal) => setHeadlessValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ headlessId: headless_editing });
						}}
					/>
					<TextControl
						label={__("API Secret", "itmaroon-ec-relate-blocks")}
						value={api_editing}
						onChange={(newVal) => setApiValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ apiSecretMask: "********" });
							setApiSecret(api_editing);
						}}
					/>
					<TextControl
						label={__("Admin API Token", "itmaroon-ec-relate-blocks")}
						value={admin_editing}
						onChange={(newVal) => setAdminValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ adminTokenMask: "********" });
							setAdminToken(admin_editing);
						}}
					/>
					<TextControl
						label={__("Storefront API Token", "itmaroon-ec-relate-blocks")}
						value={store_editing}
						onChange={(newVal) => setStoreValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ storefrontTokenMask: "**********" });
							setStorefrontToken(store_editing);
						}}
					/>

					{/* <PanelBody
						title={__("WebHook Setting", "itmaroon-ec-relate-blocks")}
						initialOpen={true}
					>
						<TextControl
							label={__("WebHook Callback Url", "itmaroon-ec-relate-blocks")}
							value={callback_editing}
							onChange={(newVal) => setCallbackValue(newVal)} // 一時的な編集値として保存する
							onBlur={() => {
								if (!isValidUrlWithUrlApi(callback_editing)) {
									dispatch("core/notices").createNotice(
										"error",
										__(
											"The input string is not in URL format.",
											"itmaroon-ec-relate-blocks",
										),
										{ type: "snackbar", isDismissible: true },
									);
									// バリデーションエラーがある場合、表示を元の値に戻す
									setCallbackValue(callbackUrl);
								} else {
									//URLの形式を確認してリンク先をセット
									setAttributes({ callbackUrl: callback_editing });
								}
							}}
						/>
						<WebhookSettingsPanel callbackUrl={callbackUrl} />
					</PanelBody> */}

					{/* <TextControl
						label={__("Stripe API Key", "itmaroon-ec-relate-blocks")}
						value={stripe_key_editing}
						onChange={(newVal) => setStripeKeyValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ stripeKey: stripe_key_editing });
						}}
					/> */}

					<ShopifyFieldSelector
						fieldType="product"
						selectedFields={selectedFields}
						setSelectedFields={(fields) =>
							setAttributes({ selectedFields: fields })
						}
					/>
					<PanelRow className="itmar_post_blocks_pannel">
						<RangeControl
							value={numberOfItems}
							label={__("Display Num", "itmaroon-ec-relate-blocks")}
							max={30}
							min={1}
							onChange={(val) => setAttributes({ numberOfItems: val })}
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				<PanelBody
					title={__("Unit Style Copy&Past", "itmaroon-ec-relate-blocks")}
				>
					<div className="itmar_post_block_notice">
						{blocksAttributesArray.map((styleObj, index) => {
							const copyBtn = {
								label: __("Copy", "itmaroon-ec-relate-blocks"),
								onClick: () => {
									//CopyがクリックされたNoticeの順番を記録
									setNoticeClickedIndex(index);
								},
							};
							const pastBtn = {
								label: isPastWait ? (
									<img
										src={`${itmar_option.plugin_url}/assets/past-wait.gif`}
										alt={__("wait", "itmaroon-ec-relate-blocks")}
										style={{ width: "36px", height: "36px" }} // サイズ調整
									/>
								) : (
									__("Paste", "itmaroon-ec-relate-blocks")
								),
								onClick: () => {
									//貼付け中フラグをオン
									setIsPastWait(true);

									//記録された順番の書式をコピー
									if (noticeClickedIndex !== null) {
										//blocksAttributesArrayのクローンを作成
										const updatedBlocksAttributes = [...blocksAttributesArray];
										//ペースト対象配列にチェックが入った順番のものにペースト
										const newInnerBlocks = [...innerBlocks];
										isCopyChecked.forEach((checked, index) => {
											if (checked) {
												const replaceBlock = createBlockTree(
													blocksAttributesArray[noticeClickedIndex],
												);
												newInnerBlocks[index] = replaceBlock;
												//ブロック属性に格納した配列の要素を入れ替える
												updatedBlocksAttributes[index] =
													blocksAttributesArray[noticeClickedIndex];
											}
										});
										//元のブロックと入れ替え
										replaceInnerBlocks(clientId, newInnerBlocks, false);

										//属性を変更
										setAttributes({
											blocksAttributesArray: updatedBlocksAttributes,
										});

										//貼付け中フラグをオフ
										setIsPastWait(false);
										setNoticeClickedIndex(null); //保持を解除
										//ペースト対象配列の初期化
										setIsCopyChecked(
											Array(blocksAttributesArray.length).fill(false),
										);
									}
								},
							};
							const actions =
								noticeClickedIndex === index ? [pastBtn] : [copyBtn];
							const checkInfo = __(
								"Check the unit to which you want to paste and press the Paste button.",
								"itmaroon-ec-relate-blocks",
							);
							const checkContent =
								noticeClickedIndex != index ? (
									<CheckboxControl
										label={__("Paste to", "itmaroon-ec-relate-blocks")}
										checked={isCopyChecked[index]}
										onChange={(newVal) => {
											handleCheckboxChange(index, newVal);
										}}
									/>
								) : (
									<p>{checkInfo}</p>
								);

							return (
								<div className="style_unit">
									<Notice
										key={index}
										actions={actions}
										status={
											noticeClickedIndex === index ? "success" : "default"
										}
										isDismissible={false}
									>
										<div>
											<p>{`Unit ${index + 1} Style`}</p>
											<p>{style_disp[index]}</p>
										</div>
									</Notice>
									<div className="past_state">{checkContent}</div>
								</div>
							);
						})}
					</div>
				</PanelBody>
			</InspectorControls>

			<div {...innerBlocksProps} />
		</>
	);
}
