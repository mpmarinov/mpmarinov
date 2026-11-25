/**
 * Calendar sync: subscribe to Kalendora on phones (feeds) and import private busy times.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { config, del, errorMessage, get, post, put } from '../api';
import ConfirmButton from '../components/ConfirmButton';
import QrCode from '../components/QrCode';

const STATUS = {
	active: [ 'success', __( 'Active', 'kalendora' ) ],
	error: [ 'error', __( 'Error', 'kalendora' ) ],
	paused: [ 'neutral', __( 'Paused', 'kalendora' ) ],
};

function ago( iso ) {
	if ( ! iso ) {
		return __( 'never', 'kalendora' );
	}
	const minutes = Math.round(
		( Date.now() - new Date( iso ).getTime() ) / 60000
	);
	if ( minutes < 1 ) {
		return __( 'just now', 'kalendora' );
	}
	if ( minutes < 60 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min. ago', 'kalendora' ), minutes );
	}
	return new Intl.DateTimeFormat( config.locale || undefined, {
		dateStyle: 'short',
		timeStyle: 'short',
	} ).format( new Date( iso ) );
}

function copy( text ) {
	if ( window.navigator.clipboard ) {
		return window.navigator.clipboard.writeText( text );
	}
	const field = document.createElement( 'textarea' );
	field.value = text;
	document.body.appendChild( field );
	field.select();
	document.execCommand( 'copy' ); // eslint-disable-line @wordpress/no-global-active-element
	field.remove();
	return Promise.resolve();
}

/* ---------------- Feeds ---------------- */

