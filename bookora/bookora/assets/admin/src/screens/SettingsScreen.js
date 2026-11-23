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
	ToggleControl,
} from '@wordpress/components';

import CodeEditor from '../components/CodeEditor';

const COLORS = [
	{ name: __( 'Blue', 'bookora' ), color: '#2563eb' },
	{ name: __( 'Green', 'bookora' ), color: '#1f6f50' },
	{ name: __( 'Teal', 'bookora' ), color: '#0e7490' },
	{ name: __( 'Violet', 'bookora' ), color: '#7c3aed' },
	{ name: __( 'Rose', 'bookora' ), color: '#be185d' },
	{ name: __( 'Orange', 'bookora' ), color: '#c2410c' },
	{ name: __( 'Anthracite', 'bookora' ), color: '#374151' },
];

function DesignPreview( { settings } ) {
	const classes = [
		'bookora-design-preview',
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
			<span className="bookora-design-preview__label">
				{ __( 'Your name', 'bookora' ) }
			</span>
			<span className="bookora-design-preview__input">Anna Muster</span>
			<span className="bookora-design-preview__slots">
				<span>09:00</span>
				<span className="is-active">10:00</span>
				<span>11:00</span>
			</span>
			<span className="bookora-design-preview__button">
				{ __( 'Book now', 'bookora' ) }
			</span>
		</div>
	);
}

const path = `${ window.bookoraAdmin?.restPath || '/bookora/v1' }/settings`;

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
					text: __( 'Settings saved.', 'bookora' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', text: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="bookora-settings">
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
					<h2>{ __( 'General', 'bookora' ) }</h2>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Business name', 'bookora' ) }
						value={ settings.business_name }
						onChange={ set( 'business_name' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="email"
						label={ __( 'Notification email', 'bookora' ) }
						help={ __(
							'New bookings are sent here. Leave empty to use the site admin email.',
							'bookora'
						) }
						value={ settings.admin_email }
						onChange={ set( 'admin_email' ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Time format', 'bookora' ) }
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
					<h2>{ __( 'Booking rules', 'bookora' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						label={ __(
							'Time grid for date fields without a service',
							'bookora'
						) }
						help={ __(
							'Booking forms use the start times defined per service.',
							'bookora'
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
						label={ __( 'New bookings are', 'bookora' ) }
						value={ settings.default_status }
						options={ [
							{
								label: __(
									'Pending – confirm with one click',
									'bookora'
								),
								value: 'pending',
							},
							{
								label: __(
									'Confirmed automatically',
									'bookora'
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
						label={ __( 'Minimum notice (minutes)', 'bookora' ) }
						min={ 0 }
						value={ settings.min_notice_minutes }
						onChange={ ( v ) =>
							set( 'min_notice_minutes' )( Number( v ) )
						}
					/>
					<TextControl
						type="number"
						__nextHasNoMarginBottom
						label={ __( 'Bookable up to (days ahead)', 'bookora' ) }
						min={ 1 }
						value={ settings.max_advance_days }
						onChange={ ( v ) =>
							set( 'max_advance_days' )( Number( v ) )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Fill gaps between appointments',
							'bookora'
						) }
						help={ __(
							'Also offers a start time directly after an existing appointment (incl. break). Less idle time, but start times are less regular.',
							'bookora'
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
							'bookora'
						) }
						help={ __(
							'0 = no limit. Limits per service can be set in the service.',
							'bookora'
						) }
						value={ settings.max_per_day }
						onChange={ ( v ) =>
							set( 'max_per_day' )( Number( v ) )
						}
					/>
					<TextControl
						type="number"
						__nextHasNoMarginBottom
						label={ __(
							'Customers can cancel up to (hours before)',
							'bookora'
						) }
						min={ 0 }
						value={ settings.cancel_limit_hours }
						onChange={ ( v ) =>
							set( 'cancel_limit_hours' )( Number( v ) )
						}
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Design & own code', 'bookora' ) }</h2>
				</CardHeader>
				<CardBody>
					<div className="bookora-design">
						<div className="bookora-design__controls">
							<div>
								<p className="bookora-label">
									{ __( 'Main color', 'bookora' ) }
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
								label={ __( 'Corner radius', 'bookora' ) }
								value={ settings.form_radius }
								min={ 0 }
								max={ 24 }
								onChange={ ( v ) =>
									set( 'form_radius' )( Number( v ) || 0 )
								}
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Spacing', 'bookora' ) }
								value={ settings.form_density }
								options={ [
									{
										label: __( 'Compact', 'bookora' ),
										value: 'compact',
									},
									{
										label: __( 'Normal', 'bookora' ),
										value: 'normal',
									},
									{
										label: __( 'Spacious', 'bookora' ),
										value: 'comfortable',
									},
								] }
								onChange={ set( 'form_density' ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Color scheme', 'bookora' ) }
								help={ __(
									'"Dark" for websites with a dark background. "Automatic" follows the visitor\'s device setting.',
									'bookora'
								) }
								value={ settings.form_scheme }
								options={ [
									{
										label: __( 'Light', 'bookora' ),
										value: 'light',
									},
									{
										label: __( 'Dark', 'bookora' ),
										value: 'dark',
									},
									{
										label: __( 'Automatic', 'bookora' ),
										value: 'auto',
									},
								] }
								onChange={ set( 'form_scheme' ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Font', 'bookora' ) }
								value={ settings.form_font }
								options={ [
									{
										label: __(
											'Like the website (theme)',
											'bookora'
										),
										value: 'inherit',
									},
									{
										label: __(
											'System font (like apps)',
											'bookora'
										),
										value: 'system',
									},
								] }
								onChange={ set( 'form_font' ) }
							/>
						</div>
						<DesignPreview settings={ settings } />
					</div>

					{ ! window.bookoraAdmin?.caps?.unfilteredHtml && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Only administrators may add own CSS and JavaScript.',
								'bookora'
							) }
						</Notice>
					) }
					<CodeEditor
						mode="css"
						label={ __( 'CSS for all forms', 'bookora' ) }
						help={ __(
							'Example: .bookora { --bookora-gap: 24px; } – all CSS variables are listed in the developer documentation.',
							'bookora'
						) }
						value={ settings.global_css }
						disabled={ ! window.bookoraAdmin?.caps?.unfilteredHtml }
						onChange={ set( 'global_css' ) }
					/>
					<CodeEditor
						mode="js"
						label={ __( 'JavaScript for all forms', 'bookora' ) }
						help={ __(
							'Runs once on every page with a Bookora form. Example: bookora.on( "success", ( data, form ) => { … } );',
							'bookora'
						) }
						value={ settings.global_js }
						disabled={ ! window.bookoraAdmin?.caps?.unfilteredHtml }
						onChange={ set( 'global_js' ) }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Security & privacy', 'bookora' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						label={ __( 'Spam protection (honeypot)', 'bookora' ) }
						checked={ settings.antispam_honeypot }
						onChange={ set( 'antispam_honeypot' ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'CAPTCHA', 'bookora' ) }
						value={ settings.captcha_provider }
						options={ [
							{ label: __( 'None', 'bookora' ), value: 'none' },
							{
								label: 'Cloudflare Turnstile',
								value: 'turnstile',
							},
							{ label: 'hCaptcha', value: 'hcaptcha' },
							{ label: 'reCAPTCHA v3', value: 'recaptcha' },
						] }
						onChange={ set( 'captcha_provider' ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Delete all Bookora data when the plugin is uninstalled',
							'bookora'
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
				{ __( 'Save settings', 'bookora' ) }
			</Button>
		</div>
	);
}
