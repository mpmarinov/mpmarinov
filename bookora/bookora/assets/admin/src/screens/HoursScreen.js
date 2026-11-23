import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { date as formatDate } from '@wordpress/date';

import { config, del, errorMessage, get, post, put } from '../api';
import ConfirmButton from '../components/ConfirmButton';

const DAY_NAMES = [
	__( 'Sunday', 'bookora' ),
	__( 'Monday', 'bookora' ),
	__( 'Tuesday', 'bookora' ),
	__( 'Wednesday', 'bookora' ),
	__( 'Thursday', 'bookora' ),
	__( 'Friday', 'bookora' ),
	__( 'Saturday', 'bookora' ),
];

function orderedDays() {
	const start = Number( config.weekStart ?? 1 );
	return [ 0, 1, 2, 3, 4, 5, 6 ].map( ( i ) => ( i + start ) % 7 );
}

function addHour( time ) {
	const hour = Number( time.slice( 0, 2 ) ) + 1;
	return hour >= 24
		? '24:00'
		: `${ String( hour ).padStart( 2, '0' ) }${ time.slice( 2, 5 ) }`;
}

function initialResource() {
	const id = Number(
		new URLSearchParams( window.location.search ).get( 'resource' )
	);
	return id > 0 ? id : 0;
}

/**
 * Validate one day: every range needs start < end and ranges must not overlap.
 *
 * @param {Array} ranges [[start, end], …]
 * @return {string|null} Error message.
 */
function dayError( ranges ) {
	const sorted = [ ...ranges ].sort( ( a, b ) =>
		a[ 0 ].localeCompare( b[ 0 ] )
	);
	for ( let i = 0; i < sorted.length; i++ ) {
		const [ start, end ] = sorted[ i ];
		if ( ! start || ! end || ( start >= end && end !== '24:00' ) ) {
			return __( 'End time must be after start time.', 'bookora' );
		}
		if ( i > 0 && start < sorted[ i - 1 ][ 1 ] ) {
			return __( 'Time ranges overlap.', 'bookora' );
		}
	}
	return null;
}

