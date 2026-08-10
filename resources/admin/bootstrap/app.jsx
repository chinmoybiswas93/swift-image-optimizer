/**
 * Admin bundle entry point.
 *
 * Mounts the dashboard into the element the admin_app view renders. React
 * comes from npm rather than WordPress's wp-element, so the plugin's UI does
 * not move when core changes the React version it ships.
 */

import { createRoot } from 'react-dom/client';

import App from '../App';

import '../../styles/admin.scss';

const mount = document.getElementById( 'swift-image-optimizer-root' );

if ( mount ) {
	// The view renders a loading state inside the mount; React replaces it.
	createRoot( mount ).render( <App /> );
}
