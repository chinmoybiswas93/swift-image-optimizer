/** The ring, the tiles and the scan control, side by side. */

import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, ProgressRing, Spinner, Stat } from '../Components';
import { formatTimeAgo } from '../Services/format';

/**
 * What the last scan found, and the button that takes another one.
 *
 * Every figure here comes from one stored snapshot measured against the disk,
 * which is the point of the unit: the old screen read its tiles from a live
 * mime count and its hero from the log table, so the two could never agree.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.snapshot  Published scan snapshot, or null if never scanned.
 * @param {Object}   props.scan      Live scan state from the server.
 * @param {boolean}  props.stale     Whether settings changed since the snapshot.
 * @param {boolean}  props.disabled  Whether scanning is blocked for another reason.
 * @param {Function} props.onScan    Start a scan.
 * @return {JSX.Element} The summary block.
 */
const ScanSummary = ( { snapshot, scan, stale, disabled, onScan } ) => {
	const scanning = !! ( scan && scan.running );
	const stalled = !! ( scan && scan.stalled );

	/*
	 * Disabled while a scan is genuinely progressing - but not once it has
	 * stalled. WP-Cron only fires when a request arrives, so on a quiet site
	 * with this tab closed a scan can sit "running" indefinitely; leaving the
	 * button dead in that state would strand the user with no way to retry.
	 * A stalled scan re-enables the button, and clicking it forces a restart.
	 */
	const scanDisabled = disabled || ( scanning && ! stalled );

	const total = snapshot ? snapshot.total_images ?? 0 : 0;
	const optimized = snapshot ? snapshot.optimized ?? 0 : 0;
	const permanent = snapshot ? snapshot.skipped_permanent ?? 0 : 0;
	const retryable = snapshot ? snapshot.skipped_retryable ?? 0 : 0;
	const failed = snapshot ? snapshot.failed ?? 0 : 0;
	const actionable = snapshot ? snapshot.actionable ?? 0 : 0;
	const percent = snapshot ? snapshot.percent ?? 0 : 0;

	// Server-computed shares, so the ring never divides in the browser.
	const mutedPercent = total > 0 ? Math.round( ( permanent / total ) * 100 ) : 0;

	const lastScanned = snapshot ? formatTimeAgo( snapshot.completed_at ) : '';

	return (
		<div className="sio-library">
			<div className="sio-library__ring">
				<ProgressRing
					percent={ percent }
					mutedPercent={ mutedPercent }
					label={ snapshot ? `${ percent }%` : '—' }
					caption={ __( 'optimized', 'swift-image-optimizer' ) }
					valueText={
						snapshot
							? sprintf(
									/* translators: 1: percentage, 2: images optimized, 3: images in the library. */
									__(
										'%1$d%% optimized: %2$d of %3$d images.',
										'swift-image-optimizer'
									),
									percent,
									optimized,
									total
							  )
							: __( 'Your library has not been scanned yet.', 'swift-image-optimizer' )
					}
				/>
			</div>

			<div className="sio-library__facts">
				{ ! snapshot ? (
					<p className="sio-lede">
						{ __(
							'Swift has not scanned your library yet. A scan checks every image against the files on disk, so the figures here describe what is really there rather than what was recorded at the time.',
							'swift-image-optimizer'
						) }
					</p>
				) : (
					<>
						<div className="sio-stats">
							<Stat
								label={ __( 'Optimized', 'swift-image-optimizer' ) }
								value={ optimized }
								tone="good"
							/>
							<Stat
								label={ __( 'Still to do', 'swift-image-optimizer' ) }
								value={ actionable }
								tone={ actionable > 0 ? 'warn' : 'good' }
							/>
							<Stat
								label={ __( 'Cannot be improved', 'swift-image-optimizer' ) }
								value={ permanent }
							/>
							<Stat
								label={ __( 'Failed', 'swift-image-optimizer' ) }
								value={ failed }
								tone={ failed > 0 ? 'warn' : undefined }
							/>
						</div>

						<p className="sio-lede">
							{ sprintf(
								/* translators: %d: number of images in the library. */
								_n(
									'%d image in your library.',
									'%d images in your library.',
									total,
									'swift-image-optimizer'
								),
								total
							) }
							{ ' ' }
							{ permanent > 0 &&
								sprintf(
									/* translators: %d: images that cannot be improved. */
									_n(
										'%d cannot be made smaller as WebP, so the ring stops short of 100%%.',
										'%d cannot be made smaller as WebP, so the ring stops short of 100%%.',
										permanent,
										'swift-image-optimizer'
									),
									permanent
								) }
						</p>

						{ retryable > 0 && (
							<details className="sio-scanmeta__details">
								<summary>
									{ sprintf(
										/* translators: %d: number of images worth retrying. */
										_n(
											'%d image was skipped for a reason that may have changed',
											'%d images were skipped for reasons that may have changed',
											retryable,
											'swift-image-optimizer'
										),
										retryable
									) }
								</summary>
								<p>
									{ __(
										'These ran out of memory, disk space or a working engine rather than being unsuitable. Bulk optimization will not retry them on its own — use Requeue on the Troubleshoot tab to return them to the queue.',
										'swift-image-optimizer'
									) }
								</p>
							</details>
						) }
					</>
				) }

				<div className="sio-scanmeta">
					<Button variant="secondary" onClick={ onScan } disabled={ scanDisabled }>
						{ scanning && ! stalled ? (
							<Spinner />
						) : (
							__( 'Scan library', 'swift-image-optimizer' )
						) }
					</Button>

					<span className="sio-scanmeta__time">
						{ scanning && ! stalled
							? sprintf(
									/* translators: 1: images checked, 2: images to check. */
									__( 'Checking %1$d of %2$d…', 'swift-image-optimizer' ),
									scan.scanned ?? 0,
									scan.total_estimate ?? 0
							  )
							: lastScanned &&
							  sprintf(
									/* translators: %s: how long ago, e.g. "5 minutes ago". */
									__( 'Last scanned %s', 'swift-image-optimizer' ),
									lastScanned
							  ) }
					</span>
				</div>

				{ stale && (
					<p className="sio-scanmeta__stale">
						{ __(
							'Your settings changed after this scan. Scan again for accurate figures.',
							'swift-image-optimizer'
						) }
					</p>
				) }
			</div>
		</div>
	);
};

export default ScanSummary;
