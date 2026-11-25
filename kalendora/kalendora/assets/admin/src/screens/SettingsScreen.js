import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ColorPalette,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import CodeEditor from '../components/CodeEditor';

const COLORS = [
	{ name: __( 'Blue', 'kalendora' ), color: '#2563eb' },
	{ name: __( 'Green', 'kalendora' ), color: '#1f6f50' },
	{ name: __( 'Teal', 'kalendora' ), color: '#0e7490' },
	{ name: __( 'Violet', 'kalendora' ), color: '#7c3aed' },
	{ name: __( 'Rose', 'kalendora' ), color: '#be185d' },
	{ name: __( 'Orange', 'kalendora' ), color: '#c2410c' },
	{ name: __( 'Anthracite', 'kalendora' ), color: '#374151' },
];

function DesignPreview( { settings } ) {
	const classes = [
		'kalendora-design-preview',
		`is-${ settings.form_density }`,
		`is-scheme-${ settings.form_scheme }`,
		settings.form_font === 'system' ? 'is-font-system' : '',
	].join( ' ' );
	return (
		<div
			className={ classes }
			style={ {
				'--p': settings.primary_color,
				'--r': `${ settings.form_radius }px`,
			} }
			aria-hidden="true"
		>
			<span className="kalendora-design-preview__label">
				{ __( 'Your name', 'kalendora' ) }
			</span>
			<span className="kalendora-design-preview__input">Anna Muster</span>
			<span className="kalendora-design-preview__slots">
				<span>09:00</span>
				<span className="is-active">10:00</span>
				<span>11:00</span>
			</span>
			<span className="kalendora-design-preview__button">
				{ __( 'Book now', 'kalendora' ) }
			</span>
		</div>
	);
}

