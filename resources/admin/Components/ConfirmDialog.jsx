/**
 * Confirmation dialog.
 *
 * The plugin's replacement for window.confirm(). Two modes:
 *
 *   - plain: a Cancel and a Confirm button;
 *   - typed: Confirm stays disabled until the operator types a given word.
 *
 * The typed mode is for actions that destroy something irreplaceable. It is
 * not friction for its own sake - a browser alert's OK button sits exactly
 * where "yes, save" sits on every other screen, and deleting every stored
 * original is not an action anyone should be able to complete by muscle
 * memory.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Button from './Button';
import Field from './Field';
import Modal from './Modal';

const ConfirmDialog = ( {
	title,
	confirmLabel = __( 'Confirm', 'swift-image-optimizer' ),
	cancelLabel = __( 'Cancel', 'swift-image-optimizer' ),
	confirmWord = '',
	isDestructive = false,
	busy = false,
	onConfirm,
	onCancel,
	children,
} ) => {
	const [ typed, setTyped ] = useState( '' );

	// Compared case-insensitively against the *translated* word, so the word
	// the label tells the operator to type is the word that works.
	const unlocked =
		! confirmWord ||
		typed.trim().toLowerCase() === confirmWord.toLowerCase();

	const submit = () => {
		if ( unlocked && ! busy && onConfirm ) {
			onConfirm();
		}
	};

	return (
		<Modal
			title={ title }
			description
			onClose={ onCancel }
			// While the request is in flight there is nothing useful to cancel
			// and dismissing would only hide what is happening.
			dismissible={ ! busy }
			className={ isDestructive ? 'sio-modal--destructive' : '' }
			footer={
				<>
					<Button
						variant="tertiary"
						onClick={ onCancel }
						disabled={ busy }
					>
						{ cancelLabel }
					</Button>
					<Button
						variant="primary"
						isDestructive={ isDestructive }
						isBusy={ busy }
						disabled={ ! unlocked || busy }
						onClick={ submit }
					>
						{ confirmLabel }
					</Button>
				</>
			}
		>
			{ children }

			{ confirmWord && (
				<Field
					label={ sprintf(
						/* translators: %s: the word the operator must type, for example DELETE. */
						__( 'Type %s to confirm', 'swift-image-optimizer' ),
						confirmWord
					) }
					className="sio-modal__confirm-field"
				>
					{ ( { id } ) => (
						<input
							type="text"
							id={ id }
							className="sio-modal__input"
							value={ typed }
							onChange={ ( event ) =>
								setTyped( event.target.value )
							}
							onKeyDown={ ( event ) => {
								if ( event.key === 'Enter' ) {
									event.preventDefault();
									submit();
								}
							} }
							disabled={ busy }
							autoComplete="off"
							spellCheck="false"
						/>
					) }
				</Field>
			) }
		</Modal>
	);
};

export default ConfirmDialog;
