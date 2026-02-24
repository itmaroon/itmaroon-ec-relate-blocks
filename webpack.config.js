const defaultConfig = require("@wordpress/scripts/config/webpack.config");

const mode = "production";

module.exports = {
	...defaultConfig,
	mode: mode,
};
