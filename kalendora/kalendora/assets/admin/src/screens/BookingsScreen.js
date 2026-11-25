import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { config, errorMessage, get, post } from '../api';
import {
	ACTION_LABEL,
	FieldValue,
	StatusBadge,
	formatDateTime,
	formatDay,
	refreshBadges,
} from '../components/entryUi';

const VIEWS = [
	{ id: 'pending', label: __( 'Pending', 'kalendora' ) },
	{ id: 'upcoming', label: __( 'Upcoming', 'kalendora' ) },
	{ id: 'past', label: __( 'Past', 'kalendora' ) },
	{ id: 'closed', label: __( 'Rejected & cancelled', 'kalendora' ) },
	{ id: 'all', label: __( 'All', 'kalendora' ) },
	{ id: 'fees', label: __( 'Open fees', 'kalendora' ), optional: true },
];

const FEE_STATUS = {
	open: __( 'open', 'kalendora' ),
	paid: __( 'paid', 'kalendora' ),
	waived: __( 'waived', 'kalendora' ),
};

const FEE_REASON = {
	late_cancel: __( 'Late cancellation', 'kalendora' ),
	no_show: __( 'Did not attend', 'kalendora' ),
	manual: __( 'Entered manually', 'kalendora' ),
};

export function formatMoney( amount ) {
	return `${ Number( amount || 0 ).toLocaleString( undefined, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	} ) } €`;
}

/**
 * Extra hints from add-ons (e.g. "Paid online").
 *
 * @param {Object} props
 * @param {Object} props.booking Booking.
 */
export function ExtraBadges( { booking } ) {
	return ( booking.badges || [] ).map( ( b, i ) => (
		<span
			key={ i }
			className={ `kalendora-status kalendora-status--${
				b.tone || 'neutral'
			}` }
		>
			{ b.label }
		</span>
	) );
}

export function FeeBadge( { booking } ) {
	if ( ! booking.fee_amount || ! booking.fee_status ) {
		return null;
	}
	return (
		<span className={ `kalendora-fee is-${ booking.fee_status }` }>
			{ sprintf(
				/* translators: 1: amount, 2: status (open, paid, waived). */
				__( 'Fee %1$s · %2$s', 'kalendora' ),
				formatMoney( booking.fee_amount ),
				FEE_STATUS[ booking.fee_status ] || booking.fee_status
			) }
		</span>
	);
}

