/** Bulk optimization tab. */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Section, Spinner, Stat } from '../Components';
import { IconBolt, IconLayers, IconSliders } from '../Icons';
import EngineNotice from '../Partials/EngineNotice';
import { request } from '../Services/http';
import config from '../Services/config';
import { formatBytes } from '../Services/format';

const BulkPage = ( { summary, setSummary, setStats } ) => {
	const [ progress, setProgress ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ dryRun, setDryRun ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	// A ref so the batch loop can be stopped without waiting for a re-render.
	const stopped = useRef( false );

	const refresh = useCallback( async () => {
		try {
			const [ nextSummary, nextStats ] = await Promise.all( [
				request( 'scan' ),
				request( 'stats' ),
			] );
			setSummary( nextSummary );
			setStats( nextStats );
		} catch ( e ) {
			setError( e.message );
		}
	}, [ setSummary, setStats ] );

	/*
	 * Reconcile with the server on mount.
	 *
	 * This used to set progress but never `running`, so a run that was already
	 * going rendered the Start button as though nothing was happening. Clicking
	 * it then reset a live run - which is what produced "bulk already running"
	 * from a button that looked available. The server is the authority now.
	 */
	useEffect( () => {
		let cancelledEffect = false;

		request( 'bulk/status', { method: 'POST' } )
			.then( ( state ) => {
				if ( cancelledEffect || ! state ) {
					return;
				}

				setProgress( state );
				setRunning( !! state.running );
			} )
			.catch( () => {} );

		return () => {
			cancelledEffect = true;
		};
	}, [] );

	/*
	 * Poll while a run is active, independently of who is driving it.
	 *
	 * Batches can now be advanced by cron or by another tab, so this tab has to
	 * be able to see progress it did not cause. Without this the numbers only
	 * moved when this tab's own loop happened to be the one running.
	 */
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}

		const timer = setInterval( async () => {
			try {
				const state = await request( 'bulk/status', { method: 'POST' } );

				if ( ! state ) {
					return;
				}

				setProgress( state );

				if ( ! state.running ) {
					setRunning( false );
					refresh();
				}
			} catch ( e ) {
				// A failed poll is not a failed run; the next tick retries.
			}
		}, 4000 );

		return () => clearInterval( timer );
	}, [ running, refresh ] );

	const runDryRun = async () => {
		setBusy( true );
		setError( '' );
		try {
			setDryRun( await request( 'dry-run', { method: 'POST' } ) );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	/*
	 * Foreground pump.
	 *
	 * Cron keeps a run moving on its own, but only as fast as site traffic and
	 * WP_CRON_LOCK_TIMEOUT allow. While someone is watching this page, driving
	 * batches directly is far quicker, so both paths exist and the server-side
	 * lock keeps them from overlapping.
	 */
	const loop = async () => {
		let state = null;

		do {
			if ( stopped.current ) {
				break;
			}

			try {
				state = await request( 'bulk/batch', { method: 'POST' } );
			} catch ( e ) {
				/*
				 * `bulk-locked` means cron or another tab got this batch first,
				 * which is ordinary now rather than a failure. Hand over and let
				 * the status poll keep the display current instead of showing
				 * the operator an error for something working as intended.
				 */
				if ( e && e.code === 'bulk-locked' ) {
					return;
				}

				setError( e.message );
				break;
			}

			setProgress( state );
		} while ( state && state.running );

		if ( ! state || ! state.running ) {
			setRunning( false );
			await refresh();
		}
	};

	const start = async () => {
		/*
		 * Resuming continues work the operator already agreed to, so it does not
		 * ask again. Only a genuinely new run gets the warning.
		 */
		if (
			! resumable &&
			! window.confirm( // eslint-disable-line no-alert
				__(
					'This converts your existing images to WebP and updates every reference to them. Originals are backed up first. Continue?',
					'swift-image-optimizer'
				)
			)
		) {
			return;
		}

		setError( '' );
		stopped.current = false;
		setRunning( true );

		try {
			setProgress( await request( 'bulk/start', { method: 'POST' } ) );
			await loop();
		} catch ( e ) {
			setError( e.message );
			setRunning( false );
		}
	};

	const cancel = async () => {
		stopped.current = true;
		try {
			setProgress( await request( 'bulk/cancel', { method: 'POST' } ) );
		} catch ( e ) {
			setError( e.message );
		}
		setRunning( false );
	};

	const total = progress ? progress.total : summary.pending;
	const done = progress ? progress.done : 0;

	/*
	 * The server computes the percentage now. Two clients each doing their own
	 * arithmetic over different snapshots is what made the figures disagree
	 * with one another mid-run.
	 */
	const percent = progress ? progress.percent ?? 0 : 0;
	const resumable = !! ( progress && progress.resumable );
	const stalled = !! ( progress && progress.stalled );

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<Section
				icon={ <IconLayers /> }
				title={ __( 'Your library', 'swift-image-optimizer' ) }
				description={ __(
					'What Swift can see in your media library right now.',
					'swift-image-optimizer'
				) }
			>
				<div className="sio-stats">
					<Stat
						label={ __( 'Convertible images', 'swift-image-optimizer' ) }
						value={ summary.total ?? 0 }
					/>
					<Stat
						label={ __( 'Already processed', 'swift-image-optimizer' ) }
						value={ summary.processed ?? 0 }
						tone="good"
					/>
					<Stat
						label={ __( 'Still to do', 'swift-image-optimizer' ) }
						value={ summary.pending ?? 0 }
						tone={ summary.pending > 0 ? 'warn' : 'good' }
					/>
				</div>
				<EngineNotice engine={ config.engine } engines={ config.engines || {} } />
			</Section>

			<Section
				icon={ <IconSliders /> }
				title={ __( 'Before you start', 'swift-image-optimizer' ) }
				description={ __(
					'Preview the damage before you commit to it.',
					'swift-image-optimizer'
				) }
			>
				<p className="sio-lede">
					{ __(
						'A dry run reports how many references would be rewritten, without changing anything. Run it first.',
						'swift-image-optimizer'
					) }
				</p>
				<Button variant="secondary" onClick={ runDryRun } disabled={ busy || running }>
					{ busy ? <Spinner /> : __( 'Run dry run', 'swift-image-optimizer' ) }
				</Button>

				{ dryRun && (
					<div className="sio-dryrun">
						<p>
							{ sprintf(
								/* translators: 1: references found, 2: rows, 3: images sampled. */
								__(
									'Found %1$d references across %2$d rows in a sample of %3$d images.',
									'swift-image-optimizer'
								),
								dryRun.replacements,
								dryRun.rows,
								dryRun.sampled
							) }
						</p>
						<p>
							{ sprintf(
								/* translators: %d: estimated total references. */
								__( 'Estimated across the whole library: about %d references.', 'swift-image-optimizer' ),
								dryRun.estimated_total
							) }
						</p>
						<ul className="sio-tablecounts">
							{ Object.entries( dryRun.by_table || {} ).map( ( [ table, count ] ) => (
								<li key={ table }>
									<code>{ table }</code> <span>{ count }</span>
								</li>
							) ) }
						</ul>
						{ dryRun.skipped > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %d: number of values. */
									__(
										'%d values contain data that cannot be safely rewritten and will be left untouched.',
										'swift-image-optimizer'
									),
									dryRun.skipped
								) }
							</Notice>
						) }
					</div>
				) }
			</Section>

			<Section
				icon={ <IconBolt /> }
				title={ __( 'Bulk optimization', 'swift-image-optimizer' ) }
				description={ __(
					'Converts every pending image and rewrites the references to it.',
					'swift-image-optimizer'
				) }
			>
				{ progress && (
					<div className="sio-progress">
						<div className="sio-progress__bar">
							<div className="sio-progress__fill" style={ { width: `${ percent }%` } } />
						</div>
						<div className="sio-progress__meta">
							<span>
								{ sprintf(
									/* translators: 1: done, 2: total. */
									__( '%1$d of %2$d', 'swift-image-optimizer' ),
									done,
									total
								) }
							</span>
							<span>
								{ sprintf(
									/* translators: 1: optimized, 2: skipped, 3: failed. */
									__( '%1$d optimized · %2$d skipped · %3$d failed', 'swift-image-optimizer' ),
									progress.optimized,
									progress.skipped,
									progress.failed
								) }
							</span>
							<span>{ formatBytes( progress.saved ) } { __( 'saved', 'swift-image-optimizer' ) }</span>
						</div>
					</div>
				) }

				<div className="sio-actions">
					{ ! running ? (
						<Button
							variant="primary"
							onClick={ start }
							disabled={
								! config.engine ||
								( ( summary.pending ?? 0 ) === 0 && ! resumable )
							}
						>
							{ resumable
								? __( 'Resume bulk optimization', 'swift-image-optimizer' )
								: __( 'Start bulk optimization', 'swift-image-optimizer' ) }
						</Button>
					) : (
						<Button variant="secondary" isDestructive onClick={ cancel }>
							{ __( 'Stop', 'swift-image-optimizer' ) }
						</Button>
					) }
					{ running && <Spinner /> }
				</div>

				{ resumable && ! running && (
					<Notice status="info" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: images already done, 2: total images in the run. */
							__(
								'This run was stopped after %1$d of %2$d images. Resuming continues from there rather than starting again.',
								'swift-image-optimizer'
							),
							done,
							total
						) }
					</Notice>
				) }

				{ stalled && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This run is still active but no batch has completed recently. Background processing relies on WP-Cron, which only runs when someone visits the site. Leave this page open to keep it moving, or set up a real system cron.',
							'swift-image-optimizer'
						) }
					</Notice>
				) }

				{ progress && progress.errors && progress.errors.length > 0 && (
					<div className="sio-errors">
						<h4>{ __( 'Failures', 'swift-image-optimizer' ) }</h4>
						<ul>
							{ progress.errors.map( ( item ) => (
								<li key={ item.id }>
									<strong>#{ item.id }</strong> { item.title } — { item.message }
								</li>
							) ) }
						</ul>
					</div>
				) }
			</Section>
		</>
	);
};

export default BulkPage;
