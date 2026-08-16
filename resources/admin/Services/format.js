/** Value formatting shared by the pages. */

import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Render a byte count in the largest unit that keeps it readable.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted size.
 */
export const formatBytes = ( bytes ) => {
	const value = Number( bytes ) || 0;

	if ( value < 1024 ) {
		return `${ value } B`;
	}

	const units = [ 'KB', 'MB', 'GB', 'TB' ];

	let size = value / 1024;
	let i    = 0;

	while ( size >= 1024 && i < units.length - 1 ) {
		size /= 1024;
		i++;
	}

	return `${ size.toFixed( size < 10 ? 2 : 1 ) } ${ units[ i ] }`;
};

/**
 * Describe how long ago a unix timestamp was.
 *
 * Deliberately coarse. The figures on this screen are as old as the last scan,
 * and the useful question is "minutes or days", not the exact second - a
 * precise timestamp invites the reader to trust it more than a weekly scan
 * deserves.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @return {string} Human phrase, or an empty string when there is no timestamp.
 */
export const formatTimeAgo = ( timestamp ) => {
	const then = Number( timestamp ) || 0;

	if ( then <= 0 ) {
		return '';
	}

	const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - then );

	if ( seconds < 60 ) {
		return __( 'just now', 'swift-image-optimizer' );
	}

	const minutes = Math.floor( seconds / 60 );

	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			_n( '%d minute ago', '%d minutes ago', minutes, 'swift-image-optimizer' ),
			minutes
		);
	}

	const hours = Math.floor( minutes / 60 );

	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n( '%d hour ago', '%d hours ago', hours, 'swift-image-optimizer' ),
			hours
		);
	}

	const days = Math.floor( hours / 24 );

	return sprintf(
		/* translators: %d: number of days. */
		_n( '%d day ago', '%d days ago', days, 'swift-image-optimizer' ),
		days
	);
};
