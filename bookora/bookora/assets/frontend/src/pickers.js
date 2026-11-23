/**
 * Appointment picker (real availability) and date picker (opening days).
 */
import { __, sprintf } from '@wordpress/i18n';
import Calendar, { el, parseYmd, ymd } from './calendar';
import apiUrl from './api';

function fetchJson( url ) {
	return window
		.fetch( url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} )
		.then( ( r ) => {
			if ( ! r.ok ) {
				return r
					.json()
					.then( ( body ) =>
						Promise.reject(
							new Error( body?.message || r.statusText )
						)
					);
			}
			return r.json();
		} );
}

function monthRange( year, month ) {
	return [
		ymd( new Date( year, month, 1 ) ),
		ymd( new Date( year, month + 1, 0 ) ),
	];
}

function maxDate( maxAdvance ) {
	const d = new Date();
	d.setDate( d.getDate() + ( maxAdvance > 0 ? maxAdvance : 365 ) );
	return d;
}

function formatTime( time, config ) {
	if ( config.timeFormat !== '12h' ) {
		return time;
	}
	const [ h, m ] = time.split( ':' ).map( Number );
	return `${ ( ( h + 11 ) % 12 ) + 1 }:${ String( m ).padStart( 2, '0' ) } ${
		h < 12 ? 'AM' : 'PM'
	}`;
}

