/**
 * Bookora frontend: conditional logic, steps, pickers, validation and AJAX submit.
 */
import { __ } from '@wordpress/i18n';
import { AppointmentPicker, DatePicker } from './pickers';
import apiUrl from './api';
import './style.scss';

/**
 * Turn form controls named fields[key], fields[key][sub], fields[key][] into an object.
 *
 * @param {HTMLFormElement} form Form.
 * @return {Object} Values by field key.
 */
function serialize( form ) {
	const out = {};
	new window.FormData( form ).forEach( ( value, name ) => {
		const m = name.match( /^fields\[([^\]]+)\](?:\[([^\]]*)\])?$/ );
		if ( ! m ) {
			return;
		}
		const [ , key, sub ] = m;
		if ( sub === undefined ) {
			out[ key ] = value;
		} else if ( sub === '' ) {
			out[ key ] = [
				...( Array.isArray( out[ key ] ) ? out[ key ] : [] ),
				value,
			];
		} else {
			out[ key ] = {
				...( typeof out[ key ] === 'object' &&
				! Array.isArray( out[ key ] )
					? out[ key ]
					: {} ),
				[ sub ]: value,
			};
		}
	} );
	return out;
}

function ruleMatches( operator, value, expected ) {
	const list = Array.isArray( value )
		? value.map( String )
		: [ String( value ?? '' ) ];
	const text = list.join( ' ' );
	const empty = text.trim() === '';
	switch ( operator ) {
		case 'is':
			return list.includes( expected );
		case 'is_not':
			return ! list.includes( expected );
		case 'contains':
			return (
				expected !== '' &&
				text.toLowerCase().includes( expected.toLowerCase() )
			);
		case 'not_contains':
			return (
				expected === '' ||
				! text.toLowerCase().includes( expected.toLowerCase() )
			);
		case 'empty':
			return empty;
		case 'not_empty':
			return ! empty;
		case 'greater':
			return (
				text !== '' &&
				! isNaN( text ) &&
				Number( text ) > Number( expected )
			);
		case 'less':
			return (
				text !== '' &&
				! isNaN( text ) &&
				Number( text ) < Number( expected )
			);
	}
	return false;
}

function isEmpty( value ) {
	if ( value === undefined || value === null ) {
		return true;
	}
	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}
	if ( typeof value === 'object' ) {
		return Object.values( value ).every(
			( v ) => String( v ).trim() === ''
		);
	}
	return String( value ).trim() === '';
}

class BookoraForm {
	constructor( root ) {
		this.root = root;
		this.config = JSON.parse( root.dataset.bookora );
		this.form = root.querySelector( 'form' );
		this.message = root.querySelector( '.bookora__message' );
		this.success = root.querySelector( '.bookora__success' );
		this.steps = [ ...root.querySelectorAll( '.bookora__step' ) ];
		this.step = 0;
		this.defs = Object.fromEntries(
			this.config.fields
				.filter( ( f ) => f.key )
				.map( ( f ) => [ f.key, f ] )
		);
		this.listeners = {};

		this.setupPickers();
		this.form.addEventListener( 'input', () => this.update() );
		this.form.addEventListener( 'change', ( e ) => this.onChange( e ) );
		this.form.addEventListener( 'submit', ( e ) => this.submit( e ) );
		root.querySelector( '[data-action="next"]' )?.addEventListener(
			'click',
			() => this.next()
		);
		root.querySelector( '[data-action="prev"]' )?.addEventListener(
			'click',
			() => this.go( this.step - 1 )
		);

		root.querySelectorAll( '.bookora__range input' ).forEach( ( input ) =>
			input.addEventListener( 'input', () => {
				input.nextElementSibling.textContent = input.value;
			} )
		);

		this.update();
		this.go( 0, false );
		root.classList.add( 'is-ready' );

		window.dispatchEvent(
			new window.CustomEvent( 'bookora:ready', {
				detail: { id: this.config.id, element: root, api: this.api() },
			} )
		);
	}

	/** Small public API for custom JavaScript. */
	api() {
		return {
			on: ( event, cb ) => {
				( this.listeners[ event ] ||= [] ).push( cb );
			},
			values: () => serialize( this.form ),
			form: this.form,
		};
	}

	emit( event, detail ) {
		( this.listeners[ event ] || [] ).forEach( ( cb ) => {
			try {
				cb( detail );
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.error( e );
			}
		} );
	}

