/**
 * File uploads and signatures.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiUrl from './api';

function formatSize( bytes ) {
	if ( bytes >= 1024 * 1024 ) {
		return `${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB`;
	}
	return `${ Math.max( 1, Math.round( bytes / 1024 ) ) } KB`;
}

function setError( field, message ) {
	const err = field.querySelector( '.kalendora__error' );
	field.classList.toggle( 'has-error', !! message );
	if ( err ) {
		err.textContent = message;
		err.hidden = ! message;
	}
}

/**
 * Uploads each chosen file right away and keeps only a token in the form.
 */
export class FileUpload {
	constructor( { root, config, form, onChange } ) {
		this.root = root;
		this.field = root.closest( '.kalendora__field' );
		this.key = this.field.dataset.key;
		this.config = config;
		this.form = form;
		this.onChange = onChange;
		this.input = root.querySelector( '.kalendora-upload__input' );
		this.list = root.querySelector( '.kalendora-upload__list' );
		this.drop = root.querySelector( '.kalendora-upload__drop' );
		this.maxFiles = Number( root.dataset.maxFiles ) || 1;
		this.maxBytes = Number( root.dataset.maxBytes ) || 5 * 1024 * 1024;
		this.extensions = ( root.dataset.extensions || '' ).split( ',' );
		this.pending = 0;

		this.input.addEventListener( 'change', () => {
			this.add( [ ...this.input.files ] );
			this.input.value = '';
		} );
		this.drop.addEventListener( 'dragover', ( e ) => {
			e.preventDefault();
			this.drop.classList.add( 'is-over' );
		} );
		this.drop.addEventListener( 'dragleave', () =>
			this.drop.classList.remove( 'is-over' )
		);
		this.drop.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			this.drop.classList.remove( 'is-over' );
			this.add( [ ...( e.dataTransfer?.files || [] ) ] );
		} );
	}

	get busy() {
		return this.pending > 0;
	}

	count() {
		return this.list.querySelectorAll( '.kalendora-upload__item' ).length;
	}

	add( files ) {
		setError( this.field, '' );
		for ( const file of files ) {
			if ( this.count() >= this.maxFiles ) {
				setError(
					this.field,
					sprintf(
						/* translators: %d: number of files. */
						__( 'Please upload at most %d file(s).', 'kalendora' ),
						this.maxFiles
					)
				);
				break;
			}
			const ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
			if ( ! this.extensions.includes( ext ) ) {
				setError(
					this.field,
					sprintf(
						/* translators: %s: list of file types. */
						__(
							'This file type is not allowed. Allowed: %s',
							'kalendora'
						),
						this.extensions
							.filter( ( e ) => e !== 'jpeg' )
							.join( ', ' )
					)
				);
				continue;
			}
			if ( file.size > this.maxBytes ) {
				setError(
					this.field,
					sprintf(
						/* translators: %s: size, e.g. "5 MB". */
						__( 'The file is too large. Maximum: %s', 'kalendora' ),
						formatSize( this.maxBytes )
					)
				);
				continue;
			}
			this.upload( file );
		}
	}

	upload( file ) {
		const li = document.createElement( 'li' );
		li.className = 'kalendora-upload__item is-busy';
		const name = document.createElement( 'span' );
		name.className = 'kalendora-upload__name';
		name.textContent = file.name;
		const meta = document.createElement( 'span' );
		meta.className = 'kalendora-upload__meta';
		meta.textContent = formatSize( file.size );
		const bar = document.createElement( 'progress' );
		bar.max = 100;
		bar.value = 0;
		bar.setAttribute(
			'aria-label',
			sprintf(
				/* translators: %s: file name. */
				__( 'Uploading %s', 'kalendora' ),
				file.name
			)
		);
		const remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'kalendora-upload__remove';
		remove.textContent = '×';
		remove.setAttribute(
			'aria-label',
			sprintf(
				/* translators: %s: file name. */
				__( 'Remove %s', 'kalendora' ),
				file.name
			)
		);
		li.append( name, meta, bar, remove );
		this.list.append( li );

		const data = new window.FormData();
		data.append( 'file', file );
		data.append( 'field', this.key );
		data.append(
			'_bk_t',
			this.form.querySelector( '[name="_bk_t"]' )?.value || ''
		);

		const xhr = new window.XMLHttpRequest();
		this.pending++;
		const done = () => {
			this.pending--;
			li.classList.remove( 'is-busy' );
			bar.remove();
			this.onChange();
		};
		remove.addEventListener( 'click', () => {
			if ( li.classList.contains( 'is-busy' ) ) {
				xhr.abort();
			}
			li.remove();
			this.onChange();
			this.input.focus();
		} );
		xhr.upload.addEventListener( 'progress', ( e ) => {
			if ( e.lengthComputable ) {
				bar.value = Math.round( ( e.loaded / e.total ) * 100 );
			}
		} );
		xhr.addEventListener( 'load', () => {
			let body = {};
			try {
				body = JSON.parse( xhr.responseText );
			} catch ( e ) {}
			if ( xhr.status >= 200 && xhr.status < 300 && body.token ) {
				const hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = `fields[${ this.key }][]`;
				hidden.value = body.token;
				li.append( hidden );
				li.classList.add( 'is-done' );
				setError( this.field, '' );
			} else {
				li.remove();
				setError(
					this.field,
					body.message ||
						__(
							'The file could not be uploaded. Please try again.',
							'kalendora'
						)
				);
			}
			done();
		} );
		const fail = () => {
			li.remove();
			setError(
				this.field,
				__(
					'Connection problem. Please check your internet connection and try again.',
					'kalendora'
				)
			);
			done();
		};
		xhr.addEventListener( 'error', fail );
		xhr.addEventListener( 'abort', () => done() );
		xhr.open(
			'POST',
			apiUrl(
				this.config.rest,
				`/public/forms/${ this.config.id }/upload`
			)
		);
		xhr.send( data );
	}
}

