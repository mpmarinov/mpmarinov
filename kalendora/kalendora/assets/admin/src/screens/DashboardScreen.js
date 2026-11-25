/**
 * Dashboard: setup wizard on a fresh install, afterwards today's overview.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
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

import { config, errorMessage, get, post, put } from '../api';
import { TemplatePicker } from './FormsScreen';
import { templateById } from '../templates';

const link = ( screen, query = '' ) =>
	`${ config.adminUrl }?page=kalendora${ screen ? '-' + screen : '' }${
		query ? '&' + query : ''
	}`;

const WEEKDAYS = [
	[ 1, __( 'Mon', 'kalendora' ) ],
	[ 2, __( 'Tue', 'kalendora' ) ],
	[ 3, __( 'Wed', 'kalendora' ) ],
	[ 4, __( 'Thu', 'kalendora' ) ],
	[ 5, __( 'Fri', 'kalendora' ) ],
	[ 6, __( 'Sat', 'kalendora' ) ],
	[ 0, __( 'Sun', 'kalendora' ) ],
];

const DURATIONS = [ 15, 20, 30, 45, 60, 75, 90, 120 ].map( ( m ) => ( {
	value: String( m ),
	label: sprintf(
		/* translators: %d: minutes. */
		_n( '%d minute', '%d minutes', m, 'kalendora' ),
		m
	),
} ) );

const STEPS = [
	__( 'Your business', 'kalendora' ),
	__( 'Opening hours', 'kalendora' ),
	__( 'First service', 'kalendora' ),
	__( 'Form', 'kalendora' ),
	__( 'Done', 'kalendora' ),
];

function Progress( { step } ) {
	return (
		<ol className="kalendora-wizard__steps">
			{ STEPS.map( ( label, i ) => (
				<li
					key={ label }
					className={
						( i < step ? 'is-done' : '' ) +
						( i === step ? ' is-current' : '' )
					}
					aria-current={ i === step ? 'step' : undefined }
				>
					<span className="kalendora-wizard__num">{ i + 1 }</span>
					{ label }
				</li>
			) ) }
		</ol>
	);
}