function FeedCard( { feed, onReset, onNotice } ) {
	const [ copied, setCopied ] = useState( false );
	const [ sendOpen, setSendOpen ] = useState( false );
	const [ qrOpen, setQrOpen ] = useState( false );
	const [ email, setEmail ] = useState( feed.email || '' );
	const [ busy, setBusy ] = useState( false );

	const send = () => {
		setBusy( true );
		post( `/calendars/feeds/${ feed.scope }/send`, { email } )
			.then( ( res ) => {
				setSendOpen( false );
				onNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %s: email address. */
						__( 'Link sent to %s.', 'kalendora' ),
						res.sent_to
					),
				} );
			} )
			.catch( ( e ) =>
				onNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="kalendora-feed">
			<div className="kalendora-feed__head">
				<span
					className="kalendora-dot"
					style={ { background: feed.color || '#64748b' } }
				/>
				<strong>{ feed.label }</strong>
			</div>
			<div className="kalendora-feed__actions">
				<Button
					variant="primary"
					icon="smartphone"
					onClick={ () => setSendOpen( true ) }
				>
					{ __( 'Send link to phone', 'kalendora' ) }
				</Button>
				<Button
					variant="secondary"
					icon="camera"
					onClick={ () => setQrOpen( true ) }
				>
					{ __( 'QR code', 'kalendora' ) }
				</Button>
				<Button variant="secondary" href={ feed.urls.webcal }>
					{ __( 'Apple Calendar', 'kalendora' ) }
				</Button>
				<Button
					variant="secondary"
					href={ feed.urls.google }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Google Calendar', 'kalendora' ) }
				</Button>
				<Button
					variant="secondary"
					href={ feed.urls.outlook }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Outlook', 'kalendora' ) }
				</Button>
				<Button
					variant="tertiary"
					icon={ copied ? 'yes' : 'admin-page' }
					onClick={ () =>
						copy( feed.urls.https ).then( () => {
							setCopied( true );
							setTimeout( () => setCopied( false ), 1500 );
						} )
					}
				>
					{ copied
						? __( 'Copied', 'kalendora' )
						: __( 'Copy address', 'kalendora' ) }
				</Button>
				<ConfirmButton
					label={ __( 'Reset address', 'kalendora' ) }
					message={ __(
						'The current address stops working immediately. Everyone who subscribed must subscribe again with the new link.',
						'kalendora'
					) }
					onConfirm={ () => onReset( feed.scope ) }
				/>
			</div>

			{ qrOpen && (
				<Modal
					title={ sprintf(
						/* translators: %s: calendar name, e.g. "Laura". */
						__( 'Scan with the phone: %s', 'kalendora' ),
						feed.label
					) }
					onRequestClose={ () => setQrOpen( false ) }
					size="medium"
				>
					<div className="kalendora-qr-box">
						<QrCode
							value={ feed.urls.subscribe || feed.urls.https }
							label={ sprintf(
								/* translators: %s: calendar name. */
								__(
									'QR code for subscribing to the calendar %s',
									'kalendora'
								),
								feed.label
							) }
						/>
						<ol>
							<li>
								{ __(
									'Open the camera app on the phone and point it at the code.',
									'kalendora'
								) }
							</li>
							<li>
								{ __(
									'Tap the link that appears. iPhones open the calendar directly, Android phones show buttons for Google Calendar and Outlook.',
									'kalendora'
								) }
							</li>
							<li>
								{ __(
									'Confirm the subscription – done. New, moved and cancelled appointments appear automatically.',
									'kalendora'
								) }
							</li>
						</ol>
					</div>
					<p className="kalendora-muted">
						{ __(
							'The code contains the private address of this calendar. Only show it to the person it belongs to. If in doubt: “Reset address”.',
							'kalendora'
						) }
					</p>
				</Modal>
			) }

			{ sendOpen && (
				<Modal
					title={ __( 'Send link to phone', 'kalendora' ) }
					onRequestClose={ () => setSendOpen( false ) }
					size="medium"
				>
					<p>
						{ __(
							'Open the email on the phone and tap the button – the calendar app starts the subscription.',
							'kalendora'
						) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Email address', 'kalendora' ) }
						value={ email }
						onChange={ setEmail }
					/>
					<div className="kalendora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setSendOpen( false ) }
						>
							{ __( 'Cancel', 'kalendora' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || ! email }
							onClick={ send }
						>
							{ __( 'Send', 'kalendora' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

/* ---------------- Imported calendars ---------------- */

function ConnectionForm( { initial, resources, onSave, onCancel, busy } ) {
	const [ label, setLabel ] = useState( initial?.label || '' );
	const [ url, setUrl ] = useState( '' );
	const [ resource, setResource ] = useState(
		String( initial?.resource_id || 0 )
	);
	const [ allDay, setAllDay ] = useState( initial?.block_all_day ?? true );

	const owners = [
		{
			value: '0',
			label: __( 'Whole business (blocks everyone)', 'kalendora' ),
		},
		...resources.map( ( r ) => ( {
			value: String( r.id ),
			label: r.name,
		} ) ),
	];

	return (
		<div className="kalendora-connection-form">
			<TextControl
				__nextHasNoMarginBottom
				label={ __(
					'Private calendar address (iCal / ICS)',
					'kalendora'
				) }
				help={
					initial
						? __(
								'Leave empty to keep the current address.',
								'kalendora'
						  )
						: __(
								'Starts with https:// or webcal://. It is stored encrypted.',
								'kalendora'
						  )
				}
				placeholder="https://calendar.google.com/calendar/ical/…/private-…/basic.ics"
				value={ url }
				onChange={ setUrl }
			/>
			<div className="kalendora-grid">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'kalendora' ) }
					placeholder={ __(
						'e.g. Private calendar Laura',
						'kalendora'
					) }
					value={ label }
					onChange={ setLabel }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Blocks the times of', 'kalendora' ) }
					value={ resource }
					options={ owners }
					onChange={ setResource }
				/>
			</div>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'All-day entries (e.g. vacation) block the whole day',
					'kalendora'
				) }
				checked={ allDay }
				onChange={ setAllDay }
			/>
			<div className="kalendora-modal-actions">
				{ onCancel && (
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel', 'kalendora' ) }
					</Button>
				) }
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ( ! initial && ! url.trim() ) }
					onClick={ () =>
						onSave( {
							label,
							resource_id: Number( resource ),
							block_all_day: allDay,
							...( url.trim() ? { url: url.trim() } : {} ),
						} )
					}
				>
					{ initial
						? __( 'Save', 'kalendora' )
						: __( 'Connect calendar', 'kalendora' ) }
				</Button>
			</div>
		</div>
	);
}

function Help() {
	return (
		<details className="kalendora-help">
			<summary>
				{ __(
					'Where do I find the private calendar address?',
					'kalendora'
				) }
			</summary>
			<dl>
				<dt>{ __( 'Google Calendar (Android)', 'kalendora' ) }</dt>
				<dd>
					{ __(
						'calendar.google.com on the computer → ⚙ Settings → choose your calendar under "Settings for my calendars" → "Integrate calendar" → copy "Secret address in iCal format".',
						'kalendora'
					) }
				</dd>
				<dt>{ __( 'iCloud (iPhone)', 'kalendora' ) }</dt>
				<dd>
					{ __(
						'Calendar app on the Mac or icloud.com → share the calendar → switch on "Public calendar" → copy the link (webcal://…).',
						'kalendora'
					) }
				</dd>
				<dt>{ __( 'Outlook / Microsoft 365', 'kalendora' ) }</dt>
				<dd>
					{ __(
						'Outlook on the web → ⚙ Settings → Calendar → Shared calendars → "Publish a calendar" → choose "Can view when I\'m busy" → copy the ICS link.',
						'kalendora'
					) }
				</dd>
			</dl>
			<p className="kalendora-muted">
				{ __(
					'Only start and end times are stored – titles and details of your private appointments stay in your calendar.',
					'kalendora'
				) }
			</p>
		</details>
	);
}

export default function CalendarsScreen() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ adding, setAdding ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback(
		() =>
			get( '/calendars' )
				.then( setData )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: errorMessage( e ) } )
				),
		[]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	const result = ( res, okText ) => {
		if ( res.result && ! res.result.ok ) {
			setNotice( { status: 'error', text: res.result.error } );
		} else if ( res.result ) {
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: 1: what happened, 2: number of busy times. */
					_n(
						'%1$s %2$d busy time imported.',
						'%1$s %2$d busy times imported.',
						res.result.events,
						'kalendora'
					),
					okText,
					res.result.events
				),
			} );
		} else {
			setNotice( { status: 'success', text: okText } );
		}
	};

	const create = ( values ) => {
		setBusy( true );
		setNotice( null );
		post( '/calendars/connections', values )
			.then( ( res ) => {
				result( res, __( 'Calendar connected.', 'kalendora' ) );
				setAdding( false );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const update = ( id, values, okText ) => {
		setBusy( true );
		setNotice( null );
		put( `/calendars/connections/${ id }`, values )
			.then( ( res ) => {
				result( res, okText );
				setEditing( null );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const sync = ( id ) => {
		setBusy( true );
		setNotice( null );
		post( `/calendars/connections/${ id }/sync` )
			.then( ( res ) => {
				result( res, __( 'Synchronized.', 'kalendora' ) );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const remove = ( id ) =>
		del( `/calendars/connections/${ id }` )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __( 'Calendar removed.', 'kalendora' ),
				} );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	const reset = ( scope ) =>
		post( `/calendars/feeds/${ scope }/reset` )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __(
						'New address created. The old one no longer works.',
						'kalendora'
					),
				} );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	const saveSetting = ( key, value ) => {
		setData( ( d ) => ( {
			...d,
			settings: { ...d.settings, [ key ]: value },
		} ) );
		post( '/settings', { [ key ]: value } ).catch( ( e ) =>
			setNotice( { status: 'error', text: errorMessage( e ) } )
		);
	};

	if ( ! data ) {
		return notice ? (
			<Notice status={ notice.status } isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const owner = ( id ) =>
		id
			? data.resources.find( ( r ) => r.id === id )?.name || '—'
			: __( 'Whole business', 'kalendora' );

	return (
		<div className="kalendora-screen kalendora-calendars">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			{ ! data.cron_ok && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WP-Cron is disabled on this site. Please make sure a server cron job calls wp-cron.php regularly, otherwise calendars are only updated when you click "Sync now".',
						'kalendora'
					) }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<div>
						<h2 className="kalendora-card-title">
							{ __(
								'1. Kalendora appointments in your calendar app',
								'kalendora'
							) }
						</h2>
						<p className="kalendora-muted">
							{ __(
								'Subscribe once – new, moved and cancelled appointments then appear automatically on the phone (iPhone, Android, Outlook).',
								'kalendora'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody>
					<div className="kalendora-feeds">
						{ data.feeds.map( ( f ) => (
							<FeedCard
								key={ f.scope }
								feed={ f }
								onReset={ reset }
								onNotice={ setNotice }
							/>
						) ) }
					</div>
					<div className="kalendora-feed-options">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show customer name, phone and email in the calendar',
								'kalendora'
							) }
							help={ __(
								'Switch off if other people can see your phone calendar.',
								'kalendora'
							) }
							checked={ data.settings.feed_customer_details }
							onChange={ ( v ) =>
								saveSetting( 'feed_customer_details', v )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Also show unconfirmed requests (marked as "Unconfirmed")',
								'kalendora'
							) }
							checked={ data.settings.feed_include_pending }
							onChange={ ( v ) =>
								saveSetting( 'feed_include_pending', v )
							}
						/>
					</div>
					<p className="kalendora-muted">
						{ __(
							'Note: Google updates subscribed calendars only every few hours. Apple and Outlook are usually faster.',
							'kalendora'
						) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<div>
						<h2 className="kalendora-card-title">
							{ __(
								'2. Block private appointments automatically',
								'kalendora'
							) }
						</h2>
						<p className="kalendora-muted">
							{ __(
								'Connect your private calendars. Times in which you already have something planned are no longer offered to customers. Updated every 15 minutes.',
								'kalendora'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody>
					{ data.connections.length === 0 && ! adding && (
						<p className="kalendora-muted">
							{ __( 'No calendar connected yet.', 'kalendora' ) }
						</p>
					) }

					<div className="kalendora-list">
						{ data.connections.map( ( c ) => {
							const [ tone, text ] =
								STATUS[ c.status ] || STATUS.active;
							return (
								<div
									key={ c.id }
									className="kalendora-connection"
								>
									<div className="kalendora-list__main">
										<strong>{ c.label }</strong>
										<span
											className={ `kalendora-status kalendora-status--${ tone }` }
										>
											{ text }
										</span>
										<div className="kalendora-meta">
											<span>
												{ owner( c.resource_id ) }
											</span>
											<span>{ c.host }</span>
											<span>
												{ sprintf(
													/* translators: %d: number of busy times. */
													_n(
														'%d busy time',
														'%d busy times',
														c.events,
														'kalendora'
													),
													c.events
												) }
											</span>
											<span>
												{ sprintf(
													/* translators: %s: e.g. "5 min. ago". */
													__(
														'Last sync: %s',
														'kalendora'
													),
													ago( c.last_synced_at )
												) }
											</span>
										</div>
										{ c.last_error && (
											<p className="kalendora-connection__error">
												{ c.last_error }
											</p>
										) }
									</div>
									<div className="kalendora-list__actions">
										<Button
											size="compact"
											variant="secondary"
											icon="update"
											disabled={ busy }
											onClick={ () => sync( c.id ) }
										>
											{ __( 'Sync now', 'kalendora' ) }
										</Button>
										<Button
											size="compact"
											variant="tertiary"
											onClick={ () =>
												update(
													c.id,
													{
														status:
															c.status ===
															'paused'
																? 'active'
																: 'paused',
													},
													c.status === 'paused'
														? __(
																'Calendar resumed.',
																'kalendora'
														  )
														: __(
																'Calendar paused – it no longer blocks times.',
																'kalendora'
														  )
												)
											}
										>
											{ c.status === 'paused'
												? __( 'Resume', 'kalendora' )
												: __( 'Pause', 'kalendora' ) }
										</Button>
										<Button
											size="compact"
											variant="tertiary"
											onClick={ () => setEditing( c ) }
										>
											{ __( 'Edit', 'kalendora' ) }
										</Button>
										<ConfirmButton
											label={ __( 'Remove', 'kalendora' ) }
											message={ __(
												'The calendar is disconnected and its busy times are released.',
												'kalendora'
											) }
											onConfirm={ () => remove( c.id ) }
										/>
									</div>
								</div>
							);
						} ) }
					</div>

					{ adding ? (
						<ConnectionForm
							resources={ data.resources }
							onSave={ create }
							onCancel={ () => setAdding( false ) }
							busy={ busy }
						/>
					) : (
						<Button
							variant="secondary"
							icon="plus"
							onClick={ () => setAdding( true ) }
						>
							{ __( 'Connect calendar', 'kalendora' ) }
						</Button>
					) }

					<Help />
				</CardBody>
			</Card>

			{ editing && (
				<Modal
					title={ editing.label }
					onRequestClose={ () => setEditing( null ) }
					size="medium"
				>
					<ConnectionForm
						initial={ editing }
						resources={ data.resources }
						busy={ busy }
						onCancel={ () => setEditing( null ) }
						onSave={ ( values ) =>
							update(
								editing.id,
								values,
								__( 'Saved.', 'kalendora' )
							)
						}
					/>
				</Modal>
			) }
		</div>
	);
}
