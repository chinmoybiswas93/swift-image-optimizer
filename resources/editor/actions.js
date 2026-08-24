/**
 * REST calls the editor panel makes.
 *
 * Reuses the plugin's existing optimize/restore routes (MediaPolicy-guarded,
 * see app/Http/Routes/api.php) — same routes resources/media/media.js posts
 * to, just called via @wordpress/api-fetch instead of window.fetch, since
 * this bundle only ever runs inside the block editor, where api-fetch
 * already has WordPress's root-URL and nonce middleware wired up globally.
 */

import apiFetch from '@wordpress/api-fetch';

const config = window.swiftImageOptimizerEditor || {};

const request = ( action, attachmentId ) =>
	apiFetch( {
		path: `/${ config.namespace }/${ action }`,
		method: 'POST',
		data: { ids: [ attachmentId ] },
	} ).then( ( result ) => {
		const first = result && result.results && result.results[ 0 ];

		if ( first && ! first.ok ) {
			throw new Error( first.message );
		}

		return result;
	} );

/**
 * Fetch the current optimization payload for one attachment.
 *
 * @param {number} attachmentId Attachment ID.
 * @return {Promise<Object|null>} The swiftImageOptimizer field, or null.
 */
export const fetchStatus = ( attachmentId ) =>
	apiFetch( {
		path: `/wp/v2/media/${ attachmentId }?_fields=swiftImageOptimizer`,
	} ).then( ( media ) => media.swiftImageOptimizer || null );

/**
 * Optimize one attachment.
 *
 * @param {number} attachmentId Attachment ID.
 * @return {Promise<Object>} REST response.
 */
export const optimize = ( attachmentId ) => request( 'optimize', attachmentId );

/**
 * Restore one attachment's original.
 *
 * @param {number} attachmentId Attachment ID.
 * @return {Promise<Object>} REST response.
 */
export const restore = ( attachmentId ) => request( 'restore', attachmentId );
