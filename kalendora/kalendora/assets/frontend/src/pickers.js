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

function formatNumber( value, decimals, config ) {
	const [ int, frac ] = Number( value ).toFixed( decimals ).split( '.' );
	const grouped = int.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		config.thousands ?? ','
	);
	return frac ? grouped + ( config.decimal ?? '.' ) + frac : grouped;
}

function monthRange( year, month, pad = 0 ) {
	return [
		ymd( new Date( year, month, 1 - pad ) ),
		ymd( new Date( year, month + 1, pad ) ),
	];
}

/** Time zone of the visitor's device, or '' if unknown. */
function visitorTimezone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch ( e ) {
		return '';
	}
}

/**
 * Do two time zones show different wall-clock times (now or in half a year)?
 * Berlin and Paris are the same for our purpose; Berlin and London are not.
 *
 * @param {string} a Time zone.
 * @param {string} b Time zone.
 * @return {boolean} True if they differ.
 */
export function zonesDiffer( a, b ) {
	if ( ! a || ! b || a === b ) {
		return false;
	}
	try {
		const now = Date.now();
		return [ now, now + 182 * 86400000 ].some( ( t ) => {
			const opts = {
				hour: '2-digit',
				minute: '2-digit',
				hourCycle: 'h23',
			};
			return (
				new Intl.DateTimeFormat( 'en', {
					...opts,
					timeZone: a,
				} ).format( t ) !==
				new Intl.DateTimeFormat( 'en', {
					...opts,
					timeZone: b,
				} ).format( t )
			);
		} );
	} catch ( e ) {
		return false;
	}
}

/**
 * "America/New_York" → "New York".
 *
 * @param {string} tz Time zone.
 * @return {string} City.
 */
export function tzCity( tz ) {
	return String( tz ).split( '/' ).pop().replace( /_/g, ' ' );
}

/**
 * H:i of an ISO timestamp in a time zone.
 *
 * @param {string} iso ISO timestamp.
 * @param {string} tz  Time zone.
 * @return {string} Time.
 */
