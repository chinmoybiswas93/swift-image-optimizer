/** The linear bar and step indicator for a run in flight. */

import { __, sprintf } from '@wordpress/i18n';
import { formatBytes } from '../Services/format';

/**
 * Progress through the three stages of a full run.
 *
 * The bar renders the server's overall percent across scan, optimize and scan,
 * so it advances steadily instead of sitting at nothing through the long middle
 * stage and then leaping. Every number here is computed server-side; two
 * clients doing their own arithmetic over different snapshots is what made the
 * old figures disagree with each other.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.phase Phase payload from bulk/phase.
 * @return {JSX.Element|null} The progress block, or nothing when idle.
 */
const RunProgress = ( { phase } ) => {
	if ( ! phase || ! phase.busy ) {
		return null;
	}

	const percent = phase.percent ?? 0;
	const bulk = phase.bulk || {};
	const index = phase.phase_index ?? 0;
	const count = phase.phase_count ?? 3;

	const steps = [];

	for ( let i = 1; i <= count; i++ ) {
		steps.push( i );
	}

	return (
		<div className="sio-phase">
			<div className="sio-phase__head">
				<span className="sio-phase__label">{ phase.phase_label }</span>

				{ index > 0 && (
					<span className="sio-phase__steps" aria-hidden="true">
						{ steps.map( ( step ) => (
							<span
								key={ step }
								className={ `sio-phase__dot${ step <= index ? ' is-done' : '' }` }
							/>
						) ) }
					</span>
				) }
			</div>

			<div className="sio-progress">
				<div className="sio-progress__bar">
					<div className="sio-progress__fill" style={ { width: `${ percent }%` } } />
				</div>

				<div className="sio-progress__meta">
					<span>
						{ sprintf(
							/* translators: %d: overall percentage complete. */
							__( '%d%% complete', 'swift-image-optimizer' ),
							percent
						) }
					</span>

					{ /* Conversion counters only mean anything once converting has begun. */ }
					{ ( bulk.done ?? 0 ) > 0 && (
						<>
							<span>
								{ sprintf(
									/* translators: 1: optimized, 2: skipped, 3: failed. */
									__(
										'%1$d optimized · %2$d skipped · %3$d failed',
										'swift-image-optimizer'
									),
									bulk.optimized ?? 0,
									bulk.skipped ?? 0,
									bulk.failed ?? 0
								) }
							</span>
							<span>
								{ formatBytes( bulk.saved ) } { __( 'saved', 'swift-image-optimizer' ) }
							</span>
						</>
					) }
				</div>
			</div>
		</div>
	);
};

export default RunProgress;
