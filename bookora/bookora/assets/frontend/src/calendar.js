/**
 * Accessible month calendar with month/year selectors.
 * Only days for which `isAvailable(date)` returns true can be chosen.
 */
import { __ } from '@wordpress/i18n';

const pad = ( n ) => String( n ).padStart( 2, '0' );
export const ymd = ( d ) =>
	`${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
export const parseYmd = ( s ) => {
	const [ y, m, d ] = s.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};

function el( tag, attrs = {}, children = [] ) {
	const node = document.createElement( tag );
	Object.entries( attrs ).forEach( ( [ k, v ] ) => {
		if ( v === false || v === null || v === undefined ) {
			return;
		}
		if ( k === 'className' ) {
			node.className = v;
		} else if ( k === 'text' ) {
			node.textContent = v;
		} else if ( k.startsWith( 'on' ) ) {
			node.addEventListener( k.slice( 2 ).toLowerCase(), v );
		} else {
			node.setAttribute( k, v === true ? '' : v );
		}
	} );
	( Array.isArray( children ) ? children : [ children ] ).forEach(
		( c ) => c && node.append( c )
	);
	return node;
}

export { el };

export default class Calendar {
	/**
	 * @param {Object}   opts
	 * @param {Element}  opts.container   Where to render.
	 * @param {string}   opts.locale      BCP 47 locale.
	 * @param {number}   opts.weekStart   0 = Sunday … 6 = Saturday.
	 * @param {Date}     opts.min         First selectable day.
	 * @param {Date}     opts.max         Last selectable day.
	 * @param {Function} opts.loadMonth   ( year, monthIndex ) => Promise resolving when availability is known.
	 * @param {Function} opts.isAvailable ( 'Y-m-d' ) => boolean.
	 * @param {Function} opts.onSelect    ( 'Y-m-d' ) => void.
	 */
	constructor( opts ) {
		this.opts = opts;
		this.selected = null;
		const start = opts.min && opts.min > new Date() ? opts.min : new Date();
		this.view = new Date( start.getFullYear(), start.getMonth(), 1 );
		this.root = el( 'div', { className: 'bookora-cal' } );
		opts.container.append( this.root );
		this.render();
	}

	monthName( m, style = 'long' ) {
		return new Intl.DateTimeFormat( this.opts.locale, {
			month: style,
		} ).format( new Date( 2026, m, 1 ) );
	}

	/**
	 * Arrow keys move between selectable days (←/→ one day, ↑/↓ one week, Home/End first/last day).
	 *
	 * @param {KeyboardEvent} e    Event.
	 * @param {HTMLElement}   grid Grid.
	 */
	onKey( e, grid ) {
		const current = e.target.closest( '.bookora-cal__day' );
		if ( ! current ) {
			return;
		}
		const days = [ ...grid.querySelectorAll( '.bookora-cal__day' ) ];
		const enabled = days.filter( ( d ) => ! d.disabled );
		const index = days.indexOf( current );
		let target = null;
		const seek = ( from, step ) => {
			for ( let i = from; i >= 0 && i < days.length; i += step ) {
				if ( ! days[ i ].disabled ) {
					return days[ i ];
				}
			}
			return null;
		};
		switch ( e.key ) {
			case 'ArrowRight':
				target = seek( index + 1, 1 );
				break;
			case 'ArrowLeft':
				target = seek( index - 1, -1 );
				break;
			case 'ArrowDown':
				target = seek( index + 7, 1 );
				break;
			case 'ArrowUp':
				target = seek( index - 7, -1 );
				break;
			case 'Home':
				target = enabled[ 0 ];
				break;
			case 'End':
				target = enabled[ enabled.length - 1 ];
				break;
			default:
				return;
		}
		e.preventDefault();
		target?.focus();
	}

	weekdayNames() {
		const fmt = new Intl.DateTimeFormat( this.opts.locale, {
			weekday: 'short',
		} );
		// 2026-11-01 is a Sunday.
		return Array.from( { length: 7 }, ( _, i ) =>
			fmt.format(
				new Date( 2026, 10, 1 + ( ( i + this.opts.weekStart ) % 7 ) )
			)
		);
	}

	canGo( delta ) {
		const target = new Date(
			this.view.getFullYear(),
			this.view.getMonth() + delta,
			1
		);
		const { min, max } = this.opts;
		if (
			delta < 0 &&
			min &&
			target < new Date( min.getFullYear(), min.getMonth(), 1 )
		) {
			return false;
		}
		if (
			delta > 0 &&
			max &&
			target > new Date( max.getFullYear(), max.getMonth(), 1 )
		) {
			return false;
		}
		return true;
	}

	go( year, month ) {
		const { min, max } = this.opts;
		let target = new Date( year, month, 1 );
		if (
			min &&
			target < new Date( min.getFullYear(), min.getMonth(), 1 )
		) {
			target = new Date( min.getFullYear(), min.getMonth(), 1 );
		}
		if (
			max &&
			target > new Date( max.getFullYear(), max.getMonth(), 1 )
		) {
			target = new Date( max.getFullYear(), max.getMonth(), 1 );
		}
		this.view = target;
		this.render();
	}

	refresh() {
		this.render();
	}

	select( date ) {
		this.selected = date;
		this.render();
	}

	render() {
		const year = this.view.getFullYear();
		const month = this.view.getMonth();
		const { min, max } = this.opts;
		const minYear = min ? min.getFullYear() : year - 1;
		const maxYear = max ? max.getFullYear() : year + 2;

		const monthSelect = el( 'select', {
			className: 'bookora-cal__month',
			'aria-label': __( 'Month', 'bookora' ),
			onChange: ( e ) => this.go( year, Number( e.target.value ) ),
		} );
		for ( let m = 0; m < 12; m++ ) {
			const first = new Date( year, m, 1 );
			const outside =
				( min && new Date( year, m + 1, 0 ) < min ) ||
				( max && first > max );
			monthSelect.append(
				el( 'option', {
					value: m,
					selected: m === month,
					disabled: !! outside,
					text: this.monthName( m ),
				} )
			);
		}

		const yearSelect = el( 'select', {
			className: 'bookora-cal__year',
			'aria-label': __( 'Year', 'bookora' ),
			onChange: ( e ) => this.go( Number( e.target.value ), month ),
		} );
		for ( let y = minYear; y <= maxYear; y++ ) {
			yearSelect.append(
				el( 'option', {
					value: y,
					selected: y === year,
					text: String( y ),
				} )
			);
		}

		const head = el( 'div', { className: 'bookora-cal__head' }, [
			el( 'button', {
				type: 'button',
				className: 'bookora-cal__nav',
				'aria-label': __( 'Previous month', 'bookora' ),
				disabled: ! this.canGo( -1 ),
				onClick: () => this.go( year, month - 1 ),
				text: '‹',
			} ),
			el( 'div', { className: 'bookora-cal__title' }, [
				monthSelect,
				yearSelect,
			] ),
			el( 'button', {
				type: 'button',
				className: 'bookora-cal__nav',
				'aria-label': __( 'Next month', 'bookora' ),
				disabled: ! this.canGo( 1 ),
				onClick: () => this.go( year, month + 1 ),
				text: '›',
			} ),
		] );

		// Day buttons carry the full date as label; weekday headers are visual only.
		const grid = el( 'div', {
			className: 'bookora-cal__grid',
			'aria-busy': 'true',
		} );
		this.weekdayNames().forEach( ( name ) =>
			grid.append(
				el( 'span', {
					className: 'bookora-cal__weekday',
					'aria-hidden': 'true',
					text: name,
				} )
			)
		);
		grid.addEventListener( 'keydown', ( e ) => this.onKey( e, grid ) );

		const offset =
			( new Date( year, month, 1 ).getDay() - this.opts.weekStart + 7 ) %
			7;
		const days = new Date( year, month + 1, 0 ).getDate();
		for ( let i = 0; i < offset; i++ ) {
			grid.append( el( 'span', { className: 'bookora-cal__blank' } ) );
		}

		const today = ymd( new Date() );
		const buttons = [];
		for ( let d = 1; d <= days; d++ ) {
			const date = new Date( year, month, d );
			const key = ymd( date );
			const btn = el( 'button', {
				type: 'button',
				className: 'bookora-cal__day',
				'data-date': key,
				'aria-label': new Intl.DateTimeFormat( this.opts.locale, {
					weekday: 'long',
					day: 'numeric',
					month: 'long',
					year: 'numeric',
				} ).format( date ),
				'aria-current': key === today ? 'date' : false,
				'aria-pressed': key === this.selected ? 'true' : 'false',
				disabled: true,
				text: String( d ),
				onClick: () => {
					this.select( key );
					this.opts.onSelect( key );
				},
			} );
			buttons.push( { btn, key, date } );
			grid.append( btn );
		}

		const legend = el( 'p', {
			className: 'bookora-cal__status',
			'aria-live': 'polite',
			text: __( 'Loading available days…', 'bookora' ),
		} );

		this.root.replaceChildren( head, grid, legend );

		const token = Symbol( 'render' );
		this.token = token;

		Promise.resolve( this.opts.loadMonth( year, month ) )
			.then( () => {
				if ( this.token !== token ) {
					return;
				}
				let count = 0;
				buttons.forEach( ( { btn, key, date } ) => {
					const inRange =
						( ! min ||
							date >=
								new Date(
									min.getFullYear(),
									min.getMonth(),
									min.getDate()
								) ) &&
						( ! max || date <= max );
					const ok = inRange && this.opts.isAvailable( key );
					btn.disabled = ! ok;
					btn.classList.toggle( 'is-available', ok );
					if ( ok ) {
						count++;
					}
				} );
				grid.setAttribute( 'aria-busy', 'false' );
				legend.textContent = count
					? ''
					: __(
							'No free days in this month. Please choose another month.',
							'bookora'
					  );
				legend.hidden = !! count;
			} )
			.catch( () => {
				if ( this.token === token ) {
					legend.textContent = __(
						'Availability could not be loaded. Please try again.',
						'bookora'
					);
				}
			} );
	}
}