function WeeklyEditor( { resourceId } ) {
	const [ weekly, setWeekly ] = useState( null );
	const [ inherits, setInherits ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const query = resourceId ? { resource_id: resourceId } : {};

	useEffect( () => {
		setWeekly( null );
		get( '/schedules/weekly', query )
			.then( ( res ) => {
				setWeekly( res.weekly || {} );
				setInherits( res.inherits );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ resourceId ] );

	if ( ! weekly ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const ranges = ( day ) => weekly[ day ] || [];
	const setDay = ( day, value ) => {
		const next = { ...weekly };
		if ( value.length ) {
			next[ day ] = value;
		} else {
			delete next[ day ];
		}
		setWeekly( next );
	};

	const errors = Object.fromEntries(
		Object.keys( weekly ).map( ( day ) => [
			day,
			dayError( weekly[ day ] ),
		] )
	);
	const hasErrors = Object.values( errors ).some( Boolean );

	const save = ( value = weekly ) => {
		setBusy( true );
		setNotice( null );
		put(
			'/schedules/weekly' +
				( resourceId ? `?resource_id=${ resourceId }` : '' ),
			{
				weekly: value,
			}
		)
			.then( ( res ) => {
				setWeekly( res.weekly || {} );
				setInherits( res.inherits );
				setNotice( {
					status: 'success',
					text: __( 'Opening hours saved.', 'bookora' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const copyToWorkdays = ( day ) => {
		const next = { ...weekly };
		[ 1, 2, 3, 4, 5 ].forEach( ( d ) => {
			next[ d ] = ranges( day ).map( ( r ) => [ ...r ] );
		} );
		setWeekly( next );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Weekly hours', 'bookora' ) }</h2>
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
				{ resourceId > 0 && inherits && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This staff member uses the general opening hours. Change and save the hours below to give them their own schedule.',
							'bookora'
						) }
					</Notice>
				) }

				<div className="bookora-week">
					{ orderedDays().map( ( day ) => {
						const list = ranges( day );
						const open = list.length > 0;
						return (
							<div className="bookora-week__day" key={ day }>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ DAY_NAMES[ day ] }
									checked={ open }
									onChange={ ( on ) =>
										setDay(
											day,
											on ? [ [ '09:00', '17:00' ] ] : []
										)
									}
								/>
								<div className="bookora-week__ranges">
									{ ! open && (
										<span className="bookora-muted">
											{ __( 'Closed', 'bookora' ) }
										</span>
									) }
									{ list.map( ( range, index ) => (
										<div
											className="bookora-range"
											key={ index }
										>
											<input
												type="time"
												aria-label={ __(
													'From',
													'bookora'
												) }
												value={ range[ 0 ] }
												onChange={ ( e ) => {
													const next = list.map(
														( r ) => [ ...r ]
													);
													next[ index ][ 0 ] =
														e.target.value;
													setDay( day, next );
												} }
											/>
											<span aria-hidden="true">–</span>
											<input
												type="time"
												aria-label={ __(
													'To',
													'bookora'
												) }
												value={
													range[ 1 ] === '24:00'
														? '23:59'
														: range[ 1 ]
												}
												onChange={ ( e ) => {
													const next = list.map(
														( r ) => [ ...r ]
													);
													next[ index ][ 1 ] =
														e.target.value;
													setDay( day, next );
												} }
											/>
											<Button
												icon="no-alt"
												label={ __(
													'Remove time range',
													'bookora'
												) }
												size="small"
												onClick={ () =>
													setDay(
														day,
														list.filter(
															( _, i ) =>
																i !== index
														)
													)
												}
											/>
										</div>
									) ) }
									{ errors[ day ] && (
										<span className="bookora-error">
											{ errors[ day ] }
										</span>
									) }
								</div>
								<div className="bookora-week__actions">
									{ open && (
										<>
											<Button
												variant="link"
												onClick={ () => {
													const last =
														list[ list.length - 1 ];
													setDay( day, [
														...list,
														[
															last[ 1 ],
															addHour(
																last[ 1 ]
															),
														],
													] );
												} }
											>
												{ __(
													'+ Add break / range',
													'bookora'
												) }
											</Button>
											<Button
												variant="link"
												onClick={ () =>
													copyToWorkdays( day )
												}
											>
												{ __(
													'Copy to Mon–Fri',
													'bookora'
												) }
											</Button>
										</>
									) }
								</div>
							</div>
						);
					} ) }
				</div>

				<div className="bookora-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || hasErrors }
						onClick={ () => save() }
					>
						{ __( 'Save opening hours', 'bookora' ) }
					</Button>
					{ resourceId > 0 && ! inherits && (
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () => save( {} ) }
						>
							{ __( 'Use general opening hours', 'bookora' ) }
						</Button>
					) }
				</div>
			</CardBody>
		</Card>
	);
}

const EMPTY_EXCEPTION = {
	type: 'closed',
	date_start: '',
	date_end: '',
	allDay: true,
	start_time: '09:00',
	end_time: '17:00',
	note: '',
};

function ExceptionsEditor( { resourceId } ) {
	const [ items, setItems ] = useState( null );
	const [ form, setForm ] = useState( EMPTY_EXCEPTION );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const reload = useCallback( () => {
		get(
			'/schedules/exceptions',
			resourceId ? { resource_id: resourceId } : {}
		)
			.then( setItems )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [ resourceId ] );

	useEffect( reload, [ reload ] );

	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );

	const submit = ( event ) => {
		event.preventDefault();
		const withTimes = form.type === 'open' || ! form.allDay;
		setBusy( true );
		setNotice( null );
		post( '/schedules/exceptions', {
			resource_id: resourceId || null,
			type: form.type,
			date_start: form.date_start,
			date_end: form.date_end || form.date_start,
			start_time: withTimes ? form.start_time : null,
			end_time: withTimes ? form.end_time : null,
			note: form.note,
		} )
			.then( () => {
				setForm( EMPTY_EXCEPTION );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'bookora' ),
				} );
				reload();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const remove = ( id ) =>
		del( `/schedules/exceptions/${ id }` )
			.then( reload )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	const describe = ( ex ) => {
		const from = formatDate( 'd.m.Y', ex.date_start );
		const to = formatDate( 'd.m.Y', ex.date_end );
		const range = from === to ? from : `${ from } – ${ to }`;
		const times = ex.start_time
			? ` · ${ ex.start_time }–${ ex.end_time }`
			: '';
		return range + times;
	};

	return (
		<Card>
			<CardHeader>
				<h2>
					{ __(
						'Holidays, vacation & special opening hours',
						'bookora'
					) }
				</h2>
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

				<form className="bookora-exception-form" onSubmit={ submit }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Type', 'bookora' ) }
						value={ form.type }
						options={ [
							{
								label: __(
									'Closed (holiday, vacation, break)',
									'bookora'
								),
								value: 'closed',
							},
							{
								label: __( 'Special opening hours', 'bookora' ),
								value: 'open',
							},
						] }
						onChange={ set( 'type' ) }
					/>
					<div className="bookora-grid">
						<TextControl
							__nextHasNoMarginBottom
							type="date"
							label={ __( 'From', 'bookora' ) }
							value={ form.date_start }
							onChange={ set( 'date_start' ) }
							required
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="date"
							label={ __( 'To (optional)', 'bookora' ) }
							value={ form.date_end }
							min={ form.date_start }
							onChange={ set( 'date_end' ) }
						/>
					</div>
					{ form.type === 'closed' && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'All day', 'bookora' ) }
							checked={ form.allDay }
							onChange={ set( 'allDay' ) }
						/>
					) }
					{ ( form.type === 'open' || ! form.allDay ) && (
						<div className="bookora-grid">
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'Start time', 'bookora' ) }
								value={ form.start_time }
								onChange={ set( 'start_time' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'End time', 'bookora' ) }
								value={ form.end_time }
								onChange={ set( 'end_time' ) }
							/>
						</div>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Note (internal)', 'bookora' ) }
						value={ form.note }
						onChange={ set( 'note' ) }
						placeholder={ __( 'e.g. Christmas', 'bookora' ) }
					/>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! form.date_start }
					>
						{ __( 'Add', 'bookora' ) }
					</Button>
				</form>

				{ ! items && <Spinner /> }
				{ items && items.length === 0 && (
					<p className="bookora-muted">
						{ __(
							'No upcoming holidays or special opening hours.',
							'bookora'
						) }
					</p>
				) }
				{ items && items.length > 0 && (
					<ul className="bookora-exceptions">
						{ items.map( ( ex ) => (
							<li key={ ex.id }>
								<span
									className={ `bookora-badge bookora-badge--${ ex.type }` }
								>
									{ ex.type === 'open'
										? __( 'Open', 'bookora' )
										: __( 'Closed', 'bookora' ) }
								</span>
								<span className="bookora-exceptions__when">
									{ describe( ex ) }
								</span>
								{ ex.note && (
									<span className="bookora-muted">
										{ ex.note }
									</span>
								) }
								{ resourceId > 0 && ex.resource_id === null && (
									<span className="bookora-muted">
										{ __(
											'(applies to everyone)',
											'bookora'
										) }
									</span>
								) }
								{ ( resourceId === 0 ||
									ex.resource_id !== null ) && (
									<ConfirmButton
										message={ sprintf(
											/* translators: %s: date range. */
											__(
												'Delete the entry for %s?',
												'bookora'
											),
											describe( ex )
										) }
										onConfirm={ () => remove( ex.id ) }
									/>
								) }
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

export default function HoursScreen() {
	const [ resources, setResources ] = useState( [] );
	const [ resourceId, setResourceId ] = useState( initialResource );

	useEffect( () => {
		get( '/resources' )
			.then( setResources )
			.catch( () => {} );
	}, [] );

	const options = [
		{
			label: __( 'General opening hours (business)', 'bookora' ),
			value: '0',
		},
		...resources.map( ( r ) => ( {
			label: r.name,
			value: String( r.id ),
		} ) ),
	];

	return (
		<div className="bookora-screen">
			{ resources.length > 0 && (
				<div className="bookora-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Show hours for', 'bookora' ) }
						value={ String( resourceId ) }
						options={ options }
						onChange={ ( value ) =>
							setResourceId( Number( value ) )
						}
					/>
				</div>
			) }
			<WeeklyEditor resourceId={ resourceId } />
			<ExceptionsEditor resourceId={ resourceId } />
		</div>
	);
}
