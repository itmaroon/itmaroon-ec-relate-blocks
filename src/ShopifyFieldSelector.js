import { PanelBody, ToggleControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

const ShopifyFieldSelector = ({
	fieldType,
	selectedFields,
	setSelectedFields,
}) => {
	const product_choices = [
		{
			key: "title",
			label: __("Title", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "description",
			label: __("Description (plain)", "itmaroon-ec-relate-bloks"),
			block: "core/paragraph",
		},
		{
			key: "descriptionHtml",
			label: __("Description (HTML)", "itmaroon-ec-relate-bloks"),
			block: "core/paragraph",
		},
		{
			key: "vendor",
			label: __("Vendor", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "productType",
			label: __("Product Type", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "handle",
			label: __("Handle (Slug)", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "tags",
			label: __("Tags", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "availableForSale",
			label: __("Available for Sale", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "quantityAvailable",
			label: __("Quantity Available", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "price",
			label: __("Price", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "compareAtPrice",
			label: __("Compare at Price (Original)", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "currencyCode",
			label: __("Currency Code", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "image",
			label: __("Main Image", "itmaroon-ec-relate-bloks"),
			block: "core/image",
		},
		{
			key: "images",
			label: __("All Images", "itmaroon-ec-relate-bloks"),
			block: "itmar/slide-mv",
		},
		{
			key: "variants",
			label: __("Variants (Options)", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "sku",
			label: __("SKU", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "onlineStoreUrl",
			label: __("Online Store URL", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "createdAt",
			label: __("Created At", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "updatedAt",
			label: __("Updated At", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
	];

	const cart_choices = [
		{
			key: "title",
			label: __("Title", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "featuredImage",
			label: __("Featured Image", "itmaroon-ec-relate-bloks"),
			block: "core/image",
		},

		{
			key: "variants",
			label: __("Variants (Options)", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "quantity",
			label: __("Quantity", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-text-ctrl",
		},
		{
			key: "quantityAvailable",
			label: __("Quantity Available", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},

		{
			key: "price",
			label: __("Price", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "compareAtPrice",
			label: __("Compare at Price (Original)", "itmaroon-ec-relate-bloks"),
			block: "itmar/design-title",
		},
	];

	const handleToggle = (fieldKey, checked, label, block) => {
		if (checked) {
			if (!selectedFields.some((item) => item.key === fieldKey)) {
				setSelectedFields([...selectedFields, { key: fieldKey, label, block }]);
			}
		} else {
			setSelectedFields(selectedFields.filter((item) => item.key !== fieldKey));
		}
	};

	const isChecked = (fieldKey) => {
		return selectedFields.some((item) => item.key === fieldKey);
	};

	const choices = fieldType === "product" ? product_choices : cart_choices;

	return (
		<PanelBody
			title={__("Display Fields", "itmaroon-ec-relate-bloks")}
			initialOpen={true}
		>
			{choices.map((choice) => (
				<div key={choice.key} className="field_section">
					<ToggleControl
						className="field_choice"
						label={choice.label}
						checked={isChecked(choice.key)}
						onChange={(checked) =>
							handleToggle(choice.key, checked, choice.label, choice.block)
						}
					/>
				</div>
			))}
		</PanelBody>
	);
};

export default ShopifyFieldSelector;
