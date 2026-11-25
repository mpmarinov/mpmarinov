/**
 * Kalendora admin app entry.
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import SettingsScreen from './screens/SettingsScreen';
import ServicesScreen from './screens/ServicesScreen';
import ResourcesScreen from './screens/ResourcesScreen';
import HoursScreen from './screens/HoursScreen';
import FormsScreen from './screens/FormsScreen';
import BookingsScreen from './screens/BookingsScreen';
import EntriesScreen from './screens/EntriesScreen';
import EmailsScreen from './screens/EmailsScreen';
import TimelineScreen from './screens/TimelineScreen';
import CalendarsScreen from './screens/CalendarsScreen';
import DashboardScreen from './screens/DashboardScreen';
import IntegrationsScreen from './screens/IntegrationsScreen';
import './builder/builder.scss';
import Placeholder from './screens/Placeholder';
import './style.scss';

const SCREENS = {
	dashboard: DashboardScreen,
	settings: SettingsScreen,
	services: ServicesScreen,
	resources: ResourcesScreen,
	hours: HoursScreen,
	forms: FormsScreen,
	bookings: BookingsScreen,
	entries: EntriesScreen,
	emails: EmailsScreen,
	timeline: TimelineScreen,
	calendars: CalendarsScreen,
	integrations: IntegrationsScreen,
};

const TITLES = {
	dashboard: __( 'Dashboard', 'kalendora' ),
	timeline: __( 'Timeline', 'kalendora' ),
	bookings: __( 'Bookings', 'kalendora' ),
	entries: __( 'Entries', 'kalendora' ),
	forms: __( 'Forms', 'kalendora' ),
	services: __( 'Services', 'kalendora' ),
	resources: __( 'Staff & Resources', 'kalendora' ),
	hours: __( 'Opening Hours', 'kalendora' ),
	emails: __( 'Emails', 'kalendora' ),
	calendars: __( 'Calendar Sync', 'kalendora' ),
	integrations: __( 'Integrations', 'kalendora' ),
	settings: __( 'Settings', 'kalendora' ),
};

function App( { screen } ) {
	const Screen = SCREENS[ screen ] || Placeholder;
	return (
		<div className="kalendora-app">
			<div className="kalendora-app__header">
				<span className="kalendora-app__logo">Kalendora</span>
				<h1 className="kalendora-app__title">
					{ TITLES[ screen ] || '' }
				</h1>
			</div>
			<div className="kalendora-app__main">
				<Screen screen={ screen } />
			</div>
		</div>
	);
}

const root = document.getElementById( 'kalendora-admin-root' );

if ( root ) {
	createRoot( root ).render(
		<App screen={ root.dataset.screen || 'dashboard' } />
	);
}
