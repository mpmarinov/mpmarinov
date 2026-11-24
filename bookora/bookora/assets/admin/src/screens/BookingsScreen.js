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
	StatusBadge,
	formatDateTime,
	formatDay,
	refreshBadges,
} from '../components/entryUi';

const VIEWS = [
	{ id: 'pending', label: __( 'Pending', 'bookora' ) },
	{ id: 'upcoming', label: __( 'Upcoming', 'bookora' ) },
	{ id: 'past', label: __( 'Past', 'bookora' ) },
	{ id: 'closed', label: __( 'Rejected & cancelled', 'bookora' ) },
	{ id: 'all', label: __( 'All', 'bookora' ) },
	{ id: 'fees', label: __( 'Open fees', 'bookora' ), optional: true },
];

const FEE_STATUS = {
	open: __( 'open', 'bookora' ),
	paid: __( 'paid', 'bookora' ),
	waived: __( 'waived', 'bookora' ),
};

const FEE_REASON = {
	late_cancel: __( 'Late cancellation', 'bookora' ),
	no_show: __( 'Did not attend', 'bookora' ),
	manual: __( 'Entered manually', 'bookora' ),
};

export function formatMoney( amount ) {
	return `${ Number( amount || 0 ).toLocaleString( undefined, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	} ) } €`;
}

