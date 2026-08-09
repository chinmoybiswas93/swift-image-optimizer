/**
 * Admin dashboard for Swift Image Optimizer.
 *
 * Drives the bulk run from the browser: each batch is a REST call, so a slow
 * server simply takes more requests rather than timing out mid-run.
 */

import { createRoot } from '@wordpress/element';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

import {
	LogoMark,
	IconImage,
	IconDisk,
	IconBolt,
	IconSliders,
	IconLayers,
	IconGear,
	IconArchive,
	IconTrendDown,
	IconStethoscope,
	IconDocument,
} from './icons';
import Tabs from './tabs';

import './index.scss';

const config = window.swiftImageOptimizer || {};

apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

const request = ( path, options = {} ) =>
	apiFetch( { url: config.restUrl + path, ...options } );

const formatBytes = ( bytes ) => {
	const value = Number( bytes ) || 0;
	if ( value < 1024 ) return `${ value } B`;
	const units = [ 'KB', 'MB', 'GB', 'TB' ];
	let size = value / 1024;
	let i = 0;
	while ( size >= 1024 && i < units.length - 1 ) {
		size /= 1024;
		i++;
	}
	return `${ size.toFixed( size < 10 ? 2 : 1 ) } ${ units[ i ] }`;
};

/** A single headline figure. */
const Stat = ( { label, value, tone } ) => (
	<div className={ `sio-stat${ tone ? ` is-${ tone }` : '' }` }>
		<div className="sio-stat__value">{ value }</div>
		<div className="sio-stat__label">{ label }</div>
	</div>
);

/** One icon + figure pair in the hero strip. */
const Metric = ( { icon, tone, value, label } ) => (
	<div className="sio-metric">
		<span className={ `sio-metric__icon is-${ tone }` }>{ icon }</span>
		<div className="sio-metric__value">{ value }</div>
		<div className="sio-metric__label">{ label }</div>
	</div>
);

/** Plugin identity: mark, wordmark and the engine currently in use. */
const Masthead = () => (
	<div className="sio-masthead">
		<LogoMark />
		<div className="sio-masthead__text">
			<h1 className="sio-masthead__title">
				{ __( 'Swift Image Optimizer', 'swift-image-optimizer' ) }
			</h1>
			<p className="sio-masthead__tagline">
				{ __(
					'Convert your media library to WebP and keep it that way.',
					'swift-image-optimizer'
				) }
			</p>
		</div>
		{ config.engine && (
			<span className="sio-pill">
				{ sprintf(
					/* translators: %s: active conversion engine. */
					__( '%s engine', 'swift-image-optimizer' ),
					config.engine
				) }
			</span>
		) }
	</div>
);

/** Library-wide savings, shown above the tabs on every screen. */
const HeroStats = ( { stats } ) => {
	const percent = Number( stats.saved_percent ) || 0;

	return (
		<Card className="sio-hero">
			<CardBody className="sio-hero__body">
				<div className="sio-hero__primary">
					<span className="sio-hero__label">
						{ __( 'Total storage saved', 'swift-image-optimizer' ) }
					</span>
					<div className="sio-hero__figure">
						<span className="sio-hero__value">{ formatBytes( stats.saved_bytes ) }</span>
						{ percent > 0 && (
							<span className="sio-hero__badge">
								<IconTrendDown />
								{ sprintf(
									/* translators: %s: percentage saved. */
									__( '%s%% smaller', 'swift-image-optimizer' ),
									percent
								) }
							</span>
						) }
					</div>
					<span className="sio-hero__sub">
						{ __( 'Across all optimized media files', 'swift-image-optimizer' ) }
					</span>
				</div>

				<div className="sio-hero__metrics">
					<Metric
						icon={ <IconImage /> }
						tone="blue"
						value={ stats.optimized ?? 0 }
						label={ __( 'Images optimized', 'swift-image-optimizer' ) }
					/>
					<Metric
						icon={ <IconDisk /> }
						tone="rose"
						value={ formatBytes( stats.original_bytes ) }
						label={ __( 'Original size', 'swift-image-optimizer' ) }
					/>
					<Metric
						icon={ <IconBolt /> }
						tone="green"
						value={ formatBytes( stats.optimized_bytes ) }
						label={ __( 'Optimized size', 'swift-image-optimizer' ) }
					/>
				</div>
			</CardBody>
		</Card>
	);
};

