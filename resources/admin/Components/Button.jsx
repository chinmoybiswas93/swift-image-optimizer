/**
 * Button.
 *
 * Replaces @wordpress/components Button with the same prop surface, so the
 * call sites did not have to change: variant, isDestructive, isBusy, disabled,
 * href, className, onClick.
 */

import Spinner from './Spinner';

const Button = ( {
	variant = 'secondary',
	isDestructive = false,
	isBusy = false,
	disabled = false,
	href,
	className = '',
	children,
	...rest
} ) => {
	const classes = [
		'sio-btn',
		`sio-btn--${ variant }`,
		isDestructive ? 'is-destructive' : '',
		isBusy ? 'is-busy' : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	// An anchor cannot be disabled, so a disabled link renders as a button
	// that does nothing rather than a link the user can still follow.
	if ( href && ! disabled ) {
		return (
			<a className={ classes } href={ href } { ...rest }>
				{ children }
			</a>
		);
	}

	return (
		<button
			type="button"
			className={ classes }
			disabled={ disabled || isBusy }
			aria-busy={ isBusy || undefined }
			{ ...rest }
		>
			{ isBusy && <Spinner /> }
			{ children }
		</button>
	);
};

export default Button;
