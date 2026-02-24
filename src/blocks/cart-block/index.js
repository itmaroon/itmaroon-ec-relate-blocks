import { __ } from "@wordpress/i18n";

import { registerBlockType } from "@wordpress/blocks";
import { ReactComponent as Cart } from "./cart.svg";

import "./style.scss";

/**
 * Internal dependencies
 */
import Edit from "./edit";
import save from "./save";
import metadata from "./block.json";

registerBlockType(metadata.name, {
	icon: <Cart />,
	description: __(
		"We provide blocks to build EC sites in cooperation with various EC companies.",
		"itmaroon-ec-relate-blocks",
	),

	edit: Edit,
	save,
});
