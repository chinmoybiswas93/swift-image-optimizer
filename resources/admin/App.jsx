/** Root component: masthead, hero stats and the tab shell. */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Tabs, ToastProvider } from './Components';
import Masthead from './Partials/Masthead';
import HeroStats from './Partials/HeroStats';
import BulkPage from './Pages/BulkPage';
import SettingsPage from './Pages/SettingsPage';
import BackupsPage from './Pages/BackupsPage';
import TroubleshootPage from './Pages/TroubleshootPage';
import config from './Services/config';

const App = () => {
	/*
	 * One snapshot, shared by the hero and the Bulk tab.
	 *
	 * There used to be two pieces of state here - a live mime count and a log
	 * table aggregate - rendered side by side on the same screen with no way to
	 * reconcile them. Everything now reads the last completed scan, so a figure
	 * in the hero and a figure in a tile cannot contradict each other.
	 *
	 * null means the library has never been scanned; the UI says so rather than
	 * showing a zero it cannot stand behind.
	 */
	const [ snapshot, setSnapshot ] = useState( config.snapshot || null );

	// Settings live here rather than in the Settings tab: the Troubleshoot tab
	// also writes one of them, and both must send the complete object.
	const [ settings, setSettings ] = useState( config.settings || {} );

	return (
		<ToastProvider>
		<div className="sio-app">
			<Masthead />
			<HeroStats snapshot={ snapshot } />

			<Tabs
				tabs={ [
					{ name: 'bulk', title: __( 'Bulk Optimize', 'swift-image-optimizer' ) },
					{ name: 'settings', title: __( 'Settings', 'swift-image-optimizer' ) },
					{ name: 'backups', title: __( 'Backups', 'swift-image-optimizer' ) },
					{ name: 'troubleshoot', title: __( 'Troubleshoot', 'swift-image-optimizer' ) },
				] }
			>
				{ ( tab ) => {
					if ( tab.name === 'settings' ) {
						return <SettingsPage values={ settings } setValues={ setSettings } />;
					}
					if ( tab.name === 'backups' ) return <BackupsPage />;
					if ( tab.name === 'troubleshoot' ) {
						return <TroubleshootPage values={ settings } setValues={ setSettings } />;
					}
					return <BulkPage snapshot={ snapshot } setSnapshot={ setSnapshot } />;
				} }
			</Tabs>
		</div>
		</ToastProvider>
	);
};

export default App;
