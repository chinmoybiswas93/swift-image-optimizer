/**
 * Build configuration.
 *
 * Extends the WordPress defaults, only changing the entry point so PHP can stay
 * in src/ and the React source in admin/.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'admin/index.js' ),
		media: path.resolve( __dirname, 'admin/media.js' ),
	},
};
