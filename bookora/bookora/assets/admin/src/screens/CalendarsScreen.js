/**
 * Calendar sync: subscribe to Bookora on phones (feeds) and import private busy times.
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
	active: [ 'success', __( 'Active', 'bookora' ) ],
	error: [ 'error', __( 'Error', 'bookora' ) ],
	paused: [ 'neutral', __( 'Paused', 'bookora' ) ],
};

function ago( iso ) {
	if ( ! iso ) {
		return __( 'never', 'bookora' );
	}
	const minutes = Math.round(
		( Date.now() - new Date( iso ).getTime() ) / 60000
	);
	if ( minutes < 1 ) {
		return __( 'just now', 'bookora' );
	}
	if ( minutes < 60 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min. ago', 'bookora' ), minutes );
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
						__( 'Link sent to %s.', 'bookora' ),
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
		<div className="bookora-feed">
			<div className="bookora-feed__head">
				<span
					className="bookora-dot"
					style={ { background: feed.color || '#64748b' } }
				/>
				<strong>{ feed.label }</strong>
			</div>
			<div className="bookora-feed__actions">
				<Button
					variant="primary"
					icon="smartphone"
					onClick={ () => setSendOpen( true ) }
				>
					{ __( 'Send link to phone', 'bookora' ) }
				</Button>
				<Button
					variant="secondary"
					icon="camera"
					onClick={ () => setQrOpen( true ) }
				>
					{ __( 'QR code', 'bookora' ) }
				</Button>
				<Button variant="secondary" href={ feed.urls.webcal }>
					{ __( 'Apple Calendar', 'bookora' ) }
				</Button>
				<Button
					variant="secondary"
					href={ feed.urls.google }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Google Calendar', 'bookora' ) }
				</Button>
				<Button
					variant="secondary"
					href={ feed.urls.outlook }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Outlook', 'bookora' ) }
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
						? __( 'Copied', 'bookora' )
						: __( 'Copy address', 'bookora' ) }
				</Button>
				<ConfirmButton
					label={ __( 'Reset address', 'bookora' ) }
					message={ __(
						'The current address stops working immediately. Everyone who subscribed must subscribe again with the new link.',
						'bookora'
					) }
					onConfirm={ () => onReset( feed.scope ) }
				/>
			</div>

			{ qrOpen && (
				<Modal
					title={ sprintf(
						/* translators: %s: calendar name, e.g. "Laura". */
						__( 'Scan with the phone: %s', 'bookora' ),
						feed.label
					) }
					onRequestClose={ () => setQrOpen( false ) }
					size="medium"
				>
					<div className="bookora-qr-box">
						<QrCode
							value={ feed.urls.subscribe || feed.urls.https }
							label={ sprintf(
								/* translators: %s: calendar name. */
								__(
									'QR code for subscribing to the calendar %s',
									'bookora'
								),
								feed.label
							) }
						/>
						<ol>
							<li>
								{ __(
									'Open the camera app on the phone and point it at the code.',
									'bookora'
								) }
							</li>
							<li>
								{ __(
									'Tap the link that appears. iPhones open the calendar directly, Android phones show buttons for Google Calendar and Outlook.',
									'bookora'
								) }
							</li>
							<li>
								{ __(
									'Confirm the subscription – done. New, moved and cancelled appointments appear automatically.',
									'bookora'
								) }
							</li>
						</ol>
					</div>
					<p className="bookora-muted">
						{ __(
							'The code contains the private address of this calendar. Only show it to the person it belongs to. If in doubt: “Reset address”.',
							'bookora'
						) }
					</p>
				</Modal>
			) }

			{ sendOpen && (
				<Modal
					title={ __( 'Send link to phone', 'bookora' ) }
					onRequestClose={ () => setSendOpen( false ) }
					size="medium"
				>
					<p>
						{ __(
							'Open the email on the phone and tap the button – the calendar app starts the subscription.',
							'bookora'
						) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Email address', 'bookora' ) }
						value={ email }
						onChange={ setEmail }
					/>
					<div className="bookora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setSendOpen( false ) }
						>
							{ __( 'Cancel', 'bookora' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || ! email }
							onClick={ send }
						>
							{ __( 'Send', 'bookora' ) }
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
			label: __( 'Whole business (blocks everyone)', 'bookora' ),
		},
		...resources.map( ( r ) => ( {
			value: String( r.id ),
			label: r.name,
		} ) ),
	];

	return (
		<div className="bookora-connection-form">
			<TextControl
				__nextHasNoMarginBottom
				label={ __(
					'Private calendar address (iCal / ICS)',
					'bookora'
				) }
				help={
					initial
						? __(
								'Leave empty to keep the current address.',
								'bookora'
						  )
						: __(
								'Starts with https:// or webcal://. It is stored encrypted.',
								'bookora'
						  )
				}
				placeholder="https://calendar.google.com/calendar/ical/…/private-…/basic.ics"
				value={ url }
				onChange={ setUrl }
			/>
			<div className="bookora-grid">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'bookora' ) }
					placeholder={ __(
						'e.g. Private calendar Laura',
						'bookora'
					) }
					value={ label }
					onChange={ setLabel }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Blocks the times of', 'bookora' ) }
					value={ resource }
					options={ owners }
					onChange={ setResource }
				/>
			</div>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'All-day entries (e.g. vacation) block the whole day',
					'bookora'
				) }
				checked={ allDay }
				onChange={ setAllDay }
			/>
			<div className="bookora-modal-actions">
				{ onCancel && (
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel', 'bookora' ) }
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
						? __( 'Save', 'bookora' )
						: __( 'Connect calendar', 'bookora' ) }
				</Button>
			</div>
		</div>
	);
}

