/** Backups tab. */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
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
	const [ repairing, setRepairing ] = useState( false );
	const [ error, setError ] = useState( '' );
	const toast = useToast();

	const repair = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await request( 'backups/reconcile', {
				method: 'POST',
			} );

			setBytes( result.backup_bytes );
			setRepairing( false );

			toast.push(
				result.repaired
					? sprintf(
							/* translators: %d: number of images. */
							_n(
								'%d image can be restored again.',
								'%d images can be restored again.',
								result.repaired,
								'swift-image-optimizer'
							),
							result.repaired
					  )
					: __(
							'Nothing to recover. Every backup on disk is already accounted for.',
							'swift-image-optimizer'
					  )
			);
		} catch ( e ) {
			setError( e.message );
			setRepairing( false );
		}
		setBusy( false );
	};

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
							__(
								'Backups deleted. %s reclaimed.',
								'swift-image-optimizer'
							),
							formatBytes( freed )
					  )
					: __(
							'There was nothing left to delete.',
							'swift-image-optimizer'
					  )
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
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<Section
				icon={ <IconArchive /> }
				title={ __( 'Original backups', 'swift-image-optimizer' ) }
				description={ __(
					'Every original is kept so any image can be restored.',
					'swift-image-optimizer'
				) }
			>
				<div className="sio-stats">
					<Stat
						label={ __(
							'Backup storage used',
							'swift-image-optimizer'
						) }
						value={ formatBytes( bytes ) }
					/>
				</div>
				<p className="sio-lede">
					{ __(
						'Originals are kept so any image can be restored. They are removed automatically once the retention period set in Settings has passed.',
						'swift-image-optimizer'
					) }
				</p>
				<div className="sio-actions">
					{ /*
					 * Repair sits before Delete deliberately. The purge sweeps
					 * the whole backup folder, including the unreferenced files
					 * this recovers, so the order of the buttons is the order
					 * the two are safe to press in.
					 */ }
					<Button
						variant="secondary"
						onClick={ () => setRepairing( true ) }
						disabled={ busy || ! bytes }
					>
						{ busy ? (
							<Spinner />
						) : (
							__(
								'Repair backup records',
								'swift-image-optimizer'
							)
						) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => setConfirming( true ) }
						disabled={ busy || ! bytes }
					>
						{ busy ? (
							<Spinner />
						) : (
							__(
								'Delete all backups now',
								'swift-image-optimizer'
							)
						) }
					</Button>
				</div>
			</Section>

			{ repairing && (
				<ConfirmDialog
					title={ __(
						'Repair backup records?',
						'swift-image-optimizer'
					) }
					confirmLabel={ __(
						'Repair records',
						'swift-image-optimizer'
					) }
					busy={ busy }
					onConfirm={ repair }
					onCancel={ () => setRepairing( false ) }
				>
					<p>
						{ __(
							'This looks for originals still on disk that no image points at any more, and makes them restorable again. No file is deleted or changed.',
							'swift-image-optimizer'
						) }
					</p>
					<p>
						{ __(
							'Run this before deleting backups. Deleting removes the same files this would recover.',
							'swift-image-optimizer'
						) }
					</p>
				</ConfirmDialog>
			) }

			{ confirming && (
				<ConfirmDialog
					title={ __(
						'Delete every stored original?',
						'swift-image-optimizer'
					) }
					confirmLabel={ __(
						'Delete backups',
						'swift-image-optimizer'
					) }
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
