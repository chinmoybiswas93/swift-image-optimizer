/**
 * Number input.
 *
 * Replaces @wordpress/components __experimentalNumberControl - an experimental
 * API that could change under us on any core release, which is reason enough
 * to own it. Same props: label, help, value, onChange, min, max.
 *
 * onChange receives the raw string, as the experimental control did: the call
 * site parses it, and an empty field has to stay distinguishable from zero.
 */

import Field from './Field';

const NumberInput = ( {
	label,
	help,
	value,
	onChange,
	min,
	max,
	step = 1,
} ) => (
	<Field label={ label } help={ help } className="sio-number">
		{ ( { id, helpId } ) => (
			<input
				id={ id }
				type="number"
				className="sio-number__input"
				value={ value }
				min={ min }
				max={ max }
				step={ step }
				aria-describedby={ helpId }
				onChange={ ( event ) =>
					onChange && onChange( event.target.value )
				}
			/>
		) }
	</Field>
);

export default NumberInput;
