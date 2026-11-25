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
	staff: __( 'Staff member', 'kalendora' ),
	room: __( 'Room', 'kalendora' ),
	equipment: __( 'Equipment', 'kalendora' ),
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
				__( 'Website timezone (%s)', 'kalendora' ),
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
					? __( 'Edit staff member / resource', 'kalendora' )
					: __( 'New staff member / resource', 'kalendora' )
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
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Type', 'kalendora' ) }
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
					label={ __( 'Name', 'kalendora' ) }
					value={ data.name }
					onChange={ set( 'name' ) }
					required
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="email"
					label={ __( 'Email', 'kalendora' ) }
					help={ __(
						'Receives notifications about own bookings.',
						'kalendora'
					) }
					value={ data.email }
					onChange={ set( 'email' ) }
				/>
				<div className="kalendora-grid">
					<ComboboxControl
						__nextHasNoMarginBottom
						label={ __( 'Timezone', 'kalendora' ) }
						value={ data.timezone }
						options={ tzOptions }
						onChange={ ( value ) =>
							set( 'timezone' )( value || '' )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="color"
						label={ __( 'Color', 'kalendora' ) }
						value={ data.color }
						onChange={ set( 'color' ) }
					/>
				</div>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'kalendora' ) }
					value={ data.status }
					options={ [
						{ label: __( 'Active', 'kalendora' ), value: 'active' },
						{
							label: __( 'Inactive (not bookable)', 'kalendora' ),
							value: 'inactive',
						},
					] }
					onChange={ set( 'status' ) }
				/>
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
		`${ config.adminUrl }?page=kalendora-hours&resource=${ id }`;

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
					{ ! config.isPro &&
						sprintf(
							/* translators: 1: used resources, 2: limit. */
							__(
								'%1$d of %2$d staff members/resources used in the free version.',
								'kalendora'
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
					{ __( 'Add staff member / resource', 'kalendora' ) }
				</Button>
			</div>

			{ limitReached && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Need more staff members or rooms? Kalendora Pro supports unlimited resources.',
						'kalendora'
					) }
				</Notice>
			) }

			{ items.length === 0 ? (
				<Card>
					<CardBody className="kalendora-empty">
						<p>
							{ __(
								'Working alone? Then you do not need any staff members – Kalendora uses the general opening hours.',
								'kalendora'
							) }
						</p>
					</CardBody>
				</Card>
			) : (
				<div className="kalendora-list">
					{ items.map( ( resource ) => (
						<Card
							key={ resource.id }
							className="kalendora-list__item"
						>
							<CardBody>
								<span
									className="kalendora-dot"
									style={ { background: resource.color } }
								/>
								<div className="kalendora-list__main">
									<strong>{ resource.name }</strong>
									{ resource.status === 'inactive' && (
										<span className="kalendora-badge">
											{ __( 'Inactive', 'kalendora' ) }
										</span>
									) }
									<div className="kalendora-meta">
										<span>{ TYPES[ resource.type ] }</span>
										{ resource.email && (
											<span>{ resource.email }</span>
										) }
										{ resource.timezone && (
											<span>{ resource.timezone }</span>
										) }
									</div>
								</div>
								<div className="kalendora-list__actions">
									<Button
										variant="tertiary"
										href={ hoursUrl( resource.id ) }
									>
										{ __( 'Working hours', 'kalendora' ) }
									</Button>
									<Button
										variant="secondary"
										onClick={ () => setEditing( resource ) }
									>
										{ __( 'Edit', 'kalendora' ) }
									</Button>
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: resource name. */
											__(
												'Delete "%s" including working hours? Existing bookings are kept.',
												'kalendora'
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
