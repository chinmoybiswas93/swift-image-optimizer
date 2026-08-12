/** Troubleshoot tab: what this server can do, and what the plugin just did. */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ConfirmDialog,
	Notice,
	Section,
	Spinner,
	Stat,
	Toggle,
	useToast,
} from '../Components';
import { IconDocument, IconLayers, IconStethoscope } from '../Icons';
import DiagnosticRow from '../Partials/DiagnosticRow';
import { request, saveSettings } from '../Services/http';
import config from '../Services/config';
import { formatBytes } from '../Services/format';

const TroubleshootPage = ( { values, setValues } ) => {
	const [ report, setReport ] = useState( null );
	const [ log, setLog ] = useState( { lines: [], size: 0, rotated: false } );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ confirming, setConfirming ] = useState( false );
	const [ error, setError ] = useState( '' );
	const viewer = useRef( null );
	const toast = useToast();

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
			await saveSettings( next );
			setValues( next );
			await loadLog();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( '' );
	};

	const reset = async () => {
		setBusy( 'reset' );
		try {
			const result = await request( 'logs/reset', { method: 'POST' } );
			setLog( result.log );
			setConfirming( false );
			toast.push( __( 'Log cleared.', 'swift-image-optimizer' ) );
		} catch ( e ) {
			setError( e.message );
			setConfirming( false );
		}
		setBusy( '' );
	};

	const requeue = async () => {
		setBusy( 'requeue' );
		try {
			const result = await request( 'requeue', { method: 'POST' } );
			toast.push(
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

	const rescan = async () => {
		setBusy( 'rescan' );
		try {
			const result = await request( 'rescan', { method: 'POST' } );
			toast.push(
				0 === result.cleared
					? sprintf(
							/* translators: %d: number of records checked. */
							__(
								'Checked %d record(s). Every optimized file is present.',
								'swift-image-optimizer'
							),
							result.checked
					  )
					: sprintf(
							/* translators: 1: records checked, 2: records cleared. */
							__(
								'Checked %1$d record(s) and cleared %2$d whose file was missing. Those images are pending again.',
								'swift-image-optimizer'
							),
							result.checked,
							result.cleared
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
			toast.push(
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
			toast.push( __( 'Diagnostics copied to the clipboard.', 'swift-image-optimizer' ) );
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
			{ /* Errors stay put: they describe something still wrong. */ }
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }

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
				<Toggle
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
						onClick={ () => setConfirming( true ) }
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
				<p className="sio-lede">
					{ __(
						'After restoring a site, the database and the uploads folder can come from different points in time, leaving images recorded as optimized whose files are no longer there. Rescanning checks every recorded result against the disk and returns any that no longer exist to the queue. Results whose files are intact are left untouched.',
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
					<Button variant="secondary" onClick={ rescan } disabled={ 'rescan' === busy }>
						{ 'rescan' === busy ? <Spinner /> : __( 'Rescan library against disk', 'swift-image-optimizer' ) }
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

			{ confirming && (
				<ConfirmDialog
					title={ __( 'Reset the log?', 'swift-image-optimizer' ) }
					confirmLabel={ __( 'Reset log', 'swift-image-optimizer' ) }
					isDestructive
					busy={ 'reset' === busy }
					onConfirm={ reset }
					onCancel={ () => setConfirming( false ) }
				>
					<p>
						{ __(
							'This permanently deletes the log file and everything recorded in it. Your images and backups are unaffected.',
							'swift-image-optimizer'
						) }
					</p>
					<p>
						{ __(
							'Download the log first if you still need it for support.',
							'swift-image-optimizer'
						) }
					</p>
				</ConfirmDialog>
			) }
		</>
	);
};

export default TroubleshootPage;
