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
		title: __( 'To your customers', 'kalendora' ),
		filter: ( t ) => t.group === 'booking' && t.recipient === 'customer',
	},
	{
		id: 'admin',
		title: __( 'To you', 'kalendora' ),
		filter: ( t ) => t.recipient === 'admin',
	},
	{
		id: 'entry-customer',
		title: __( 'Contact & request forms', 'kalendora' ),
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
		<fieldset className="kalendora-fieldset kalendora-reminders">
			<legend>
				{ __( 'Reminders before the appointment', 'kalendora' ) }
			</legend>
			{ rows.length === 0 && (
				<p className="kalendora-muted">
					{ __( 'No reminders.', 'kalendora' ) }
				</p>
			) }
			{ rows.map( ( r, i ) => (
				<div className="kalendora-reminders__row" key={ i }>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						type="number"
						min={ 1 }
						max={ r.unit === 'days' ? 30 : 720 }
						label={ __(
							'Time before the appointment',
							'kalendora'
						) }
						value={ r.value }
						onChange={ ( v ) => update( i, { value: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Unit', 'kalendora' ) }
						value={ r.unit }
						options={ [
							{
								label: __( 'days before', 'kalendora' ),
								value: 'days',
							},
							{
								label: __( 'hours before', 'kalendora' ),
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
						{ __( 'Remove', 'kalendora' ) }
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
					{ __( 'Add reminder', 'kalendora' ) }
				</Button>
			) }
			<p className="kalendora-muted">
				{ __(
					'Recommended: 7 days and 24 hours before. A reminder is skipped if the appointment was confirmed only shortly before. Placeholder {time_until} writes e.g. "in 7 days" or "tomorrow".',
					'kalendora'
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
					text: __( 'Settings saved.', 'kalendora' ),
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
				<h2>{ __( 'Sender & notifications', 'kalendora' ) }</h2>
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
				<div className="kalendora-grid">
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Business name (sender name)',
							'kalendora'
						) }
						value={ s.business_name }
						onChange={ set( 'business_name' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Notifications go to', 'kalendora' ) }
						help={ __(
							'Empty = admin email of the website.',
							'kalendora'
						) }
						value={ s.admin_email }
						onChange={ set( 'admin_email' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'Sender address (optional)', 'kalendora' ) }
						help={ __(
							'Should belong to your domain, otherwise emails may land in spam.',
							'kalendora'
						) }
						value={ s.email_from_address }
						onChange={ set( 'email_from_address' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Address (for calendar entries)',
							'kalendora'
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
							'kalendora'
						) }
						value={ s.token_ttl_hours }
						onChange={ ( v ) =>
							set( 'token_ttl_hours' )( Number( v ) )
						}
					/>
				</div>
				<div className="kalendora-actions">
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save', 'kalendora' ) }
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
					text: __( 'Saved.', 'kalendora' ),
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
						__( 'Test email sent to %s.', 'kalendora' ),
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
					text: __( 'Default text restored.', 'kalendora' ),
				} );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ data.title }
			onRequestClose={ onClose }
			className="kalendora-modal kalendora-email-editor"
			size="fill"
		>
			<div className="kalendora-email-editor__grid">
				<div className="kalendora-email-editor__form">
					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.text }
						</Notice>
					) }
					<p className="kalendora-muted">{ data.description }</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Send this email', 'kalendora' ) }
						checked={ data.enabled }
						onChange={ ( enabled ) =>
							setData( { ...data, enabled } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Subject', 'kalendora' ) }
						value={ data.subject }
						onChange={ ( subject ) =>
							setData( { ...data, subject } )
						}
					/>
					<div ref={ bodyRef }>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Text', 'kalendora' ) }
							help={ __(
								'Line breaks are kept. Allowed: <strong>, <em>, <a>.',
								'kalendora'
							) }
							value={ data.body }
							onChange={ ( body ) =>
								setData( { ...data, body } )
							}
							rows={ 14 }
						/>
					</div>
					<div className="kalendora-placeholders">
						<span className="kalendora-placeholders__title">
							{ __( 'Insert placeholder:', 'kalendora' ) }
						</span>
						{ Object.entries( placeholders ).map(
							( [ token, label ] ) => (
								<button
									type="button"
									key={ token }
									className="kalendora-placeholders__chip"
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
					<div className="kalendora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ reset }
							disabled={ busy || ! data.customized }
						>
							{ __( 'Restore default', 'kalendora' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ test }
							disabled={ busy }
						>
							{ __( 'Send test email to me', 'kalendora' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ busy }
							disabled={ busy }
						>
							{ __( 'Save', 'kalendora' ) }
						</Button>
					</div>
				</div>
				<div className="kalendora-email-editor__preview">
					<p className="kalendora-email-editor__subject">
						<span>
							{ __( 'Preview with sample data', 'kalendora' ) }
						</span>
						<strong>{ preview?.subject }</strong>
					</p>
					{ preview ? (
						<iframe
							title={ __( 'Email preview', 'kalendora' ) }
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
				<h2>{ __( 'Recently sent', 'kalendora' ) }</h2>
			</CardHeader>
			<CardBody>
				{ rows.length === 0 ? (
					<p className="kalendora-muted">
						{ __( 'No emails sent yet.', 'kalendora' ) }
					</p>
				) : (
					<table className="kalendora-log">
						<tbody>
							{ rows.slice( 0, 15 ).map( ( r ) => (
								<tr key={ r.id }>
									<td>{ formatDateTime( r.created_at ) }</td>
									<td>{ r.recipient }</td>
									<td>{ r.event.replace( /_/g, ' ' ) }</td>
									<td>
										<span
											className={ `kalendora-status kalendora-status--${
												{
													sent: 'success',
													queued: 'warning',
												}[ r.status ] || 'error'
											}` }
											title={ r.error || '' }
										>
											{ {
												sent: __( 'Sent', 'kalendora' ),
												queued: __(
													'Waiting',
													'kalendora'
												),
											}[ r.status ] ||
												__( 'Failed', 'kalendora' ) }
										</span>
										{ r.error && r.status !== 'sent' && (
											<span className="kalendora-log__error">
												{ r.error }
											</span>
										) }
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
		<div className="kalendora-screen">
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
						<CardBody className="kalendora-email-list">
							{ data.templates.filter( g.filter ).map( ( t ) => (
								<div
									key={ t.event }
									className="kalendora-email-list__item"
								>
									<FormToggle
										checked={ t.enabled }
										onChange={ () => toggle( t ) }
										aria-label={ t.title }
									/>
									<div className="kalendora-list__main">
										<strong>{ t.title }</strong>
										{ t.customized && (
											<span className="kalendora-badge">
												{ __(
													'customized',
													'kalendora'
												) }
											</span>
										) }
										<div className="kalendora-meta">
											<span>{ t.description }</span>
										</div>
									</div>
									<Button
										variant="secondary"
										onClick={ () => setEditing( { ...t } ) }
									>
										{ __( 'Edit', 'kalendora' ) }
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
