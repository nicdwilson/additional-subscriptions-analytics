const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		index: './client/index.js',
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooDependencyExtractionWebpackPlugin(),
	],
};
