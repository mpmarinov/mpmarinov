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

/* ---------- Public JavaScript API (documented in docs/ENTWICKLER.md) ---------- */

const forms = [];
const globalListeners = {};
const readyCallbacks = [];

function safe( fn, ...args ) {
	try {
		return fn( ...args );
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.error( 'Bookora custom code:', e );
	}
	return undefined;
}

const bookora = Object.assign( window.bookora || {}, {
	/** APIs of all forms on the page. */
	forms,
	/**
	 * Listen to an event of every form (also forms added later).
	 *
	 * @param {string}   event Event name.
	 * @param {Function} cb    Callback( detail, formApi ).
	 */
	on( event, cb ) {
		( globalListeners[ event ] ||= [] ).push( cb );
	},
	off( event, cb ) {
		globalListeners[ event ] = ( globalListeners[ event ] || [] ).filter(
			( c ) => c !== cb
		);
	},
	/**
	 * Run code for every form once it is ready.
	 *
	 * @param {Function} cb Callback( formApi ).
	 */
	ready( cb ) {
		readyCallbacks.push( cb );
		forms.forEach( ( api ) => safe( cb, api ) );
	},
} );
window.bookora = bookora;

/**
 * Custom code queue: works no matter whether it runs before or after this script.
 * Items: function( bookora ) or { id: formId|null, fn: function( api, element ) }.
 *
 * @param {Function|Object} item Queued code.
 */
