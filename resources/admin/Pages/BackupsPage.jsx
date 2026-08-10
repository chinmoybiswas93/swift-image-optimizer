/** Backups tab. */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Section, Spinner, Stat, useToast } from '../Components';
import { IconArchive } from '../Icons';
import { request } from '../Services/http';
import config from '../Services/config';
import { formatBytes } from '../Services/format';

const BackupsPage = () => {
	const [ bytes, setBytes ] = useState( config.backupBytes || 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const toast = useToast();

	const purge = async () => {
		if (
			! window.confirm( // eslint-disable-line no-alert
				__(
					'This permanently deletes every stored original. Optimized images are unaffected, but they can no longer be restored. Continue?',
					'swift-image-optimizer'
				)
			)
		) {
			return;
		}

		setBusy( true );
		setError( '' );
		try {
			const result = await request( 'backups/purge', { method: 'POST' } );
			setBytes( result.backup_bytes );
			toast.push(
				sprintf(
					/* translators: %d: number of backups. */
					__( '%d backups removed.', 'swift-image-optimizer' ),
					result.purged
				)
			);
		} catch ( e ) {
			// Previously shown as an info notice alongside successes, so a
			// failed purge read like a completed one.
			setError( e.message );
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
					<Button variant="secondary" isDestructive onClick={ purge } disabled={ busy || ! bytes }>
						{ busy ? <Spinner /> : __( 'Delete all backups now', 'swift-image-optimizer' ) }
					</Button>
				</div>
			</Section>
		</>
	);
};

export default BackupsPage;
