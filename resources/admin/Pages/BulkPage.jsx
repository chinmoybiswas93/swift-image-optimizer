/** Bulk optimization tab: one card, one source of figures. */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConfirmDialog, Notice } from '../Components';
import LibraryCard from '../Partials/LibraryCard';
import { request } from '../Services/http';
import config from '../Services/config';

const POLL_MS = 4000;

const BulkPage = ( { snapshot, setSnapshot } ) => {
	const [ phase, setPhase ] = useState( null );
	const [ stale, setStale ] = useState( !! config.snapshotStale );
	const [ dryRun, setDryRun ] = useState( null );
	const [ dryRunBusy, setDryRunBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Refs so the batch pumps can be stopped without waiting for a re-render.
	const stopped = useRef( false );
	const autoScanned = useRef( false );

	/**
	 * Take a phase payload and spread it across the pieces that render it.
	 *
	 * The snapshot travels inside the same response as the run state, so the
	 * ring and the progress bar are always describing the same instant. Fetching
	 * them separately is what let the old screen show two different libraries.
	 */
	const absorb = useCallback(
		( state ) => {
			if ( ! state ) {
				return null;
			}

			setPhase( state );
			setStale( !! state.snapshot_stale );

			if ( state.snapshot ) {
				setSnapshot( state.snapshot );
			}

			return state;
		},
		[ setSnapshot ]
	);

	/*
	 * Reconcile with the server on mount. The server is the authority on
	 * whether anything is running - this tab may have been closed through an
	 * entire run, or another tab may be driving one right now.
	 */
	useEffect( () => {
		let cancelledEffect = false;

		request( 'bulk/phase', { method: 'POST' } )
			.then( ( state ) => {
				if ( cancelledEffect ) {
					return;
				}

				absorb( state );

				/*
				 * Nothing has ever been scanned and nothing is running, so take
				 * a first one. Behind a ref because effects can run twice in
				 * development, and behind the busy check because a scan during
				 * a run is refused server-side anyway.
				 *
				 * Started from the client rather than from the enqueue on the
				 * PHP side deliberately: enqueue runs on every load of this
				 * screen, and starting minutes of batched work as a side effect
				 * of drawing a page is how a dashboard freezes a site.
				 */
				if (
					! autoScanned.current &&
					state &&
					! state.busy &&
					! state.snapshot
				) {
					autoScanned.current = true;
					startScan();
				}
			} )
			.catch( () => {} );

		return () => {
			cancelledEffect = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	/*
	 * Poll while anything is in flight, whoever is driving it. Batches are
	 * advanced by cron and by other tabs as well as by this one, so this tab
	 * has to be able to see progress it did not cause.
	 */
	useEffect( () => {
		if ( ! phase || ! phase.busy ) {
			return undefined;
		}

		const timer = setInterval( async () => {
			try {
				absorb( await request( 'bulk/phase', { method: 'POST' } ) );
			} catch ( e ) {
				// A failed poll is not a failed run; the next tick retries.
			}
		}, POLL_MS );

		return () => clearInterval( timer );
	}, [ phase, absorb ] );

	/**
	 * Drive scan batches directly while someone is watching.
	 *
	 * Cron advances a scan on its own, but only as fast as site traffic allows.
	 * Both paths exist and the server-side lock keeps them from overlapping, so
	 * an open tab makes the scan quick without making cron redundant.
	 */
	const pumpScan = async () => {
		let state = null;

		do {
			if ( stopped.current ) {
				break;
			}

			try {
				state = await request( 'library/scan/batch', {
					method: 'POST',
				} );
			} catch ( e ) {
				// Cron or another tab took this batch. Ordinary, not a failure -
				// hand over and let the poll keep the display current.
				if (
					e &&
					( e.code === 'scan-locked' || e.code === 'not-scanning' )
				) {
					return;
				}

				setError( e.message );
				break;
			}

			if ( state ) {
				setStale( !! state.snapshot_stale );

				if ( state.snapshot ) {
					setSnapshot( state.snapshot );
				}
			}
		} while ( state && state.running );

		try {
			absorb( await request( 'bulk/phase', { method: 'POST' } ) );
		} catch ( e ) {
			// The figures are already current; the next poll will catch up.
		}
	};

	/**
	 * Drive optimize batches directly, for the same reason.
	 */
	const pumpBulk = async () => {
		let state = null;

		do {
			if ( stopped.current ) {
				break;
			}

			try {
				state = await request( 'bulk/batch', { method: 'POST' } );
			} catch ( e ) {
				if (
					e &&
					( e.code === 'bulk-locked' || e.code === 'not-running' )
				) {
					return;
				}

				setError( e.message );
				break;
			}
		} while ( state && state.running );

		try {
			absorb( await request( 'bulk/phase', { method: 'POST' } ) );
		} catch ( e ) {
			// As above.
		}
	};

	const startScan = async ( force = false ) => {
		setError( '' );
		stopped.current = false;

		try {
			const state = await request( 'library/scan', {
				method: 'POST',
				data: { force },
			} );

			if ( state ) {
				setPhase( ( prev ) => ( {
					...( prev || {} ),
					busy: true,
					scan: state,
				} ) );
			}

			await pumpScan();
		} catch ( e ) {
			setError( e.message );
		}
	};

	const onScan = () => {
		// A stalled scan is restarted rather than joined: its cron tick is
		// queued fine, it is the request to run it that never arrived.
		startScan( !! ( phase && phase.scan && phase.scan.stalled ) );
	};

	const onOptimize = () => setConfirming( true );

	const startOptimize = async () => {
		// Closed before the run rather than after: the pumps below take as
		// long as the library does, and the progress belongs on the card.
		setConfirming( false );
		setError( '' );
		stopped.current = false;

		try {
			absorb( await request( 'bulk/run', { method: 'POST' } ) );
		} catch ( e ) {
			setError( e.message );

			return;
		}

		/*
		 * Pump the leading scan, then the conversions, then the closing scan.
		 * The server owns the transitions between them - these calls only make
		 * each stage finish sooner than cron alone would.
		 */
		await pumpScan();
		await pumpBulk();
		await pumpScan();
	};

	const onCancel = async () => {
		stopped.current = true;

		try {
			absorb( await request( 'bulk/cancel', { method: 'POST' } ) );
		} catch ( e ) {
			setError( e.message );
		}
	};

	const onDryRun = async () => {
		setDryRunBusy( true );
		setError( '' );

		try {
			setDryRun( await request( 'dry-run', { method: 'POST' } ) );
		} catch ( e ) {
			setError( e.message );
		}

		setDryRunBusy( false );
	};

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<LibraryCard
				phase={ phase }
				snapshot={ snapshot }
				stale={ stale }
				dryRun={ dryRun }
				dryRunBusy={ dryRunBusy }
				onScan={ onScan }
				onOptimize={ onOptimize }
				onCancel={ onCancel }
				onDryRun={ onDryRun }
			/>

			{ confirming && (
				<ConfirmDialog
					title={ __(
						'Optimize your media library?',
						'swift-image-optimizer'
					) }
					confirmLabel={ __(
						'Start optimizing',
						'swift-image-optimizer'
					) }
					onConfirm={ startOptimize }
					onCancel={ () => setConfirming( false ) }
				>
					<p>
						{ __(
							'This converts your existing images to WebP and updates every reference to them across your site.',
							'swift-image-optimizer'
						) }
					</p>
					<p>
						{ __(
							'Every original is backed up first, so any image can be restored afterwards.',
							'swift-image-optimizer'
						) }
					</p>
				</ConfirmDialog>
			) }
		</>
	);
};

export default BulkPage;
