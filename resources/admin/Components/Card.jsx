/**
 * Card, CardHeader and CardBody.
 *
 * Replaces the @wordpress/components trio. They were only ever used as styled
 * containers, so these are the same three elements without the dependency on
 * core's admin stylesheet.
 */

export const Card = ( { className = '', children, ...rest } ) => (
	<div
		className={ [ 'sio-card', className ].filter( Boolean ).join( ' ' ) }
		{ ...rest }
	>
		{ children }
	</div>
);

export const CardHeader = ( { className = '', children, ...rest } ) => (
	<div
		className={ [ 'sio-card__header', className ]
			.filter( Boolean )
			.join( ' ' ) }
		{ ...rest }
	>
		{ children }
	</div>
);

export const CardBody = ( { className = '', children, ...rest } ) => (
	<div
		className={ [ 'sio-card__body', className ]
			.filter( Boolean )
			.join( ' ' ) }
		{ ...rest }
	>
		{ children }
	</div>
);

export default Card;
