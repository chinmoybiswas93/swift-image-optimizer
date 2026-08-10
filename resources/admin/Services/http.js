/**
 * REST client.
 *
 * Built on WordPress's own @wordpress/api-fetch, which the build externalizes
 * to the `wp-api-fetch` script WordPress already loads. It handles the nonce,
 * JSON encoding, path resolution and error unwrapping, so there is nothing
 * here worth hand-rolling.
 */

import apiFetch from '@wordpress/api-fetch';

import config from './config';

// Signs every request, including the ones aimed at core's own endpoints.
apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

/**
 * Call a plugin REST endpoint.
 *
 * Plugin routes are addressed by absolute URL rather than by `path`, because
 * config.restUrl already carries the namespace and honours a site that serves
 * the REST API from a non-default location.
 *
 * @param {string} path    Route path relative to the plugin REST namespace.
 * @param {Object} options apiFetch options (`method`, `data`, …).
 * @return {Promise<any>} Parsed response body.
 */
export const request = ( path, options = {} ) =>
	apiFetch( { url: config.restUrl + path, ...options } );

/**
 * Save plugin settings.
 *
 * Settings go through WordPress's own settings endpoint rather than a plugin
 * route: register_setting() already defines the schema and the sanitizer, and
 * duplicating that in a second place is how the two drift apart.
 *
 * The whole settings object is sent every time because the sanitizer rebuilds
 * it from the input - a partial save would reset every other value.
 *
 * @param {Object} values Settings to persist.
 * @return {Promise<any>} The saved settings.
 */
export const saveSettings = ( values ) =>
	apiFetch( {
		path: '/wp/v2/settings',
		method: 'POST',
		data: { [ config.optionName ]: values },
	} );

export default request;
