const defaultConfig = require("@wordpress/scripts/config/webpack.config");

const mode = "production";

module.exports = {
	...defaultConfig,
	mode: mode,
	entry: () => ({
		...defaultConfig.entry(),
		"shopify-auth-callback": "./src/shopify-auth-callback.ts",
	}),
};