/**
 * Signature pad with a typed alternative (keyboard and screen reader users).
 */
export class SignaturePad {
	constructor( { root, onChange } ) {
		this.root = root;
		this.onChange = onChange;
		this.canvas = root.querySelector( '.kalendora-sign__pad' );
		this.value = root.querySelector( '.kalendora-sign__value' );
		this.typedBox = root.querySelector( '.kalendora-sign__typed' );
		this.typed = this.typedBox.querySelector( 'input' );
		this.toggle = root.querySelector( '[data-sign="type"]' );
		this.strokes = [];
		this.width = 0;

		this.resize();
		window.addEventListener( 'resize', () => this.resize() );

		this.canvas.addEventListener( 'pointerdown', ( e ) => this.start( e ) );
		this.canvas.addEventListener( 'pointermove', ( e ) => this.move( e ) );
		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( ( t ) =>
			this.canvas.addEventListener( t, () => this.end() )
		);
		root.querySelector( '[data-sign="clear"]' ).addEventListener(
			'click',
			() => this.clear()
		);
		this.toggle.addEventListener( 'click', () => this.switchMode() );
		this.typed.addEventListener( 'input', () => {
			const name = this.typed.value.trim();
			this.value.value = name ? `typed:${ name }` : '';
			this.onChange();
		} );
	}

	resize() {
		const width = this.canvas.clientWidth;
		if ( ! width || width === this.width ) {
			return;
		}
		this.width = width;
		const ratio = Math.min( window.devicePixelRatio || 1, 2 );
		const height = this.canvas.clientHeight || 180;
		this.canvas.width = Math.round( width * ratio );
		this.canvas.height = Math.round( height * ratio );
		this.ctx = this.canvas.getContext( '2d' );
		this.ctx.scale( ratio, ratio );
		this.redraw();
	}

	point( e ) {
		const r = this.canvas.getBoundingClientRect();
		return [
			( e.clientX - r.left ) / r.width,
			( e.clientY - r.top ) / r.height,
		];
	}

	start( e ) {
		e.preventDefault();
		this.canvas.setPointerCapture?.( e.pointerId );
		this.drawing = true;
		this.strokes.push( [ this.point( e ) ] );
		this.redraw();
	}

	move( e ) {
		if ( ! this.drawing ) {
			return;
		}
		this.strokes[ this.strokes.length - 1 ].push( this.point( e ) );
		this.redraw();
	}

	end() {
		if ( ! this.drawing ) {
			return;
		}
		this.drawing = false;
		const ink = this.strokes.reduce( ( n, s ) => n + s.length, 0 );
		this.value.value = ink > 3 ? this.canvas.toDataURL( 'image/png' ) : '';
		this.onChange();
	}

	redraw() {
		if ( ! this.ctx ) {
			return;
		}
		const w = this.canvas.clientWidth;
		const h = this.canvas.clientHeight || 180;
		this.ctx.clearRect( 0, 0, w, h );
		this.ctx.lineWidth = 2.2;
		this.ctx.lineCap = 'round';
		this.ctx.lineJoin = 'round';
		this.ctx.strokeStyle = '#111827';
		this.strokes.forEach( ( stroke ) => {
			this.ctx.beginPath();
			stroke.forEach( ( [ x, y ], i ) =>
				i
					? this.ctx.lineTo( x * w, y * h )
					: this.ctx.moveTo( x * w, y * h )
			);
			if ( stroke.length === 1 ) {
				this.ctx.lineTo(
					stroke[ 0 ][ 0 ] * w + 0.5,
					stroke[ 0 ][ 1 ] * h
				);
			}
			this.ctx.stroke();
		} );
	}

	clear() {
		this.strokes = [];
		this.typed.value = '';
		this.value.value = '';
		this.redraw();
		this.onChange();
	}

	switchMode() {
		const typing = this.typedBox.hidden;
		this.typedBox.hidden = ! typing;
		this.canvas.hidden = typing;
		this.root.querySelector( '.kalendora-sign__how' ).hidden = typing;
		this.toggle.setAttribute( 'aria-expanded', String( typing ) );
		this.toggle.textContent = typing
			? __( 'Sign by hand instead', 'kalendora' )
			: __( 'Type my name instead', 'kalendora' );
		this.clear();
		if ( typing ) {
			this.typed.focus();
		} else {
			this.resize();
		}
	}
}
