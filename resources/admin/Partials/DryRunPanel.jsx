/** The dry-run preview, inside the library card. */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '../Components';

/**
 * Report what a run would change, without changing anything.
 *
 * Folded into the library card when the three cards merged, but kept as a
 * disclosure above the Bulk Optimize button rather than deleted: the run it
 * previews rewrites references across five tables, and the preview should stay
 * reachable before the trigger rather than after it.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.report   Dry-run report, or null before one is run.
 * @param {boolean}  props.busy     Whether a dry run is in flight.
 * @param {boolean}  props.disabled Whether running one is blocked.
 * @param {Function} props.onRun    Start a dry run.
 * @return {JSX.Element} The panel.
 */
const DryRunPanel = ( { report, busy, disabled, onRun } ) => (
	<details className="sio-dryrun__panel">
		<summary>{ __( 'Preview what would change', 'swift-image-optimizer' ) }</summary>

		<p className="sio-lede">
			{ __(
				'A dry run reports how many references would be rewritten, without changing anything.',
				'swift-image-optimizer'
			) }
		</p>

		<Button variant="secondary" onClick={ onRun } disabled={ busy || disabled }>
			{ busy ? <Spinner /> : __( 'Run dry run', 'swift-image-optimizer' ) }
		</Button>

		{ report && (
			<div className="sio-dryrun">
				<p>
					{ sprintf(
						/* translators: 1: references found, 2: rows, 3: images sampled. */
						__(
							'Found %1$d references across %2$d rows in a sample of %3$d images.',
							'swift-image-optimizer'
						),
						report.replacements,
						report.rows,
						report.sampled
					) }
				</p>
				<p>
					{ sprintf(
						/* translators: %d: estimated total references. */
						__(
							'Estimated across the whole library: about %d references.',
							'swift-image-optimizer'
						),
						report.estimated_total
					) }
				</p>
				<ul className="sio-tablecounts">
					{ Object.entries( report.by_table || {} ).map( ( [ table, count ] ) => (
						<li key={ table }>
							<code>{ table }</code> <span>{ count }</span>
						</li>
					) ) }
				</ul>
				{ report.skipped > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of values. */
							__(
								'%d values contain data that cannot be safely rewritten and will be left untouched.',
								'swift-image-optimizer'
							),
							report.skipped
						) }
					</Notice>
				) }
			</div>
		) }
	</details>
);

export default DryRunPanel;
