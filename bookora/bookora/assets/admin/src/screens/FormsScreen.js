import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	Dashicon,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorMessage, post } from '../api';
import ConfirmButton from '../components/ConfirmButton';
import useCollection from '../components/useCollection';
import Builder from '../builder/Builder';
import { TEMPLATES, templateById } from '../templates';

function currentFormId() {
	const id = Number(
		new URLSearchParams( window.location.search ).get( 'form' )
	);
	return id > 0 ? id : 0;
}

function navigate( id ) {
	const url = new URL( window.location.href );
	if ( id ) {
		url.searchParams.set( 'form', id );
	} else {
		url.searchParams.delete( 'form' );
	}
	window.history.pushState( {}, '', url );
	window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
}

/**
 * Grid of templates, grouped by "with appointment" / "without appointment".
 *
 * @param {Object}   props
 * @param {string}   props.value    Selected template id.
 * @param {Function} props.onChange Called with the template id.
 */
export function TemplatePicker( { value, onChange } ) {
	const groups = [
		{
			type: 'booking',
			title: __( 'With appointment booking', 'bookora' ),
		},
		{
			type: 'standard',
			title: __( 'Without appointment', 'bookora' ),
		},
	];
	return groups.map( ( g ) => (
		<fieldset key={ g.type } className="bookora-templates">
			<legend>{ g.title }</legend>
			<div className="bookora-type-picker">
				{ TEMPLATES.filter( ( t ) => t.type === g.type ).map( ( t ) => (
					<label
						key={ t.id }
						htmlFor={ `bookora-template-${ t.id }` }
						className={ `bookora-type-picker__option${
							value === t.id ? ' is-selected' : ''
						}` }
					>
						<input
							id={ `bookora-template-${ t.id }` }
							type="radio"
							name="bookora-template"
							className="screen-reader-text"
							value={ t.id }
							checked={ value === t.id }
							onChange={ () => onChange( t.id ) }
						/>
						<Dashicon icon={ t.icon } />
						<strong>{ t.title }</strong>
						<span>{ t.text }</span>
					</label>
				) ) }
			</div>
		</fieldset>
	) );
}

function NewFormModal( { onClose } ) {
	const [ title, setTitle ] = useState( '' );
	const [ template, setTemplate ] = useState( 'booking' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const chosen = templateById( template );

	const submit = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		post( '/forms', {
			title: title || chosen.title,
			type: chosen.type,
			fields: chosen.fields(),
		} )
			.then( ( form ) => navigate( form.id ) )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setBusy( false );
			} );
	};

	return (
		<Modal
			title={ __( 'New form', 'bookora' ) }
			onRequestClose={ onClose }
			className="bookora-modal bookora-modal--wide"
		>
			<form onSubmit={ submit } className="bookora-form">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TemplatePicker value={ template } onChange={ setTemplate } />
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Form title', 'bookora' ) }
					placeholder={ chosen.title }
					value={ title }
					onChange={ setTitle }
				/>
				<p className="bookora-muted">
					{ __(
						'The template is only a start – you can change, add or remove every field in the next step.',
						'bookora'
					) }
				</p>
				<div className="bookora-modal-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'bookora' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Create form', 'bookora' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

function FormList() {
	const { items, notice, setNotice, reload, remove } =
		useCollection( '/forms' );
	const [ creating, setCreating ] = useState( false );
	const [ copied, setCopied ] = useState( 0 );

	if ( ! items ) {
		return <Spinner />;
	}

	const copy = ( form ) => {
		const code = `[bookora id="${ form.id }"]`;
		( window.navigator.clipboard?.writeText( code ) || Promise.reject() )
			.then( () => {
				setCopied( form.id );
				setTimeout( () => setCopied( 0 ), 2000 );
			} )
			.catch( () => setNotice( { status: 'info', text: code } ) );
	};

	const duplicate = ( id ) =>
		post( `/forms/${ id }/duplicate` )
			.then( reload )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	return (
		<div className="bookora-screen">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<div className="bookora-toolbar">
				<p className="description">
					{ __(
						'Create booking forms with a calendar or any other form – contact, request, survey.',
						'bookora'
					) }
				</p>
				<Button variant="primary" onClick={ () => setCreating( true ) }>
					{ __( 'New form', 'bookora' ) }
				</Button>
			</div>

			{ items.length === 0 ? (
				<Card>
					<CardBody className="bookora-empty">
						<Dashicon
							icon="feedback"
							className="bookora-empty__icon"
						/>
						<p>
							{ __(
								'No forms yet. Create your first form in under a minute.',
								'bookora'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ () => setCreating( true ) }
						>
							{ __( 'Create your first form', 'bookora' ) }
						</Button>
					</CardBody>
				</Card>
			) : (
				<div className="bookora-list">
					{ items.map( ( form ) => (
						<Card key={ form.id } className="bookora-list__item">
							<CardBody>
								<Dashicon
									icon={
										form.type === 'booking'
											? 'calendar-alt'
											: 'feedback'
									}
									className="bookora-list__icon"
								/>
								<div className="bookora-list__main">
									<Button
										variant="link"
										className="bookora-list__title"
										onClick={ () => navigate( form.id ) }
									>
										{ form.title }
									</Button>
									<span
										className={ `bookora-badge bookora-badge--${ form.status }` }
									>
										{ form.status === 'published'
											? __( 'Published', 'bookora' )
											: __( 'Draft', 'bookora' ) }
									</span>
									<div className="bookora-meta">
										<span>
											{ form.type === 'booking'
												? __(
														'Booking form',
														'bookora'
												  )
												: __(
														'Standard form',
														'bookora'
												  ) }
										</span>
										<span>
											{ sprintf(
												/* translators: %d: number of fields. */
												_n(
													'%d field',
													'%d fields',
													form.field_count,
													'bookora'
												),
												form.field_count
											) }
										</span>
										<span>
											{ sprintf(
												/* translators: %d: number of entries. */
												_n(
													'%d entry',
													'%d entries',
													form.entries,
													'bookora'
												),
												form.entries
											) }
										</span>
										<button
											type="button"
											className="bookora-shortcode"
											onClick={ () => copy( form ) }
											title={ __(
												'Copy shortcode',
												'bookora'
											) }
										>
											<code>
												[bookora id=&quot;{ form.id }
												&quot;]
											</code>
											<Dashicon
												icon={
													copied === form.id
														? 'yes'
														: 'clipboard'
												}
											/>
										</button>
									</div>
								</div>
								<div className="bookora-list__actions">
									<Button
										variant="secondary"
										onClick={ () => navigate( form.id ) }
									>
										{ __( 'Edit', 'bookora' ) }
									</Button>
									<Button
										variant="tertiary"
										onClick={ () => duplicate( form.id ) }
									>
										{ __( 'Duplicate', 'bookora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: form title. */
											__(
												'Delete the form "%s"? Entries are kept.',
												'bookora'
											),
											form.title
										) }
										onConfirm={ () => remove( form.id ) }
									/>
								</div>
							</CardBody>
						</Card>
					) ) }
				</div>
			) }

			{ creating && (
				<NewFormModal onClose={ () => setCreating( false ) } />
			) }
		</div>
	);
}

export default function FormsScreen() {
	const [ formId, setFormId ] = useState( currentFormId );

	useEffect( () => {
		const onPop = () => setFormId( currentFormId() );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	useEffect( () => {
		document.body.classList.toggle( 'bookora-is-building', formId > 0 );
	}, [ formId ] );

	return formId ? (
		<Builder
			key={ formId }
			formId={ formId }
			onBack={ () => navigate( 0 ) }
		/>
	) : (
		<FormList />
	);
}
