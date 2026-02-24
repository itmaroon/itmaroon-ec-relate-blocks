import { useBlockProps, InnerBlocks } from "@wordpress/block-editor";

export default function save({ attributes }) {
	const { pickupId, shopId, headlessId, numberOfItems, selectedFields } =
		attributes;
	return (
		<div
			{...useBlockProps.save()}
			data-pickup_id={pickupId}
			data-shop_id={shopId}
			data-headless_id={headlessId}
			data-number_of_items={numberOfItems}
			data-selected_slug="product_category"
			data-selected_fields={JSON.stringify(selectedFields)}
		>
			<div className="template_unit unit_hide">
				<InnerBlocks.Content />
			</div>
		</div>
	);
}
