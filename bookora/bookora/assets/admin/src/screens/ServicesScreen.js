import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { config, errorMessage, get } from '../api';
import ConfirmButton from '../components/ConfirmButton';
import useCollection from '../components/useCollection';

const EMPTY = {
	name: '',
	description: '',
	duration: 60,
	buffer_before: 0,
	buffer_after: 0,
	capacity: 1,
	price: 0,
	color: '#2563eb',
	min_notice: 0,
	max_advance: 0,
	status: 'active',
	resource_ids: [],
};

function formatPrice( price ) {
	if ( ! price ) {
		return __( 'Free', 'bookora' );
	}
	try {
		return new Intl.NumberFormat( config.locale || undefined, {
			style: 'currency',
			currency: config.currency || 'EUR',
		} ).format( price );
	} catch ( e ) {
		return String( price );
	}
}

function ServiceForm( { service, resources, onSave, onClose } ) {
	const [ data, setData ] = useState( { ...EMPTY, ...service } );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const set = ( key ) => ( value ) => setData( { ...data, [ key ]: value } );
	const num = ( key ) => ( value ) => set( key )( Number( value ) || 0 );

	const toggleResource = ( id, checked ) =>
		set( 'resource_ids' )(
			checked
				? [ ...data.resource_ids, id ]
				: data.resource_ids.filter( ( r ) => r !== id )
		);

	const submit = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		onSave( data )
			.then( onClose )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={
				data.id
					? __( 'Edit service', 'bookora' )
					: __( 'New service', 'bookora' )
			}
			onRequestClose={ onClose }
			className="bookora-modal"
		>
			<form onSubmit={ submit } className="bookora-form">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'bookora' ) }
					value={ data.name }
					onChange={ set( 'name' ) }
					required
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description', 'bookora' ) }
					value={ data.description }
					onChange={ set( 'description' ) }
					rows={ 3 }
				/>

				<div className="bookora-grid">
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 5 }
						step={ 5 }
						label={ __( 'Duration (minutes)', 'bookora' ) }
						value={ data.duration }
						onChange={ num( 'duration' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						step="0.01"
						label={ sprintf(
							/* translators: %s: currency code. */
							__( 'Price (%s)', 'bookora' ),
							config.currency || 'EUR'
						) }
						value={ data.price }
						onChange={ num( 'price' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						step={ 5 }
						label={ __( 'Buffer before (minutes)', 'bookora' ) }
						value={ data.buffer_before }
						onChange={ num( 'buffer_before' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						step={ 5 }
						label={ __( 'Buffer after (minutes)', 'bookora' ) }
						value={ data.buffer_after }
						onChange={ num( 'buffer_after' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Places per appointment', 'bookora' ) }
						help={ __(
							'1 = individual appointment, more = group or class.',
							'bookora'
						) }
						value={ data.capacity }
						onChange={ num( 'capacity' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="color"
						label={ __( 'Color', 'bookora' ) }
						value={ data.color }
						onChange={ set( 'color' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Minimum notice (minutes)', 'bookora' ) }
						help={ __( '0 = use the global setting.', 'bookora' ) }
						value={ data.min_notice }
						onChange={ num( 'min_notice' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Bookable up to (days ahead)', 'bookora' ) }
						help={ __( '0 = use the global setting.', 'bookora' ) }
						value={ data.max_advance }
						onChange={ num( 'max_advance' ) }
					/>
				</div>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'bookora' ) }
					value={ data.status }
					options={ [
						{ label: __( 'Active', 'bookora' ), value: 'active' },
						{
							label: __( 'Inactive (hidden)', 'bookora' ),
							value: 'inactive',
						},
					] }
					onChange={ set( 'status' ) }
				/>

				<fieldset className="bookora-fieldset">
					<legend>
						{ __( 'Who provides this service?', 'bookora' ) }
					</legend>
					{ resources.length === 0 && (
						<p className="description">
							{ __(
								'No staff members yet. Without assignment, the service uses the general opening hours.',
								'bookora'
							) }
						</p>
					) }
					{ resources.map( ( resource ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ resource.id }
							label={ resource.name }
							checked={ data.resource_ids.includes(
								resource.id
							) }
							onChange={ ( checked ) =>
								toggleResource( resource.id, checked )
							}
						/>
					) ) }
				</fieldset>

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
						{ __( 'Save', 'bookora' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function ServicesScreen() {
	const { items, notice, setNotice, save, remove } =
		useCollection( '/services' );
	const [ resources, setResources ] = useState( [] );
	const [ editing, setEditing ] = useState( null );

	useEffect( () => {
		get( '/resources' )
			.then( setResources )
			.catch( () => {} );
	}, [] );

	if ( ! items ) {
		return <Spinner />;
	}

	const resourceName = ( id ) =>
		resources.find( ( r ) => r.id === id )?.name || `#${ id }`;

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
						'Services are what customers can book – e.g. a haircut, a consultation or a course.',
						'bookora'
					) }
				</p>
				<Button variant="primary" onClick={ () => setEditing( {} ) }>
					{ __( 'Add service', 'bookora' ) }
				</Button>
			</div>

			{ items.length === 0 ? (
				<Card>
					<CardBody className="bookora-empty">
						<p>{ __( 'No services yet.', 'bookora' ) }</p>
						<Button
							variant="secondary"
							onClick={ () => setEditing( {} ) }
						>
							{ __( 'Create your first service', 'bookora' ) }
						</Button>
					</CardBody>
				</Card>
			) : (
				<div className="bookora-list">
					{ items.map( ( service ) => (
						<Card key={ service.id } className="bookora-list__item">
							<CardBody>
								<span
									className="bookora-dot"
									style={ { background: service.color } }
								/>
								<div className="bookora-list__main">
									<strong>{ service.name }</strong>
									{ service.status === 'inactive' && (
										<span className="bookora-badge">
											{ __( 'Inactive', 'bookora' ) }
										</span>
									) }
									<div className="bookora-meta">
										<span>
											{ sprintf(
												/* translators: %d: minutes. */
												__( '%d min', 'bookora' ),
												service.duration
											) }
										</span>
										<span>
											{ formatPrice( service.price ) }
										</span>
										{ service.capacity > 1 && (
											<span>
												{ sprintf(
													/* translators: %d: number of places. */
													__(
														'%d places',
														'bookora'
													),
													service.capacity
												) }
											</span>
										) }
										{ service.resource_ids.length > 0 && (
											<span>
												{ service.resource_ids
													.map( resourceName )
													.join( ', ' ) }
											</span>
										) }
									</div>
								</div>
								<div className="bookora-list__actions">
									<Button
										variant="secondary"
										onClick={ () => setEditing( service ) }
									>
										{ __( 'Edit', 'bookora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: service name. */
											__(
												'Delete the service "%s"? Existing bookings are kept.',
												'bookora'
											),
											service.name
										) }
										onConfirm={ () => remove( service.id ) }
									/>
								</div>
							</CardBody>
						</Card>
					) ) }
				</div>
			) }

			{ editing && (
				<ServiceForm
					service={ editing }
					resources={ resources }
					onSave={ save }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}
