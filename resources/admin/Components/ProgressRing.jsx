/** Circular progress, drawn by hand. */

import { __, sprintf } from '@wordpress/i18n';

/**
 * A ring showing how much of the library is optimized.
 *
 * Hand-rolled SVG rather than a package, the same call the icon set makes: two
 * circles and one arithmetic line is less code than a dependency, and it
 * inherits the plugin's own colours instead of arriving with its own.
 *
 * Two arcs, not one. `percent` is the headline figure and fills the brand arc;
 * `mutedPercent` is the share that can never be optimized - images WebP would
 * make larger, animations, formats the plugin does not touch - drawn behind it
 * in grey. Without that second arc a fully-processed library shows a ring
 * stuck at 87% with no indication that the remaining 13% is finished business,
 * which reads as a stall. The number never changes; only the drawing does.
 *
 * @param {Object} props              Component props.
 * @param {number} props.percent      Share optimized, 0-100. Server-computed.
 * @param {number} props.mutedPercent Share that cannot be improved, 0-100.
 * @param {number} props.size         Outer diameter in pixels.
 * @param {number} props.stroke       Ring thickness in pixels.
 * @param {string} props.label        Large text inside the ring.
 * @param {string} props.caption      Small text under the label.
 * @param {string} props.valueText    Full sentence for assistive technology.
 * @return {JSX.Element} The ring.
 */
const ProgressRing = ( {
	percent = 0,
	mutedPercent = 0,
	size = 148,
	stroke = 12,
	label,
	caption,
	valueText,
} ) => {
	const value = Math.max( 0, Math.min( 100, Number( percent ) || 0 ) );

	// The two arcs share the circle, so the muted one can only occupy what the
	// value arc has not taken. Without this clamp a rounding disagreement
	// between them draws one arc over the other.
	const muted = Math.max( 0, Math.min( 100 - value, Number( mutedPercent ) || 0 ) );

	// Inset by half the stroke, or the ring is clipped by its own viewBox.
	const radius = ( size - stroke ) / 2;
	const circumference = 2 * Math.PI * radius;

	const valueDash = ( value / 100 ) * circumference;
	const mutedDash = ( muted / 100 ) * circumference;

	const describe =
		valueText ||
		sprintf(
			/* translators: %d: percentage of the library optimized. */
			__( '%d%% of your library is optimized.', 'swift-image-optimizer' ),
			value
		);

	return (
		<div
			className="sio-ring"
			style={ { width: size, height: size } }
			role="progressbar"
			aria-valuenow={ value }
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-valuetext={ describe }
		>
			{ /* Decorative: every figure it encodes is in aria-valuetext above. */ }
			<svg
				className="sio-ring__svg"
				width={ size }
				height={ size }
				viewBox={ `0 0 ${ size } ${ size }` }
				aria-hidden="true"
				focusable="false"
			>
				<circle
					className="sio-ring__track"
					cx={ size / 2 }
					cy={ size / 2 }
					r={ radius }
					fill="none"
					strokeWidth={ stroke }
				/>

				{ /*
				  * Drawn before the value arc and offset past it, so the two sit
				  * end to end around the circle rather than both starting at 12
				  * o'clock. The negative offset is how SVG walks a dash pattern
				  * forward from the path start.
				  */ }
				{ muted > 0 && (
					<circle
						className="sio-ring__muted"
						cx={ size / 2 }
						cy={ size / 2 }
						r={ radius }
						fill="none"
						strokeWidth={ stroke }
						strokeDasharray={ `${ mutedDash } ${ circumference - mutedDash }` }
						strokeDashoffset={ -valueDash }
						transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` }
					/>
				) }

				<circle
					className="sio-ring__value"
					cx={ size / 2 }
					cy={ size / 2 }
					r={ radius }
					fill="none"
					strokeWidth={ stroke }
					strokeLinecap="round"
					strokeDasharray={ `${ valueDash } ${ circumference - valueDash }` }
					strokeDashoffset={ 0 }
					transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` }
				/>
			</svg>

			{ /*
			  * HTML rather than <text>, so the caption inherits the admin font
			  * stack and a long translation wraps instead of overflowing.
			  */ }
			<div className="sio-ring__center">
				<span className="sio-ring__figure">{ label ?? `${ value }%` }</span>
				{ caption && <span className="sio-ring__caption">{ caption }</span> }
			</div>
		</div>
	);
};

export default ProgressRing;
