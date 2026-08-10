/** Plugin identity: mark, wordmark and the engine currently in use. */

import { __, sprintf } from '@wordpress/i18n';
import { LogoMark } from '../Icons';
import config from '../Services/config';

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

export default Masthead;