/** A titled settings block. Replaces the old accordion panels. */
const Section = ( { icon, title, description, children } ) => (
	<Card className="sio-card sio-section">
		<CardHeader className="sio-section__header">
			<span className="sio-section__icon">{ icon }</span>
			<span className="sio-section__heading">
				<span className="sio-section__title">{ title }</span>
				{ description && (
					<span className="sio-section__desc">{ description }</span>
				) }
			</span>
		</CardHeader>
		<CardBody>{ children }</CardBody>
	</Card>
);

/** Environment warning when no engine is available. */
const EngineNotice = ( { engine, engines } ) => {
	if ( engine ) {
		const available = Object.keys( engines ).filter( ( k ) => engines[ k ] );
		return (
			<p className="sio-muted">
				{ sprintf(
					/* translators: 1: active engine, 2: available engines. */
					__( 'Using the %1$s engine. Available here: %2$s.', 'swift-image-optimizer' ),
					engine,
					available.join( ', ' )
				) }
			</p>
		);
	}

	return (
		<Notice status="error" isDismissible={ false }>
			{ __(
				'No image conversion engine is available on this server. Ask your host to enable GD with WebP support, or Imagick.',
				'swift-image-optimizer'
			) }
		</Notice>
	);
};

/** Bulk optimization tab. */
const BulkPanel = ( { summary, setSummary, setStats } ) => {
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

/** Settings tab. */
const SettingsPanel = ( { values, setValues } ) => {
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );

	const set = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setSaved( false );
	};

	const save = async () => {
		setSaving( true );
		setError( '' );
		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ config.optionName ]: values },
			} );
			setSaved( true );
		} catch ( e ) {
			setError( e.message );
		}
		setSaving( false );
	};

	const bool = ( key ) => !! Number( values[ key ] );

	return (
		<>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ saved && <Notice status="success" onRemove={ () => setSaved( false ) }>{ __( 'Settings saved.', 'swift-image-optimizer' ) }</Notice> }

			<Section
				icon={ <IconSliders /> }
				title={ __( 'Conversion', 'swift-image-optimizer' ) }
				description={ __(
					'How images are turned into WebP.',
					'swift-image-optimizer'
				) }
			>
				<ToggleControl
					label={ __( 'Optimize new uploads automatically', 'swift-image-optimizer' ) }
					help={ __( 'Convert images to WebP as they are uploaded.', 'swift-image-optimizer' ) }
					checked={ bool( 'auto_optimize' ) }
					onChange={ ( v ) => set( 'auto_optimize', v ? 1 : 0 ) }
				/>
				<RangeControl
					label={ __( 'WebP quality', 'swift-image-optimizer' ) }
					help={ __( '82 is a good balance. Below 75 shows artefacts; above 88 gains little.', 'swift-image-optimizer' ) }
					value={ Number( values.quality ) || 82 }
					onChange={ ( v ) => set( 'quality', v ) }
					min={ 1 }
					max={ 100 }
				/>
				<NumberControl
					label={ __( 'Maximum image dimension (px)', 'swift-image-optimizer' ) }
					help={ __( 'Longest edge. Set to 0 to leave dimensions alone.', 'swift-image-optimizer' ) }
					value={ Number( values.max_dimension ) || 0 }
					onChange={ ( v ) => set( 'max_dimension', parseInt( v, 10 ) || 0 ) }
					min={ 0 }
					max={ 10000 }
				/>
				<ToggleControl
					label={ __( 'Convert PNG images', 'swift-image-optimizer' ) }
					checked={ bool( 'convert_png' ) }
					onChange={ ( v ) => set( 'convert_png', v ? 1 : 0 ) }
				/>
				<ToggleControl
					label={ __( 'Keep the original when WebP would be larger', 'swift-image-optimizer' ) }
					checked={ bool( 'skip_if_larger' ) }
					onChange={ ( v ) => set( 'skip_if_larger', v ? 1 : 0 ) }
				/>
				<ToggleControl
					label={ __( 'Keep a backup of uploaded originals', 'swift-image-optimizer' ) }
					help={ __( 'Uploads are converted and the original file is replaced. With this on, the original is kept so the image can be restored later. Turning it off means uploaded originals are gone for good.', 'swift-image-optimizer' ) }
					checked={ bool( 'backup_uploads' ) }
					onChange={ ( v ) => set( 'backup_uploads', v ? 1 : 0 ) }
				/>
				<SelectControl
					label={ __( 'Conversion engine', 'swift-image-optimizer' ) }
					value={ values.engine || 'auto' }
					options={ [
						{ label: __( 'Automatic (recommended)', 'swift-image-optimizer' ), value: 'auto' },
						{ label: 'Imagick', value: 'imagick' },
						{ label: 'cwebp', value: 'cwebp' },
						{ label: 'GD', value: 'gd' },
					] }
					onChange={ ( v ) => set( 'engine', v ) }
				/>
			</Section>

			<Section
				icon={ <IconLayers /> }
				title={ __( 'Existing images', 'swift-image-optimizer' ) }
				description={ __(
					'What happens to images already published on your site.',
					'swift-image-optimizer'
				) }
			>
				<ToggleControl
					label={ __( 'Rewrite URLs in the database', 'swift-image-optimizer' ) }
					help={ __( 'Update references in posts, meta and options when an existing image is converted. Turning this off will leave broken images.', 'swift-image-optimizer' ) }
					checked={ bool( 'rewrite_urls' ) }
					onChange={ ( v ) => set( 'rewrite_urls', v ? 1 : 0 ) }
				/>
				<ToggleControl
					label={ __( 'Serve the WebP when an old URL is requested', 'swift-image-optimizer' ) }
					help={ __( 'Catches references the rewriter could not reach, such as hotlinks and cached pages.', 'swift-image-optimizer' ) }
					checked={ bool( 'enable_404_fallback' ) }
					onChange={ ( v ) => set( 'enable_404_fallback', v ? 1 : 0 ) }
				/>
				<SelectControl
					label={ __( 'Keep original backups for', 'swift-image-optimizer' ) }
					help={ __( 'After this, originals are deleted and images can no longer be restored.', 'swift-image-optimizer' ) }
					value={ String( values.backup_retention ?? 30 ) }
					options={ [
						{ label: __( '7 days', 'swift-image-optimizer' ), value: '7' },
						{ label: __( '30 days', 'swift-image-optimizer' ), value: '30' },
						{ label: __( '90 days', 'swift-image-optimizer' ), value: '90' },
						{ label: __( 'Keep forever', 'swift-image-optimizer' ), value: '0' },
					] }
					onChange={ ( v ) => set( 'backup_retention', parseInt( v, 10 ) ) }
				/>
			</Section>

			<Section
				icon={ <IconGear /> }
				title={ __( 'WordPress behaviour', 'swift-image-optimizer' ) }
				description={ __(
					'Core defaults this plugin can override.',
					'swift-image-optimizer'
				) }
			>
				<ToggleControl
					label={ __( 'Disable WordPress automatic image scaling', 'swift-image-optimizer' ) }
					help={ __( 'WordPress scales images over 2560px by default.', 'swift-image-optimizer' ) }
					checked={ bool( 'disable_wp_scaling' ) }
					onChange={ ( v ) => set( 'disable_wp_scaling', v ? 1 : 0 ) }
				/>
			</Section>

			<div className="sio-savebar">
				<Button variant="primary" onClick={ save } disabled={ saving }>
					{ saving ? <Spinner /> : __( 'Save settings', 'swift-image-optimizer' ) }
				</Button>
			</div>
		</>
	);
};