function timeIn( iso, tz ) {
	try {
		return new Intl.DateTimeFormat( 'en-GB', {
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23',
			timeZone: tz,
		} ).format( new Date( iso ) );
	} catch ( e ) {
		return '';
	}
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
	constructor( { field, config, getService, getResource, onChange, onDay } ) {
		this.field = field;
		this.config = config;
		this.getService = getService;
		this.getResource = getResource;
		this.onChange = onChange;
		this.onDay = onDay || ( () => {} );
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
			className: 'kalendora__hint',
			text: __( 'Please choose a service first.', 'kalendora' ),
		} );
		this.calWrap = el( 'div', { className: 'kalendora-appt__cal' } );
		this.slotsWrap = el( 'div', {
			className: 'kalendora-appt__slots',
			'aria-live': 'polite',
		} );
		this.summary = el( 'p', {
			className: 'kalendora-appt__summary',
			hidden: true,
		} );
		// Visitor in another time zone: let them choose in which time the slots are shown.
		this.businessTz = config.timezone;
		this.tz = config.timezone;
		const own = visitorTimezone();
		this.tzSwitch = null;
		if ( zonesDiffer( this.businessTz, own ) ) {
			this.tz = config.tzMode === 'visitor' ? own : this.businessTz;
			const name = `kalendora-tz-${ config.id }-${
				field.dataset.key || 'a'
			}`;
			const option = ( value, text ) => {
				const input = el( 'input', {
					type: 'radio',
					name,
					value,
					checked: this.tz === value,
					onChange: () => this.setTimezone( value ),
				} );
				return el( 'label', { className: 'kalendora-tz__option' }, [
					input,
					el( 'span', { text } ),
				] );
			};
			this.tzSwitch = el( 'fieldset', { className: 'kalendora-tz' }, [
				el( 'legend', {
					text: __( 'Show times in', 'kalendora' ),
				} ),
				option(
					this.businessTz,
					sprintf(
						/* translators: %s: city of the business, e.g. "Berlin". */
						__( 'local time of the business (%s)', 'kalendora' ),
						tzCity( this.businessTz )
					)
				),
				option(
					own,
					sprintf(
						/* translators: %s: city of the visitor's time zone, e.g. "New York". */
						__( 'my time (%s)', 'kalendora' ),
						tzCity( own )
					)
				),
			] );
		}

		// Where the appointment takes place (customer address, office, remote).
		const base = this.inputStart.name.replace( /\[start\]$/, '' );
		this.placeName = base;
		this.placeBox = el( 'div', {
			className: 'kalendora-place',
			hidden: true,
		} );
		this.placeInfo = el( 'p', {
			className: 'kalendora-place__info',
			'aria-live': 'polite',
			hidden: true,
		} );
		this.placeService = null;
		this.place = '';
		this.address = '';

		this.container.append(
			this.placeBox,
			this.placeInfo,
			...( this.tzSwitch ? [ this.tzSwitch ] : [] ),
			this.hint,
			el( 'div', { className: 'kalendora-appt' }, [
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

	/**
	 * Location choice and address input for the selected service.
	 */
	renderPlace() {
		const service = this.getService();
		if ( service === this.placeService ) {
			return;
		}
		this.placeService = service;
		const cfg = this.config.places?.[ service ];
		this.placeBox.replaceChildren();
		this.placeInfo.hidden = true;
		this.placeBox.hidden = ! cfg;
		if ( ! cfg ) {
			this.place = '';
			this.address = '';
			return;
		}
		if ( ! cfg.options.includes( this.place ) ) {
			this.place = cfg.options[ 0 ];
		}

		const address = el( 'input', {
			type: 'text',
			name: `${ this.placeName }[address]`,
			id: `${ this.placeName }-address`.replace( /[^\w-]/g, '-' ),
			className: 'kalendora__input',
			autocomplete: 'street-address',
			placeholder: __( 'Street and no., postcode, town', 'kalendora' ),
			value: this.address,
		} );
		let timer = 0;
		const apply = () => {
			window.clearTimeout( timer );
			const next = address.value.trim();
			if ( next !== this.address ) {
				this.address = next;
				this.reset();
			}
		};
		address.addEventListener( 'input', () => {
			window.clearTimeout( timer );
			timer = window.setTimeout( apply, 900 );
		} );
		address.addEventListener( 'change', apply );
		address.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				apply();
			}
		} );
		const addressRow = el(
			'div',
			{ className: 'kalendora-place__address' },
			[
				el( 'label', {
					for: address.id,
					className: 'kalendora__label',
					text: __( 'Address of the appointment', 'kalendora' ),
				} ),
				address,
			]
		);
		const note = el( 'p', { className: 'kalendora__muted' } );
		const update = () => {
			addressRow.hidden = this.place !== 'onsite';
			if ( this.place === 'office' ) {
				note.textContent = [ cfg.office, cfg.info ]
					.filter( Boolean )
					.join( ' – ' );
			} else if ( this.place === 'remote' ) {
				note.textContent = __(
					'You will receive the access details by email.',
					'kalendora'
				);
			} else {
				note.textContent = '';
			}
			note.hidden = ! note.textContent;
		};

		if ( cfg.options.length > 1 ) {
			const name = `${ this.placeName }[place]`;
			this.placeBox.append(
				el( 'fieldset', { className: 'kalendora-place__choice' }, [
					el( 'legend', {
						className: 'kalendora__label',
						text: __(
							'Where should the appointment take place?',
							'kalendora'
						),
					} ),
					...cfg.options.map( ( type ) =>
						el( 'label', { className: 'kalendora-place__option' }, [
							el( 'input', {
								type: 'radio',
								name,
								value: type,
								checked: type === this.place,
								onChange: () => {
									this.place = type;
									update();
									this.reset();
								},
							} ),
							el( 'span', { text: cfg.labels[ type ] } ),
						] )
					),
				] )
			);
		} else {
			this.placeBox.append(
				el( 'input', {
					type: 'hidden',
					name: `${ this.placeName }[place]`,
					value: this.place,
				} ),
				el( 'p', {
					className: 'kalendora-place__type',
					text: cfg.labels[ this.place ],
				} )
			);
		}
		this.placeBox.append( addressRow, note );
		update();
	}

	/**
	 * Travel time, distance and costs from the availability answer.
	 *
	 * @param {Object|null} place Place info.
	 */
	showTravel( place ) {
		this.placeInfo.classList.remove( 'is-error' );
		const parts = [];
		if ( place && place.type === 'onsite' ) {
			if ( place.travel > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: minutes. */
						__( 'Travel time approx. %d min', 'kalendora' ),
						place.travel
					)
				);
			}
			if ( place.km > 0 ) {
				parts.push(
					sprintf(
						/* translators: %s: distance in km. */
						__( '%s km', 'kalendora' ),
						formatNumber( place.km, 1, this.config )
					)
				);
			}
			if ( place.cost > 0 ) {
				parts.push(
					sprintf(
						/* translators: %s: amount with currency. */
						__( 'Travel costs %s', 'kalendora' ),
						formatNumber( place.cost, 2, this.config ) +
							' ' +
							( this.config.currency || '€' )
					)
				);
			}
			if ( place.note ) {
				parts.push( place.note );
			}
		}
		this.placeInfo.textContent = parts.join( ' · ' );
		this.placeInfo.hidden = ! parts.length;
	}

	/**
	 * Switch the time zone the slots are shown in.
	 *
	 * @param {string} tz Time zone.
	 */
	setTimezone( tz ) {
		this.tz = tz;
		this.reset();
	}

	key( year, month ) {
		return [
			this.tz,
			this.getService(),
			this.getResource() || 0,
			this.place,
			this.address,
			year,
			month,
		].join( '|' );
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
		this.renderPlace();
		if ( this.place === 'onsite' && this.address.length < 5 ) {
			this.hint.hidden = false;
			this.hint.textContent = __(
				'Please enter the address first – then the free times are shown.',
				'kalendora'
			);
			this.currentDays = {};
			return Promise.resolve();
		}
		this.hint.textContent = __(
			'Please choose a service first.',
			'kalendora'
		);
		const key = this.key( year, month );
		if ( ! this.cache.has( key ) ) {
			// In another time zone, days at the month edge can shift by one.
			const [ from, to ] = monthRange(
				year,
				month,
				this.tz === this.businessTz ? 0 : 1
			);
			const params = { service, from, to, tz: this.tz };
			if ( this.getResource() ) {
				params.resource = this.getResource();
			}
			if ( this.place ) {
				params.place = this.place;
				params.address = this.address;
			}
			this.cache.set(
				key,
				fetchJson(
					apiUrl( this.config.rest, '/availability', params )
				).then( ( res ) => {
					this.showTravel( res.place );
					return res.days || {};
				} )
			);
		}
		return this.cache.get( key ).then(
			( days ) => {
				this.currentDays = days;
			},
			( error ) => {
				// E.g. address outside the service area.
				this.cache.delete( key );
				this.currentDays = {};
				this.placeInfo.hidden = false;
				this.placeInfo.classList.add( 'is-error' );
				this.placeInfo.textContent = error.message;
			}
		);
	}

	/** Called when service or staff member changes. */
	reset() {
		this.cache.clear();
		this.selectedDay = null;
		this.setValue( null );
		this.slotsWrap.replaceChildren(
			el( 'p', {
				className: 'kalendora__muted',
				text: __( 'Choose a day to see the free times.', 'kalendora' ),
			} )
		);
		this.calendar.selected = null;
		this.calendar.refresh();
	}

	showSlots( key ) {
		this.selectedDay = key;
		this.setValue( null );
		this.onDay( key );
		const slots = this.days()[ key ] || [];
		const title = el( 'p', {
			className: 'kalendora-appt__day',
			text: longDate( key, this.config.locale ),
		} );
		const list = el( 'div', {
			className: 'kalendora-appt__times',
			role: 'listbox',
			'aria-label': __( 'Available times', 'kalendora' ),
		} );

		slots.forEach( ( slot ) => {
			const btn = el( 'button', {
				type: 'button',
				role: 'option',
				'aria-selected': 'false',
				className: 'kalendora-appt__time',
				text: formatTime( slot.time, this.config ),
				onClick: () => {
					list.querySelectorAll( '.kalendora-appt__time' ).forEach(
						( b ) => b.setAttribute( 'aria-selected', 'false' )
					);
					btn.setAttribute( 'aria-selected', 'true' );
					this.setValue( slot );
				},
			} );
			if ( slot.end_time ) {
				btn.append(
					el( 'small', {
						className: 'kalendora-appt__end',
						text: sprintf(
							/* translators: %s: end time. */
							__( 'until %s', 'kalendora' ),
							formatTime( slot.end_time, this.config )
						),
					} )
				);
			}
			if ( slot.available > 1 ) {
				btn.append(
					el( 'small', {
						text: sprintf(
							/* translators: %d: free places. */
							__( '%d free', 'kalendora' ),
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
						className: 'kalendora__muted',
						text: __( 'No free times on this day.', 'kalendora' ),
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
			const other =
				this.tz !== this.businessTz
					? ' ' +
					  sprintf(
							/* translators: 1: time, 2: city, e.g. "(14:00 in Berlin)". */
							__( '(%1$s in %2$s)', 'kalendora' ),
							formatTime(
								timeIn( slot.start, this.businessTz ),
								this.config
							),
							tzCity( this.businessTz )
					  )
					: '';
			this.summary.textContent =
				sprintf(
					/* translators: 1: date, 2: time. */
					__( 'Selected: %1$s, %2$s', 'kalendora' ),
					longDate( this.selectedDay, this.config.locale ),
					slot.end_time
						? `${ formatTime(
								slot.time,
								this.config
						  ) } – ${ formatTime( slot.end_time, this.config ) }`
						: formatTime( slot.time, this.config )
				) + other;
		} else {
			this.summary.hidden = true;
		}
		this.onChange(
			slot
				? {
						date: this.selectedDay,
						time: slot.time,
						end_time: slot.end_time || '',
						start: slot.start,
						resources: slot.resources || [],
				  }
				: null
		);
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
			className: 'kalendora-date__display',
			'aria-haspopup': 'dialog',
			'aria-expanded': 'false',
			'aria-describedby': native.getAttribute( 'aria-describedby' ),
			text: native.placeholder,
			onClick: () => this.toggle(),
		} );
		this.placeholder = native.placeholder;
		this.panel = el( 'div', {
			className: 'kalendora-date__panel',
			hidden: true,
		} );
		this.times = el( 'div', {
			className: 'kalendora-appt__times kalendora-date__times',
		} );
		native.replaceWith(
			el( 'div', { className: 'kalendora-date' }, [
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
					'.kalendora-cal__day.is-available, .kalendora-cal__nav'
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
				className: 'kalendora-appt__day',
				text: longDate( key, this.config.locale ),
			} ),
			...times.map( ( time ) =>
				el( 'button', {
					type: 'button',
					className: 'kalendora-appt__time',
					text: formatTime( time, this.config ),
					onClick: () => {
						this.set(
							`${ key } ${ time }`,
							sprintf(
								/* translators: 1: date, 2: time. */
								__( '%1$s at %2$s', 'kalendora' ),
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