export function SetupWizard( { onFinish } ) {
	const [ step, setStep ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const heading = useRef();

	const [ business, setBusiness ] = useState( {
		business_name: config.siteName || '',
		admin_email: config.adminEmail || '',
		business_phone: '',
		default_status: 'pending',
	} );
	const [ days, setDays ] = useState( [ 1, 2, 3, 4, 5 ] );
	const [ hours, setHours ] = useState( { from: '09:00', to: '18:00' } );
	const [ lunch, setLunch ] = useState( {
		on: false,
		from: '12:00',
		to: '13:00',
	} );
	const [ service, setService ] = useState( {
		name: '',
		duration: '60',
		price: '',
	} );
	const [ template, setTemplate ] = useState( 'booking' );
	const [ form, setForm ] = useState( null );
	const [ page, setPage ] = useState( null );

	useEffect( () => {
		get( '/settings' ).then( ( s ) =>
			setBusiness( ( b ) => ( {
				business_name: s.business_name || b.business_name,
				admin_email: s.admin_email || b.admin_email,
				business_phone: s.business_phone || '',
				default_status: s.default_status || 'pending',
			} ) )
		);
	}, [] );

	useEffect( () => {
		heading.current?.focus();
	}, [ step ] );

	const run = ( promise, next = step + 1 ) => {
		setBusy( true );
		setError( null );
		return promise
			.then( ( result ) => {
				setStep( next );
				return result;
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const weekly = () => {
		const ranges =
			lunch.on && lunch.from > hours.from && lunch.to < hours.to
				? [
						[ hours.from, lunch.from ],
						[ lunch.to, hours.to ],
				  ]
				: [ [ hours.from, hours.to ] ];
		const out = {};
		days.forEach( ( d ) => ( out[ d ] = ranges ) );
		return out;
	};

	const toggleDay = ( d, on ) =>
		setDays( ( list ) =>
			on ? [ ...list, d ] : list.filter( ( x ) => x !== d )
		);

	const createForm = () => {
		const chosen = templateById( template );
		return post( '/forms', {
			title: chosen.title,
			type: chosen.type,
			status: 'published',
			fields: chosen.fields(),
		} ).then( ( f ) => setForm( f ) );
	};

	const createPage = () => {
		setBusy( true );
		setError( null );
		apiFetch( {
			path: '/wp/v2/pages',
			method: 'POST',
			data: {
				title: form.title,
				status: 'draft',
				content: `<!-- wp:kalendora/form {"formId":${ form.id }} /-->`,
			},
		} )
			.then( setPage )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const finish = () =>
		post( '/settings', { setup_done: true } ).then( onFinish );

	const chosen = templateById( template );
	const shortcode = form ? `[kalendora id="${ form.id }"]` : '';

	return (
		<Card className="kalendora-wizard">
			<CardBody>
				<Progress step={ step } />
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ step === 0 && (
					<form
						className="kalendora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run( post( '/settings', business ) );
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Welcome to Kalendora', 'kalendora' ) }
						</h2>
						<p>
							{ __(
								'Five short steps and your first booking form is online. Everything can be changed later.',
								'kalendora'
							) }
						</p>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Business name', 'kalendora' ) }
							value={ business.business_name }
							onChange={ ( v ) =>
								setBusiness( { ...business, business_name: v } )
							}
							required
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="email"
							label={ __( 'Email for new bookings', 'kalendora' ) }
							value={ business.admin_email }
							onChange={ ( v ) =>
								setBusiness( { ...business, admin_email: v } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="tel"
							label={ __( 'Phone', 'kalendora' ) }
							help={ __(
								'Shown to customers who can no longer cancel online at short notice.',
								'kalendora'
							) }
							value={ business.business_phone }
							onChange={ ( v ) =>
								setBusiness( {
									...business,
									business_phone: v,
								} )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'New bookings are', 'kalendora' ) }
							value={ business.default_status }
							options={ [
								{
									value: 'pending',
									label: __(
										'Requests – I confirm each one',
										'kalendora'
									),
								},
								{
									value: 'confirmed',
									label: __(
										'Confirmed immediately',
										'kalendora'
									),
								},
							] }
							onChange={ ( v ) =>
								setBusiness( {
									...business,
									default_status: v,
								} )
							}
						/>
						{ ! config.timezoneSet && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Your site uses a UTC offset instead of a city as time zone. Please choose your city (e.g. Berlin) so that appointments stay correct when the clocks change.',
									'kalendora'
								) }{ ' ' }
								<a href={ config.generalUrl }>
									{ __(
										'Open time zone settings',
										'kalendora'
									) }
								</a>
							</Notice>
						) }
						<Actions busy={ busy } onSkip={ finish } />
					</form>
				) }

				{ step === 1 && (
					<form
						className="kalendora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run(
								put( '/schedules/weekly', { weekly: weekly() } )
							);
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'When are you open?', 'kalendora' ) }
						</h2>
						<fieldset className="kalendora-wizard__days">
							<legend>{ __( 'Opening days', 'kalendora' ) }</legend>
							{ WEEKDAYS.map( ( [ d, label ] ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ d }
									label={ label }
									checked={ days.includes( d ) }
									onChange={ ( on ) => toggleDay( d, on ) }
								/>
							) ) }
						</fieldset>
						<div className="kalendora-wizard__row">
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'From', 'kalendora' ) }
								value={ hours.from }
								onChange={ ( v ) =>
									setHours( { ...hours, from: v } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'To', 'kalendora' ) }
								value={ hours.to }
								onChange={ ( v ) =>
									setHours( { ...hours, to: v } )
								}
							/>
						</div>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'Lunch break', 'kalendora' ) }
							checked={ lunch.on }
							onChange={ ( on ) => setLunch( { ...lunch, on } ) }
						/>
						{ lunch.on && (
							<div className="kalendora-wizard__row">
								<TextControl
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'Break from', 'kalendora' ) }
									value={ lunch.from }
									onChange={ ( v ) =>
										setLunch( { ...lunch, from: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'Break until', 'kalendora' ) }
									value={ lunch.to }
									onChange={ ( v ) =>
										setLunch( { ...lunch, to: v } )
									}
								/>
							</div>
						) }
						<p className="kalendora-muted">
							{ __(
								'Different hours per day, holidays and vacation: Kalendora → Opening Hours.',
								'kalendora'
							) }
						</p>
						<Actions
							busy={ busy }
							disabled={ ! days.length || hours.from >= hours.to }
							onBack={ () => setStep( 0 ) }
						/>
					</form>
				) }

				{ step === 2 && (
					<form
						className="kalendora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run(
								post( '/services', {
									name: service.name,
									duration: Number( service.duration ),
									price:
										Number(
											String( service.price ).replace(
												',',
												'.'
											)
										) || 0,
								} )
							);
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'What can customers book?', 'kalendora' ) }
						</h2>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Service', 'kalendora' ) }
							placeholder={ __(
								'e.g. Classic massage',
								'kalendora'
							) }
							value={ service.name }
							onChange={ ( v ) =>
								setService( { ...service, name: v } )
							}
							required
						/>
						<div className="kalendora-wizard__row">
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Duration', 'kalendora' ) }
								value={ service.duration }
								options={ DURATIONS }
								onChange={ ( v ) =>
									setService( { ...service, duration: v } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __(
									'Price in € (optional)',
									'kalendora'
								) }
								inputMode="decimal"
								value={ service.price }
								onChange={ ( v ) =>
									setService( { ...service, price: v } )
								}
							/>
						</div>
						<p className="kalendora-muted">
							{ __(
								'More services, buffers, staff and places for courses: Kalendora → Services.',
								'kalendora'
							) }
						</p>
						<Actions
							busy={ busy }
							disabled={ ! service.name.trim() }
							onBack={ () => setStep( 1 ) }
						/>
					</form>
				) }

				{ step === 3 && (
					<form
						className="kalendora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run( createForm() );
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Choose a form', 'kalendora' ) }
						</h2>
						<TemplatePicker
							value={ template }
							onChange={ setTemplate }
						/>
						<Actions
							busy={ busy }
							label={ sprintf(
								/* translators: %s: template name. */
								__( 'Create “%s”', 'kalendora' ),
								chosen.title
							) }
							onBack={ () => setStep( 2 ) }
						/>
					</form>
				) }

				{ step === 4 && form && (
					<div className="kalendora-form">
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Your form is ready', 'kalendora' ) }
						</h2>
						<p>
							{ __(
								'Put it on any page of your website:',
								'kalendora'
							) }
						</p>
						<ul className="kalendora-wizard__embed">
							<li>
								<strong>
									{ __( 'Block editor:', 'kalendora' ) }
								</strong>{ ' ' }
								{ __(
									'add the block “Kalendora” and choose the form.',
									'kalendora'
								) }
							</li>
							<li>
								<strong>
									{ __( 'Other page builders:', 'kalendora' ) }
								</strong>{ ' ' }
								{ __( 'paste this shortcode', 'kalendora' ) }{ ' ' }
								<code>{ shortcode }</code>
							</li>
						</ul>
						{ config.canEditPages && ! page && (
							<Button
								variant="secondary"
								onClick={ createPage }
								isBusy={ busy }
								disabled={ busy }
							>
								{ __(
									'Create a page with this form (draft)',
									'kalendora'
								) }
							</Button>
						) }
						{ page && (
							<Notice status="success" isDismissible={ false }>
								{ __(
									'The page was created as a draft. Check it and publish it when you are ready.',
									'kalendora'
								) }{ ' ' }
								<a href={ page.link }>
									{ __( 'Preview', 'kalendora' ) }
								</a>
								{ ' · ' }
								<a
									href={ `${ config.adminUrl.replace(
										'admin.php',
										'post.php'
									) }?post=${ page.id }&action=edit` }
								>
									{ __( 'Edit page', 'kalendora' ) }
								</a>
							</Notice>
						) }
						<h3>{ __( 'Recommended next steps', 'kalendora' ) }</h3>
						<ul className="kalendora-wizard__next">
							<li>
								<a href={ link( 'emails' ) }>
									{ __(
										'Check the emails and send yourself a test email',
										'kalendora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'calendars' ) }>
									{ __(
										'Put your appointments on your phone',
										'kalendora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'settings' ) }>
									{ __(
										'Cancellation deadline and optional cancellation fee',
										'kalendora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'forms', 'form=' + form.id ) }>
									{ __( 'Adjust the form', 'kalendora' ) }
								</a>
							</li>
						</ul>
						<div className="kalendora-modal-actions">
							<Button variant="primary" onClick={ finish }>
								{ __( 'Go to the overview', 'kalendora' ) }
							</Button>
						</div>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

function Actions( { busy, disabled, label, onBack, onSkip } ) {
	return (
		<div className="kalendora-modal-actions kalendora-wizard__actions">
			{ onSkip && (
				<Button variant="tertiary" onClick={ onSkip }>
					{ __( 'Skip setup', 'kalendora' ) }
				</Button>
			) }
			{ onBack && (
				<Button variant="tertiary" onClick={ onBack }>
					{ __( 'Back', 'kalendora' ) }
				</Button>
			) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || disabled }
			>
				{ label || __( 'Next', 'kalendora' ) }
			</Button>
		</div>
	);
}

function Stat( { value, label, href, tone } ) {
	return (
		<a
			className={ `kalendora-stat${ tone ? ' is-' + tone : '' }` }
			href={ href }
		>
			<span className="kalendora-stat__value">{ value }</span>
			<span className="kalendora-stat__label">{ label }</span>
		</a>
	);
}

function Overview( { onWizard } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const today = new Date().toLocaleDateString( 'sv-SE' );
		Promise.all( [
			get( '/bookings', { view: 'upcoming', per_page: 8 } ),
			get( '/badges' ),
			get( '/timeline', { view: 'day', date: today } ),
		] )
			.then( ( [ upcoming, badges, day ] ) =>
				setData( { upcoming, badges, day } )
			)
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const todays = data.day.bookings.filter(
		( b ) => b.status === 'confirmed' || b.status === 'pending'
	);
	const counts = data.upcoming.counts || {};

	return (
		<div className="kalendora-screen kalendora-dashboard">
			<div className="kalendora-stats">
				<Stat
					value={ todays.length }
					label={ _n(
						'appointment today',
						'appointments today',
						todays.length,
						'kalendora'
					) }
					href={ link( 'timeline' ) }
				/>
				<Stat
					value={ counts.pending || 0 }
					label={ _n(
						'open request',
						'open requests',
						counts.pending || 0,
						'kalendora'
					) }
					href={ link( 'bookings' ) }
					tone={ counts.pending ? 'attention' : '' }
				/>
				<Stat
					value={ data.badges.new_entries || 0 }
					label={ _n(
						'new entry',
						'new entries',
						data.badges.new_entries || 0,
						'kalendora'
					) }
					href={ link( 'entries' ) }
				/>
				{ counts.fees > 0 && (
					<Stat
						value={ counts.fees }
						label={ _n(
							'open fee',
							'open fees',
							counts.fees,
							'kalendora'
						) }
						href={ link( 'bookings', 'view=fees' ) }
						tone="attention"
					/>
				) }
			</div>

			<Card>
				<CardBody>
					<h2>{ __( 'Next appointments', 'kalendora' ) }</h2>
					{ data.upcoming.items.length ? (
						<ul className="kalendora-next">
							{ data.upcoming.items.map( ( b ) => (
								<li key={ b.id }>
									<span
										className="kalendora-next__dot"
										style={ {
											background: b.service_color,
										} }
									/>
									<span className="kalendora-next__when">
										{ new Date(
											b.date + 'T12:00:00'
										).toLocaleDateString( config.locale, {
											weekday: 'short',
											day: 'numeric',
											month: 'short',
										} ) }{ ' ' }
										{ b.time }
									</span>
									<span className="kalendora-next__what">
										{ b.service_name }
										{ b.resource_name
											? ' · ' + b.resource_name
											: '' }
									</span>
									<span className="kalendora-next__who">
										{ b.name || b.email }
									</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="kalendora-muted">
							{ __( 'No upcoming appointments.', 'kalendora' ) }
						</p>
					) }
					<p>
						<a href={ link( 'bookings', 'view=upcoming' ) }>
							{ __( 'All bookings', 'kalendora' ) }
						</a>
						{ ' · ' }
						<a href={ link( 'timeline' ) }>
							{ __( 'Timeline', 'kalendora' ) }
						</a>
					</p>
				</CardBody>
			</Card>

			<p className="kalendora-muted">
				<Button variant="link" onClick={ onWizard }>
					{ __( 'Run the setup wizard again', 'kalendora' ) }
				</Button>
				{ ' · ' }
				<ExternalLink href="https://mpmarinov.com/kalendora">
					{ __( 'Help & guide', 'kalendora' ) }
				</ExternalLink>
			</p>
		</div>
	);
}

export default function DashboardScreen() {
	const [ mode, setMode ] = useState( null );

	useEffect( () => {
		if ( ! config.caps?.manageSettings ) {
			setMode( 'overview' );
			return;
		}
		Promise.all( [ get( '/settings' ), get( '/forms' ) ] )
			.then( ( [ settings, forms ] ) =>
				setMode(
					! settings.setup_done && ! ( forms || [] ).length
						? 'wizard'
						: 'overview'
				)
			)
			.catch( () => setMode( 'overview' ) );
	}, [] );

	if ( ! mode ) {
		return <Spinner />;
	}
	return mode === 'wizard' ? (
		<SetupWizard onFinish={ () => setMode( 'overview' ) } />
	) : (
		<Overview onWizard={ () => setMode( 'wizard' ) } />
	);
}
