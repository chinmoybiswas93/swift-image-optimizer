/** Environment warning when no conversion engine is available. */

import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '../Components';

const EngineNotice = ( { engine, engines } ) => {
	if ( engine ) {
		const available = Object.keys( engines ).filter(
			( k ) => engines[ k ]
		);
		return (
			<p className="sio-muted">
				{ sprintf(
					/* translators: 1: active engine, 2: available engines. */
					__(
						'Using the %1$s engine. Available here: %2$s.',
						'swift-image-optimizer'
					),
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

export default EngineNotice;
