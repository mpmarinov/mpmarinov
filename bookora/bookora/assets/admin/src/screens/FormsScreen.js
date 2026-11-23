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

const TYPES = [
	{
		value: 'booking',
		icon: 'calendar-alt',
		title: __( 'Booking form', 'bookora' ),
		text: __(
			'Customers choose a service, date and time. Booked times are blocked automatically.',
			'bookora'
		),
	},
	{
		value: 'standard',
		icon: 'feedback',
		title: __( 'Standard form', 'bookora' ),
		text: __(
			'Contact form, request, survey, application – any form without a calendar.',
			'bookora'
		),
	},
];

function NewFormModal( { onClose } ) {
	const [ title, setTitle ] = useState( '' );
	const [ type, setType ] = useState( 'booking' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const starter =
		type === 'booking'
			? [
					{
						type: 'service',
						label: __( 'Service', 'bookora' ),
						required: true,
					},
					{
						type: 'appointment',
						label: __( 'Choose a date and time', 'bookora' ),
						required: true,
					},
					{
						type: 'name',
						label: __( 'Name', 'bookora' ),
						required: true,
						format: 'first_last',
					},
					{
						type: 'email',
						key: 'email',
						label: __( 'Email', 'bookora' ),
						required: true,
					},
					{ type: 'phone', label: __( 'Phone', 'bookora' ) },
					{
						type: 'textarea',
						label: __( 'Message', 'bookora' ),
						rows: 3,
					},
			  ]
			: [
					{
						type: 'name',
						label: __( 'Name', 'bookora' ),
						required: true,
						format: 'first_last',
					},
					{
						type: 'email',
						key: 'email',
						label: __( 'Email', 'bookora' ),
						required: true,
					},
					{
						type: 'textarea',
						label: __( 'Message', 'bookora' ),
						required: true,
						rows: 5,
					},
			  ];

	const submit = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		post( '/forms', {
			title:
				title ||
				( type === 'booking'
					? __( 'Booking form', 'bookora' )
					: __( 'Contact form', 'bookora' ) ),
			type,
			fields: starter,
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
			className="bookora-modal"
		>
			<form onSubmit={ submit } className="bookora-form">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<div
					className="bookora-type-picker"
					role="radiogroup"
					aria-label={ __( 'Form type', 'bookora' ) }
				>
					{ TYPES.map( ( t ) => (
						<button
							type="button"
							role="radio"
							aria-checked={ type === t.value }
							key={ t.value }
							className={ `bookora-type-picker__option${
								type === t.value ? ' is-selected' : ''
							}` }
							onClick={ () => setType( t.value ) }
						>
							<Dashicon icon={ t.icon } />
							<strong>{ t.title }</strong>
							<span>{ t.text }</span>
						</button>
					) ) }
				</div>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Form title', 'bookora' ) }
					placeholder={
						type === 'booking'
							? __( 'e.g. Book an appointment', 'bookora' )
							: __( 'e.g. Contact', 'bookora' )
					}
					value={ title }
					onChange={ setTitle }
				/>
				<p className="bookora-muted">
					{ __(
						'We start you with the most common fields – you can change everything in the next step.',
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