function Help() {
	return (
		<details className="bookora-help">
			<summary>
				{ __(
					'Where do I find the private calendar address?',
					'bookora'
				) }
			</summary>
			<dl>
				<dt>{ __( 'Google Calendar (Android)', 'bookora' ) }</dt>
				<dd>
					{ __(
						'calendar.google.com on the computer → ⚙ Settings → choose your calendar under "Settings for my calendars" → "Integrate calendar" → copy "Secret address in iCal format".',
						'bookora'
					) }
				</dd>
				<dt>{ __( 'iCloud (iPhone)', 'bookora' ) }</dt>
				<dd>
					{ __(
						'Calendar app on the Mac or icloud.com → share the calendar → switch on "Public calendar" → copy the link (webcal://…).',
						'bookora'
					) }
				</dd>
				<dt>{ __( 'Outlook / Microsoft 365', 'bookora' ) }</dt>
				<dd>
					{ __(
						'Outlook on the web → ⚙ Settings → Calendar → Shared calendars → "Publish a calendar" → choose "Can view when I\'m busy" → copy the ICS link.',
						'bookora'
					) }
				</dd>
			</dl>
			<p className="bookora-muted">
				{ __(
					'Only start and end times are stored – titles and details of your private appointments stay in your calendar.',
					'bookora'
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
						'bookora'
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
				result( res, __( 'Calendar connected.', 'bookora' ) );
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
				result( res, __( 'Synchronized.', 'bookora' ) );
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
					text: __( 'Calendar removed.', 'bookora' ),
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
						'bookora'
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
			: __( 'Whole business', 'bookora' );

	return (
		<div className="bookora-screen bookora-calendars">
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
						'bookora'
					) }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<div>
						<h2 className="bookora-card-title">
							{ __(
								'1. Bookora appointments in your calendar app',
								'bookora'
							) }
						</h2>
						<p className="bookora-muted">
							{ __(
								'Subscribe once – new, moved and cancelled appointments then appear automatically on the phone (iPhone, Android, Outlook).',
								'bookora'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody>
					<div className="bookora-feeds">
						{ data.feeds.map( ( f ) => (
							<FeedCard
								key={ f.scope }
								feed={ f }
								onReset={ reset }
								onNotice={ setNotice }
							/>
						) ) }
					</div>
					<div className="bookora-feed-options">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show customer name, phone and email in the calendar',
								'bookora'
							) }
							help={ __(
								'Switch off if other people can see your phone calendar.',
								'bookora'
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
								'bookora'
							) }
							checked={ data.settings.feed_include_pending }
							onChange={ ( v ) =>
								saveSetting( 'feed_include_pending', v )
							}
						/>
					</div>
					<p className="bookora-muted">
						{ __(
							'Note: Google updates subscribed calendars only every few hours. Apple and Outlook are usually faster.',
							'bookora'
						) }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<div>
						<h2 className="bookora-card-title">
							{ __(
								'2. Block private appointments automatically',
								'bookora'
							) }
						</h2>
						<p className="bookora-muted">
							{ __(
								'Connect your private calendars. Times in which you already have something planned are no longer offered to customers. Updated every 15 minutes.',
								'bookora'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody>
					{ data.connections.length === 0 && ! adding && (
						<p className="bookora-muted">
							{ __( 'No calendar connected yet.', 'bookora' ) }
						</p>
					) }

					<div className="bookora-list">
						{ data.connections.map( ( c ) => {
							const [ tone, text ] =
								STATUS[ c.status ] || STATUS.active;
							return (
								<div
									key={ c.id }
									className="bookora-connection"
								>
									<div className="bookora-list__main">
										<strong>{ c.label }</strong>
										<span
											className={ `bookora-status bookora-status--${ tone }` }
										>
											{ text }
										</span>
										<div className="bookora-meta">
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
														'bookora'
													),
													c.events
												) }
											</span>
											<span>
												{ sprintf(
													/* translators: %s: e.g. "5 min. ago". */
													__(
														'Last sync: %s',
														'bookora'
													),
													ago( c.last_synced_at )
												) }
											</span>
										</div>
										{ c.last_error && (
											<p className="bookora-connection__error">
												{ c.last_error }
											</p>
										) }
									</div>
									<div className="bookora-list__actions">
										<Button
											size="compact"
											variant="secondary"
											icon="update"
											disabled={ busy }
											onClick={ () => sync( c.id ) }
										>
											{ __( 'Sync now', 'bookora' ) }
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
																'bookora'
														  )
														: __(
																'Calendar paused – it no longer blocks times.',
																'bookora'
														  )
												)
											}
										>
											{ c.status === 'paused'
												? __( 'Resume', 'bookora' )
												: __( 'Pause', 'bookora' ) }
										</Button>
										<Button
											size="compact"
											variant="tertiary"
											onClick={ () => setEditing( c ) }
										>
											{ __( 'Edit', 'bookora' ) }
										</Button>
										<ConfirmButton
											label={ __( 'Remove', 'bookora' ) }
											message={ __(
												'The calendar is disconnected and its busy times are released.',
												'bookora'
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
							{ __( 'Connect calendar', 'bookora' ) }
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
								__( 'Saved.', 'bookora' )
							)
						}
					/>
				</Modal>
			) }
		</div>
	);
}