	setupPickers() {
		const service = this.form.querySelector(
			'[data-role="service"], .bookora__field--service input[type="hidden"]'
		);
		const resource = this.form.querySelector( '[data-role="resource"]' );
		const apptField = this.form.querySelector(
			'.bookora__field--appointment'
		);

		const fillResources = () => {
			if ( ! resource ) {
				return;
			}
			const list = this.config.resources?.[ service?.value ] || [];
			const allowAny = resource.querySelector( 'option[value=""]' );
			resource.replaceChildren( ...( allowAny ? [ allowAny ] : [] ) );
			list.forEach( ( r ) =>
				resource.append( new window.Option( r.name, r.id ) )
			);
			resource.closest( '.bookora__field' ).hidden = list.length === 0;
		};

		if ( resource ) {
			fillResources();
		}

		if ( apptField ) {
			this.appointment = new AppointmentPicker( {
				field: apptField,
				config: this.config,
				getService: () =>
					service?.value ||
					( this.config.fields.some( ( f ) => f.type === 'service' )
						? ''
						: String( this.config.service || '' ) ),
				getResource: () => resource?.value || '',
				onChange: () => this.update(),
			} );
		}

		service?.addEventListener( 'change', () => {
			fillResources();
			this.appointment?.reset();
		} );
		resource?.addEventListener( 'change', () => this.appointment?.reset() );

		this.form
			.querySelectorAll( '.bookora__field--date' )
			.forEach( ( field ) => {
				const def = this.defs[ field.dataset.key ] || {};
				// eslint-disable-next-line no-new
				new DatePicker( {
					field,
					def,
					config: this.config,
					onChange: () => this.update(),
				} );
			} );
	}

	onChange( e ) {
		this.update();
		const field = e.target.closest( '.bookora__field' );
		if ( field?.classList.contains( 'has-error' ) ) {
			this.validateField( field );
		}
	}

	/** Apply conditional logic. */
	update() {
		const values = serialize( this.form );
		this.visible = {};

		const visible = ( key, depth = 0 ) => {
			if ( key in this.visible ) {
				return this.visible[ key ];
			}
			const def = this.defs[ key ];
			if ( ! def?.logic?.rules?.length || depth > 10 ) {
				return true;
			}
			const results = def.logic.rules.map( ( rule ) =>
				ruleMatches(
					rule.operator,
					visible( rule.field, depth + 1 )
						? values[ rule.field ]
						: '',
					rule.value
				)
			);
			const matched =
				def.logic.match === 'any'
					? results.includes( true )
					: ! results.includes( false );
			return def.logic.action === 'hide' ? ! matched : matched;
		};

		this.form
			.querySelectorAll( '.bookora__field[data-key]' )
			.forEach( ( field ) => {
				const key = field.dataset.key;
				if ( ! key || field.dataset.type === 'hidden' ) {
					return;
				}
				const show = visible( key );
				this.visible[ key ] = show;
				if ( field.dataset.type !== 'resource' || show ) {
					field.classList.toggle( 'is-hidden-by-logic', ! show );
				}
				field
					.querySelectorAll( 'input, select, textarea, button' )
					.forEach( ( input ) => {
						input.disabled = ! show;
					} );
			} );
	}

	validateField( field ) {
		const key = field.dataset.key;
		const def = this.defs[ key ];
		if (
			! def ||
			field.classList.contains( 'is-hidden-by-logic' ) ||
			field.hidden
		) {
			return true;
		}
		const error = field.querySelector( '.bookora__error' );
		const values = serialize( this.form );
		let value = values[ key ];
		let msg = '';

		if ( def.type === 'appointment' ) {
			value = this.appointment?.value();
			if ( ! value ) {
				msg = __( 'Please choose a date and time.', 'bookora' );
			}
		} else if (
			def.required &&
			def.type === 'name' &&
			typeof value === 'object' &&
			( ! value.first?.trim() || ! value.last?.trim() )
		) {
			msg = __( 'Please enter your first and last name.', 'bookora' );
		} else if ( def.required && isEmpty( value ) ) {
			msg =
				def.type === 'consent'
					? __( 'Please confirm to continue.', 'bookora' )
					: __( 'This field is required.', 'bookora' );
		} else if ( ! isEmpty( value ) ) {
			const input = field.querySelector(
				'input:not([type=hidden]), textarea, select'
			);
			if (
				def.type === 'email' &&
				! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value )
			) {
				msg = __( 'Please enter a valid email address.', 'bookora' );
			} else if (
				def.type === 'phone' &&
				! /^[0-9 +()/.-]{5,30}$/.test( value )
			) {
				msg = __( 'Please enter a valid phone number.', 'bookora' );
			} else if (
				input &&
				input.validity &&
				! input.validity.valid &&
				input.type !== 'radio' &&
				input.type !== 'checkbox'
			) {
				msg = input.validationMessage;
			}
		}

