/** One diagnostics row: label, value and, when something is wrong, the remedy. */

const DiagnosticRow = ( { row } ) => (
	<div className={ `sio-diagnostics__row is-${ row.state }` }>
		<span className="sio-diagnostics__label">{ row.label }</span>
		<span className="sio-diagnostics__value">
			{ row.value }
			{ row.hint && (
				<span className="sio-diagnostics__hint">{ row.hint }</span>
			) }
		</span>
		<span
			className={ `sio-diagnostics__state is-${ row.state }` }
			aria-hidden="true"
		/>
	</div>
);

export default DiagnosticRow;
