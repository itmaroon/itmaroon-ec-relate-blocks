import { __ } from "@wordpress/i18n";
import ShopifyFieldSelector from "../../ShopifyFieldSelector";

import {
	PanelBody,
	PanelRow,
	TextControl,
	RangeControl,
} from "@wordpress/components";

import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from "@wordpress/block-editor";

import { useEffect, useRef, useMemo, useState } from "@wordpress/element";
import { useSelect } from "@wordpress/data";

import {
	serializeBlockTree,
	useRebuildChangeField,
} from "itmar-block-packages";

import "./editor.scss";

export default function Edit({ attributes, setAttributes, clientId }) {
	const {
		selectedFields,
		numberOfItems,
		cartId,
		cartIconId,
		blocksAttributesArray,
	} = attributes;
	const [cart_id_editing, setCartIdValue] = useState(cartId);
	const [cart_icon_editing, setCartIconValue] = useState(cartIconId);

	//スペースのリセットバリュー
	const padding_resetValues = {
		top: "10px",
		left: "10px",
		right: "10px",
		bottom: "10px",
	};

	//ボーダーのリセットバリュー
	const border_resetValues = {
		top: "0px",
		left: "0px",
		right: "0px",
		bottom: "0px",
	};

	const units = [
		{ value: "px", label: "px" },
		{ value: "em", label: "em" },
		{ value: "rem", label: "rem" },
	];
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
			"itmar/design-text-ctrl",
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

	//表示フィールド変更によるインナーブロックの再構成
	const sectionCount = 1;
	const emptyTaxonomies = useMemo(() => [], []);
	//const domType = parentBlock?.name === "itmar/slide-mv" ? "div" : "form";
	const domType = "div";
	const insert_id =
		parentBlock?.name === "itmar/slide-mv" ? parentId : clientId;
	useRebuildChangeField(
		blocksAttributesArray,
		selectedFields,
		"cart",
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

	return (
		<>
			<InspectorControls>
				<PanelBody title={__("Cart setting", "itmaroon-ec-relate-blocks")}>
					<ShopifyFieldSelector
						fieldType="cart"
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
					<PanelRow className="itmar_post_blocks_pannel">
						<TextControl
							label={__("Cart Modal ID", "itmaroon-ec-relate-blocks")}
							value={cart_id_editing}
							onChange={(newVal) => setCartIdValue(newVal)} // 一時的な編集値として保存する
							onBlur={() => {
								setAttributes({ cartId: cart_id_editing });
							}}
						/>
					</PanelRow>
					<PanelRow className="itmar_post_blocks_pannel">
						<TextControl
							label={__("Cart Icon ID", "itmaroon-ec-relate-blocks")}
							value={cart_icon_editing}
							onChange={(newVal) => setCartIdValue(newVal)} // 一時的な編集値として保存する
							onBlur={() => {
								setAttributes({ cartIconId: cart_icon_editing });
							}}
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>

			<div {...innerBlocksProps} />
		</>
	);
}
