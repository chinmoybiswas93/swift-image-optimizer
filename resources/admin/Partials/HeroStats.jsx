/** Library-wide savings, shown above the tabs on every screen. */

import { __, sprintf } from '@wordpress/i18n';
import { Card, CardBody, Metric } from '../Components';
import { IconBolt, IconDisk, IconImage, IconTrendDown } from '../Icons';
import { formatBytes } from '../Services/format';

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

export default HeroStats;
