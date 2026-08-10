/** Value formatting shared by the pages. */

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
