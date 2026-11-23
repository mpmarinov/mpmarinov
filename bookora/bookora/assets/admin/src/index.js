/**
 * Bookora admin app entry.
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
import './builder/builder.scss';
import Placeholder from './screens/Placeholder';
import './style.scss';

const SCREENS = {
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
};

const TITLES = {
	dashboard: __( 'Dashboard', 'bookora' ),
	timeline: __( 'Timeline', 'bookora' ),
	bookings: __( 'Bookings', 'bookora' ),
	entries: __( 'Entries', 'bookora' ),
	forms: __( 'Forms', 'bookora' ),
	services: __( 'Services', 'bookora' ),
	resources: __( 'Staff & Resources', 'bookora' ),
	hours: __( 'Opening Hours', 'bookora' ),
	emails: __( 'Emails', 'bookora' ),
	calendars: __( 'Calendar Sync', 'bookora' ),
	settings: __( 'Settings', 'bookora' ),
};

function App( { screen } ) {
	const Screen = SCREENS[ screen ] || Placeholder;
	return (
		<div className="bookora-app">
			<header className="bookora-app__header">
				<span className="bookora-app__logo">Bookora</span>
				<h1 className="bookora-app__title">
					{ TITLES[ screen ] || '' }
				</h1>
			</header>
			<main className="bookora-app__main">
				<Screen screen={ screen } />
			</main>
		</div>
	);
}

const root = document.getElementById( 'bookora-admin-root' );

if ( root ) {
	createRoot( root ).render(
		<App screen={ root.dataset.screen || 'dashboard' } />
	);
}