function CancellationCard( { settings, set } ) {
	const feeOn = !! settings.cancel_fee_enabled;
	const percent = settings.cancel_fee_type !== 'fixed';
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Cancelling & moving by customers', 'kalendora' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="kalendora-muted">
					{ __(
						'Every booking email contains a personal link. With it, customers can move or cancel their appointment themselves – you are informed by email and the time becomes free automatically.',
						'kalendora'
					) }
				</p>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Customers can cancel online', 'kalendora' ) }
					checked={ !! settings.self_cancel }
					onChange={ set( 'self_cancel' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Customers can move online', 'kalendora' ) }
					help={ __(
						'Only to free times of the same staff member.',
						'kalendora'
					) }
					checked={ !! settings.self_reschedule }
					onChange={ set( 'self_reschedule' ) }
				/>
				<TextControl
					type="number"
					__nextHasNoMarginBottom
					label={ __(
						'Free of charge up to (hours before the appointment)',
						'kalendora'
					) }
					help={ __(
						'After this, online changes are no longer possible – unless you use a cancellation fee (below). 0 = until the start.',
						'kalendora'
					) }
					min={ 0 }
					value={ settings.cancel_limit_hours }
					onChange={ ( v ) =>
						set( 'cancel_limit_hours' )( Number( v ) )
					}
				/>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Use a cancellation fee', 'kalendora' ) }
					help={ __(
						'Common in practices: "Cancel up to 24 hours before, otherwise a fee applies." Customers must accept the terms when booking. Can be changed per service.',
						'kalendora'
					) }
					checked={ feeOn }
					onChange={ set( 'cancel_fee_enabled' ) }
				/>
				{ feeOn && (
					<div className="kalendora-subsettings">
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Fee type', 'kalendora' ) }
							value={ settings.cancel_fee_type }
							options={ [
								{
									label: __(
										'Percent of the service price',
										'kalendora'
									),
									value: 'percent',
								},
								{
									label: __( 'Fixed amount (€)', 'kalendora' ),
									value: 'fixed',
								},
							] }
							onChange={ set( 'cancel_fee_type' ) }
						/>
						<TextControl
							type="number"
							__nextHasNoMarginBottom
							label={
								percent
									? __( 'Fee in %', 'kalendora' )
									: __( 'Fee in €', 'kalendora' )
							}
							min={ 0 }
							max={ percent ? 100 : undefined }
							step={ percent ? 1 : 0.01 }
							value={ settings.cancel_fee_value }
							onChange={ ( v ) =>
								set( 'cancel_fee_value' )( Number( v ) )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Also charge if the customer does not attend',
								'kalendora'
							) }
							help={ __(
								'Recorded automatically when you mark the booking as "Not attended".',
								'kalendora'
							) }
							checked={ !! settings.cancel_fee_no_show }
							onChange={ set( 'cancel_fee_no_show' ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __(
								'Cancelling online after the deadline',
								'kalendora'
							) }
							value={ settings.cancel_late_online }
							options={ [
								{
									label: __(
										'Allowed – the fee is shown beforehand and recorded',
										'kalendora'
									),
									value: 'fee',
								},
								{
									label: __(
										'Not possible – customers have to call',
										'kalendora'
									),
									value: 'block',
								},
							] }
							onChange={ set( 'cancel_late_online' ) }
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Own wording (optional)', 'kalendora' ) }
							help={ __(
								'Leave empty for the automatic text. Placeholders: {hours}, {fee}.',
								'kalendora'
							) }
							value={ settings.cancel_policy_text }
							onChange={ set( 'cancel_policy_text' ) }
							rows={ 3 }
						/>
						<Notice status="info" isDismissible={ false }>
							{ __(
								"The free version records the fee and shows it in the bookings – you invoice it yourself. Note: In Germany a fee is only enforceable if it was agreed before; Kalendora stores the customer's consent with the booking. This is not legal advice.",
								'kalendora'
							) }
						</Notice>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

const path = `${ window.kalendoraAdmin?.restPath || '/kalendora/v1' }/settings`;

export default function SettingsScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path } )
			.then( setSettings )
			.catch( ( error ) =>
				setNotice( { status: 'error', text: error.message } )
			);
	}, [] );

	if ( ! settings ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const set = ( key ) => ( value ) =>
		setSettings( { ...settings, [ key ]: value } );

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path, method: 'POST', data: settings } )
			.then( ( saved ) => {
				setSettings( saved );
				setNotice( {
					status: 'success',
					text: __( 'Settings saved.', 'kalendora' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', text: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="kalendora-settings">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'General', 'kalendora' ) }</h2>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Business name', 'kalendora' ) }
						value={ settings.business_name }
						onChange={ set( 'business_name' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="tel"
						label={ __( 'Phone', 'kalendora' ) }
						help={ __(
							'Shown to customers when a short-notice change is only possible by phone. Placeholder {business_phone} in emails.',
							'kalendora'
						) }
						value={ settings.business_phone }
						onChange={ set( 'business_phone' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="email"
						label={ __( 'Notification email', 'kalendora' ) }
						help={ __(
							'New bookings are sent here. Leave empty to use the site admin email.',
							'kalendora'
						) }
						value={ settings.admin_email }
						onChange={ set( 'admin_email' ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Time format', 'kalendora' ) }
						value={ settings.time_format }
						options={ [
							{ label: '24h (14:30)', value: '24h' },
							{ label: '12h (2:30 PM)', value: '12h' },
						] }
						onChange={ set( 'time_format' ) }
						__nextHasNoMarginBottom
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Booking rules', 'kalendora' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						label={ __(
							'Time grid for date fields without a service',
							'kalendora'
						) }
						help={ __(
							'Booking forms use the start times defined per service.',
							'kalendora'
						) }
						value={ String( settings.slot_interval ) }
						options={ [ 5, 10, 15, 20, 30, 45, 60, 90, 120 ].map(
							( m ) => ( {
								label: `${ m } min`,
								value: String( m ),
							} )
						) }
						onChange={ ( v ) =>
							set( 'slot_interval' )( Number( v ) )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'New bookings are', 'kalendora' ) }
						value={ settings.default_status }
						options={ [
							{
								label: __(
									'Pending – confirm with one click',
									'kalendora'
								),
								value: 'pending',
							},
							{
								label: __(
									'Confirmed automatically',
									'kalendora'
								),
								value: 'confirmed',
							},
						] }
						onChange={ set( 'default_status' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						__nextHasNoMarginBottom
						label={ __( 'Minimum notice (minutes)', 'kalendora' ) }
						min={ 0 }
						value={ settings.min_notice_minutes }
						onChange={ ( v ) =>
							set( 'min_notice_minutes' )( Number( v ) )
						}
					/>
					<TextControl
						type="number"
						__nextHasNoMarginBottom
						label={ __( 'Bookable up to (days ahead)', 'kalendora' ) }
						min={ 1 }
						value={ settings.max_advance_days }
						onChange={ ( v ) =>
							set( 'max_advance_days' )( Number( v ) )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __(
							'Visitors from another time zone see times in',
							'kalendora'
						) }
						help={ __(
							'Visitors in another time zone can always switch. Choose "their own time" for online appointments (video, phone).',
							'kalendora'
						) }
						value={ settings.customer_timezone }
						options={ [
							{
								label: __(
									'the local time of the business',
									'kalendora'
								),
								value: 'business',
							},
							{
								label: __( 'their own time', 'kalendora' ),
								value: 'visitor',
							},
						] }
						onChange={ set( 'customer_timezone' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Fill gaps between appointments',
							'kalendora'
						) }
						help={ __(
							'Also offers a start time directly after an existing appointment (incl. break). Less idle time, but start times are less regular.',
							'kalendora'
						) }
						checked={ !! settings.fill_gaps }
						onChange={ set( 'fill_gaps' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __(
							'Max. appointments per day (all services)',
							'kalendora'
						) }
						help={ __(
							'0 = no limit. Limits per service can be set in the service.',
							'kalendora'
						) }
						value={ settings.max_per_day }
						onChange={ ( v ) =>
							set( 'max_per_day' )( Number( v ) )
						}
					/>
				</CardBody>
			</Card>

			<CancellationCard settings={ settings } set={ set } />

			<Card>
				<CardHeader>
					<h2>{ __( 'Design & own code', 'kalendora' ) }</h2>
				</CardHeader>
				<CardBody>
					<div className="kalendora-design">
						<div className="kalendora-design__controls">
							<div>
								<p className="kalendora-label">
									{ __( 'Main color', 'kalendora' ) }
								</p>
								<ColorPalette
									colors={ COLORS }
									value={ settings.primary_color }
									onChange={ ( c ) =>
										set( 'primary_color' )( c || '#2563eb' )
									}
									clearable={ false }
								/>
							</div>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Corner radius', 'kalendora' ) }
								value={ settings.form_radius }
								min={ 0 }
								max={ 24 }
								onChange={ ( v ) =>
									set( 'form_radius' )( Number( v ) || 0 )
								}
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Spacing', 'kalendora' ) }
								value={ settings.form_density }
								options={ [
									{
										label: __( 'Compact', 'kalendora' ),
										value: 'compact',
									},
									{
										label: __( 'Normal', 'kalendora' ),
										value: 'normal',
									},
									{
										label: __( 'Spacious', 'kalendora' ),
										value: 'comfortable',
									},
								] }
								onChange={ set( 'form_density' ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Color scheme', 'kalendora' ) }
								help={ __(
									'"Dark" for websites with a dark background. "Automatic" follows the visitor\'s device setting.',
									'kalendora'
								) }
								value={ settings.form_scheme }
								options={ [
									{
										label: __( 'Light', 'kalendora' ),
										value: 'light',
									},
									{
										label: __( 'Dark', 'kalendora' ),
										value: 'dark',
									},
									{
										label: __( 'Automatic', 'kalendora' ),
										value: 'auto',
									},
								] }
								onChange={ set( 'form_scheme' ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Font', 'kalendora' ) }
								value={ settings.form_font }
								options={ [
									{
										label: __(
											'Like the website (theme)',
											'kalendora'
										),
										value: 'inherit',
									},
									{
										label: __(
											'System font (like apps)',
											'kalendora'
										),
										value: 'system',
									},
								] }
								onChange={ set( 'form_font' ) }
							/>
						</div>
						<DesignPreview settings={ settings } />
					</div>

					{ ! window.kalendoraAdmin?.caps?.unfilteredHtml && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Only administrators may add own CSS and JavaScript.',
								'kalendora'
							) }
						</Notice>
					) }
					<CodeEditor
						mode="css"
						label={ __( 'CSS for all forms', 'kalendora' ) }
						help={ __(
							'Example: .kalendora { --kalendora-gap: 24px; } – all CSS variables are listed in the developer documentation.',
							'kalendora'
						) }
						value={ settings.global_css }
						disabled={ ! window.kalendoraAdmin?.caps?.unfilteredHtml }
						onChange={ set( 'global_css' ) }
					/>
					<CodeEditor
						mode="js"
						label={ __( 'JavaScript for all forms', 'kalendora' ) }
						help={ __(
							'Runs once on every page with a Kalendora form. Example: kalendora.on( "success", ( data, form ) => { … } );',
							'kalendora'
						) }
						value={ settings.global_js }
						disabled={ ! window.kalendoraAdmin?.caps?.unfilteredHtml }
						onChange={ set( 'global_js' ) }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Security & privacy', 'kalendora' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="kalendora-muted">
						{ __(
							'Spam protection is always active and invisible for your customers: hidden trap field, minimum fill-in time, limits per sender and per IP address, and at most 3 open requests per email address.',
							'kalendora'
						) }
					</p>
					<ToggleControl
						label={ __(
							'Delete all Kalendora data when the plugin is uninstalled',
							'kalendora'
						) }
						checked={ settings.delete_on_uninstall }
						onChange={ set( 'delete_on_uninstall' ) }
						__nextHasNoMarginBottom
					/>
				</CardBody>
			</Card>

			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ saving }
				onClick={ save }
			>
				{ __( 'Save settings', 'kalendora' ) }
			</Button>
		</div>
	);
}
