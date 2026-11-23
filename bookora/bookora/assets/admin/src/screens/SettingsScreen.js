import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

const path = `${ window.bookoraAdmin?.restPath || '/bookora/v1' }/settings`;

export default function SettingsScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path } )
			.then( setSettings )
			.catch( ( error ) => setNotice( { status: 'error', text: error.message } ) );
	}, [] );

	if ( ! settings ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const set = ( key ) => ( value ) => setSettings( { ...settings, [ key ]: value } );

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path, method: 'POST', data: settings } )
			.then( ( saved ) => {
				setSettings( saved );
				setNotice( { status: 'success', text: __( 'Settings saved.', 'bookora' ) } );
			} )
			.catch( ( error ) => setNotice( { status: 'error', text: error.message } ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="bookora-settings">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Card>
				<CardHeader><h2>{ __( 'General', 'bookora' ) }</h2></CardHeader>
				<CardBody>
					<TextControl label={ __( 'Business name', 'bookora' ) } value={ settings.business_name } onChange={ set( 'business_name' ) } __nextHasNoMarginBottom />
					<TextControl type="email" label={ __( 'Notification email', 'bookora' ) } help={ __( 'New bookings are sent here. Leave empty to use the site admin email.', 'bookora' ) } value={ settings.admin_email } onChange={ set( 'admin_email' ) } __nextHasNoMarginBottom />
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
				<CardHeader><h2>{ __( 'Booking rules', 'bookora' ) }</h2></CardHeader>
				<CardBody>
					<SelectControl
						label={ __( 'Slot interval', 'bookora' ) }
						value={ String( settings.slot_interval ) }
						options={ [ 5, 10, 15, 20, 30, 45, 60, 90, 120 ].map( ( m ) => ( { label: `${ m } min`, value: String( m ) } ) ) }
						onChange={ ( v ) => set( 'slot_interval' )( Number( v ) ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'New bookings are', 'bookora' ) }
						value={ settings.default_status }
						options={ [
							{ label: __( 'Pending – confirm with one click', 'bookora' ), value: 'pending' },
							{ label: __( 'Confirmed automatically', 'bookora' ), value: 'confirmed' },
						] }
						onChange={ set( 'default_status' ) }
						__nextHasNoMarginBottom
					/>
					<NumberControl label={ __( 'Minimum notice (minutes)', 'bookora' ) } min={ 0 } value={ settings.min_notice_minutes } onChange={ ( v ) => set( 'min_notice_minutes' )( Number( v ) ) } />
					<NumberControl label={ __( 'Bookable up to (days ahead)', 'bookora' ) } min={ 1 } value={ settings.max_advance_days } onChange={ ( v ) => set( 'max_advance_days' )( Number( v ) ) } />
					<NumberControl label={ __( 'Customers can cancel up to (hours before)', 'bookora' ) } min={ 0 } value={ settings.cancel_limit_hours } onChange={ ( v ) => set( 'cancel_limit_hours' )( Number( v ) ) } />
				</CardBody>
			</Card>

			<Card>
				<CardHeader><h2>{ __( 'Security & privacy', 'bookora' ) }</h2></CardHeader>
				<CardBody>
					<ToggleControl label={ __( 'Spam protection (honeypot)', 'bookora' ) } checked={ settings.antispam_honeypot } onChange={ set( 'antispam_honeypot' ) } __nextHasNoMarginBottom />
					<SelectControl
						label={ __( 'CAPTCHA', 'bookora' ) }
						value={ settings.captcha_provider }
						options={ [
							{ label: __( 'None', 'bookora' ), value: 'none' },
							{ label: 'Cloudflare Turnstile', value: 'turnstile' },
							{ label: 'hCaptcha', value: 'hcaptcha' },
							{ label: 'reCAPTCHA v3', value: 'recaptcha' },
						] }
						onChange={ set( 'captcha_provider' ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl label={ __( 'Delete all Bookora data when the plugin is uninstalled', 'bookora' ) } checked={ settings.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } __nextHasNoMarginBottom />
				</CardBody>
			</Card>

			<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
				{ __( 'Save settings', 'bookora' ) }
			</Button>
		</div>
	);
}