function FeePanel( { booking, onUpdated } ) {
	const [ amount, setAmount ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const policy = booking.policy || {};
	const hasFee = booking.fee_amount > 0 && booking.fee_status;

	if ( ! hasFee && ! policy.fee && ! canManage ) {
		return null;
	}

	const save = ( status, value ) => {
		setBusy( true );
		setError( '' );
		post( `/bookings/${ booking.id }/fee`, {
			status,
			...( value !== undefined ? { amount: value } : {} ),
		} )
			.then( ( updated ) => {
				setAmount( '' );
				onUpdated?.( updated );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="kalendora-detail__fee">
			<h2 className="kalendora-detail__heading">
				{ __( 'Cancellation terms & fee', 'kalendora' ) }
			</h2>
			{ policy.text && (
				<p className="kalendora-muted">
					{ policy.text }
					{ policy.accepted_at &&
						` ${ sprintf(
							/* translators: %s: date and time. */
							__(
								'(accepted by the customer on %s)',
								'kalendora'
							),
							formatDateTime( policy.accepted_at )
						) }` }
				</p>
			) }
			{ booking.cancelled_by === 'customer' && (
				<p>
					{ __( 'Cancelled online by the customer.', 'kalendora' ) }
				</p>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ hasFee ? (
				<>
					<p>
						<FeeBadge booking={ booking } />{ ' ' }
						{ FEE_REASON[ booking.fee_reason ] || '' }
					</p>
					{ canManage && (
						<div className="kalendora-actions-inline">
							{ booking.fee_status !== 'paid' && (
								<Button
									variant="secondary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'paid' ) }
								>
									{ __( 'Mark as paid', 'kalendora' ) }
								</Button>
							) }
							{ booking.fee_status !== 'waived' && (
								<Button
									variant="secondary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'waived' ) }
								>
									{ __( 'Waive fee', 'kalendora' ) }
								</Button>
							) }
							{ booking.fee_status !== 'open' && (
								<Button
									variant="tertiary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'open' ) }
								>
									{ __( 'Mark as open again', 'kalendora' ) }
								</Button>
							) }
						</div>
					) }
				</>
			) : (
				canManage && (
					<div className="kalendora-fee-form">
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							step="0.01"
							label={ __( 'Record a fee (€)', 'kalendora' ) }
							help={ __(
								'E.g. for a short-notice cancellation by phone.',
								'kalendora'
							) }
							value={ amount }
							onChange={ setAmount }
						/>
						<Button
							variant="secondary"
							size="compact"
							disabled={ busy || ! ( Number( amount ) > 0 ) }
							onClick={ () => save( 'open', Number( amount ) ) }
						>
							{ __( 'Record fee', 'kalendora' ) }
						</Button>
					</div>
				)
			) }
		</div>
	);
}

const canManage = !! config.caps?.manageBookings;

const DONE = {
	confirmed: __( 'confirmed', 'kalendora' ),
	rejected: __( 'rejected', 'kalendora' ),
	cancelled: __( 'cancelled', 'kalendora' ),
	completed: __( 'marked as attended', 'kalendora' ),
	no_show: __( 'marked as no-show', 'kalendora' ),
	pending: __( 'reopened', 'kalendora' ),
};

function Actions( { booking, onChange, busy, size } ) {
	if ( ! canManage ) {
		return null;
	}
	const primary =
		booking.transitions.includes( 'confirmed' ) &&
		booking.status === 'pending';
	return (
		<div className="kalendora-actions-inline">
			{ booking.transitions
				.filter(
					( to ) =>
						! ( booking.status === 'pending' && to === 'cancelled' )
				)
				.map( ( to ) => (
					<Button
						key={ to }
						size={ size }
						disabled={ busy }
						variant={
							to === 'confirmed' && primary
								? 'primary'
								: 'secondary'
						}
						isDestructive={ [
							'rejected',
							'cancelled',
							'no_show',
						].includes( to ) }
						onClick={ ( e ) => {
							e.stopPropagation();
							onChange( booking, to );
						} }
					>
						{ ACTION_LABEL[ to ] || to }
					</Button>
				) ) }
		</div>
	);
}

export function BookingDetail( {
	booking,
	onClose,
	onChange,
	onUpdated,
	busy,
	children,
} ) {
	const [ entry, setEntry ] = useState( null );
	const [ notes, setNotes ] = useState( booking.notes || '' );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		if ( booking.entry_id ) {
			get( `/entries/${ booking.entry_id }` )
				.then( setEntry )
				.catch( () => setEntry( { values: [] } ) );
		} else {
			setEntry( { values: [] } );
		}
	}, [ booking.entry_id ] );

	const saveNotes = () =>
		post( `/bookings/${ booking.id }/notes`, { notes } ).then( () => {
			setSaved( true );
			setTimeout( () => setSaved( false ), 1500 );
		} );

	return (
		<Modal
			title={
				booking.name || booking.email || __( 'Booking', 'kalendora' )
			}
			onRequestClose={ onClose }
			className="kalendora-modal"
		>
			<div className="kalendora-detail">
				<div
					className="kalendora-detail__when"
					style={ { borderColor: booking.service_color } }
				>
					<strong>{ formatDay( booking.date ) }</strong>
					<span>
						{ booking.time } – { booking.end_time }
					</span>
					<span>
						{ booking.service_name }
						{ booking.resource_name
							? ` · ${ booking.resource_name }`
							: '' }
						{ booking.seats > 1
							? ` · ${ sprintf(
									/* translators: %d: number of places. */
									__( '%d places', 'kalendora' ),
									booking.seats
							  ) }`
							: '' }
					</span>
					<StatusBadge status={ booking.status } />
					<ExtraBadges booking={ booking } />
				</div>

				<dl className="kalendora-detail__contact">
					{ booking.email && (
						<>
							<dt>{ __( 'Email', 'kalendora' ) }</dt>
							<dd>
								<a href={ `mailto:${ booking.email }` }>
									{ booking.email }
								</a>
							</dd>
						</>
					) }
					{ booking.phone && (
						<>
							<dt>{ __( 'Phone', 'kalendora' ) }</dt>
							<dd>
								<a
									href={ `tel:${ booking.phone.replace(
										/[^0-9+]/g,
										''
									) }` }
								>
									{ booking.phone }
								</a>
							</dd>
						</>
					) }
					<dt>{ __( 'Booked on', 'kalendora' ) }</dt>
					<dd>{ formatDateTime( booking.created_at ) }</dd>
				</dl>

				<h2 className="kalendora-detail__heading">
					{ __( 'Form details', 'kalendora' ) }
				</h2>
				{ ! entry && <Spinner /> }
				{ entry && entry.values.length === 0 && (
					<p className="kalendora-muted">
						{ __( 'No further details.', 'kalendora' ) }
					</p>
				) }
				{ entry && entry.values.length > 0 && (
					<dl className="kalendora-detail__values">
						{ entry.values.map( ( v ) => (
							<div key={ v.key }>
								<dt>{ v.label }</dt>
								<dd>
									<FieldValue value={ v } />
								</dd>
							</div>
						) ) }
					</dl>
				) }

				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Internal notes', 'kalendora' ) }
					help={ __(
						'Only visible to you and your team.',
						'kalendora'
					) }
					value={ notes }
					onChange={ setNotes }
					rows={ 3 }
					disabled={ ! canManage }
				/>
				{ canManage && (
					<Button
						variant="secondary"
						size="small"
						onClick={ saveNotes }
						disabled={
							notes === ( booking.notes || '' ) && ! saved
						}
					>
						{ saved
							? __( 'Saved', 'kalendora' )
							: __( 'Save notes', 'kalendora' ) }
					</Button>
				) }

				<FeePanel
					key={ `${ booking.id }-${ booking.fee_status }-${ booking.fee_amount }` }
					booking={ booking }
					onUpdated={ onUpdated }
				/>

				{ children }

				<div className="kalendora-modal-actions">
					<Actions
						booking={ booking }
						onChange={ onChange }
						busy={ busy }
					/>
				</div>
			</div>
		</Modal>
	);
}

export default function BookingsScreen() {
	const [ view, setView ] = useState( 'pending' );
	const [ search, setSearch ] = useState( '' );
	const [ service, setService ] = useState( '' );
	const [ services, setServices ] = useState( [] );
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ open, setOpen ] = useState( null );

	useEffect( () => {
		get( '/services' )
			.then( setServices )
			.catch( () => {} );
	}, [] );

	const load = useCallback(
		( p = 1 ) => {
			get( '/bookings', {
				view,
				search,
				service_id: service,
				page: p,
				per_page: 25,
			} )
				.then( ( res ) => {
					setData( ( prev ) =>
						p > 1 && prev
							? { ...res, items: [ ...prev.items, ...res.items ] }
							: res
					);
					setPage( p );
				} )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: errorMessage( e ) } )
				);
		},
		[ view, search, service ]
	);

	useEffect( () => {
		const timer = setTimeout( () => load( 1 ), search ? 300 : 0 );
		return () => clearTimeout( timer );
	}, [ load, search ] );

	const change = ( booking, status ) => {
		setBusy( true );
		setNotice( null );
		post( `/bookings/${ booking.id }/status`, { status } )
			.then( ( updated ) => {
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: 1: customer name, 2: what happened, e.g. "confirmed". */
						__( '%1$s: %2$s', 'kalendora' ),
						updated.name || updated.email,
						DONE[ status ] || status
					),
				} );
				if ( open?.id === updated.id ) {
					setOpen( updated );
				}
				load( 1 );
				refreshBadges();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const grouped = {};
	( data?.items || [] ).forEach( ( b ) => {
		( grouped[ b.date ] ||= [] ).push( b );
	} );

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

			<div className="kalendora-tabs" role="tablist">
				{ VIEWS.filter(
					( v ) =>
						! v.optional ||
						view === v.id ||
						data?.counts?.[ v.id ] > 0
				).map( ( v ) => (
					<button
						type="button"
						role="tab"
						key={ v.id }
						aria-selected={ view === v.id }
						className={ `kalendora-tabs__tab${
							view === v.id ? ' is-active' : ''
						}` }
						onClick={ () => {
							setData( null );
							setView( v.id );
						} }
					>
						{ v.label }
						{ data?.counts?.[ v.id ] > 0 && (
							<span
								className={ `kalendora-tabs__count${
									v.id === 'pending' ? '' : ' is-muted'
								}` }
							>
								{ data.counts[ v.id ] }
							</span>
						) }
					</button>
				) ) }
			</div>

			<div className="kalendora-toolbar kalendora-toolbar--filters">
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search name, email or phone',
						'kalendora'
					) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Service', 'kalendora' ) }
					value={ service }
					options={ [
						{ label: __( 'All services', 'kalendora' ), value: '' },
						...services.map( ( s ) => ( {
							label: s.name,
							value: String( s.id ),
						} ) ),
					] }
					onChange={ setService }
				/>
			</div>

			{ ! data && <Spinner /> }

			{ data && data.items.length === 0 && (
				<Card>
					<CardBody className="kalendora-empty">
						<p>
							{ view === 'pending'
								? __(
										'No open requests – everything is done.',
										'kalendora'
								  )
								: __( 'No bookings found.', 'kalendora' ) }
						</p>
					</CardBody>
				</Card>
			) }

			{ data &&
				Object.entries( grouped ).map( ( [ day, items ] ) => (
					<section key={ day } className="kalendora-day">
						<h2 className="kalendora-day__title">
							{ formatDay( day ) }
						</h2>
						<div className="kalendora-list">
							{ items.map( ( b ) => (
								<Card
									key={ b.id }
									className="kalendora-list__item kalendora-booking"
								>
									<CardBody>
										<button
											type="button"
											className="kalendora-booking__open"
											onClick={ () => setOpen( b ) }
											aria-label={ sprintf(
												/* translators: %s: customer name. */
												__(
													'Show details for %s',
													'kalendora'
												),
												b.name || b.email
											) }
										/>
										<div className="kalendora-booking__time">
											<strong>{ b.time }</strong>
											<span>{ b.end_time }</span>
										</div>
										<span
											className="kalendora-booking__bar"
											style={ {
												background: b.service_color,
											} }
										/>
										<div className="kalendora-list__main">
											<strong>
												{ b.name || b.email }
											</strong>{ ' ' }
											<StatusBadge status={ b.status } />{ ' ' }
											<FeeBadge booking={ b } />{ ' ' }
											<ExtraBadges booking={ b } />
											<div className="kalendora-meta">
												<span>{ b.service_name }</span>
												{ b.resource_name && (
													<span>
														{ b.resource_name }
													</span>
												) }
												{ b.phone && (
													<span>{ b.phone }</span>
												) }
												{ b.email && (
													<span>{ b.email }</span>
												) }
											</div>
										</div>
										<Actions
											booking={ b }
											onChange={ change }
											busy={ busy }
											size="compact"
										/>
									</CardBody>
								</Card>
							) ) }
						</div>
					</section>
				) ) }

			{ data && data.items.length < data.total && (
				<Button variant="secondary" onClick={ () => load( page + 1 ) }>
					{ __( 'Load more', 'kalendora' ) }
				</Button>
			) }

			{ open && (
				<BookingDetail
					booking={ open }
					onClose={ () => setOpen( null ) }
					onChange={ change }
					onUpdated={ ( updated ) => {
						setOpen( updated );
						load( 1 );
					} }
					busy={ busy }
				/>
			) }
		</div>
	);
}
