/**
 * Timeline: day view (rows = staff/resources) and week view (rows = days).
 * Bookings can be moved by drag & drop or with the form in the detail dialog.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { config, errorMessage, get, post } from '../api';
import { formatDay, refreshBadges } from '../components/entryUi';
import { BookingDetail } from './BookingsScreen';

const SNAP = 15;
const LABEL = window.innerWidth < 783 ? 96 : 160;
const canManage = !! config.caps?.manageBookings;

const pad = ( n ) => String( n ).padStart( 2, '0' );
const toTime = ( min ) =>
	`${ pad( Math.floor( min / 60 ) ) }:${ pad( min % 60 ) }`;
const ymd = ( d ) =>
	`${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
const parse = ( s ) => {
	const [ y, m, d ] = s.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};
const shift = ( s, days ) => {
	const d = parse( s );
	d.setDate( d.getDate() + days );
	return ymd( d );
};
const short = ( s ) =>
	new Intl.DateTimeFormat( config.locale || undefined, {
		weekday: 'short',
		day: 'numeric',
		month: 'numeric',
	} ).format( parse( s ) );

/**
 * Assign overlapping bookings to lanes inside one row.
 *
 * @param {Array} items Bookings with start_min/end_min.
 * @return {number} Number of lanes (items get .lane).
 */
function assignLanes( items ) {
	const ends = [];
	items
		.sort( ( a, b ) => a.start_min - b.start_min )
		.forEach( ( b ) => {
			let lane = ends.findIndex( ( end ) => end <= b.start_min );
			if ( lane === -1 ) {
				lane = ends.length;
				ends.push( 0 );
			}
			ends[ lane ] = b.end_min;
			b.lane = lane;
		} );
	return Math.max( 1, ends.length );
}

function MoveForm( { booking, resources, onMove, busy } ) {
	const [ date, setDate ] = useState( booking.date );
	const [ time, setTime ] = useState( booking.time );
	const [ resource, setResource ] = useState(
		String( booking.resource_id || 0 )
	);
	const [ notify, setNotify ] = useState( true );

	if (
		! canManage ||
		! [ 'pending', 'confirmed' ].includes( booking.status )
	) {
		return null;
	}

	return (
		<fieldset className="bookora-fieldset bookora-moveform">
			<legend>{ __( 'Move appointment', 'bookora' ) }</legend>
			<div className="bookora-grid bookora-grid--3">
				<TextControl
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'Date', 'bookora' ) }
					value={ date }
					onChange={ setDate }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="time"
					step={ 300 }
					label={ __( 'Time', 'bookora' ) }
					value={ time }
					onChange={ setTime }
				/>
				{ resources.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Staff member', 'bookora' ) }
						value={ resource }
						options={ [
							{ value: '0', label: '—' },
							...resources.map( ( r ) => ( {
								value: String( r.id ),
								label: r.name,
							} ) ),
						] }
						onChange={ setResource }
					/>
				) }
			</div>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Inform the customer by email', 'bookora' ) }
				checked={ notify }
				onChange={ setNotify }
			/>
			<Button
				variant="secondary"
				disabled={
					busy ||
					( date === booking.date &&
						time === booking.time &&
						Number( resource ) === ( booking.resource_id || 0 ) )
				}
				onClick={ () =>
					onMove( booking, {
						date,
						time,
						resource_id: resources.length
							? Number( resource )
							: undefined,
						notify,
					} )
				}
			>
				{ __( 'Move', 'bookora' ) }
			</Button>
		</fieldset>
	);
}