function longDate( key, locale ) {
	return new Intl.DateTimeFormat( locale, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( parseYmd( key ) );
}

/**
 * Booking appointment: calendar + free time slots from /availability.
 */
export class AppointmentPicker {
	constructor( { field, config, getService, getResource, onChange } ) {
		this.field = field;
		this.config = config;
		this.getService = getService;
		this.getResource = getResource;
		this.onChange = onChange;
		this.cache = new Map();
		this.selectedDay = null;

		this.container = field.querySelector( '[data-role="appointment"]' );
		this.inputStart = field.querySelector(
			'[data-role="appointment-start"]'
		);
		this.inputResource = field.querySelector(
			'[data-role="appointment-resource"]'
		);

		this.hint = el( 'p', {
			className: 'bookora__hint',
			text: __( 'Please choose a service first.', 'bookora' ),
		} );
		this.calWrap = el( 'div', { className: 'bookora-appt__cal' } );
		this.slotsWrap = el( 'div', {
			className: 'bookora-appt__slots',
			'aria-live': 'polite',
		} );
		this.summary = el( 'p', {
			className: 'bookora-appt__summary',
			hidden: true,
		} );
		this.container.append(
			this.hint,
			el( 'div', { className: 'bookora-appt' }, [
				this.calWrap,
				this.slotsWrap,
			] ),
			this.summary
		);

		this.calendar = new Calendar( {
			container: this.calWrap,
			locale: config.locale,
			weekStart: config.weekStart,
			min: new Date(),
			max: maxDate( config.maxAdvance ),
			loadMonth: ( y, m ) => this.load( y, m ),
			isAvailable: ( key ) => ( this.days()?.[ key ] || [] ).length > 0,
			onSelect: ( key ) => this.showSlots( key ),
		} );

		this.reset();
	}

	key( year, month ) {
		return [ this.getService(), this.getResource() || 0, year, month ].join(
			'|'
		);
	}

	days() {
		return this.currentDays || {};
	}

	load( year, month ) {
		const service = this.getService();
		this.hint.hidden = !! service;
		if ( ! service ) {
			this.currentDays = {};
			return Promise.resolve();
		}
		const key = this.key( year, month );
		if ( ! this.cache.has( key ) ) {
			const [ from, to ] = monthRange( year, month );
			const params = { service, from, to, tz: this.config.timezone };
			if ( this.getResource() ) {
				params.resource = this.getResource();
			}
			this.cache.set(
				key,
				fetchJson(
					apiUrl( this.config.rest, '/availability', params )
				).then( ( res ) => res.days || {} )
			);
		}
		return this.cache.get( key ).then( ( days ) => {
			this.currentDays = days;
		} );
	}

	/** Called when service or staff member changes. */
	reset() {
		this.cache.clear();
		this.selectedDay = null;
		this.setValue( null );
		this.slotsWrap.replaceChildren(
			el( 'p', {
				className: 'bookora__muted',
				text: __( 'Choose a day to see the free times.', 'bookora' ),
			} )
		);
		this.calendar.selected = null;
		this.calendar.refresh();
	}

	showSlots( key ) {
		this.selectedDay = key;
		this.setValue( null );
		const slots = this.days()[ key ] || [];
		const title = el( 'p', {
			className: 'bookora-appt__day',
			text: longDate( key, this.config.locale ),
		} );
		const list = el( 'div', {
			className: 'bookora-appt__times',
			role: 'listbox',
			'aria-label': __( 'Available times', 'bookora' ),
		} );

		slots.forEach( ( slot ) => {
			const btn = el( 'button', {
				type: 'button',
				role: 'option',
				'aria-selected': 'false',
				className: 'bookora-appt__time',
				text: formatTime( slot.time, this.config ),
				onClick: () => {
					list.querySelectorAll( '.bookora-appt__time' ).forEach(
						( b ) => b.setAttribute( 'aria-selected', 'false' )
					);
					btn.setAttribute( 'aria-selected', 'true' );
					this.setValue( slot );
				},
			} );
			if ( slot.available > 1 ) {
				btn.append(
					el( 'small', {
						text: sprintf(
							/* translators: %d: free places. */
							__( '%d free', 'bookora' ),
							slot.available
						),
					} )
				);
			}
			list.append( btn );
		} );

		this.slotsWrap.replaceChildren(
			title,
			slots.length
				? list
				: el( 'p', {
						className: 'bookora__muted',
						text: __( 'No free times on this day.', 'bookora' ),
				  } )
		);
		list.querySelector( 'button' )?.focus( { preventScroll: true } );
	}

	setValue( slot ) {
		this.inputStart.value = slot ? slot.start : '';
		this.inputResource.value =
			slot && slot.resources?.length === 1 ? slot.resources[ 0 ] : '';
		if ( slot ) {
			this.summary.hidden = false;
			this.summary.textContent = sprintf(
				/* translators: 1: date, 2: time. */
				__( 'Selected: %1$s at %2$s', 'bookora' ),
				longDate( this.selectedDay, this.config.locale ),
				formatTime( slot.time, this.config )
			);
		} else {
			this.summary.hidden = true;
		}
		this.onChange();
	}

	value() {
		return this.inputStart.value;
	}

	/** After a failed booking (slot taken) reload the current month. */
	reload() {
		this.reset();
	}
}

/**
 * Date field: opens a calendar; optionally only working days and times within opening hours.
 */
export class DatePicker {
	constructor( { field, def, config, onChange } ) {
		this.def = def;
		this.config = config;
		this.onChange = onChange;
		this.opening = new Map();
		this.days = {};

		const native = field.querySelector( 'input[data-picker]' );
		this.hidden = el( 'input', { type: 'hidden', name: native.name } );
		this.display = el( 'button', {
			type: 'button',
			id: native.id,
			className: 'bookora-date__display',
			'aria-haspopup': 'dialog',
			'aria-expanded': 'false',
			'aria-describedby': native.getAttribute( 'aria-describedby' ),
			text: native.placeholder,
			onClick: () => this.toggle(),
		} );
		this.placeholder = native.placeholder;
		this.panel = el( 'div', {
			className: 'bookora-date__panel',
			hidden: true,
		} );
		this.times = el( 'div', {
			className: 'bookora-appt__times bookora-date__times',
		} );
		native.replaceWith(
			el( 'div', { className: 'bookora-date' }, [
				this.display,
				this.hidden,
				this.panel,
			] )
		);

		const min = def.minDate ? this.relative( def.minDate ) : null;
		let max = def.workingDaysOnly ? maxDate( config.maxAdvance ) : null;
		if ( def.maxDate ) {
			max = this.relative( def.maxDate );
		}

		this.calendar = new Calendar( {
			container: this.panel,
			locale: config.locale,
			weekStart: config.weekStart,
			min: min || ( def.workingDaysOnly ? new Date() : null ),
			max,
			loadMonth: ( y, m ) => this.load( y, m ),
			isAvailable: ( key ) =>
				! def.workingDaysOnly || ( this.days[ key ] || [] ).length > 0,
			onSelect: ( key ) => this.pickDay( key ),
		} );
		this.panel.append( this.times );

		document.addEventListener( 'click', ( e ) => {
			if ( ! this.panel.hidden && ! e.composedPath().includes( field ) ) {
				this.toggle( false );
			}
		} );
		field.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' && ! this.panel.hidden ) {
				this.toggle( false );
				this.display.focus();
			}
		} );
	}

	relative( expr ) {
		if ( /^\d{4}-\d{2}-\d{2}$/.test( expr ) ) {
			return parseYmd( expr );
		}
		const d = new Date();
		const m = expr.match( /^([+-]\d+) (day|week|month|year)s?$/ );
		if ( m ) {
			const n = Number( m[ 1 ] );
			if ( m[ 2 ] === 'day' ) {
				d.setDate( d.getDate() + n );
			} else if ( m[ 2 ] === 'week' ) {
				d.setDate( d.getDate() + 7 * n );
			} else if ( m[ 2 ] === 'month' ) {
				d.setMonth( d.getMonth() + n );
			} else {
				d.setFullYear( d.getFullYear() + n );
			}
		}
		return d;
	}

	load( year, month ) {
		if ( ! this.def.workingDaysOnly ) {
			return Promise.resolve();
		}
		const key = `${ year }-${ month }`;
		if ( ! this.opening.has( key ) ) {
			const [ from, to ] = monthRange( year, month );
			this.opening.set(
				key,
				fetchJson(
					apiUrl( this.config.rest, '/public/opening', { from, to } )
				).then( ( r ) => r.days || {} )
			);
		}
		return this.opening.get( key ).then( ( days ) => {
			Object.assign( this.days, days );
		} );
	}

	toggle( open = this.panel.hidden ) {
		this.panel.hidden = ! open;
		this.display.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			this.panel
				.querySelector(
					'.bookora-cal__day.is-available, .bookora-cal__nav'
				)
				?.focus( { preventScroll: true } );
		}
	}

	pickDay( key ) {
		if ( ! this.def.withTime ) {
			this.set( key, longDate( key, this.config.locale ) );
			this.toggle( false );
			this.display.focus();
			return;
		}
		this.set( '', this.placeholder );
		const times = this.days[ key ] || [];
		this.times.replaceChildren(
			el( 'p', {
				className: 'bookora-appt__day',
				text: longDate( key, this.config.locale ),
			} ),
			...times.map( ( time ) =>
				el( 'button', {
					type: 'button',
					className: 'bookora-appt__time',
					text: formatTime( time, this.config ),
					onClick: () => {
						this.set(
							`${ key } ${ time }`,
							sprintf(
								/* translators: 1: date, 2: time. */
								__( '%1$s at %2$s', 'bookora' ),
								longDate( key, this.config.locale ),
								formatTime( time, this.config )
							)
						);
						this.toggle( false );
						this.display.focus();
					},
				} )
			)
		);
	}

	set( value, label ) {
		this.hidden.value = value;
		this.display.textContent = label;
		this.display.classList.toggle( 'has-value', !! value );
		this.onChange();
	}
}
