/** One icon + figure pair in the hero strip. */

const Metric = ( { icon, tone, value, label } ) => (
	<div className="sio-metric">
		<span className={ `sio-metric__icon is-${ tone }` }>{ icon }</span>
		<div className="sio-metric__value">{ value }</div>
		<div className="sio-metric__label">{ label }</div>
	</div>
);

export default Metric;
