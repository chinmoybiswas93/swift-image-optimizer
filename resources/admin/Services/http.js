/**
 * REST client.
 *
 * Replaces @wordpress/api-fetch. All it did for us was prepend a nonce header
 * and unwrap the JSON body, which is a dozen lines against fetch - not worth a
 * dependency on WordPress's script bundle.
 */

import config from './config';

/**
 * Call a plugin REST endpoint.
 *
 * @param {string} path    Route path relative to the plugin REST namespace.
 * @param {Object} options fetch options; `data` is JSON-encoded as the body.
 * @return {Promise<any>} Parsed response body.
 */
export const request = async ( path, options = {} ) => {
	const { data, headers = {}, ...rest } = options;

	const response = await fetch( config.restUrl + path, {
		// Cookie auth is what the nonce authenticates, so it has to be sent.
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...headers,
		},
		...( data !== undefined ? { body: JSON.stringify( data ) } : {} ),
		...rest,
	} );

	// 204 and other empty responses have no body to parse.
	const text = await response.text();
	const body = text ? JSON.parse( text ) : null;

	if ( ! response.ok ) {
		// WordPress reports REST errors as { code, message, data }. Surfacing
		// that message is the difference between a useful error and "failed".
		const error = new Error(
			( body && body.message ) || `Request failed (${ response.status })`
		);
		error.code   = body && body.code;
		error.status = response.status;

		throw error;
	}

	return body;
};

/**
 * Save plugin settings.
 *
 * Settings go through WordPress's own settings endpoint rather than a plugin
 * route: register_setting() already defines the schema and the sanitizer, and
 * duplicating that in a second place is how the two drift apart.
 *
 * @param {Object} values Settings to persist.
 * @return {Promise<any>} The saved settings.
 */
export const saveSettings = async ( values ) => {
	const response = await fetch( `${ config.wpRestUrl }wp/v2/settings`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify( { [ config.optionName ]: values } ),
	} );

	const text = await response.text();
	const body = text ? JSON.parse( text ) : null;

	if ( ! response.ok ) {
		throw new Error( ( body && body.message ) || 'Could not save settings.' );
	}

	return body;
};

export default request;
