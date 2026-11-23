import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	ComboboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { config, errorMessage, timezones } from '../api';
import ConfirmButton from '../components/ConfirmButton';
import useCollection from '../components/useCollection';

const EMPTY = {
	type: 'staff',
	name: '',
	email: '',
	timezone: '',
	color: '#2563eb',
	status: 'active',
};

const TYPES = {
	staff: __( 'Staff member', 'bookora' ),
	room: __( 'Room', 'bookora' ),
	equipment: __( 'Equipment', 'bookora' ),
};

function ResourceForm( { resource, onSave, onClose } ) {
	const [ data, setData ] = useState( { ...EMPTY, ...resource } );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setData( { ...data, [ key ]: value } );

	const tzOptions = [
		{
			label: sprintf(
				/* translators: %s: timezone of the website. */
				__( 'Website timezone (%s)', 'bookora' ),
				config.siteTimezone
			),
			value: '',
		},
		...timezones().map( ( tz ) => ( { label: tz, value: tz } ) ),
	];

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
					? __( 'Edit staff member / resource', 'bookora' )
					: __( 'New staff member / resource', 'bookora' )
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
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Type', 'bookora' ) }
					value={ data.type }
					options={ Object.entries( TYPES ).map(
						( [ value, label ] ) => ( {
							value,
							label,
						} )
					) }
					onChange={ set( 'type' ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'bookora' ) }
					value={ data.name }
					onChange={ set( 'name' ) }
					required
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="email"
					label={ __( 'Email', 'bookora' ) }
					help={ __(
						'Receives notifications about own bookings.',
						'bookora'
					) }
					value={ data.email }
					onChange={ set( 'email' ) }
				/>
				<div className="bookora-grid">
					<ComboboxControl
						__nextHasNoMarginBottom
						label={ __( 'Timezone', 'bookora' ) }
						value={ data.timezone }
						options={ tzOptions }
						onChange={ ( value ) =>
							set( 'timezone' )( value || '' )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="color"
						label={ __( 'Color', 'bookora' ) }
						value={ data.color }
						onChange={ set( 'color' ) }
					/>
				</div>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'bookora' ) }
					value={ data.status }
					options={ [
						{ label: __( 'Active', 'bookora' ), value: 'active' },
						{
							label: __( 'Inactive (not bookable)', 'bookora' ),
							value: 'inactive',
						},
					] }
					onChange={ set( 'status' ) }
				/>
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

export default function ResourcesScreen() {
	const { items, notice, setNotice, save, remove } =
		useCollection( '/resources' );
	const [ editing, setEditing ] = useState( null );

	if ( ! items ) {
		return <Spinner />;
	}

	const limit = config.resourceLimit || 3;
	const limitReached = ! config.isPro && items.length >= limit;
	const hoursUrl = ( id ) =>
		`${ config.adminUrl }?page=bookora-hours&resource=${ id }`;

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
					{ ! config.isPro &&
						sprintf(
							/* translators: 1: used resources, 2: limit. */
							__(
								'%1$d of %2$d staff members/resources used in the free version.',
								'bookora'
							),
							items.length,
							limit
						) }
				</p>
				<Button
					variant="primary"
					disabled={ limitReached }
					onClick={ () => setEditing( {} ) }
				>
					{ __( 'Add staff member / resource', 'bookora' ) }
				</Button>
			</div>

			{ limitReached && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Need more staff members or rooms? Bookora Pro supports unlimited resources.',
						'bookora'
					) }
				</Notice>
			) }

			{ items.length === 0 ? (
				<Card>
					<CardBody className="bookora-empty">
						<p>
							{ __(
								'Working alone? Then you do not need any staff members – Bookora uses the general opening hours.',
								'bookora'
							) }
						</p>
					</CardBody>
				</Card>
			) : (
				<div className="bookora-list">
					{ items.map( ( resource ) => (
						<Card
							key={ resource.id }
							className="bookora-list__item"
						>
							<CardBody>
								<span
									className="bookora-dot"
									style={ { background: resource.color } }
								/>
								<div className="bookora-list__main">
									<strong>{ resource.name }</strong>
									{ resource.status === 'inactive' && (
										<span className="bookora-badge">
											{ __( 'Inactive', 'bookora' ) }
										</span>
									) }
									<div className="bookora-meta">
										<span>{ TYPES[ resource.type ] }</span>
										{ resource.email && (
											<span>{ resource.email }</span>
										) }
										{ resource.timezone && (
											<span>{ resource.timezone }</span>
										) }
									</div>
								</div>
								<div className="bookora-list__actions">
									<Button
										variant="tertiary"
										href={ hoursUrl( resource.id ) }
									>
										{ __( 'Working hours', 'bookora' ) }
									</Button>
									<Button
										variant="secondary"
										onClick={ () => setEditing( resource ) }
									>
										{ __( 'Edit', 'bookora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: resource name. */
											__(
												'Delete "%s" including working hours? Existing bookings are kept.',
												'bookora'
											),
											resource.name
										) }
										onConfirm={ () =>
											remove( resource.id )
										}
									/>
								</div>
							</CardBody>
						</Card>
					) ) }
				</div>
			) }

			{ editing && (
				<ResourceForm
					resource={ editing }
					onSave={ save }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}
