/**
 * Admin bundle entry point.
 *
 * Mounts the dashboard into the element the admin_app view renders.
 *
 * React comes from WordPress via @wordpress/element, so the plugin ships no
 * React of its own. The UI built on top of it is entirely the plugin's own -
 * no @wordpress/components anywhere.
 */

import { createRoot } from '@wordpress/element';

import App from '../App';

import '../../styles/admin.scss';

const mount = document.getElementById( 'swift-image-optimizer-root' );

if ( mount ) {
	// The view renders a loading state inside the mount; React replaces it.
	createRoot( mount ).render( <App /> );
}
