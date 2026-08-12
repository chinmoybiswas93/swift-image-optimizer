/**
 * Modal dialog.
 *
 * Replaces window.confirm() for destructive actions. A browser alert cannot be
 * styled, cannot say more than one paragraph, and cannot ask the operator to
 * type anything - all three of which matter when the action being confirmed
 * permanently deletes the only copy of someone's originals.
 *
 * Built on nothing, like the rest of the component set: no component library,
 * no focus-trap package. It renders in place rather than through a portal,
 * which is why the backdrop is fixed and carries the same z-index rationale as
 * the toasts.
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useFieldId from './useFieldId';

const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join( ',' );

const Modal = ( {
	title,
	description,
	onClose,
	dismissible = true,
	className = '',
	children,
	footer,
} ) => {
	const id = useFieldId( 'sio-modal' );
	const titleId = `${ id }-title`;
	const bodyId = `${ id }-body`;
	const dialog = useRef( null );
	const returnTo = useRef( null );

	const close = useCallback( () => {
		if ( dismissible && onClose ) {
			onClose();
		}
	}, [ dismissible, onClose ] );

	// Focus in on open, and back to whatever opened it on close, so a keyboard
	// operator is never dropped at the top of the document.
	useEffect( () => {
		const node = dialog.current;

		returnTo.current = node && node.ownerDocument.activeElement;

		const first = node && node.querySelector( FOCUSABLE );

		if ( first ) {
			first.focus();
		} else if ( dialog.current ) {
			dialog.current.focus();
		}

		const { body } = document;
		const overflow = body.style.overflow;

		body.style.overflow = 'hidden';

		return () => {
			body.style.overflow = overflow;

			if (
				returnTo.current &&
				typeof returnTo.current.focus === 'function'
			) {
				returnTo.current.focus();
			}
		};
	}, [] );

	// Escape closes; Tab cycles inside the dialog rather than walking off into
	// the admin menu behind it.
	useEffect( () => {
		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				close();

				return;
			}

			if ( event.key !== 'Tab' || ! dialog.current ) {
				return;
			}

			const targets = Array.from(
				dialog.current.querySelectorAll( FOCUSABLE )
			).filter( ( node ) => node.offsetParent !== null );

			if ( ! targets.length ) {
				return;
			}

			const active = dialog.current.ownerDocument.activeElement;
			const first = targets[ 0 ];
			const last = targets[ targets.length - 1 ];

			if ( event.shiftKey && active === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && active === last ) {
				event.preventDefault();
				first.focus();
			}
		};

		document.addEventListener( 'keydown', onKeyDown, true );

		return () => document.removeEventListener( 'keydown', onKeyDown, true );
	}, [ close ] );

	return (
		<div
			className="sio-modal__backdrop"
			// Presentational: the backdrop is a convenience for the mouse.
			// Escape and the two buttons are what actually close the dialog,
			// so there is nothing here a keyboard user has to reach.
			role="presentation"
			onMouseDown={ ( event ) => {
				// Only a click that both starts and ends on the backdrop
				// closes: a drag that began inside the dialog should not.
				if ( event.target === event.currentTarget ) {
					close();
				}
			} }
		>
			<div
				className={ [ 'sio-modal', className ]
					.filter( Boolean )
					.join( ' ' ) }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				aria-describedby={ description ? bodyId : undefined }
				ref={ dialog }
				tabIndex={ -1 }
			>
				<div className="sio-modal__head">
					<h2 className="sio-modal__title" id={ titleId }>
						{ title }
					</h2>
					{ dismissible && (
						<button
							type="button"
							className="sio-modal__dismiss"
							onClick={ close }
							aria-label={ __(
								'Close',
								'swift-image-optimizer'
							) }
						>
							<span aria-hidden="true">&times;</span>
						</button>
					) }
				</div>

				<div className="sio-modal__body" id={ bodyId }>
					{ children }
				</div>

				{ footer && <div className="sio-modal__foot">{ footer }</div> }
			</div>
		</div>
	);
};

export default Modal;