export default function TimelineScreen() {
	const [ view, setView ] = useState( () =>
		window.innerWidth < 600 ? 'day' : 'day'
	);
	const [ date, setDate ] = useState( () => ymd( new Date() ) );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ pending, setPending ] = useState( null );
	const [ notify, setNotify ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const drag = useRef( null );
	const trackRef = useRef( null );
	const reqId = useRef( 0 );
	const [ avail, setAvail ] = useState( 0 );

	useEffect( () => {
		const el = trackRef.current;
		if ( ! el || typeof window.ResizeObserver === 'undefined' ) {
			return;
		}
		const ro = new window.ResizeObserver( ( [ entry ] ) =>
			setAvail( Math.floor( entry.contentRect.width ) )
		);
		ro.observe( el );
		return () => ro.disconnect();
	}, [ data !== null ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const load = useCallback( () => {
		const req = ++reqId.current;
		get( '/timeline', { view, date } )
			.then( ( res ) => req === reqId.current && setData( res ) )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [ view, date ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Auto-refresh every 2 minutes (new bookings, now-line).
	useEffect( () => {
		const timer = setInterval( load, 120000 );
		return () => clearInterval( timer );
	}, [ load ] );

	const from = data?.from ?? 480;
	const to = data?.to ?? 1080;
	// Pixels per minute: fill the available width, but never squeeze below a readable minimum (then scroll).
	const mobile = LABEL < 160;
	const minPpm = ( view === 'day' ? 1.6 : 1 ) * ( mobile ? 1.6 : 1 );
	const ppm = Math.max(
		minPpm,
		( avail - LABEL ) / Math.max( 60, to - from )
	);
	const width = ( to - from ) * ppm;

	const switchView = ( next ) => {
		if ( next !== view ) {
			setData( null );
			setView( next );
		}
	};

	const move = ( booking, target ) => {
		setBusy( true );
		setNotice( null );
		return post( `/bookings/${ booking.id }/move`, target )
			.then( ( res ) => {
				const parts = [
					sprintf(
						/* translators: 1: customer, 2: new date, 3: new time. */
						__( '%1$s moved to %2$s, %3$s.', 'bookora' ),
						res.booking.name || res.booking.email,
						short( res.booking.date ),
						res.booking.time
					),
				];
				if ( res.notified ) {
					parts.push(
						__( 'The customer has been informed.', 'bookora' )
					);
				}
				if ( res.outside_hours ) {
					parts.push(
						__(
							'Note: the new time is outside the opening hours.',
							'bookora'
						)
					);
				}
				setNotice( {
					status: res.outside_hours ? 'warning' : 'success',
					text: parts.join( ' ' ),
				} );
				setOpen( ( o ) =>
					o && o.id === res.booking.id ? res.booking : o
				);
				load();
				refreshBadges();
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: errorMessage( e ) } );
				load();
			} )
			.finally( () => setBusy( false ) );
	};

	const changeStatus = ( booking, status ) => {
		setBusy( true );
		post( `/bookings/${ booking.id }/status`, { status } )
			.then( ( updated ) => {
				setOpen( updated );
				load();
				refreshBadges();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	/* ---------- Drag & drop ---------- */
	const onPointerDown = ( e, booking ) => {
		if ( e.button !== 0 ) {
			return;
		}
		const el = e.currentTarget;
		drag.current = {
			booking,
			el,
			x: e.clientX,
			y: e.clientY,
			moved: false,
			movable:
				canManage &&
				[ 'pending', 'confirmed' ].includes( booking.status ),
		};
		el.setPointerCapture( e.pointerId );
	};

	const onPointerMove = ( e ) => {
		const d = drag.current;
		if ( ! d ) {
			return;
		}
		const dx = e.clientX - d.x;
		const dy = e.clientY - d.y;
		if ( ! d.moved && Math.hypot( dx, dy ) < 5 ) {
			return;
		}
		if ( ! d.movable ) {
			return;
		}
		d.moved = true;
		const snapped = Math.round( dx / ppm / SNAP ) * SNAP * ppm;
		d.el.style.transform = `translate(${ snapped }px, ${ dy }px)`;
		d.el.classList.add( 'is-dragging' );
		const minutes =
			d.booking.start_min + Math.round( dx / ppm / SNAP ) * SNAP;
		d.el.dataset.preview = toTime( Math.max( 0, minutes ) );
	};

	const onPointerUp = ( e ) => {
		const d = drag.current;
		drag.current = null;
		if ( ! d ) {
			return;
		}
		d.el.style.transform = '';
		d.el.classList.remove( 'is-dragging' );
		delete d.el.dataset.preview;

		if ( ! d.moved ) {
			setOpen( d.booking );
			return;
		}

		const minutes =
			d.booking.start_min +
			Math.round( ( e.clientX - d.x ) / ppm / SNAP ) * SNAP;
		d.el.style.visibility = 'hidden';
		const row = document
			.elementFromPoint( e.clientX, e.clientY )
			?.closest( '[data-row]' );
		d.el.style.visibility = '';
		const target = {
			date: d.booking.date,
			time: toTime( Math.max( 0, Math.min( 1439, minutes ) ) ),
		};
		if ( row && view === 'day' ) {
			target.resource_id = Number( row.dataset.row );
		} else if ( row && view === 'week' ) {
			target.date = row.dataset.row;
		}
		const same =
			target.date === d.booking.date &&
			target.time === d.booking.time &&
			( target.resource_id === undefined ||
				target.resource_id === ( d.booking.resource_id || 0 ) );
		if ( ! same ) {
			setNotify( true );
			setPending( { booking: d.booking, target } );
		}
	};

	/* ---------- Render ---------- */
	const rows = data?.rows || [];
	const bookingsFor = ( row ) =>
		( data?.bookings || [] )
			.filter( ( b ) =>
				view === 'day'
					? ( b.resource_id || 0 ) === row.id
					: b.date === row.id
			)
			.map( ( b ) => ( { ...b } ) );

	const hours = [];
	for ( let m = from; m <= to; m += 60 ) {
		hours.push( m );
	}

	let title = formatDay( date );
	if ( view === 'week' ) {
		title =
			data?.view === 'week'
				? `${ short( data.dates[ 0 ] ) } – ${ short(
						data.dates[ data.dates.length - 1 ]
				  ) }`
				: '';
	}

	const resourceName = ( id ) =>
		data?.resources.find( ( r ) => r.id === id )?.name || '—';

	return (
		<div className="bookora-screen bookora-tl-screen">
			<div className="bookora-tl__bar">
				<div className="bookora-tl__nav">
					<Button
						icon="arrow-left-alt2"
						label={ __( 'Previous', 'bookora' ) }
						onClick={ () =>
							setDate( shift( date, view === 'day' ? -1 : -7 ) )
						}
					/>
					<Button
						variant="secondary"
						onClick={ () => setDate( ymd( new Date() ) ) }
					>
						{ __( 'Today', 'bookora' ) }
					</Button>
					<Button
						icon="arrow-right-alt2"
						label={ __( 'Next', 'bookora' ) }
						onClick={ () =>
							setDate( shift( date, view === 'day' ? 1 : 7 ) )
						}
					/>
					<input
						type="date"
						className="bookora-tl__date"
						value={ date }
						aria-label={ __( 'Date', 'bookora' ) }
						onChange={ ( e ) =>
							e.target.value && setDate( e.target.value )
						}
					/>
				</div>
				<strong className="bookora-tl__title">{ title }</strong>
				<div
					className="bookora-tl__views"
					role="group"
					aria-label={ __( 'View', 'bookora' ) }
				>
					<Button
						isPressed={ view === 'day' }
						onClick={ () => switchView( 'day' ) }
					>
						{ __( 'Day', 'bookora' ) }
					</Button>
					<Button
						isPressed={ view === 'week' }
						onClick={ () => switchView( 'week' ) }
					>
						{ __( 'Week', 'bookora' ) }
					</Button>
				</div>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			{ ! data && <Spinner /> }

			{ data && (
				<div
					className={ `bookora-tl bookora-tl--${ view }` }
					ref={ trackRef }
				>
					{ /* Focusable so keyboard users can scroll the wide timeline, also on days without appointments. */ }
					{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex */ }
					<div
						className="bookora-tl__scroll"
						tabIndex={ 0 }
						role="region"
						aria-label={ __( 'Timeline', 'bookora' ) }
					>
						<div
							className="bookora-tl__inner"
							style={ { '--tl-width': `${ width }px` } }
						>
							<div className="bookora-tl__head">
								<div className="bookora-tl__corner" />
								<div
									className="bookora-tl__hours"
									style={ { width } }
								>
									{ hours.map( ( m ) => (
										<span
											key={ m }
											style={ {
												left: ( m - from ) * ppm,
											} }
										>
											{ toTime( m ) }
										</span>
									) ) }
								</div>
							</div>

							{ rows.length === 0 && (
								<p className="bookora-muted">
									{ __( 'No staff members.', 'bookora' ) }
								</p>
							) }

							{ rows.map( ( row ) => {
								const items = bookingsFor( row );
								const lanes = assignLanes( items );
								const isToday = row.date === data.today;
								return (
									<div
										key={ row.id }
										className={ `bookora-tl__row${
											isToday && view === 'week'
												? ' is-today'
												: ''
										}` }
									>
										<div className="bookora-tl__label">
											<span
												className="bookora-dot"
												style={ {
													background: row.color,
												} }
											/>
											<span>
												{ view === 'week'
													? short( row.date )
													: row.label }
											</span>
											<small>
												{ items.length
													? sprintf(
															/* translators: %d: number of appointments. */
															__(
																'%d appt.',
																'bookora'
															),
															items.length
													  )
													: '' }
											</small>
										</div>
										<div
											className="bookora-tl__track"
											data-row={ row.id }
											style={ {
												width,
												height: lanes * 46 + 10,
											} }
										>
											{ hours.map( ( m ) => (
												<span
													key={ m }
													className="bookora-tl__line"
													style={ {
														left:
															( m - from ) * ppm,
													} }
												/>
											) ) }
											{ row.open.map( ( [ a, b ], i ) => (
												<span
													key={ i }
													className="bookora-tl__open"
													style={ {
														left:
															( Math.max(
																a,
																from
															) -
																from ) *
															ppm,
														width:
															( Math.min(
																b,
																to
															) -
																Math.max(
																	a,
																	from
																) ) *
															ppm,
													} }
												/>
											) ) }
											{ ( data.blocks || [] )
												.filter(
													( bl ) =>
														bl.row === row.id &&
														bl.end_min > from &&
														bl.start_min < to
												)
												.map( ( bl, i ) => (
													<span
														key={ `bl${ i }` }
														className="bookora-tl__block"
														title={ bl.label }
														style={ {
															left:
																( Math.max(
																	bl.start_min,
																	from
																) -
																	from ) *
																ppm,
															width:
																( Math.min(
																	bl.end_min,
																	to
																) -
																	Math.max(
																		bl.start_min,
																		from
																	) ) *
																ppm,
														} }
													>
														{ bl.label }
													</span>
												) ) }
											{ isToday &&
												data.now_min >= from &&
												data.now_min <= to && (
													<span
														className="bookora-tl__now"
														style={ {
															left:
																( data.now_min -
																	from ) *
																ppm,
														} }
														title={ toTime(
															data.now_min
														) }
													/>
												) }
											{ items.map( ( b ) => (
												<button
													type="button"
													key={ b.id }
													className={ `bookora-tl__booking is-${ b.status }` }
													style={ {
														left:
															( b.start_min -
																from ) *
															ppm,
														width: Math.max(
															24,
															( b.end_min -
																b.start_min ) *
																ppm
														),
														top: 5 + b.lane * 46,
														'--bk-color':
															b.service_color,
													} }
													onPointerDown={ ( e ) =>
														onPointerDown( e, b )
													}
													onPointerMove={
														onPointerMove
													}
													onPointerUp={ onPointerUp }
													onKeyDown={ ( e ) =>
														e.key === 'Enter' &&
														setOpen( b )
													}
													title={ `${ b.time }–${
														b.end_time
													} · ${
														b.name || b.email
													} · ${ b.service_name }` }
												>
													<strong>
														{ b.name || b.email }
													</strong>
													<span>
														{ b.time } ·{ ' ' }
														{ b.service_name }
														{ view === 'week' &&
														b.resource_name
															? ` · ${ b.resource_name }`
															: '' }
													</span>
												</button>
											) ) }
										</div>
									</div>
								);
							} ) }
						</div>
					</div>
					<div className="bookora-tl__legend">
						<span>
							<i className="is-confirmed" />{ ' ' }
							{ __( 'Confirmed', 'bookora' ) }
						</span>
						<span>
							<i className="is-pending" />{ ' ' }
							{ __( 'Pending', 'bookora' ) }
						</span>
						<span>
							<i className="is-closed" />{ ' ' }
							{ __( 'Closed', 'bookora' ) }
						</span>
						{ ( data.blocks || [] ).length > 0 && (
							<span>
								<i className="is-external" />{ ' ' }
								{ __( 'Busy (own calendar)', 'bookora' ) }
							</span>
						) }
						{ canManage && (
							<span className="bookora-muted">
								{ __(
									'Drag appointments to move them (15-minute steps).',
									'bookora'
								) }
							</span>
						) }
					</div>
				</div>
			) }

			{ pending && (
				<Modal
					title={ __( 'Move appointment?', 'bookora' ) }
					onRequestClose={ () => setPending( null ) }
					size="medium"
				>
					<p>
						<strong>
							{ pending.booking.name || pending.booking.email }
						</strong>{ ' ' }
						· { pending.booking.service_name }
					</p>
					<p className="bookora-move-summary">
						<span>
							{ short( pending.booking.date ) },{ ' ' }
							{ pending.booking.time }
							{ pending.booking.resource_name
								? ` · ${ pending.booking.resource_name }`
								: '' }
						</span>
						<span aria-hidden="true">→</span>
						<strong>
							{ short( pending.target.date ) },{ ' ' }
							{ pending.target.time }
							{ pending.target.resource_id !== undefined &&
							data?.resources.length
								? ` · ${ resourceName(
										pending.target.resource_id
								  ) }`
								: '' }
						</strong>
					</p>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Inform the customer by email',
							'bookora'
						) }
						checked={ notify }
						onChange={ setNotify }
					/>
					<div className="bookora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setPending( null ) }
						>
							{ __( 'Cancel', 'bookora' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => {
								const p = pending;
								setPending( null );
								move( p.booking, { ...p.target, notify } );
							} }
						>
							{ __( 'Move', 'bookora' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ open && (
				<BookingDetail
					booking={ open }
					onClose={ () => setOpen( null ) }
					onChange={ changeStatus }
					onUpdated={ setOpen }
					busy={ busy }
				>
					<MoveForm
						key={ `${ open.id }-${ open.date }-${ open.time }` }
						booking={ open }
						resources={ data?.resources || [] }
						onMove={ move }
						busy={ busy }
					/>
				</BookingDetail>
			) }
		</div>
	);
}
