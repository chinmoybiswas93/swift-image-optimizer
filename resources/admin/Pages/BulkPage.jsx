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

	useEffect( () => {
		request( 'bulk/status', { method: 'POST' } )
			.then( ( state ) => {
				if ( state && state.running ) {
					setProgress( state );
				}
			} )
			.catch( () => {} );
	}, [] );

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

	const loop = async () => {
		let state = null;

		do {
			if ( stopped.current ) {
				break;
			}

			try {
				state = await request( 'bulk/batch', { method: 'POST' } );
			} catch ( e ) {
				setError( e.message );
				break;
			}

			setProgress( state );
		} while ( state && state.running );

		setRunning( false );
		await refresh();
	};

	const start = async () => {
		if (
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
	const percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;

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
							disabled={ ! config.engine || ( summary.pending ?? 0 ) === 0 }
						>
							{ __( 'Start bulk optimization', 'swift-image-optimizer' ) }
						</Button>
					) : (
						<Button variant="secondary" isDestructive onClick={ cancel }>
							{ __( 'Stop', 'swift-image-optimizer' ) }
						</Button>
					) }
					{ running && <Spinner /> }
				</div>

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
