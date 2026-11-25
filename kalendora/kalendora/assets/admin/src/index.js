/**
 * Kalendora admin app entry.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
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

/**
 * Screen slug from an admin URL ("admin.php?page=kalendora-bookings" → "bookings").
 *
 * @param {string} href URL.
 * @return {string|null} Screen or null when the URL is not a Kalendora screen.
 */
function screenFromUrl( href ) {
	let url;
	try {
		url = new URL( href, window.location.href );
	} catch ( e ) {
		return null;
	}
	if (
		url.origin !== window.location.origin ||
		url.pathname !== window.location.pathname
	) {
		return null;
	}
	const page = url.searchParams.get( 'page' ) || '';
	if ( 'kalendora' === page ) {
		return 'dashboard';
	}
	const slug = page.startsWith( 'kalendora-' ) ? page.slice( 10 ) : '';
	return SCREENS[ slug ] ? slug : null;
}

/**
 * Highlight the current entry in the WordPress admin menu and set the tab title.
 *
 * @param {string} screen Screen.
 */
function syncChrome( screen ) {
	const page = 'dashboard' === screen ? 'kalendora' : 'kalendora-' + screen;
	document
		.querySelectorAll( '#toplevel_page_kalendora .wp-submenu li' )
		.forEach( ( li ) => {
			const a = li.querySelector( 'a' );
			const on =
				!! a &&
				new URL( a.href, window.location.href ).searchParams.get(
					'page'
				) === page;
			li.classList.toggle( 'current', on );
			a?.classList.toggle( 'current', on );
			if ( a ) {
				if ( on ) {
					a.setAttribute( 'aria-current', 'page' );
				} else {
					a.removeAttribute( 'aria-current' );
				}
			}
		} );
	const parts = document.title.split( ' ‹ ' );
	if ( parts.length > 1 && TITLES[ screen ] ) {
		parts[ 0 ] = TITLES[ screen ];
		document.title = parts.join( ' ‹ ' );
	}
}

/**
 * Switch between Kalendora screens without reloading the whole admin page
 * (each WordPress page load costs 1–2 s on a typical host with many plugins).
 *
 * @param {string} initial Screen from the server.
 * @return {string} Current screen.
 */
function useScreen( initial ) {
	const [ screen, setScreen ] = useState( initial );

	useEffect( () => {
		const go = ( href ) => {
			const next = screenFromUrl( href );
			// Screens with unsaved changes can veto (they ask the user).
			const ask = new window.CustomEvent( 'kalendora:navigate', {
				cancelable: true,
			} );
			if ( ! next || ! window.dispatchEvent( ask ) ) {
				return false;
			}
			window.history.pushState( { kalendora: next }, '', href );
			setScreen( next );
			syncChrome( next );
			window.scrollTo( 0, 0 );
			// Screens that read the URL themselves (forms) follow along.
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
			return true;
		};
		const onClick = ( e ) => {
			const a = e.target.closest?.( 'a[href]' );
			if (
				! a ||
				e.defaultPrevented ||
				e.button !== 0 ||
				e.metaKey ||
				e.ctrlKey ||
				e.shiftKey ||
				e.altKey ||
				( a.target && a.target !== '_self' ) ||
				a.hasAttribute( 'download' ) ||
				! screenFromUrl( a.href )
			) {
				return;
			}
			e.preventDefault();
			if ( go( a.href ) ) {
				a.blur();
			}
		};
		const onPop = () => {
			const next = screenFromUrl( window.location.href );
			if ( next ) {
				setScreen( next );
				syncChrome( next );
			}
		};
		document.addEventListener( 'click', onClick );
		window.addEventListener( 'popstate', onPop );
		return () => {
			document.removeEventListener( 'click', onClick );
			window.removeEventListener( 'popstate', onPop );
		};
	}, [] );

	return screen;
}

function App( { initial } ) {
	const screen = useScreen( initial );
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
				<Screen key={ screen } screen={ screen } />
			</div>
		</div>
	);
}

const root = document.getElementById( 'kalendora-admin-root' );

if ( root ) {
	createRoot( root ).render(
		<App initial={ root.dataset.screen || 'dashboard' } />
	);
}