function runQueued( item ) {
	if ( typeof item === 'function' ) {
		safe( item, bookora );
	} else if ( item && typeof item.fn === 'function' ) {
		if ( item.id === null || item.id === undefined ) {
			safe( item.fn, bookora );
		} else {
			bookora.ready( ( api ) => {
				if ( api.id === Number( item.id ) ) {
					item.fn( api, api.element );
				}
			} );
		}
	}
}
const queued = Array.isArray( window.bookoraQueue ) ? window.bookoraQueue : [];
window.bookoraQueue = { push: runQueued };
queued.forEach( runQueued );

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
		this.publicApi = this.api();

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

		forms.push( this.publicApi );
		readyCallbacks.forEach( ( cb ) => safe( cb, this.publicApi ) );
		this.emit( 'ready', { id: this.config.id } );
		// Backwards compatible window event.
		window.dispatchEvent(
			new window.CustomEvent( 'bookora:ready', {
				detail: {
					id: this.config.id,
					element: root,
					api: this.publicApi,
				},
			} )
		);
	}

	/** Public API of one form (see docs/ENTWICKLER.md). */
	api() {
		return {
			id: Number( this.config.id ),
			element: this.root,
			form: this.form,
			on: ( event, cb ) => {
				( this.listeners[ event ] ||= [] ).push( cb );
			},
			off: ( event, cb ) => {
				this.listeners[ event ] = (
					this.listeners[ event ] || []
				).filter( ( c ) => c !== cb );
			},
			values: () => serialize( this.form ),
			setValue: ( key, value ) => this.setValue( key, value ),
			step: () => this.step,
			goTo: ( index ) => this.go( Number( index ) ),
		};
	}

	/**
	 * Notify listeners (form, global) and dispatch a bubbling DOM event "bookora:<event>".
	 *
	 * @param {string} event  Event name.
	 * @param {*}      detail Data.
	 * @return {boolean} False when a listener returned false (used by beforeSubmit).
	 */
	emit( event, detail ) {
		let proceed = true;
		const callbacks = [
			...( this.listeners[ event ] || [] ),
			...( globalListeners[ event ] || [] ),
		];
		callbacks.forEach( ( cb ) => {
			if ( safe( cb, detail, this.publicApi ) === false ) {
				proceed = false;
			}
		} );
		const domEvent = new window.CustomEvent( `bookora:${ event }`, {
			bubbles: true,
			cancelable: event === 'beforeSubmit',
			detail: { formId: Number( this.config.id ), data: detail },
		} );
		if ( ! this.root.dispatchEvent( domEvent ) ) {
			proceed = false;
		}
		return proceed;
	}

	/**
	 * Set a field value from custom code and re-run conditional logic.
	 *
	 * @param {string}                      key   Field key.
	 * @param {string|string[]|Object|null} value Value.
	 * @return {boolean} Whether the field exists.
	 */
	setValue( key, value ) {
		const inputs = [
			...this.form.querySelectorAll(
				`[name="fields[${ key }]"], [name="fields[${ key }][]"], [name^="fields[${ key }]["]`
			),
		];
		inputs.forEach( ( input ) => {
			const sub = input.name.match( /\]\[([^\]]+)\]$/ );
			if ( input.type === 'checkbox' || input.type === 'radio' ) {
				const list = Array.isArray( value ) ? value : [ value ];
				input.checked = list.map( String ).includes( input.value );
			} else if ( sub && value && typeof value === 'object' ) {
				input.value = value[ sub[ 1 ] ] ?? '';
			} else {
				input.value = value ?? '';
			}
		} );
		if ( inputs.length ) {
			inputs[ 0 ].dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
		}
		return inputs.length > 0;
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
				onChange: ( slot ) => {
					this.update();
					this.emit( 'slot', slot || null );
				},
				onDay: ( date ) => this.emit( 'date', { date } ),
			} );
		}

		// Cancellation terms with a fee: shown (and required) only for services that have them.
		const policyField = this.form.querySelector(
			'.bookora__field--policy'
		);
		const updatePolicy = () => {
			if ( ! policyField ) {
				return;
			}
			const id = service
				? service.value
				: String( this.config.service || '' );
			const text = ( this.config.policies || {} )[ id ];
			policyField.hidden = ! text;
			if ( text ) {
				policyField.querySelector(
					'.bookora__policy-text'
				).textContent = text;
			}
		};
		updatePolicy();

		service?.addEventListener( 'change', () => {
			fillResources();
			updatePolicy();
			this.emit( 'service', { id: Number( service.value ) || null } );
			this.appointment?.reset();
		} );
		resource?.addEventListener( 'change', () => {
			this.emit( 'resource', { id: Number( resource.value ) || null } );
			this.appointment?.reset();
		} );

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
		if ( field?.dataset.key ) {
			const values = serialize( this.form );
			this.emit( 'change', {
				key: field.dataset.key,
				value: values[ field.dataset.key ],
				values,
			} );
		}
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
			.forEach( ( input ) => {
				input.setAttribute( 'aria-invalid', msg ? 'true' : 'false' );
				// Link the message to every input of the field (also grouped inputs like first/last name).
				if ( error?.id ) {
					const ids = (
						input.getAttribute( 'aria-describedby' ) || ''
					)
						.split( ' ' )
						.filter( ( x ) => x && x !== error.id );
					if ( msg ) {
						ids.push( error.id );
					}
					if ( ids.length ) {
						input.setAttribute(
							'aria-describedby',
							ids.join( ' ' )
						);
					} else {
						input.removeAttribute( 'aria-describedby' );
					}
				}
			} );
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
		const changed = this.step !== index;
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

		if ( changed ) {
			this.emit( 'step', { index, total: this.steps.length } );
		}

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

		const payload = {
			fields: serialize( this.form ),
			_bk_t: this.form.querySelector( '[name="_bk_t"]' ).value,
			website_url_hp: this.form.querySelector( '[name="website_url_hp"]' )
				.value,
			_source: window.location.href,
			_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
		};

		// beforeSubmit: listeners may add values, or cancel (return false / detail.cancel( message )).
		let cancelMessage = null;
		const before = {
			values: payload.fields,
			cancel: ( message = '' ) => {
				cancelMessage = String( message );
			},
		};
		if ( ! this.emit( 'beforeSubmit', before ) || cancelMessage !== null ) {
			this.showMessage( cancelMessage || '' );
			return;
		}

		const button = this.root.querySelector( '.bookora__submit' );
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