		field.classList.toggle( 'has-error', !! msg );
		if ( error ) {
			error.textContent = msg;
			error.hidden = ! msg;
		}
		field
			.querySelectorAll( 'input, select, textarea' )
			.forEach( ( input ) =>
				input.setAttribute( 'aria-invalid', msg ? 'true' : 'false' )
			);
		return ! msg;
	}

	validateStep( index ) {
		const fields = [
			...this.steps[ index ].querySelectorAll(
				'.bookora__field[data-key]'
			),
		];
		const invalid = fields.filter( ( f ) => ! this.validateField( f ) );
		if ( invalid.length ) {
			const target = invalid[ 0 ];
			target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			target
				.querySelector(
					'input:not([type=hidden]), select, textarea, button'
				)
				?.focus( { preventScroll: true } );
		}
		return invalid.length === 0;
	}

	go( index, focus = true ) {
		if ( index < 0 || index >= this.steps.length ) {
			return;
		}
		this.step = index;
		this.steps.forEach( ( s, i ) => {
			s.hidden = i !== index;
		} );
		const last = index === this.steps.length - 1;
		const prev = this.root.querySelector( '[data-action="prev"]' );
		const next = this.root.querySelector( '[data-action="next"]' );
		if ( prev ) {
			prev.hidden = index === 0;
		}
		if ( next ) {
			next.hidden = last;
		}
		this.root.querySelector( '.bookora__submit' ).hidden = ! last;

		const bar = this.root.querySelector( '.bookora__progress-bar span' );
		if ( bar ) {
			bar.style.width = `${
				( ( index + 1 ) / this.steps.length ) * 100
			}%`;
		}
		this.root
			.querySelectorAll( '.bookora__progress li' )
			.forEach( ( li, i ) => {
				li.classList.toggle( 'is-active', i === index );
				li.classList.toggle( 'is-done', i < index );
			} );

		if ( focus ) {
			this.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			this.steps[ index ]
				.querySelector(
					'input:not([type=hidden]), select, textarea, button'
				)
				?.focus( { preventScroll: true } );
		}
	}

	next() {
		if ( this.validateStep( this.step ) ) {
			this.go( this.step + 1 );
		}
	}

	showMessage( text, type = 'error' ) {
		this.message.textContent = text;
		this.message.className = `bookora__message bookora__message--${ type }`;
		this.message.hidden = ! text;
	}

	submit( e ) {
		e.preventDefault();
		for ( let i = 0; i < this.steps.length; i++ ) {
			if ( ! this.validateStep( i ) ) {
				this.go( i, false );
				this.validateStep( i );
				return;
			}
		}

		const button = this.root.querySelector( '.bookora__submit' );
		const payload = {
			fields: serialize( this.form ),
			_bk_t: this.form.querySelector( '[name="_bk_t"]' ).value,
			website_url_hp: this.form.querySelector( '[name="website_url_hp"]' )
				.value,
			_source: window.location.href,
			_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
		};

		this.emit( 'submit', payload );
		this.showMessage( '' );
		button.disabled = true;
		button.classList.add( 'is-busy' );

		window
			.fetch(
				apiUrl(
					this.config.rest,
					`/public/forms/${ this.config.id }/submit`
				),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
					body: JSON.stringify( payload ),
				}
			)
			.then( ( r ) =>
				r.json().then( ( body ) => ( { ok: r.ok, body } ) )
			)
			.then( ( { ok, body } ) => {
				if ( ! ok ) {
					const fieldErrors = body?.data?.fields || {};
					Object.entries( fieldErrors ).forEach( ( [ key, msg ] ) => {
						const field = this.form.querySelector(
							`.bookora__field[data-key="${ window.CSS.escape(
								key
							) }"]`
						);
						if ( field ) {
							field.classList.add( 'has-error' );
							const err =
								field.querySelector( '.bookora__error' );
							err.textContent = msg;
							err.hidden = false;
						}
					} );
					if ( body?.code === 'bookora_slot_taken' ) {
						this.appointment?.reload();
					}
					this.showMessage(
						body?.message ||
							__(
								'Something went wrong. Please try again.',
								'bookora'
							)
					);
					this.emit( 'error', body );
					return;
				}
				this.emit( 'success', body );
				if ( body.redirect ) {
					window.location.assign( body.redirect );
					return;
				}
				this.form.hidden = true;
				// Message is sanitized server-side with wp_kses_post.
				this.success.innerHTML = body.message;
				this.success.hidden = false;
				this.success.setAttribute( 'tabindex', '-1' );
				this.success.focus();
			} )
			.catch( () =>
				this.showMessage(
					__(
						'Connection problem. Please check your internet connection and try again.',
						'bookora'
					)
				)
			)
			.finally( () => {
				button.disabled = false;
				button.classList.remove( 'is-busy' );
			} );
	}
}

function init() {
	document
		.querySelectorAll( '.bookora[data-bookora]:not(.is-ready)' )
		.forEach( ( root ) => {
			try {
				// eslint-disable-next-line no-new
				new BookoraForm( root );
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.error( 'Bookora:', e );
			}
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
