/**
 * Toggle switch.
 *
 * Replaces @wordpress/components ToggleControl: label, help, checked,
 * onChange, disabled. onChange receives the boolean, matching the original.
 *
 * Built on a real checkbox rather than a div with a click handler, so it is
 * focusable, space-togglable and announced correctly without extra ARIA.
 */

import useFieldId from './useFieldId';

const Toggle = ( {
	label,
	help,
	checked = false,
	onChange,
	disabled = false,
} ) => {
	const id = useFieldId( 'sio-toggle' );
	const helpId = help ? `${ id }-help` : undefined;

	return (
		<div
			className={ `sio-field sio-toggle${
				disabled ? ' is-disabled' : ''
			}` }
		>
			<label className="sio-toggle__row" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					className="sio-toggle__input"
					checked={ !! checked }
					disabled={ disabled }
					aria-describedby={ helpId }
					onChange={ ( event ) =>
						onChange && onChange( event.target.checked )
					}
				/>
				<span className="sio-toggle__track" aria-hidden="true">
					<span className="sio-toggle__thumb" />
				</span>
				<span className="sio-toggle__label">{ label }</span>
			</label>
			{ help && (
				<p className="sio-field__help" id={ helpId }>
					{ help }
				</p>
			) }
		</div>
	);
};

export default Toggle;
