/**
 * A stable, unique id for one control instance.
 *
 * React 18 has `useId`, but `@wordpress/element` does not reliably re-export
 * it and the runtime here is whichever React the installed WordPress ships.
 * A ref plus a module counter gives the same guarantee - stable across
 * re-renders, unique per instance - on any version.
 *
 * Ids only ever bind a label and its help text to an input, so they need to be
 * unique on the page, not globally meaningful.
 */

import { useRef } from '@wordpress/element';

let counter = 0;

/**
 * @param {string} prefix Short label used to make the id readable in devtools.
 * @return {string} The id, stable for the life of the component.
 */
const useFieldId = ( prefix = 'sio-field' ) => {
	const id = useRef( null );

	if ( id.current === null ) {
		counter += 1;
		id.current = `${ prefix }-${ counter }`;
	}

	return id.current;
};

export default useFieldId;
