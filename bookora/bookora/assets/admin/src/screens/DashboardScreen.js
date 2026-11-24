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
	`${ config.adminUrl }?page=bookora${ screen ? '-' + screen : '' }${
		query ? '&' + query : ''
	}`;

const WEEKDAYS = [
	[ 1, __( 'Mon', 'bookora' ) ],
	[ 2, __( 'Tue', 'bookora' ) ],
	[ 3, __( 'Wed', 'bookora' ) ],
	[ 4, __( 'Thu', 'bookora' ) ],
	[ 5, __( 'Fri', 'bookora' ) ],
	[ 6, __( 'Sat', 'bookora' ) ],
	[ 0, __( 'Sun', 'bookora' ) ],
];

const DURATIONS = [ 15, 20, 30, 45, 60, 75, 90, 120 ].map( ( m ) => ( {
	value: String( m ),
	label: sprintf(
		/* translators: %d: minutes. */
		_n( '%d minute', '%d minutes', m, 'bookora' ),
		m
	),
} ) );

const STEPS = [
	__( 'Your business', 'bookora' ),
	__( 'Opening hours', 'bookora' ),
	__( 'First service', 'bookora' ),
	__( 'Form', 'bookora' ),
	__( 'Done', 'bookora' ),
];

function Progress( { step } ) {
	return (
		<ol className="bookora-wizard__steps">
			{ STEPS.map( ( label, i ) => (
				<li
					key={ label }
					className={
						( i < step ? 'is-done' : '' ) +
						( i === step ? ' is-current' : '' )
					}
					aria-current={ i === step ? 'step' : undefined }
				>
					<span className="bookora-wizard__num">{ i + 1 }</span>
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
				content: `<!-- wp:bookora/form {"formId":${ form.id }} /-->`,
			},
		} )
			.then( setPage )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const finish = () =>
		post( '/settings', { setup_done: true } ).then( onFinish );

	const chosen = templateById( template );
	const shortcode = form ? `[bookora id="${ form.id }"]` : '';

	return (
		<Card className="bookora-wizard">
			<CardBody>
				<Progress step={ step } />
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ step === 0 && (
					<form
						className="bookora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run( post( '/settings', business ) );
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Welcome to Bookora', 'bookora' ) }
						</h2>
						<p>
							{ __(
								'Five short steps and your first booking form is online. Everything can be changed later.',
								'bookora'
							) }
						</p>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Business name', 'bookora' ) }
							value={ business.business_name }
							onChange={ ( v ) =>
								setBusiness( { ...business, business_name: v } )
							}
							required
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="email"
							label={ __( 'Email for new bookings', 'bookora' ) }
							value={ business.admin_email }
							onChange={ ( v ) =>
								setBusiness( { ...business, admin_email: v } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="tel"
							label={ __( 'Phone', 'bookora' ) }
							help={ __(
								'Shown to customers who can no longer cancel online at short notice.',
								'bookora'
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
							label={ __( 'New bookings are', 'bookora' ) }
							value={ business.default_status }
							options={ [
								{
									value: 'pending',
									label: __(
										'Requests – I confirm each one',
										'bookora'
									),
								},
								{
									value: 'confirmed',
									label: __(
										'Confirmed immediately',
										'bookora'
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
									'bookora'
								) }{ ' ' }
								<a href={ config.generalUrl }>
									{ __(
										'Open time zone settings',
										'bookora'
									) }
								</a>
							</Notice>
						) }
						<Actions busy={ busy } onSkip={ finish } />
					</form>
				) }

				{ step === 1 && (
					<form
						className="bookora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run(
								put( '/schedules/weekly', { weekly: weekly() } )
							);
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'When are you open?', 'bookora' ) }
						</h2>
						<fieldset className="bookora-wizard__days">
							<legend>{ __( 'Opening days', 'bookora' ) }</legend>
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
						<div className="bookora-wizard__row">
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'From', 'bookora' ) }
								value={ hours.from }
								onChange={ ( v ) =>
									setHours( { ...hours, from: v } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'To', 'bookora' ) }
								value={ hours.to }
								onChange={ ( v ) =>
									setHours( { ...hours, to: v } )
								}
							/>
						</div>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'Lunch break', 'bookora' ) }
							checked={ lunch.on }
							onChange={ ( on ) => setLunch( { ...lunch, on } ) }
						/>
						{ lunch.on && (
							<div className="bookora-wizard__row">
								<TextControl
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'Break from', 'bookora' ) }
									value={ lunch.from }
									onChange={ ( v ) =>
										setLunch( { ...lunch, from: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'Break until', 'bookora' ) }
									value={ lunch.to }
									onChange={ ( v ) =>
										setLunch( { ...lunch, to: v } )
									}
								/>
							</div>
						) }
						<p className="bookora-muted">
							{ __(
								'Different hours per day, holidays and vacation: Bookora → Opening Hours.',
								'bookora'
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
						className="bookora-form"
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
							{ __( 'What can customers book?', 'bookora' ) }
						</h2>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Service', 'bookora' ) }
							placeholder={ __(
								'e.g. Classic massage',
								'bookora'
							) }
							value={ service.name }
							onChange={ ( v ) =>
								setService( { ...service, name: v } )
							}
							required
						/>
						<div className="bookora-wizard__row">
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Duration', 'bookora' ) }
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
									'bookora'
								) }
								inputMode="decimal"
								value={ service.price }
								onChange={ ( v ) =>
									setService( { ...service, price: v } )
								}
							/>
						</div>
						<p className="bookora-muted">
							{ __(
								'More services, buffers, staff and places for courses: Bookora → Services.',
								'bookora'
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
						className="bookora-form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							run( createForm() );
						} }
					>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Choose a form', 'bookora' ) }
						</h2>
						<TemplatePicker
							value={ template }
							onChange={ setTemplate }
						/>
						<Actions
							busy={ busy }
							label={ sprintf(
								/* translators: %s: template name. */
								__( 'Create “%s”', 'bookora' ),
								chosen.title
							) }
							onBack={ () => setStep( 2 ) }
						/>
					</form>
				) }

				{ step === 4 && form && (
					<div className="bookora-form">
						<h2 ref={ heading } tabIndex={ -1 }>
							{ __( 'Your form is ready', 'bookora' ) }
						</h2>
						<p>
							{ __(
								'Put it on any page of your website:',
								'bookora'
							) }
						</p>
						<ul className="bookora-wizard__embed">
							<li>
								<strong>
									{ __( 'Block editor:', 'bookora' ) }
								</strong>{ ' ' }
								{ __(
									'add the block “Bookora” and choose the form.',
									'bookora'
								) }
							</li>
							<li>
								<strong>
									{ __( 'Other page builders:', 'bookora' ) }
								</strong>{ ' ' }
								{ __( 'paste this shortcode', 'bookora' ) }{ ' ' }
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
									'bookora'
								) }
							</Button>
						) }
						{ page && (
							<Notice status="success" isDismissible={ false }>
								{ __(
									'The page was created as a draft. Check it and publish it when you are ready.',
									'bookora'
								) }{ ' ' }
								<a href={ page.link }>
									{ __( 'Preview', 'bookora' ) }
								</a>
								{ ' · ' }
								<a
									href={ `${ config.adminUrl.replace(
										'admin.php',
										'post.php'
									) }?post=${ page.id }&action=edit` }
								>
									{ __( 'Edit page', 'bookora' ) }
								</a>
							</Notice>
						) }
						<h3>{ __( 'Recommended next steps', 'bookora' ) }</h3>
						<ul className="bookora-wizard__next">
							<li>
								<a href={ link( 'emails' ) }>
									{ __(
										'Check the emails and send yourself a test email',
										'bookora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'calendars' ) }>
									{ __(
										'Put your appointments on your phone',
										'bookora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'settings' ) }>
									{ __(
										'Cancellation deadline and optional cancellation fee',
										'bookora'
									) }
								</a>
							</li>
							<li>
								<a href={ link( 'forms', 'form=' + form.id ) }>
									{ __( 'Adjust the form', 'bookora' ) }
								</a>
							</li>
						</ul>
						<div className="bookora-modal-actions">
							<Button variant="primary" onClick={ finish }>
								{ __( 'Go to the overview', 'bookora' ) }
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
		<div className="bookora-modal-actions bookora-wizard__actions">
			{ onSkip && (
				<Button variant="tertiary" onClick={ onSkip }>
					{ __( 'Skip setup', 'bookora' ) }
				</Button>
			) }
			{ onBack && (
				<Button variant="tertiary" onClick={ onBack }>
					{ __( 'Back', 'bookora' ) }
				</Button>
			) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || disabled }
			>
				{ label || __( 'Next', 'bookora' ) }
			</Button>
		</div>
	);
}

function Stat( { value, label, href, tone } ) {
	return (
		<a
			className={ `bookora-stat${ tone ? ' is-' + tone : '' }` }
			href={ href }
		>
			<span className="bookora-stat__value">{ value }</span>
			<span className="bookora-stat__label">{ label }</span>
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
		<div className="bookora-screen bookora-dashboard">
			<div className="bookora-stats">
				<Stat
					value={ todays.length }
					label={ _n(
						'appointment today',
						'appointments today',
						todays.length,
						'bookora'
					) }
					href={ link( 'timeline' ) }
				/>
				<Stat
					value={ counts.pending || 0 }
					label={ _n(
						'open request',
						'open requests',
						counts.pending || 0,
						'bookora'
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
						'bookora'
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
							'bookora'
						) }
						href={ link( 'bookings', 'view=fees' ) }
						tone="attention"
					/>
				) }
			</div>

			<Card>
				<CardBody>
					<h2>{ __( 'Next appointments', 'bookora' ) }</h2>
					{ data.upcoming.items.length ? (
						<ul className="bookora-next">
							{ data.upcoming.items.map( ( b ) => (
								<li key={ b.id }>
									<span
										className="bookora-next__dot"
										style={ {
											background: b.service_color,
										} }
									/>
									<span className="bookora-next__when">
										{ new Date(
											b.date + 'T12:00:00'
										).toLocaleDateString( config.locale, {
											weekday: 'short',
											day: 'numeric',
											month: 'short',
										} ) }{ ' ' }
										{ b.time }
									</span>
									<span className="bookora-next__what">
										{ b.service_name }
										{ b.resource_name
											? ' · ' + b.resource_name
											: '' }
									</span>
									<span className="bookora-next__who">
										{ b.name || b.email }
									</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="bookora-muted">
							{ __( 'No upcoming appointments.', 'bookora' ) }
						</p>
					) }
					<p>
						<a href={ link( 'bookings', 'view=upcoming' ) }>
							{ __( 'All bookings', 'bookora' ) }
						</a>
						{ ' · ' }
						<a href={ link( 'timeline' ) }>
							{ __( 'Timeline', 'bookora' ) }
						</a>
					</p>
				</CardBody>
			</Card>

			<p className="bookora-muted">
				<Button variant="link" onClick={ onWizard }>
					{ __( 'Run the setup wizard again', 'bookora' ) }
				</Button>
				{ ' · ' }
				<ExternalLink href="https://mpmarinov.com/bookora">
					{ __( 'Help & guide', 'bookora' ) }
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
