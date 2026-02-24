import { useBlockProps, InnerBlocks } from "@wordpress/block-editor";

export default function save({ attributes }) {
	const { numberOfItems, cartId, cartIconId, selectedFields } = attributes;

	return (
		<div
			{...useBlockProps.save()}
			data-number_of_items={numberOfItems}
			data-cart_id={cartId}
			data-cart_icon_id={cartIconId}
			data-selected_fields={JSON.stringify(selectedFields)}
		>
			<div className="template_unit unit_hide">
				<InnerBlocks.Content />
			</div>
		</div>
	);
}