export function FeeBadge( { booking } ) {
	if ( ! booking.fee_amount || ! booking.fee_status ) {
		return null;
	}
	return (
		<span className={ `bookora-fee is-${ booking.fee_status }` }>
			{ sprintf(
				/* translators: 1: amount, 2: status (open, paid, waived). */
				__( 'Fee %1$s · %2$s', 'bookora' ),
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
		<div className="bookora-detail__fee">
			<h3>{ __( 'Cancellation terms & fee', 'bookora' ) }</h3>
			{ policy.text && (
				<p className="bookora-muted">
					{ policy.text }
					{ policy.accepted_at &&
						` ${ sprintf(
							/* translators: %s: date and time. */
							__( '(accepted by the customer on %s)', 'bookora' ),
							formatDateTime( policy.accepted_at )
						) }` }
				</p>
			) }
			{ booking.cancelled_by === 'customer' && (
				<p>{ __( 'Cancelled online by the customer.', 'bookora' ) }</p>
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
						<div className="bookora-actions-inline">
							{ booking.fee_status !== 'paid' && (
								<Button
									variant="secondary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'paid' ) }
								>
									{ __( 'Mark as paid', 'bookora' ) }
								</Button>
							) }
							{ booking.fee_status !== 'waived' && (
								<Button
									variant="secondary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'waived' ) }
								>
									{ __( 'Waive fee', 'bookora' ) }
								</Button>
							) }
							{ booking.fee_status !== 'open' && (
								<Button
									variant="tertiary"
									size="compact"
									disabled={ busy }
									onClick={ () => save( 'open' ) }
								>
									{ __( 'Mark as open again', 'bookora' ) }
								</Button>
							) }
						</div>
					) }
				</>
			) : (
				canManage && (
					<div className="bookora-fee-form">
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							step="0.01"
							label={ __( 'Record a fee (€)', 'bookora' ) }
							help={ __(
								'E.g. for a short-notice cancellation by phone.',
								'bookora'
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
							{ __( 'Record fee', 'bookora' ) }
						</Button>
					</div>
				)
			) }
		</div>
	);
}

const canManage = !! config.caps?.manageBookings;

const DONE = {
	confirmed: __( 'confirmed', 'bookora' ),
	rejected: __( 'rejected', 'bookora' ),
	cancelled: __( 'cancelled', 'bookora' ),
	completed: __( 'marked as attended', 'bookora' ),
	no_show: __( 'marked as no-show', 'bookora' ),
	pending: __( 'reopened', 'bookora' ),
};

function Actions( { booking, onChange, busy, size } ) {
	if ( ! canManage ) {
		return null;
	}
	const primary =
		booking.transitions.includes( 'confirmed' ) &&
		booking.status === 'pending';
	return (
		<div className="bookora-actions-inline">
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
				booking.name || booking.email || __( 'Booking', 'bookora' )
			}
			onRequestClose={ onClose }
			className="bookora-modal"
		>
			<div className="bookora-detail">
				<div
					className="bookora-detail__when"
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
									__( '%d places', 'bookora' ),
									booking.seats
							  ) }`
							: '' }
					</span>
					<StatusBadge status={ booking.status } />
				</div>

				<dl className="bookora-detail__contact">
					{ booking.email && (
						<>
							<dt>{ __( 'Email', 'bookora' ) }</dt>
							<dd>
								<a href={ `mailto:${ booking.email }` }>
									{ booking.email }
								</a>
							</dd>
						</>
					) }
					{ booking.phone && (
						<>
							<dt>{ __( 'Phone', 'bookora' ) }</dt>
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
					<dt>{ __( 'Booked on', 'bookora' ) }</dt>
					<dd>{ formatDateTime( booking.created_at ) }</dd>
				</dl>

				<h3>{ __( 'Form details', 'bookora' ) }</h3>
				{ ! entry && <Spinner /> }
				{ entry && entry.values.length === 0 && (
					<p className="bookora-muted">
						{ __( 'No further details.', 'bookora' ) }
					</p>
				) }
				{ entry && entry.values.length > 0 && (
					<dl className="bookora-detail__values">
						{ entry.values.map( ( v ) => (
							<div key={ v.key }>
								<dt>{ v.label }</dt>
								<dd>{ v.value }</dd>
							</div>
						) ) }
					</dl>
				) }

				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Internal notes', 'bookora' ) }
					help={ __(
						'Only visible to you and your team.',
						'bookora'
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
							? __( 'Saved', 'bookora' )
							: __( 'Save notes', 'bookora' ) }
					</Button>
				) }

				<FeePanel
					key={ `${ booking.id }-${ booking.fee_status }-${ booking.fee_amount }` }
					booking={ booking }
					onUpdated={ onUpdated }
				/>

				{ children }

				<div className="bookora-modal-actions">
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
						__( '%1$s: %2$s', 'bookora' ),
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
		<div className="bookora-screen">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<div className="bookora-tabs" role="tablist">
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
						className={ `bookora-tabs__tab${
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
								className={ `bookora-tabs__count${
									v.id === 'pending' ? '' : ' is-muted'
								}` }
							>
								{ data.counts[ v.id ] }
							</span>
						) }
					</button>
				) ) }
			</div>

			<div className="bookora-toolbar bookora-toolbar--filters">
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search name, email or phone',
						'bookora'
					) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Service', 'bookora' ) }
					value={ service }
					options={ [
						{ label: __( 'All services', 'bookora' ), value: '' },
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
					<CardBody className="bookora-empty">
						<p>
							{ view === 'pending'
								? __(
										'No open requests – everything is done.',
										'bookora'
								  )
								: __( 'No bookings found.', 'bookora' ) }
						</p>
					</CardBody>
				</Card>
			) }

			{ data &&
				Object.entries( grouped ).map( ( [ day, items ] ) => (
					<section key={ day } className="bookora-day">
						<h2 className="bookora-day__title">
							{ formatDay( day ) }
						</h2>
						<div className="bookora-list">
							{ items.map( ( b ) => (
								<Card
									key={ b.id }
									className="bookora-list__item bookora-booking"
								>
									<CardBody>
										<button
											type="button"
											className="bookora-booking__open"
											onClick={ () => setOpen( b ) }
											aria-label={ sprintf(
												/* translators: %s: customer name. */
												__(
													'Show details for %s',
													'bookora'
												),
												b.name || b.email
											) }
										/>
										<div className="bookora-booking__time">
											<strong>{ b.time }</strong>
											<span>{ b.end_time }</span>
										</div>
										<span
											className="bookora-booking__bar"
											style={ {
												background: b.service_color,
											} }
										/>
										<div className="bookora-list__main">
											<strong>
												{ b.name || b.email }
											</strong>{ ' ' }
											<StatusBadge status={ b.status } />{ ' ' }
											<FeeBadge booking={ b } />
											<div className="bookora-meta">
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
					{ __( 'Load more', 'bookora' ) }
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
