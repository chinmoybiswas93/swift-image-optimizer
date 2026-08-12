/**
 * Confirmation dialog for the Media Library surface.
 *
 * The admin app has Components/ConfirmDialog.jsx. This bundle deliberately
 * carries no React - the media modal is Backbone and this script loads on
 * screens the admin app never touches - so the same dialog is built here by
 * hand. Both render the identical `sio-modal` markup and share
 * styles/_modal.scss, which is what stops the two drifting into
 * different-looking dialogs for the same kind of decision.
 *
 * Returns a promise so a call site reads the way window.confirm() did:
 *
 *     if ( ! await sioConfirm( { ... } ) ) { return; }
 */

import { __ } from '@wordpress/i18n';

const FOCUSABLE =
	'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])';

let nextId = 0;

/**
 * Ask the operator to confirm an action.
 *
 * @param {Object}  options               Dialog content.
 * @param {string}  options.title         Heading.
 * @param {string}  options.message       Body text. Rendered as text, never HTML.
 * @param {string}  [options.confirm]     Confirm button label.
 * @param {string}  [options.cancel]      Cancel button label.
 * @param {boolean} [options.destructive] Style the confirm button as destructive.
 * @return {Promise<boolean>} True when confirmed.
 */
export default function sioConfirm( options ) {
	const {
		title,
		message,
		confirm = __( 'Confirm', 'swift-image-optimizer' ),
		cancel = __( 'Cancel', 'swift-image-optimizer' ),
		destructive = false,
	} = options;

	return new Promise( ( resolve ) => {
		nextId += 1;

		const titleId = `sio-modal-title-${ nextId }`;
		const bodyId = `sio-modal-body-${ nextId }`;

		const backdrop = document.createElement( 'div' );

		// Via a node rather than the global, so the dialog still behaves if it
		// is ever opened inside an iframe.
		const doc = backdrop.ownerDocument;
		const opener = doc.activeElement;

		backdrop.className = 'sio-modal__backdrop';

		const dialog = document.createElement( 'div' );
		dialog.className = destructive
			? 'sio-modal sio-modal--destructive'
			: 'sio-modal';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', titleId );
		dialog.setAttribute( 'aria-describedby', bodyId );
		dialog.tabIndex = -1;

		const head = document.createElement( 'div' );
		head.className = 'sio-modal__head';

		const heading = document.createElement( 'h2' );
		heading.className = 'sio-modal__title';
		heading.id = titleId;
		heading.textContent = title;

		const dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'sio-modal__dismiss';
		dismiss.setAttribute(
			'aria-label',
			__( 'Close', 'swift-image-optimizer' )
		);
		dismiss.innerHTML = '<span aria-hidden="true">&times;</span>';

		head.appendChild( heading );
		head.appendChild( dismiss );

		const body = document.createElement( 'div' );
		body.className = 'sio-modal__body';
		body.id = bodyId;

		const paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		body.appendChild( paragraph );

		const foot = document.createElement( 'div' );
		foot.className = 'sio-modal__foot';

		const cancelButton = document.createElement( 'button' );
		cancelButton.type = 'button';
		cancelButton.className = 'sio-btn sio-btn--tertiary';
		cancelButton.textContent = cancel;

		const confirmButton = document.createElement( 'button' );
		confirmButton.type = 'button';
		confirmButton.className = destructive
			? 'sio-btn sio-btn--primary is-destructive'
			: 'sio-btn sio-btn--primary';
		confirmButton.textContent = confirm;

		foot.appendChild( cancelButton );
		foot.appendChild( confirmButton );

		dialog.appendChild( head );
		dialog.appendChild( body );
		dialog.appendChild( foot );
		backdrop.appendChild( dialog );

		const overflow = doc.body.style.overflow;

		const close = ( answer ) => {
			doc.removeEventListener( 'keydown', onKeyDown, true );
			backdrop.remove();
			doc.body.style.overflow = overflow;

			// Back to whatever opened the dialog, so a keyboard operator is
			// not dropped at the top of the document.
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}

			resolve( answer );
		};

		function onKeyDown( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
				close( false );

				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			const targets = Array.from( dialog.querySelectorAll( FOCUSABLE ) );

			if ( ! targets.length ) {
				return;
			}

			const active = doc.activeElement;
			const first = targets[ 0 ];
			const last = targets[ targets.length - 1 ];

			if ( event.shiftKey && active === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && active === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		backdrop.addEventListener( 'mousedown', ( event ) => {
			if ( event.target === backdrop ) {
				close( false );
			}
		} );

		cancelButton.addEventListener( 'click', () => close( false ) );
		dismiss.addEventListener( 'click', () => close( false ) );
		confirmButton.addEventListener( 'click', () => close( true ) );
		doc.addEventListener( 'keydown', onKeyDown, true );

		doc.body.appendChild( backdrop );
		doc.body.style.overflow = 'hidden';
		// Cancel takes focus, not Confirm: Enter on a freshly-opened dialog
		// should never complete a destructive action.
		cancelButton.focus();
	} );
}
