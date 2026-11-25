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
			title: __( 'With appointment booking', 'kalendora' ),
		},
		{
			type: 'standard',
			title: __( 'Without appointment', 'kalendora' ),
		},
	];
	return groups.map( ( g ) => (
		<fieldset key={ g.type } className="kalendora-templates">
			<legend>{ g.title }</legend>
			<div className="kalendora-type-picker">
				{ TEMPLATES.filter( ( t ) => t.type === g.type ).map( ( t ) => (
					<label
						key={ t.id }
						htmlFor={ `kalendora-template-${ t.id }` }
						className={ `kalendora-type-picker__option${
							value === t.id ? ' is-selected' : ''
						}` }
					>
						<input
							id={ `kalendora-template-${ t.id }` }
							type="radio"
							name="kalendora-template"
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
			title={ __( 'New form', 'kalendora' ) }
			onRequestClose={ onClose }
			className="kalendora-modal kalendora-modal--wide"
		>
			<form onSubmit={ submit } className="kalendora-form">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TemplatePicker value={ template } onChange={ setTemplate } />
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Form title', 'kalendora' ) }
					placeholder={ chosen.title }
					value={ title }
					onChange={ setTitle }
				/>
				<p className="kalendora-muted">
					{ __(
						'The template is only a start – you can change, add or remove every field in the next step.',
						'kalendora'
					) }
				</p>
				<div className="kalendora-modal-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'kalendora' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Create form', 'kalendora' ) }
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
		const code = `[kalendora id="${ form.id }"]`;
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
		<div className="kalendora-screen">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<div className="kalendora-toolbar">
				<p className="description">
					{ __(
						'Create booking forms with a calendar or any other form – contact, request, survey.',
						'kalendora'
					) }
				</p>
				<Button variant="primary" onClick={ () => setCreating( true ) }>
					{ __( 'New form', 'kalendora' ) }
				</Button>
			</div>

			{ items.length === 0 ? (
				<Card>
					<CardBody className="kalendora-empty">
						<Dashicon
							icon="feedback"
							className="kalendora-empty__icon"
						/>
						<p>
							{ __(
								'No forms yet. Create your first form in under a minute.',
								'kalendora'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ () => setCreating( true ) }
						>
							{ __( 'Create your first form', 'kalendora' ) }
						</Button>
					</CardBody>
				</Card>
			) : (
				<div className="kalendora-list">
					{ items.map( ( form ) => (
						<Card key={ form.id } className="kalendora-list__item">
							<CardBody>
								<Dashicon
									icon={
										form.type === 'booking'
											? 'calendar-alt'
											: 'feedback'
									}
									className="kalendora-list__icon"
								/>
								<div className="kalendora-list__main">
									<Button
										variant="link"
										className="kalendora-list__title"
										onClick={ () => navigate( form.id ) }
									>
										{ form.title }
									</Button>
									<span
										className={ `kalendora-badge kalendora-badge--${ form.status }` }
									>
										{ form.status === 'published'
											? __( 'Published', 'kalendora' )
											: __( 'Draft', 'kalendora' ) }
									</span>
									<div className="kalendora-meta">
										<span>
											{ form.type === 'booking'
												? __(
														'Booking form',
														'kalendora'
												  )
												: __(
														'Standard form',
														'kalendora'
												  ) }
										</span>
										<span>
											{ sprintf(
												/* translators: %d: number of fields. */
												_n(
													'%d field',
													'%d fields',
													form.field_count,
													'kalendora'
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
													'kalendora'
												),
												form.entries
											) }
										</span>
										<button
											type="button"
											className="kalendora-shortcode"
											onClick={ () => copy( form ) }
											title={ __(
												'Copy shortcode',
												'kalendora'
											) }
										>
											<code>
												[kalendora id=&quot;{ form.id }
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
								<div className="kalendora-list__actions">
									<Button
										variant="secondary"
										onClick={ () => navigate( form.id ) }
									>
										{ __( 'Edit', 'kalendora' ) }
									</Button>
									<Button
										variant="tertiary"
										onClick={ () => duplicate( form.id ) }
									>
										{ __( 'Duplicate', 'kalendora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: form title. */
											__(
												'Delete the form "%s"? Entries are kept.',
												'kalendora'
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
		document.body.classList.toggle( 'kalendora-is-building', formId > 0 );
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
