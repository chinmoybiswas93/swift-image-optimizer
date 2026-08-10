/**
 * Shared label/help wrapper for the form controls.
 *
 * Every control needs the same three things - a label bound to the input, an
 * optional help line, and a stable id linking them for assistive technology.
 * Keeping that in one place is what stops the controls drifting apart.
 */

import { useId } from 'react';

const Field = ( { label, help, className = '', children } ) => {
	const id     = useId();
	const helpId = help ? `${ id }-help` : undefined;

	return (
		<div className={ [ 'sio-field', className ].filter( Boolean ).join( ' ' ) }>
			{ label && (
				<label className="sio-field__label" htmlFor={ id }>
					{ label }
				</label>
			) }
			{ children( { id, helpId } ) }
			{ help && (
				<p className="sio-field__help" id={ helpId }>
					{ help }
				</p>
			) }
		</div>
	);
};

export default Field;