/** Backups tab. */
const BackupsPanel = () => {
	const [ bytes, setBytes ] = useState( config.backupBytes || 0 );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( '' );

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
		try {
			const result = await request( 'backups/purge', { method: 'POST' } );
			setBytes( result.backup_bytes );
			setMessage(
				sprintf(
					/* translators: %d: number of backups. */
					__( '%d backups removed.', 'swift-image-optimizer' ),
					result.purged
				)
			);
		} catch ( e ) {
			setMessage( e.message );
		}
		setBusy( false );
	};

	return (
		<>
			{ message && <Notice status="info" onRemove={ () => setMessage( '' ) }>{ message }</Notice> }

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

/** One diagnostics row: label, value and, when something is wrong, the remedy. */
const DiagnosticRow = ( { row } ) => (
	<div className={ `sio-diagnostics__row is-${ row.state }` }>
		<span className="sio-diagnostics__label">{ row.label }</span>
		<span className="sio-diagnostics__value">
			{ row.value }
			{ row.hint && (
				<span className="sio-diagnostics__hint">{ row.hint }</span>
			) }
		</span>
		<span className={ `sio-diagnostics__state is-${ row.state }` } aria-hidden="true" />
	</div>
);

/** Troubleshoot tab: what this server can do, and what the plugin just did. */
const TroubleshootPanel = ( { values, setValues } ) => {
	const [ report, setReport ] = useState( null );
	const [ log, setLog ] = useState( { lines: [], size: 0, rotated: false } );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const viewer = useRef( null );

	const enabled = !! Number( values.enable_log );

	const loadReport = useCallback( async () => {
		try {
			setReport( await request( 'diagnostics' ) );
		} catch ( e ) {
			setError( e.message );
		}
	}, [] );

	const loadLog = useCallback( async () => {
		try {
			setLog( await request( 'logs?lines=500' ) );
		} catch ( e ) {
			setError( e.message );
		}
	}, [] );

	useEffect( () => {
		loadReport();
		loadLog();
	}, [ loadReport, loadLog ] );

	// Keep the newest line in view, the way a terminal would.
	useEffect( () => {
		if ( viewer.current ) {
			viewer.current.scrollTop = viewer.current.scrollHeight;
		}
	}, [ log, filter ] );

	const toggleLogging = async ( on ) => {
		const next = { ...values, enable_log: on ? 1 : 0 };

		setBusy( 'toggle' );
		setError( '' );
		try {
			// The whole settings object is sent because the sanitizer rebuilds
			// it from the input - a partial save would reset every other value.
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ config.optionName ]: next },
			} );
			setValues( next );
			await loadLog();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const reset = async () => {
		if (
			! window.confirm( // eslint-disable-line no-alert
				__(
					'This permanently deletes the log file and everything recorded in it. Continue?',
					'swift-image-optimizer'
				)
			)
		) {
			return;
		}

		setBusy( 'reset' );
		try {
			const result = await request( 'logs/reset', { method: 'POST' } );
			setLog( result.log );
			setMessage( __( 'Log cleared.', 'swift-image-optimizer' ) );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const requeue = async () => {
		setBusy( 'requeue' );
		try {
			const result = await request( 'requeue', { method: 'POST' } );
			setMessage(
				sprintf(
					/* translators: %d: number of images returned to the queue. */
					__( '%d image(s) will be tried again on the next bulk run.', 'swift-image-optimizer' ),
					result.requeued
				)
			);
			await loadReport();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const cleanup = async () => {
		setBusy( 'cleanup' );
		try {
			const result = await request( 'cleanup', { method: 'POST' } );
			setMessage(
				sprintf(
					/* translators: %d: number of leftover files deleted. */
					__( '%d leftover file(s) removed.', 'swift-image-optimizer' ),
					result.removed
				)
			);
			await loadReport();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const copyReport = async () => {
		if ( ! report?.text ) {
			return;
		}

		try {
			await window.navigator.clipboard.writeText( report.text );
			setMessage( __( 'Diagnostics copied to the clipboard.', 'swift-image-optimizer' ) );
		} catch ( e ) {
			setError( __( 'Could not copy. Select the text and copy it manually.', 'swift-image-optimizer' ) );
		}
	};

	const downloadUrl = `${ config.restUrl }logs/download?_wpnonce=${ encodeURIComponent( config.nonce ) }`;

	const visible = filter
		? log.lines.filter( ( line ) => line.toLowerCase().includes( filter.toLowerCase() ) )
		: log.lines;

	return (
		<>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ message && <Notice status="success" onRemove={ () => setMessage( '' ) }>{ message }</Notice> }

			<Section
				icon={ <IconStethoscope /> }
				title={ __( 'Server information', 'swift-image-optimizer' ) }
				description={ __(
					'What this server can and cannot do for image conversion.',
					'swift-image-optimizer'
				) }
			>
				{ ! report && <Spinner /> }

				{ report?.sections?.map( ( section ) => (
					<div className="sio-diagnostics" key={ section.title }>
						<h3 className="sio-diagnostics__title">{ section.title }</h3>
						{ section.rows.map( ( row ) => (
							<DiagnosticRow row={ row } key={ row.label } />
						) ) }
					</div>
				) ) }

				<div className="sio-actions">
					<Button variant="secondary" onClick={ copyReport } disabled={ ! report }>
						{ __( 'Copy for support', 'swift-image-optimizer' ) }
					</Button>
					<Button variant="tertiary" onClick={ loadReport }>
						{ __( 'Refresh', 'swift-image-optimizer' ) }
					</Button>
				</div>
			</Section>

			<Section
				icon={ <IconDocument /> }
				title={ __( 'Activity log', 'swift-image-optimizer' ) }
				description={ __(
					'A step-by-step record of every conversion, written to a file.',
					'swift-image-optimizer'
				) }
			>
				<ToggleControl
					label={ __( 'Enable logging', 'swift-image-optimizer' ) }
					help={ __( 'Records every step of every optimization: backup, encode, file rename, URL rewrite and file deletion. Failures are always recorded, even with this off. The file is capped at 10MB and never leaves your server.', 'swift-image-optimizer' ) }
					checked={ enabled }
					disabled={ 'toggle' === busy }
					onChange={ toggleLogging }
				/>

				<div className="sio-logtoolbar">
					<input
						type="search"
						className="sio-logtoolbar__filter"
						placeholder={ __( 'Filter lines…', 'swift-image-optimizer' ) }
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
					/>
					<span className="sio-muted">
						{ sprintf(
							/* translators: 1: number of lines shown, 2: log file size. */
							__( '%1$d lines · %2$s', 'swift-image-optimizer' ),
							visible.length,
							formatBytes( log.size )
						) }
					</span>
				</div>

				<div className="sio-logviewer" ref={ viewer } tabIndex={ 0 }>
					{ visible.length === 0 && (
						<p className="sio-muted">
							{ enabled
								? __( 'Nothing recorded yet. Optimize an image and refresh.', 'swift-image-optimizer' )
								: __( 'Nothing recorded yet. Turn logging on to capture the full detail of each conversion.', 'swift-image-optimizer' ) }
						</p>
					) }
					{ visible.map( ( line, i ) => (
						<div
							className={ `sio-logviewer__line${ line.includes( ' ERROR ' ) ? ' is-error' : '' }${ line.includes( ' WARN ' ) ? ' is-warn' : '' }` }
							key={ i }
						>
							{ line }
						</div>
					) ) }
				</div>

				{ log.rotated && (
					<p className="sio-muted">
						{ __( 'The log reached its 10MB limit and rolled over. Only the most recent file is shown; the download includes what is on disk now.', 'swift-image-optimizer' ) }
					</p>
				) }

				<div className="sio-actions">
					<Button variant="secondary" onClick={ loadLog }>
						{ __( 'Refresh', 'swift-image-optimizer' ) }
					</Button>
					<Button variant="secondary" href={ downloadUrl } disabled={ ! log.size }>
						{ __( 'Download', 'swift-image-optimizer' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ reset }
						disabled={ 'reset' === busy || ! log.size }
					>
						{ 'reset' === busy ? <Spinner /> : __( 'Reset log', 'swift-image-optimizer' ) }
					</Button>
				</div>
			</Section>

			<Section
				icon={ <IconLayers /> }
				title={ __( 'Maintenance', 'swift-image-optimizer' ) }
				description={ __(
					'Recover images that were passed over because of a temporary problem.',
					'swift-image-optimizer'
				) }
			>
				<div className="sio-stats">
					<Stat
						label={ __( 'Images worth trying again', 'swift-image-optimizer' ) }
						value={ report?.retryable ?? '—' }
						tone={ report?.retryable > 0 ? 'warning' : '' }
					/>
					<Stat
						label={ __( 'Leftover temporary files', 'swift-image-optimizer' ) }
						value={ report?.tempFiles ?? '—' }
						tone={ report?.tempFiles > 0 ? 'warning' : '' }
					/>
				</div>
				<p className="sio-lede">
					{ __(
						'Images skipped because the server ran out of memory or disk space, or because no engine was available, are not retried automatically. Once the cause is fixed, this returns them to the queue. Images skipped because they genuinely cannot be improved are left alone.',
						'swift-image-optimizer'
					) }
				</p>
				<p className="sio-lede">
					{ __(
						'Temporary files are left behind when a conversion is interrupted, for example by a server timeout. They are cleared automatically once a day, and can be cleared now.',
						'swift-image-optimizer'
					) }
				</p>
				<div className="sio-actions">
					<Button
						variant="secondary"
						onClick={ requeue }
						disabled={ 'requeue' === busy || ! report?.retryable }
					>
						{ 'requeue' === busy ? <Spinner /> : __( 'Requeue for another attempt', 'swift-image-optimizer' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ cleanup }
						disabled={ 'cleanup' === busy || ! report?.tempFiles }
					>
						{ 'cleanup' === busy ? <Spinner /> : __( 'Clean up temporary files', 'swift-image-optimizer' ) }
					</Button>
				</div>
			</Section>
		</>
	);
};

/** Root component. */
const App = () => {
	const [ summary, setSummary ] = useState( config.summary || {} );
	const [ stats, setStats ] = useState( config.stats || {} );

	// Settings live here rather than in the Settings tab: the Troubleshoot tab
	// also writes one of them, and both must send the complete object.
	const [ settings, setSettings ] = useState( config.settings || {} );

	return (
		<div className="sio-app">
			<Masthead />
			<HeroStats stats={ stats } />

			<Tabs
				tabs={ [
					{ name: 'bulk', title: __( 'Bulk Optimize', 'swift-image-optimizer' ) },
					{ name: 'settings', title: __( 'Settings', 'swift-image-optimizer' ) },
					{ name: 'backups', title: __( 'Backups', 'swift-image-optimizer' ) },
					{ name: 'troubleshoot', title: __( 'Troubleshoot', 'swift-image-optimizer' ) },
				] }
			>
				{ ( tab ) => {
					if ( tab.name === 'settings' ) {
						return <SettingsPanel values={ settings } setValues={ setSettings } />;
					}
					if ( tab.name === 'backups' ) return <BackupsPanel />;
					if ( tab.name === 'troubleshoot' ) {
						return <TroubleshootPanel values={ settings } setValues={ setSettings } />;
					}
					return (
						<BulkPanel
							summary={ summary }
							setSummary={ setSummary }
							setStats={ setStats }
						/>
					);
				} }
			</Tabs>
		</div>
	);
};

const mount = document.getElementById( 'swift-image-optimizer-root' );

if ( mount ) {
	createRoot( mount ).render( <App /> );
}
