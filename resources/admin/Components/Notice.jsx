/**
 * Notice.
 *
 * Replaces @wordpress/components Notice: status, isDismissible, onRemove.
 * Dismissible notices render a real button so they are reachable by keyboard,
 * which the plain markup version this replaces was not.
 */

import { __ } from '@wordpress/i18n';

const Notice = ( {
	status = 'info',
	isDismissible = true,
	onRemove,
	className = '',
	children,
} ) => {
	const classes = [ 'sio-notice', `sio-notice--${ status }`, className ]
		.filter( Boolean )
		.join( ' ' );

	// Errors interrupt; anything else is announced without stealing focus.
	const live = status === 'error' ? 'assertive' : 'polite';

	return (
		<div className={ classes } role={ status === 'error' ? 'alert' : 'status' } aria-live={ live }>
			<div className="sio-notice__body">{ children }</div>
			{ isDismissible && onRemove && (
				<button
					type="button"
					className="sio-notice__dismiss"
					onClick={ onRemove }
					aria-label={ __( 'Dismiss this notice', 'swift-image-optimizer' ) }
				>
					<span aria-hidden="true">&times;</span>
				</button>
			) }
		</div>
	);
};

export default Notice;
