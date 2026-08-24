/**
 * Select.
 *
 * Replaces @wordpress/components SelectControl: label, help, value, options
 * ([{ label, value }]), onChange. onChange receives the raw string value,
 * matching the original.
 */

import Field from './Field';

const Select = ( {
	label,
	help,
	value,
	options = [],
	onChange,
	disabled = false,
} ) => (
	<Field label={ label } help={ help } className="sio-select">
		{ ( { id, helpId } ) => (
			<select
				id={ id }
				className="sio-select__input"
				value={ value }
				disabled={ disabled }
				aria-describedby={ helpId }
				onChange={ ( event ) =>
					onChange && onChange( event.target.value )
				}
			>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		) }
	</Field>
);

export default Select;
