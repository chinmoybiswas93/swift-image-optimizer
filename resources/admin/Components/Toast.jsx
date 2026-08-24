/**
 * Toasts.
 *
 * Transient feedback - a save confirmed, an action finished - that should not
 * push the page around or leave the operator with something to dismiss.
 * Anything that stays true until acted upon is a Notice, not a toast.
 *
 * Built on nothing: no @wordpress/components, no toast library. `useToast`
 * exposes a single `push` and the provider owns the timers, so a component
 * that fires one never has to think about cleanup.
 */

import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const ToastContext = createContext( null );

const DEFAULT_DURATION = 5000;

/**
 * Read the toast API.
 *
 * Safe to call outside a provider: it returns a no-op so a component is never
 * brought down by feedback failing to appear.
 *
 * @return {{push: Function, dismiss: Function}} Toast controls.
 */
export const useToast = () => {
	const context = useContext( ToastContext );

	return (
		context || {
			push: () => {},
			dismiss: () => {},
		}
	);
};

export const ToastProvider = ( { children } ) => {
	const [ toasts, setToasts ] = useState( [] );
	const timers = useRef( new Map() );
	const nextId = useRef( 0 );

	const dismiss = useCallback( ( id ) => {
		setToasts( ( current ) =>
			current.filter( ( toast ) => toast.id !== id )
		);

		const timer = timers.current.get( id );

		if ( timer ) {
			clearTimeout( timer );
			timers.current.delete( id );
		}
	}, [] );

	const push = useCallback(
		( message, options = {} ) => {
			if ( ! message ) {
				return null;
			}

			const id = ++nextId.current;
			const status = options.status || 'success';
			const duration =
				typeof options.duration === 'number'
					? options.duration
					: DEFAULT_DURATION;

			setToasts( ( current ) => [ ...current, { id, message, status } ] );

			/*
			 * Errors stay until dismissed. A message explaining why something
			 * failed is not something to take away after five seconds.
			 */
			if ( duration > 0 && status !== 'error' ) {
				timers.current.set(
					id,
					setTimeout( () => dismiss( id ), duration )
				);
			}

			return id;
		},
		[ dismiss ]
	);

	// Clear any pending timers if the whole admin app unmounts.
	useEffect( () => {
		const pending = timers.current;

		return () => {
			pending.forEach( ( timer ) => clearTimeout( timer ) );
			pending.clear();
		};
	}, [] );

	const api = useMemo( () => ( { push, dismiss } ), [ push, dismiss ] );

	return (
		<ToastContext.Provider value={ api }>
			{ children }
			<div className="sio-toasts" aria-live="polite" aria-atomic="false">
				{ toasts.map( ( toast ) => (
					<div
						key={ toast.id }
						className={ `sio-toast sio-toast--${ toast.status }` }
						role={ toast.status === 'error' ? 'alert' : 'status' }
					>
						<span className="sio-toast__body">
							{ toast.message }
						</span>
						<button
							type="button"
							className="sio-toast__dismiss"
							onClick={ () => dismiss( toast.id ) }
							aria-label={ __(
								'Dismiss',
								'swift-image-optimizer'
							) }
						>
							<span aria-hidden="true">&times;</span>
						</button>
					</div>
				) ) }
			</div>
		</ToastContext.Provider>
	);
};

export default ToastProvider;
