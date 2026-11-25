/**
 * Shared helpers for the bookings and entries screens.
 */
import { __ } from '@wordpress/i18n';
import { config, get } from '../api';

export const BOOKING_STATUS = {
	pending: { label: __( 'Pending', 'kalendora' ), tone: 'warning' },
	confirmed: { label: __( 'Confirmed', 'kalendora' ), tone: 'success' },
	rejected: { label: __( 'Rejected', 'kalendora' ), tone: 'error' },
	cancelled: { label: __( 'Cancelled', 'kalendora' ), tone: 'error' },
	completed: { label: __( 'Attended', 'kalendora' ), tone: 'neutral' },
	no_show: { label: __( 'No-show', 'kalendora' ), tone: 'error' },
};

export const ACTION_LABEL = {
	confirmed: __( 'Confirm', 'kalendora' ),
	rejected: __( 'Reject', 'kalendora' ),
	cancelled: __( 'Cancel appointment', 'kalendora' ),
	completed: __( 'Attended', 'kalendora' ),
	no_show: __( 'No-show', 'kalendora' ),
	pending: __( 'Reopen', 'kalendora' ),
};

export function StatusBadge( { status } ) {
	const s = BOOKING_STATUS[ status ] || { label: status, tone: 'neutral' };
	return (
		<span className={ `kalendora-status kalendora-status--${ s.tone }` }>
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
				'#adminmenu a[href$="page=kalendora-bookings"]',
				counts.pending
			);
			set(
				'#adminmenu a[href$="page=kalendora-entries"]',
				counts.new_entries
			);
			set(
				'#adminmenu .toplevel_page_kalendora > a .wp-menu-name',
				counts.pending + counts.new_entries
			);
		} )
		.catch( () => {} );
}

/**
 * Uploaded files and signatures of a form value (download links, signature as image).
 *
 * @param {Object} props       Props.
 * @param {Object} props.value Entry value with optional files.
 */
export function FieldValue( { value } ) {
	const files = value.files || [];
	if ( ! files.length ) {
		return value.value;
	}
	return (
		<ul className="kalendora-files">
			{ files.map( ( f ) =>
				f.kind === 'signature' ? (
					<li key={ f.id }>
						<img
							className="kalendora-files__signature"
							src={ f.url }
							alt={ __( 'Signature', 'kalendora' ) }
						/>
					</li>
				) : (
					<li key={ f.id }>
						<a href={ f.url } target="_blank" rel="noreferrer">
							{ f.name }
						</a>{ ' ' }
						<span className="kalendora-muted">
							{ f.size >= 1048576
								? `${ ( f.size / 1048576 ).toFixed( 1 ) } MB`
								: `${ Math.max(
										1,
										Math.round( f.size / 1024 )
								  ) } KB` }
						</span>
					</li>
				)
			) }
		</ul>
	);
}
