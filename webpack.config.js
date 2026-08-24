/**
 * Build configuration.
 *
 * Extends the WordPress defaults, changing only the entry points so PHP can
 * stay in app/ and the React source in resources/.
 *
 * The defaults are what make React, apiFetch and i18n come from WordPress
 * rather than the bundle: DependencyExtractionWebpackPlugin rewrites every
 * `@wordpress/*` import to the matching `wp.*` global and writes the script
 * handles it depended on into build/*.asset.php, which the enqueue reads.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'resources/admin/bootstrap/app.jsx' ),
		media: path.resolve( __dirname, 'resources/media/media.js' ),
		editor: path.resolve( __dirname, 'resources/editor/index.jsx' ),
	},
};
