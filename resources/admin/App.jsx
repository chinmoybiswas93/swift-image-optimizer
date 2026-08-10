/** Root component: masthead, hero stats and the tab shell. */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Tabs } from './Components';
import Masthead from './Partials/Masthead';
import HeroStats from './Partials/HeroStats';
import BulkPage from './Pages/BulkPage';
import SettingsPage from './Pages/SettingsPage';
import BackupsPage from './Pages/BackupsPage';
import TroubleshootPage from './Pages/TroubleshootPage';
import config from './Services/config';

const App = () => {
	const [ summary, setSummary ] = useState( config.summary || {} );
	const [ stats, setStats ] = useState( config.stats || {} );

	// Settings live here rather than in the Settings tab: the Troubleshoot tab
	// also writes one of them, and both must send the complete object.
	const [ settings, setSettings ] = useState( config.settings || {} );

	return (
		<div className="sio-app">
			<Masthead />
			<HeroStats stats={ stats } />

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
					return (
						<BulkPage
							summary={ summary }
							setSummary={ setSummary }
							setStats={ setStats }
						/>
					);
				} }
			</Tabs>
		</div>
	);
};

export default App;
