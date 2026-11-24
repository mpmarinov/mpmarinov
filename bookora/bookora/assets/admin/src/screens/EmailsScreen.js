import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormToggle,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorMessage, get, post, put } from '../api';
import { formatDateTime } from '../components/entryUi';

const GROUPS = [
	{
		id: 'booking-customer',
		title: __( 'To your customers', 'bookora' ),
		filter: ( t ) => t.group === 'booking' && t.recipient === 'customer',
	},
	{
		id: 'admin',
		title: __( 'To you', 'bookora' ),
		filter: ( t ) => t.recipient === 'admin',
	},
	{
		id: 'entry-customer',
		title: __( 'Contact & request forms', 'bookora' ),
		filter: ( t ) => t.group === 'entry' && t.recipient === 'customer',
	},
];

const toRows = ( hours ) =>
	( hours || [] )
		.slice()
		.sort( ( a, b ) => b - a )
		.map( ( h ) =>
			h % 24 === 0
				? { value: h / 24, unit: 'days' }
				: { value: h, unit: 'hours' }
		);

const toHours = ( rows ) =>
	rows
		.map( ( r ) => Number( r.value ) * ( r.unit === 'days' ? 24 : 1 ) )
		.filter( ( h ) => h > 0 );

function ReminderEditor( { rows, onChange } ) {
	const update = ( i, patch ) =>
		onChange(
			rows.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) )
		);
	return (
		<fieldset className="bookora-fieldset bookora-reminders">
			<legend>
				{ __( 'Reminders before the appointment', 'bookora' ) }
			</legend>
			{ rows.length === 0 && (
				<p className="bookora-muted">
					{ __( 'No reminders.', 'bookora' ) }
				</p>
			) }
			{ rows.map( ( r, i ) => (
				<div className="bookora-reminders__row" key={ i }>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						type="number"
						min={ 1 }
						max={ r.unit === 'days' ? 30 : 720 }
						label={ __( 'Time before the appointment', 'bookora' ) }
						value={ r.value }
						onChange={ ( v ) => update( i, { value: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Unit', 'bookora' ) }
						value={ r.unit }
						options={ [
							{
								label: __( 'days before', 'bookora' ),
								value: 'days',
							},
							{
								label: __( 'hours before', 'bookora' ),
								value: 'hours',
							},
						] }
						onChange={ ( v ) => update( i, { unit: v } ) }
					/>
					<Button
						variant="tertiary"
						isDestructive
						size="compact"
						onClick={ () =>
							onChange( rows.filter( ( _, j ) => j !== i ) )
						}
					>
						{ __( 'Remove', 'bookora' ) }
					</Button>
				</div>
			) ) }
			{ rows.length < 5 && (
				<Button
					variant="secondary"
					size="compact"
					onClick={ () =>
						onChange( [ ...rows, { value: 1, unit: 'days' } ] )
					}
				>
					{ __( 'Add reminder', 'bookora' ) }
				</Button>
			) }
			<p className="bookora-muted">
				{ __(
					'Recommended: 7 days and 24 hours before. A reminder is skipped if the appointment was confirmed only shortly before. Placeholder {time_until} writes e.g. "in 7 days" or "tomorrow".',
					'bookora'
				) }
			</p>
		</fieldset>
	);
}

function SenderCard() {
	const [ s, setS ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		get( '/settings' )
			.then( ( data ) =>
				setS( {
					...data,
					reminder_rows: toRows( data.reminder_hours ),
				} )
			)
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [] );

	if ( ! s ) {
		return <Spinner />;
	}
	const set = ( key ) => ( value ) => setS( { ...s, [ key ]: value } );

	const save = () => {
		setBusy( true );
		const reminderHours = toHours( s.reminder_rows );
		post( '/settings', { ...s, reminder_hours: reminderHours } )
			.then( ( data ) => {
				setS( {
					...data,
					reminder_rows: toRows( data.reminder_hours ),
				} );
				setNotice( {
					status: 'success',
					text: __( 'Settings saved.', 'bookora' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Sender & notifications', 'bookora' ) }</h2>
			</CardHeader>
			<CardBody>
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.text }
					</Notice>
				) }
				<div className="bookora-grid">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Business name (sender name)', 'bookora' ) }
						value={ s.business_name }
						onChange={ set( 'business_name' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Notifications go to', 'bookora' ) }
						help={ __(
							'Empty = admin email of the website.',
							'bookora'
						) }
						value={ s.admin_email }
						onChange={ set( 'admin_email' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Sender address (optional)', 'bookora' ) }
						help={ __(
							'Should belong to your domain, otherwise emails may land in spam.',
							'bookora'
						) }
						value={ s.email_from_address }
						onChange={ set( 'email_from_address' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Address (for calendar entries)',
							'bookora'
						) }
						value={ s.business_address }
						onChange={ set( 'business_address' ) }
					/>
					<ReminderEditor
						rows={ s.reminder_rows }
						onChange={ set( 'reminder_rows' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __(
							'One-click links valid for (hours)',
							'bookora'
						) }
						value={ s.token_ttl_hours }
						onChange={ ( v ) =>
							set( 'token_ttl_hours' )( Number( v ) )
						}
					/>
				</div>
				<div className="bookora-actions">
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save', 'bookora' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function Editor( { template, placeholders, onClose, onSaved } ) {
	const [ data, setData ] = useState( template );
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const bodyRef = useRef( null );

	useEffect( () => {
		const timer = setTimeout( () => {
			post( `/emails/${ data.event }/preview`, {
				subject: data.subject,
				body: data.body,
			} )
				.then( setPreview )
				.catch( () => {} );
		}, 400 );
		return () => clearTimeout( timer );
	}, [ data.event, data.subject, data.body ] );

	const insert = ( token ) => {
		const el = bodyRef.current?.querySelector( 'textarea' );
		if ( ! el ) {
			setData( { ...data, body: data.body + token } );
			return;
		}
		const { selectionStart: a, selectionEnd: b } = el;
		const body = data.body.slice( 0, a ) + token + data.body.slice( b );
		setData( { ...data, body } );
		window.requestAnimationFrame( () => {
			el.focus();
			el.selectionStart = el.selectionEnd = a + token.length;
		} );
	};

	const save = () => {
		setBusy( true );
		put( `/emails/${ data.event }`, {
			enabled: data.enabled,
			subject: data.subject,
			body: data.body,
		} )
			.then( ( saved ) => {
				onSaved( saved );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'bookora' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const test = () => {
		setBusy( true );
		post( `/emails/${ data.event }/test` )
			.then( ( res ) =>
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %s: email address. */
						__( 'Test email sent to %s.', 'bookora' ),
						res.sent
					),
				} )
			)
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const reset = () => {
		setBusy( true );
		post( `/emails/${ data.event }/reset` )
			.then( ( saved ) => {
				setData( { ...data, ...saved } );
				onSaved( saved );
				setNotice( {
					status: 'success',
					text: __( 'Default text restored.', 'bookora' ),
				} );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ data.title }
			onRequestClose={ onClose }
			className="bookora-modal bookora-email-editor"
			size="fill"
		>
			<div className="bookora-email-editor__grid">
				<div className="bookora-email-editor__form">
					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.text }
						</Notice>
					) }
					<p className="bookora-muted">{ data.description }</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Send this email', 'bookora' ) }
						checked={ data.enabled }
						onChange={ ( enabled ) =>
							setData( { ...data, enabled } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Subject', 'bookora' ) }
						value={ data.subject }
						onChange={ ( subject ) =>
							setData( { ...data, subject } )
						}
					/>
					<div ref={ bodyRef }>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Text', 'bookora' ) }
							help={ __(
								'Line breaks are kept. Allowed: <strong>, <em>, <a>.',
								'bookora'
							) }
							value={ data.body }
							onChange={ ( body ) =>
								setData( { ...data, body } )
							}
							rows={ 14 }
						/>
					</div>
					<div className="bookora-placeholders">
						<span className="bookora-placeholders__title">
							{ __( 'Insert placeholder:', 'bookora' ) }
						</span>
						{ Object.entries( placeholders ).map(
							( [ token, label ] ) => (
								<button
									type="button"
									key={ token }
									className="bookora-placeholders__chip"
									title={ label }
									onClick={ () =>
										insert(
											token === '{field:key}'
												? '{field:}'
												: token
										)
									}
								>
									{ label }
								</button>
							)
						) }
					</div>
					<div className="bookora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ reset }
							disabled={ busy || ! data.customized }
						>
							{ __( 'Restore default', 'bookora' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ test }
							disabled={ busy }
						>
							{ __( 'Send test email to me', 'bookora' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ busy }
							disabled={ busy }
						>
							{ __( 'Save', 'bookora' ) }
						</Button>
					</div>
				</div>
				<div className="bookora-email-editor__preview">
					<p className="bookora-email-editor__subject">
						<span>
							{ __( 'Preview with sample data', 'bookora' ) }
						</span>
						<strong>{ preview?.subject }</strong>
					</p>
					{ preview ? (
						<iframe
							title={ __( 'Email preview', 'bookora' ) }
							sandbox=""
							srcDoc={ preview.html }
						/>
					) : (
						<Spinner />
					) }
				</div>
			</div>
		</Modal>
	);
}

function Log() {
	const [ rows, setRows ] = useState( null );
	useEffect( () => {
		get( '/emails/log' )
			.then( setRows )
			.catch( () => setRows( [] ) );
	}, [] );
	if ( ! rows ) {
		return null;
	}
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Recently sent', 'bookora' ) }</h2>
			</CardHeader>
			<CardBody>
				{ rows.length === 0 ? (
					<p className="bookora-muted">
						{ __( 'No emails sent yet.', 'bookora' ) }
					</p>
				) : (
					<table className="bookora-log">
						<tbody>
							{ rows.slice( 0, 15 ).map( ( r ) => (
								<tr key={ r.id }>
									<td>{ formatDateTime( r.created_at ) }</td>
									<td>{ r.recipient }</td>
									<td>{ r.event.replace( /_/g, ' ' ) }</td>
									<td>
										<span
											className={ `bookora-status bookora-status--${
												r.status === 'sent'
													? 'success'
													: 'error'
											}` }
											title={ r.error || '' }
										>
											{ r.status === 'sent'
												? __( 'Sent', 'bookora' )
												: __( 'Failed', 'bookora' ) }
										</span>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}

export default function EmailsScreen() {
	const [ data, setData ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = () =>
		get( '/emails' )
			.then( setData )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	useEffect( () => {
		load();
	}, [] );

	const toggle = ( t ) =>
		put( `/emails/${ t.event }`, {
			enabled: ! t.enabled,
			subject: t.subject,
			body: t.body,
		} )
			.then( load )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

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
			<SenderCard />
			{ ! data && <Spinner /> }
			{ data &&
				GROUPS.map( ( g ) => (
					<Card key={ g.id }>
						<CardHeader>
							<h2>{ g.title }</h2>
						</CardHeader>
						<CardBody className="bookora-email-list">
							{ data.templates.filter( g.filter ).map( ( t ) => (
								<div
									key={ t.event }
									className="bookora-email-list__item"
								>
									<FormToggle
										checked={ t.enabled }
										onChange={ () => toggle( t ) }
										aria-label={ t.title }
									/>
									<div className="bookora-list__main">
										<strong>{ t.title }</strong>
										{ t.customized && (
											<span className="bookora-badge">
												{ __(
													'customized',
													'bookora'
												) }
											</span>
										) }
										<div className="bookora-meta">
											<span>{ t.description }</span>
										</div>
									</div>
									<Button
										variant="secondary"
										onClick={ () => setEditing( { ...t } ) }
									>
										{ __( 'Edit', 'bookora' ) }
									</Button>
								</div>
							) ) }
						</CardBody>
					</Card>
				) ) }
			<Log />
			{ editing && (
				<Editor
					template={ editing }
					placeholders={ data.placeholders }
					onClose={ () => setEditing( null ) }
					onSaved={ () => load() }
				/>
			) }
		</div>
	);
}
