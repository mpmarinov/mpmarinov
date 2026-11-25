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
import DayGrid from '../components/DayGrid';

const EMPTY = {
	name: '',
	description: '',
	duration: 60,
	slot_interval: 0,
	max_per_day: 0,
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
		return __( 'Free', 'kalendora' );
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

function ServiceCancellation( { value, onChange } ) {
	const mode = value.cancel_fee || 'inherit';
	const upd = ( key ) => ( v ) => onChange( { ...value, [ key ]: v } );
	return (
		<fieldset className="kalendora-fieldset">
			<legend>{ __( 'Cancellation & reminders', 'kalendora' ) }</legend>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Send reminders for this service', 'kalendora' ) }
				checked={ value.reminders !== false }
				onChange={ upd( 'reminders' ) }
			/>
			<div className="kalendora-grid">
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					label={ __(
						'Free cancellation up to (hours before)',
						'kalendora'
					) }
					help={ __(
						'Empty = use the global setting.',
						'kalendora'
					) }
					value={ value.cancel_limit_hours ?? '' }
					onChange={ ( v ) =>
						upd( 'cancel_limit_hours' )(
							v === '' ? '' : Math.max( 0, Number( v ) || 0 )
						)
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Cancellation fee', 'kalendora' ) }
					value={ mode }
					options={ [
						{
							label: __( 'As in the settings', 'kalendora' ),
							value: 'inherit',
						},
						{
							label: __( 'No fee for this service', 'kalendora' ),
							value: 'off',
						},
						{
							label: __(
								'Own fee for this service',
								'kalendora'
							),
							value: 'custom',
						},
					] }
					onChange={ upd( 'cancel_fee' ) }
				/>
				{ mode === 'custom' && (
					<>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Fee type', 'kalendora' ) }
							value={ value.cancel_fee_type || 'percent' }
							options={ [
								{
									label: __(
										'Percent of the service price',
										'kalendora'
									),
									value: 'percent',
								},
								{
									label: __(
										'Fixed amount (€)',
										'kalendora'
									),
									value: 'fixed',
								},
							] }
							onChange={ upd( 'cancel_fee_type' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							step="0.01"
							label={
								value.cancel_fee_type === 'fixed'
									? __( 'Fee in €', 'kalendora' )
									: __( 'Fee in %', 'kalendora' )
							}
							value={ value.cancel_fee_value ?? '' }
							onChange={ ( v ) =>
								upd( 'cancel_fee_value' )( Number( v ) || 0 )
							}
						/>
					</>
				) }
			</div>
		</fieldset>
	);
}

/**
 * Where the appointment takes place: customer address (with travel time), office or remote.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Service settings.
 * @param {Function} props.onChange Change handler (whole settings object).
 */
function ServiceLocation( { value, onChange } ) {
	const loc = value.location || {};
	const types = config.locationTypes || {};
	const type = loc.type || '';
	const upd = ( key ) => ( v ) =>
		onChange( { ...value, location: { ...loc, [ key ]: v } } );
	const has = ( t ) =>
		type === t ||
		( type === 'choice' && ( loc.choices || [] ).includes( t ) );
	const toggleChoice = ( t, on ) =>
		upd( 'choices' )(
			on
				? [ ...( loc.choices || [] ), t ]
				: ( loc.choices || [] ).filter( ( c ) => c !== t )
		);

	return (
		<fieldset className="kalendora-fieldset">
			<legend>{ __( 'Location', 'kalendora' ) }</legend>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __(
					'Where does the appointment take place?',
					'kalendora'
				) }
				value={ type }
				options={ [
					{ label: __( 'Not specified', 'kalendora' ), value: '' },
					...Object.entries( types ).map( ( [ v, label ] ) => ( {
						label,
						value: v,
					} ) ),
				] }
				onChange={ upd( 'type' ) }
			/>
			{ type === 'choice' && (
				<div className="kalendora-fieldset__row">
					{ [ 'onsite', 'office', 'remote' ].map( ( t ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ t }
							label={ types[ t ] }
							checked={ ( loc.choices || [] ).includes( t ) }
							onChange={ ( on ) => toggleChoice( t, on ) }
						/>
					) ) }
				</div>
			) }
			{ has( 'onsite' ) && (
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					step={ 5 }
					label={ __(
						'Travel time each way (minutes)',
						'kalendora'
					) }
					help={
						config.travelAuto
							? __(
									'Calculated automatically from the customer’s address. This value is used if the route cannot be calculated.',
									'kalendora'
							  )
							: __(
									'Blocked before and after the appointment, within the working hours. The customer enters the address when booking.',
									'kalendora'
							  )
					}
					value={ loc.travel ?? 0 }
					onChange={ ( v ) =>
						upd( 'travel' )( Math.max( 0, Number( v ) || 0 ) )
					}
				/>
			) }
			{ ( has( 'office' ) || has( 'remote' ) ) && (
				<TextareaControl
					__nextHasNoMarginBottom
					rows={ 2 }
					label={
						has( 'remote' )
							? __(
									'Access details for remote appointments (link, ID, instructions)',
									'kalendora'
							  )
							: __( 'Directions to the office', 'kalendora' )
					}
					help={
						has( 'office' ) && ! config.officeAddress
							? __(
									'Please enter your address under Settings → General – it is used for office appointments.',
									'kalendora'
							  )
							: __(
									'Shown in the confirmation and the reminder.',
									'kalendora'
							  )
					}
					value={ loc.info || '' }
					onChange={ upd( 'info' ) }
				/>
			) }
		</fieldset>
	);
}

function ServiceForm( { service, resources, weekly, onSave, onClose } ) {
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
					? __( 'Edit service', 'kalendora' )
					: __( 'New service', 'kalendora' )
			}
			onRequestClose={ onClose }
			className="kalendora-modal"
		>
			<form onSubmit={ submit } className="kalendora-form">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'kalendora' ) }
					value={ data.name }
					onChange={ set( 'name' ) }
					required
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description', 'kalendora' ) }
					value={ data.description }
					onChange={ set( 'description' ) }
					rows={ 3 }
				/>

				<div className="kalendora-grid">
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 5 }
						step={ 5 }
						label={ __( 'Duration (minutes)', 'kalendora' ) }
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
							__( 'Price (%s)', 'kalendora' ),
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
						label={ __(
							'Preparation before (minutes)',
							'kalendora'
						) }
						value={ data.buffer_before }
						onChange={ num( 'buffer_before' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						step={ 5 }
						label={ __(
							'Break after the appointment (minutes)',
							'kalendora'
						) }
						help={ __(
							'e.g. cleaning, preparing the room',
							'kalendora'
						) }
						value={ data.buffer_after }
						onChange={ num( 'buffer_after' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Max. appointments per day', 'kalendora' ) }
						help={ __(
							'0 = as many as fit into the working hours.',
							'kalendora'
						) }
						value={ data.max_per_day }
						onChange={ num( 'max_per_day' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Places per appointment', 'kalendora' ) }
						help={ __(
							'1 = individual appointment, more = group or class.',
							'kalendora'
						) }
						value={ data.capacity }
						onChange={ num( 'capacity' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="color"
						label={ __( 'Color', 'kalendora' ) }
						value={ data.color }
						onChange={ set( 'color' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Minimum notice (minutes)', 'kalendora' ) }
						help={ __(
							'0 = use the global setting.',
							'kalendora'
						) }
						value={ data.min_notice }
						onChange={ num( 'min_notice' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __(
							'Bookable up to (days ahead)',
							'kalendora'
						) }
						help={ __(
							'0 = use the global setting.',
							'kalendora'
						) }
						value={ data.max_advance }
						onChange={ num( 'max_advance' ) }
					/>
				</div>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Start times', 'kalendora' ) }
					help={ __(
						'Appointments are always placed within the working hours and end before closing time.',
						'kalendora'
					) }
					value={ String( data.slot_interval || 0 ) }
					options={ [
						{
							label: sprintf(
								/* translators: %d: minutes. */
								__(
									'One after another – every %d min (duration + preparation + break)',
									'kalendora'
								),
								( Number( data.duration ) || 0 ) +
									( Number( data.buffer_before ) || 0 ) +
									( Number( data.buffer_after ) || 0 )
							),
							value: '0',
						},
						...[ 10, 15, 20, 30, 45, 60, 90, 120 ].map( ( m ) => ( {
							label: sprintf(
								/* translators: %d: minutes. */
								__( 'Fixed grid – every %d min', 'kalendora' ),
								m
							),
							value: String( m ),
						} ) ),
					] }
					onChange={ ( v ) => set( 'slot_interval' )( Number( v ) ) }
				/>

				<DayGrid
					weekly={ weekly }
					data={ data }
					weekStart={ Number( config.weekStart ?? 1 ) }
				/>

				<ServiceLocation
					value={
						data.settings && ! Array.isArray( data.settings )
							? data.settings
							: {}
					}
					onChange={ set( 'settings' ) }
				/>

				<ServiceCancellation
					value={
						data.settings && ! Array.isArray( data.settings )
							? data.settings
							: {}
					}
					onChange={ set( 'settings' ) }
				/>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'kalendora' ) }
					value={ data.status }
					options={ [
						{ label: __( 'Active', 'kalendora' ), value: 'active' },
						{
							label: __( 'Inactive (hidden)', 'kalendora' ),
							value: 'inactive',
						},
					] }
					onChange={ set( 'status' ) }
				/>

				<fieldset className="kalendora-fieldset">
					<legend>
						{ __( 'Who provides this service?', 'kalendora' ) }
					</legend>
					{ resources.length === 0 && (
						<p className="description">
							{ __(
								'No staff members yet. Without assignment, the service uses the general opening hours.',
								'kalendora'
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
						{ __( 'Save', 'kalendora' ) }
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
	const [ weekly, setWeekly ] = useState( {} );
	const [ editing, setEditing ] = useState( null );

	useEffect( () => {
		get( '/resources' )
			.then( setResources )
			.catch( () => {} );
		get( '/schedules/weekly' )
			.then( ( res ) => setWeekly( res.weekly || {} ) )
			.catch( () => {} );
	}, [] );

	if ( ! items ) {
		return <Spinner />;
	}

	const resourceName = ( id ) =>
		resources.find( ( r ) => r.id === id )?.name || `#${ id }`;

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
						'Services are what customers can book – e.g. a haircut, a consultation or a course.',
						'kalendora'
					) }
				</p>
				<Button variant="primary" onClick={ () => setEditing( {} ) }>
					{ __( 'Add service', 'kalendora' ) }
				</Button>
			</div>

			{ items.length === 0 ? (
				<Card>
					<CardBody className="kalendora-empty">
						<p>{ __( 'No services yet.', 'kalendora' ) }</p>
						<Button
							variant="secondary"
							onClick={ () => setEditing( {} ) }
						>
							{ __( 'Create your first service', 'kalendora' ) }
						</Button>
					</CardBody>
				</Card>
			) : (
				<div className="kalendora-list">
					{ items.map( ( service ) => (
						<Card
							key={ service.id }
							className="kalendora-list__item"
						>
							<CardBody>
								<span
									className="kalendora-dot"
									style={ { background: service.color } }
								/>
								<div className="kalendora-list__main">
									<strong>{ service.name }</strong>
									{ service.status === 'inactive' && (
										<span className="kalendora-badge">
											{ __( 'Inactive', 'kalendora' ) }
										</span>
									) }
									<div className="kalendora-meta">
										<span>
											{ sprintf(
												/* translators: %d: minutes. */
												__( '%d min', 'kalendora' ),
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
														'kalendora'
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
								<div className="kalendora-list__actions">
									<Button
										variant="secondary"
										onClick={ () => setEditing( service ) }
									>
										{ __( 'Edit', 'kalendora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: service name. */
											__(
												'Delete the service "%s"? Existing bookings are kept.',
												'kalendora'
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
					weekly={ weekly }
					onSave={ save }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}
