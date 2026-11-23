/**
 * Shared helpers for the bookings and entries screens.
 */
import { __ } from '@wordpress/i18n';
import { config, get } from '../api';

export const BOOKING_STATUS = {
	pending: { label: __( 'Pending', 'bookora' ), tone: 'warning' },
	confirmed: { label: __( 'Confirmed', 'bookora' ), tone: 'success' },
	rejected: { label: __( 'Rejected', 'bookora' ), tone: 'error' },
	cancelled: { label: __( 'Cancelled', 'bookora' ), tone: 'error' },
	completed: { label: __( 'Attended', 'bookora' ), tone: 'neutral' },
	no_show: { label: __( 'No-show', 'bookora' ), tone: 'error' },
};

export const ACTION_LABEL = {
	confirmed: __( 'Confirm', 'bookora' ),
	rejected: __( 'Reject', 'bookora' ),
	cancelled: __( 'Cancel appointment', 'bookora' ),
	completed: __( 'Attended', 'bookora' ),
	no_show: __( 'No-show', 'bookora' ),
	pending: __( 'Reopen', 'bookora' ),
};

export function StatusBadge( { status } ) {
	const s = BOOKING_STATUS[ status ] || { label: status, tone: 'neutral' };
	return (
		<span className={ `bookora-status bookora-status--${ s.tone }` }>
			{ s.label }
		</span>
	);
}

const locale = () => config.locale || undefined;

export function formatDay( ymd ) {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return new Intl.DateTimeFormat( locale(), {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( new Date( y, m - 1, d ) );
}

export function formatDateTime( utcMysql ) {
	const date = new Date( utcMysql.replace( ' ', 'T' ) + 'Z' );
	return new Intl.DateTimeFormat( locale(), {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( date );
}

/**
 * Refresh the count bubbles in the WordPress admin menu.
 */
export function refreshBadges() {
	get( '/badges' )
		.then( ( counts ) => {
			const set = ( selector, n ) => {
				const link = document.querySelector( selector );
				if ( ! link ) {
					return;
				}
				let bubble = link.querySelector( '.awaiting-mod' );
				if ( ! n ) {
					bubble?.remove();
					return;
				}
				if ( ! bubble ) {
					bubble = document.createElement( 'span' );
					bubble.className = 'awaiting-mod';
					bubble.innerHTML = '<span class="pending-count"></span>';
					link.append( ' ', bubble );
				}
				bubble.className = `awaiting-mod count-${ n }`;
				bubble.querySelector( '.pending-count' ).textContent =
					String( n );
			};
			set(
				'#adminmenu a[href$="page=bookora-bookings"]',
				counts.pending
			);
			set(
				'#adminmenu a[href$="page=bookora-entries"]',
				counts.new_entries
			);
			set(
				'#adminmenu .toplevel_page_bookora > a .wp-menu-name',
				counts.pending + counts.new_entries
			);
		} )
		.catch( () => {} );
}
