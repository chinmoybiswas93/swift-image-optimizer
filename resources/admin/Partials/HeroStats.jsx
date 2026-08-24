/** Library-wide savings, shown above the tabs on every screen. */

import { __, sprintf } from '@wordpress/i18n';
import { Card, CardBody, Metric } from '../Components';
import { IconBolt, IconDisk, IconImage, IconTrendDown } from '../Icons';
import { formatBytes, formatTimeAgo } from '../Services/format';

/**
 * Library-wide savings, from the last completed scan.
 *
 * These used to come from a cached aggregate over the log table while the tiles
 * below came from a live query, which is how the same screen could show two
 * incompatible pictures of one library. Both now read the scan.
 *
 * The cost is that these figures move when a scan completes rather than the
 * instant a conversion does - which is why a bulk run ends with a scan, and why
 * the sublabel dates the number instead of implying it is live.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.snapshot Published scan snapshot, or null.
 * @return {JSX.Element} The hero.
 */
const HeroStats = ( { snapshot } ) => {
	const stats = snapshot || {};
	const percent = Number( stats.saved_percent ) || 0;
	const scannedAt = snapshot ? formatTimeAgo( snapshot.completed_at ) : '';

	return (
		<Card className="sio-hero">
			<CardBody className="sio-hero__body">
				<div className="sio-hero__primary">
					<span className="sio-hero__label">
						{ __( 'Total storage saved', 'swift-image-optimizer' ) }
					</span>
					<div className="sio-hero__figure">
						<span className="sio-hero__value">
							{ formatBytes( stats.saved_bytes ) }
						</span>
						{ percent > 0 && (
							<span className="sio-hero__badge">
								<IconTrendDown />
								{ sprintf(
									/* translators: %s: percentage saved. */
									__(
										'%s%% smaller',
										'swift-image-optimizer'
									),
									percent
								) }
							</span>
						) }
					</div>
					<span className="sio-hero__sub">
						{ scannedAt
							? sprintf(
									/* translators: %s: how long ago the library was scanned. */
									__(
										'Across all optimized media files, as of %s',
										'swift-image-optimizer'
									),
									scannedAt
							  )
							: __(
									'Scan your library to see what it has saved',
									'swift-image-optimizer'
							  ) }
					</span>
				</div>

				<div className="sio-hero__metrics">
					<Metric
						icon={ <IconImage /> }
						tone="blue"
						value={ stats.optimized ?? 0 }
						label={ __(
							'Images optimized',
							'swift-image-optimizer'
						) }
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
						label={ __(
							'Optimized size',
							'swift-image-optimizer'
						) }
					/>
				</div>
			</CardBody>
		</Card>
	);
};

export default HeroStats;
