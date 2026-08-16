/** The one card the Bulk Optimize tab is now made of. */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Section, Spinner } from '../Components';
import { IconLayers } from '../Icons';
import EngineNotice from './EngineNotice';
import ScanSummary from './ScanSummary';
import RunProgress from './RunProgress';
import DryRunPanel from './DryRunPanel';
import config from '../Services/config';

/**
 * Library state and the actions that change it, in a single card.
 *
 * This replaces three separate cards - "Your library", "Before you start" and
 * "Bulk optimization" - which between them showed two sets of figures from two
 * different sources. One card, one snapshot, one set of numbers.
 *
 * Purely presentational: every piece of state and every request lives in
 * BulkPage, so this file stays readable as layout.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.phase        Phase payload from bulk/phase.
 * @param {Object}   props.snapshot     Published scan snapshot, or null.
 * @param {boolean}  props.stale        Whether the snapshot predates a settings change.
 * @param {Object}   props.dryRun       Dry-run report, or null.
 * @param {boolean}  props.dryRunBusy   Whether a dry run is in flight.
 * @param {Function} props.onScan       Start a scan.
 * @param {Function} props.onOptimize   Start a full run.
 * @param {Function} props.onCancel     Stop everything.
 * @param {Function} props.onDryRun     Start a dry run.
 * @return {JSX.Element} The card.
 */
const LibraryCard = ( {
	phase,
	snapshot,
	stale,
	dryRun,
	dryRunBusy,
	onScan,
	onOptimize,
	onCancel,
	onDryRun,
} ) => {
	const scan = ( phase && phase.scan ) || {};
	const bulk = ( phase && phase.bulk ) || {};
	const busy = !! ( phase && phase.busy );
	const optimizing = !! bulk.running;

	const actionable = snapshot ? snapshot.actionable ?? 0 : 0;

	/*
	 * Disable, do not hide. A Bulk Optimize button that vanishes when there is
	 * no engine leaves the user with nothing to explain the absence; a disabled
	 * one beside the engine notice does.
	 *
	 * Before the first scan the count is unknown rather than zero, so the
	 * button stays available - refusing to offer the action because nothing has
	 * been measured yet would be the same wrong assumption this unit removes.
	 */
	const nothingToDo = !! snapshot && actionable === 0;
	const optimizeDisabled = ! config.engine || busy || nothingToDo;

	return (
		<Section
			icon={ <IconLayers /> }
			title={ __( 'Your library', 'swift-image-optimizer' ) }
			description={ __(
				'Measured against the files on disk, not assumed from past runs.',
				'swift-image-optimizer'
			) }
		>
			<ScanSummary
				snapshot={ snapshot }
				scan={ scan }
				stale={ stale }
				disabled={ optimizing }
				onScan={ onScan }
			/>

			<EngineNotice engine={ config.engine } engines={ config.engines || {} } />

			<RunProgress phase={ phase } />

			<DryRunPanel
				report={ dryRun }
				busy={ dryRunBusy }
				disabled={ busy }
				onRun={ onDryRun }
			/>

			<div className="sio-actions">
				{ ! busy ? (
					<Button variant="primary" onClick={ onOptimize } disabled={ optimizeDisabled }>
						{ __( 'Bulk optimize', 'swift-image-optimizer' ) }
					</Button>
				) : (
					<Button variant="secondary" isDestructive onClick={ onCancel }>
						{ __( 'Stop', 'swift-image-optimizer' ) }
					</Button>
				) }
				{ busy && <Spinner /> }
			</div>

			{ nothingToDo && ! busy && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Every image that can be optimized already has been. Scan again if you have added images since.',
						'swift-image-optimizer'
					) }
				</Notice>
			) }

			{ scan.stalled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'A scan is still open but no batch has completed recently. Background work relies on WP-Cron, which only runs when someone visits the site. Leave this page open to keep it moving, or start the scan again.',
						'swift-image-optimizer'
					) }
				</Notice>
			) }

			{ bulk.stalled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This run is still active but no batch has completed recently. Background processing relies on WP-Cron, which only runs when someone visits the site. Leave this page open to keep it moving, or set up a real system cron.',
						'swift-image-optimizer'
					) }
				</Notice>
			) }

			{ bulk.errors && bulk.errors.length > 0 && (
				<div className="sio-errors">
					<h4>{ __( 'Failures', 'swift-image-optimizer' ) }</h4>
					<ul>
						{ bulk.errors.map( ( item ) => (
							<li key={ item.id }>
								<strong>#{ item.id }</strong> { item.title } — { item.message }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ bulk.resumable && ! busy && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: images already done, 2: total images in the run. */
						__(
							'A previous run stopped after %1$d of %2$d images. Bulk optimize continues from there rather than starting again.',
							'swift-image-optimizer'
						),
						bulk.done ?? 0,
						bulk.total ?? 0
					) }
				</Notice>
			) }
		</Section>
	);
};

export default LibraryCard;
