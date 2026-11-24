/**
 * Integrations: spam protection, Slack/Telegram notifications, Mailchimp/Brevo.
 * Secrets are never sent back by the server – only "••••" and the last 4 characters.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorMessage, get, post } from '../api';

const EVENTS = [
	[ 'booking', __( 'New booking', 'bookora' ) ],
	[ 'entry', __( 'New entry (forms without appointment)', 'bookora' ) ],
	[ 'cancelled', __( 'Cancelled by the customer', 'bookora' ) ],
	[ 'rescheduled', __( 'Moved by the customer', 'bookora' ) ],
];

function Secret( { label, value, onChange, help } ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			type="password"
			autoComplete="off"
			value={ value || '' }
			onChange={ onChange }
		/>
	);
}

export default function IntegrationsScreen() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		get( '/integrations' )
			.then( setData )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [] );

	if ( ! data ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const set = ( key ) => ( value ) => setData( { ...data, [ key ]: value } );

	const save = () => {
		setBusy( 'save' );
		setNotice( null );
		return post( '/integrations', data )
			.then( ( saved ) => {
				setData( saved );
				setNotice( {
					status: 'success',
					text: __( 'Settings saved.', 'bookora' ),
				} );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: errorMessage( e ) } );
				throw e;
			} )
			.finally( () => setBusy( '' ) );
	};

	// Save first, then test with the stored values.
	const test = ( channel, success ) => {
		save()
			.then( () => {
				setBusy( channel );
				return post( `/integrations/test/${ channel }` );
			} )
			.then( () => setNotice( { status: 'success', text: success } ) )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};

	const toggleEvent = ( key, on ) =>
		set( 'notify_events' )(
			on
				? [ ...data.notify_events, key ]
				: data.notify_events.filter( ( k ) => k !== key )
		);

	return (
		<div className="bookora-screen bookora-integrations">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<p className="description">
				{ __(
					'All connections are optional. Bookora only contacts a service once you have entered its access data. Keys are stored encrypted.',
					'bookora'
				) }
			</p>

			<Card>
				<CardBody>
					<h2>{ __( 'Spam protection', 'bookora' ) }</h2>
					<p className="bookora-muted">
						{ __(
							'Bookora already blocks most spam without any service (hidden field, time check, limits per IP). An additional check helps against targeted bots. Note: the provider’s script is loaded on pages with a form – mention it in your privacy policy.',
							'bookora'
						) }
					</p>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Additional check', 'bookora' ) }
						value={ data.captcha }
						options={ [
							{ value: '', label: __( 'None', 'bookora' ) },
							{
								value: 'turnstile',
								label: __(
									'Cloudflare Turnstile (recommended, usually without puzzle)',
									'bookora'
								),
							},
							{ value: 'hcaptcha', label: 'hCaptcha' },
							{
								value: 'recaptcha',
								label: 'Google reCAPTCHA v2',
							},
						] }
						onChange={ set( 'captcha' ) }
					/>
					{ data.captcha && (
						<div className="bookora-grid-2">
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Site key', 'bookora' ) }
								value={ data.captcha_site_key }
								onChange={ set( 'captcha_site_key' ) }
							/>
							<Secret
								label={ __( 'Secret key', 'bookora' ) }
								value={ data.captcha_secret }
								onChange={ set( 'captcha_secret' ) }
							/>
						</div>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardBody>
					<h2>
						{ __( 'Messages to Slack and Telegram', 'bookora' ) }
					</h2>
					<p className="bookora-muted">
						{ __(
							'Short message with customer name, service, date and a link to the admin – no form contents of bookings.',
							'bookora'
						) }
					</p>
					<fieldset className="bookora-fieldset">
						<legend>
							{ __( 'Send a message for', 'bookora' ) }
						</legend>
						{ EVENTS.map( ( [ key, label ] ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ key }
								label={ label }
								checked={ data.notify_events.includes( key ) }
								onChange={ ( on ) => toggleEvent( key, on ) }
							/>
						) ) }
					</fieldset>
					<h3>Slack</h3>
					<Secret
						label={ __( 'Webhook address', 'bookora' ) }
						help={
							<>
								{ __(
									'In Slack: Apps → Incoming Webhooks → add to a channel → copy the address.',
									'bookora'
								) }{ ' ' }
								<ExternalLink href="https://api.slack.com/messaging/webhooks">
									{ __( 'Guide', 'bookora' ) }
								</ExternalLink>
							</>
						}
						value={ data.slack_webhook }
						onChange={ set( 'slack_webhook' ) }
					/>
					<Button
						variant="secondary"
						isBusy={ busy === 'slack' }
						disabled={ !! busy || ! data.slack_webhook }
						onClick={ () =>
							test(
								'slack',
								__( 'Test message sent to Slack.', 'bookora' )
							)
						}
					>
						{ __( 'Send test message', 'bookora' ) }
					</Button>
					<h3>Telegram</h3>
					<div className="bookora-grid-2">
						<Secret
							label={ __( 'Bot token', 'bookora' ) }
							help={ __(
								'Create a bot with @BotFather and copy the token.',
								'bookora'
							) }
							value={ data.telegram_token }
							onChange={ set( 'telegram_token' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Chat ID', 'bookora' ) }
							help={ __(
								'Your user or group ID, or @channelname. Send the bot a message first.',
								'bookora'
							) }
							value={ data.telegram_chat }
							onChange={ set( 'telegram_chat' ) }
						/>
					</div>
					<Button
						variant="secondary"
						isBusy={ busy === 'telegram' }
						disabled={
							!! busy ||
							! data.telegram_token ||
							! data.telegram_chat
						}
						onClick={ () =>
							test(
								'telegram',
								__(
									'Test message sent to Telegram.',
									'bookora'
								)
							)
						}
					>
						{ __( 'Send test message', 'bookora' ) }
					</Button>
				</CardBody>
			</Card>

			<Card>
				<CardBody>
					<h2>{ __( 'Newsletter', 'bookora' ) }</h2>
					<p className="bookora-muted">
						{ __(
							'Visitors are only signed up if they tick a consent box in the form, and they always receive a confirmation email (double opt-in). Choose the form and the box in the form builder → Form → Newsletter.',
							'bookora'
						) }
					</p>
					<h3>Mailchimp</h3>
					<div className="bookora-grid-2">
						<Secret
							label={ __( 'API key', 'bookora' ) }
							help={ __(
								'Profile → Extras → API keys. Ends with e.g. -us21.',
								'bookora'
							) }
							value={ data.mailchimp_key }
							onChange={ set( 'mailchimp_key' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Audience ID', 'bookora' ) }
							help={ __(
								'Audience → Settings → Audience name and defaults.',
								'bookora'
							) }
							value={ data.mailchimp_list }
							onChange={ set( 'mailchimp_list' ) }
						/>
					</div>
					<Button
						variant="secondary"
						isBusy={ busy === 'mailchimp' }
						disabled={
							!! busy ||
							! data.mailchimp_key ||
							! data.mailchimp_list
						}
						onClick={ () =>
							test(
								'mailchimp',
								__(
									'Connection to Mailchimp works.',
									'bookora'
								)
							)
						}
					>
						{ __( 'Check connection', 'bookora' ) }
					</Button>
					<h3>Brevo</h3>
					<div className="bookora-grid-2">
						<Secret
							label={ __( 'API key', 'bookora' ) }
							value={ data.brevo_key }
							onChange={ set( 'brevo_key' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							label={ __( 'List ID', 'bookora' ) }
							value={ data.brevo_list || '' }
							onChange={ ( v ) =>
								set( 'brevo_list' )( Number( v ) || 0 )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							label={ __(
								'Template ID of the confirmation email',
								'bookora'
							) }
							help={ __(
								'A Brevo template of the type “double opt-in” with the confirmation link.',
								'bookora'
							) }
							value={ data.brevo_template || '' }
							onChange={ ( v ) =>
								set( 'brevo_template' )( Number( v ) || 0 )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="url"
							label={ __( 'Page after confirming', 'bookora' ) }
							placeholder={ sprintf(
								/* translators: %s: example address. */
								__( 'e.g. %s', 'bookora' ),
								'https://…/danke'
							) }
							value={ data.brevo_redirect }
							onChange={ set( 'brevo_redirect' ) }
						/>
					</div>
					<Button
						variant="secondary"
						isBusy={ busy === 'brevo' }
						disabled={
							!! busy || ! data.brevo_key || ! data.brevo_list
						}
						onClick={ () =>
							test(
								'brevo',
								__( 'Connection to Brevo works.', 'bookora' )
							)
						}
					>
						{ __( 'Check connection', 'bookora' ) }
					</Button>
				</CardBody>
			</Card>

			<div className="bookora-actions">
				<Button
					variant="primary"
					isBusy={ busy === 'save' }
					disabled={ !! busy }
					onClick={ () => save().catch( () => {} ) }
				>
					{ __( 'Save', 'bookora' ) }
				</Button>
			</div>
		</div>
	);
}
