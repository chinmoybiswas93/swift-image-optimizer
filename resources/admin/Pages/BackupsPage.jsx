/** Backups tab. */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ConfirmDialog,
	Notice,
	Section,
	Spinner,
	Stat,
	useToast,
} from '../Components';
import { IconArchive } from '../Icons';
import { request } from '../Services/http';
import config from '../Services/config';
import { formatBytes } from '../Services/format';

/*
 * The word the operator types to unlock the purge. Translated, because the
 * label tells them what to type and the two have to agree.
 */
const CONFIRM_WORD = __( 'DELETE', 'swift-image-optimizer' );

const BackupsPage = () => {
	const [ bytes, setBytes ] = useState( config.backupBytes || 0 );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ error, setError ] = useState( '' );
	const toast = useToast();

	const purge = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await request( 'backups/purge', { method: 'POST' } );

			setBytes( result.backup_bytes );
			setConfirming( false );

			/*
			 * Reported as space reclaimed rather than as a backup count. The
			 * count only ever described rows in the log, so a folder full of
			 * files no row points at reported "0 backups removed" while
			 * deleting them - which is the bug this replaced.
			 */
			const freed = result.bytes_freed || 0;

			toast.push(
				freed
					? sprintf(
							/* translators: %s: formatted size, e.g. 12.4 MB. */
							__( 'Backups deleted. %s reclaimed.', 'swift-image-optimizer' ),
							formatBytes( freed )
					  )
					: __( 'There was nothing left to delete.', 'swift-image-optimizer' )
			);
		} catch ( e ) {
			// Previously shown as an info notice alongside successes, so a
			// failed purge read like a completed one.
			setError( e.message );
			setConfirming( false );
		}
		setBusy( false );
	};

	return (
		<>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }

			<Section
				icon={ <IconArchive /> }
				title={ __( 'Original backups', 'swift-image-optimizer' ) }
				description={ __(
					'Every original is kept so any image can be restored.',
					'swift-image-optimizer'
				) }
			>
				<div className="sio-stats">
					<Stat label={ __( 'Backup storage used', 'swift-image-optimizer' ) } value={ formatBytes( bytes ) } />
				</div>
				<p className="sio-lede">
					{ __(
						'Originals are kept so any image can be restored. They are removed automatically once the retention period set in Settings has passed.',
						'swift-image-optimizer'
					) }
				</p>
				<div className="sio-actions">
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => setConfirming( true ) }
						disabled={ busy || ! bytes }
					>
						{ busy ? <Spinner /> : __( 'Delete all backups now', 'swift-image-optimizer' ) }
					</Button>
				</div>
			</Section>

			{ confirming && (
				<ConfirmDialog
					title={ __( 'Delete every stored original?', 'swift-image-optimizer' ) }
					confirmLabel={ __( 'Delete backups', 'swift-image-optimizer' ) }
					confirmWord={ CONFIRM_WORD }
					isDestructive
					busy={ busy }
					onConfirm={ purge }
					onCancel={ () => setConfirming( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: formatted size, e.g. 831.4 KB. */
							__(
								'This permanently deletes every stored original, freeing %s.',
								'swift-image-optimizer'
							),
							formatBytes( bytes )
						) }
					</p>
					<p>
						{ __(
							'Your optimized images are unaffected and stay exactly as they are. But nothing can be restored to its original afterwards, and this cannot be undone.',
							'swift-image-optimizer'
						) }
					</p>
				</ConfirmDialog>
			) }
		</>
	);
};

export default BackupsPage;
