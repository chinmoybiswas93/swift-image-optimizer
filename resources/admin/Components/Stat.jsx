/** A single headline figure. */

const Stat = ( { label, value, tone } ) => (
	<div className={ `sio-stat${ tone ? ` is-${ tone }` : '' }` }>
		<div className="sio-stat__value">{ value }</div>
		<div className="sio-stat__label">{ label }</div>
	</div>
);

export default Stat;
