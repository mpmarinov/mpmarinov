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
	__( 'Sunday', 'kalendora' ),
	__( 'Monday', 'kalendora' ),
	__( 'Tuesday', 'kalendora' ),
	__( 'Wednesday', 'kalendora' ),
	__( 'Thursday', 'kalendora' ),
	__( 'Friday', 'kalendora' ),
	__( 'Saturday', 'kalendora' ),
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
			return __( 'End time must be after start time.', 'kalendora' );
		}
		if ( i > 0 && start < sorted[ i - 1 ][ 1 ] ) {
			return __( 'Time ranges overlap.', 'kalendora' );
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
					text: __( 'Opening hours saved.', 'kalendora' ),
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
				<h2>{ __( 'Weekly hours', 'kalendora' ) }</h2>
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
							'kalendora'
						) }
					</Notice>
				) }

				<div className="kalendora-week">
					{ orderedDays().map( ( day ) => {
						const list = ranges( day );
						const open = list.length > 0;
						return (
							<div className="kalendora-week__day" key={ day }>
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
								<div className="kalendora-week__ranges">
									{ ! open && (
										<span className="kalendora-muted">
											{ __( 'Closed', 'kalendora' ) }
										</span>
									) }
									{ list.map( ( range, index ) => (
										<div
											className="kalendora-range"
											key={ index }
										>
											<input
												type="time"
												aria-label={ __(
													'From',
													'kalendora'
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
													'kalendora'
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
													'kalendora'
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
										<span className="kalendora-error">
											{ errors[ day ] }
										</span>
									) }
								</div>
								<div className="kalendora-week__actions">
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
													'kalendora'
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
													'kalendora'
												) }
											</Button>
										</>
									) }
								</div>
							</div>
						);
					} ) }
				</div>

				<div className="kalendora-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || hasErrors }
						onClick={ () => save() }
					>
						{ __( 'Save opening hours', 'kalendora' ) }
					</Button>
					{ resourceId > 0 && ! inherits && (
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () => save( {} ) }
						>
							{ __( 'Use general opening hours', 'kalendora' ) }
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
					text: __( 'Saved.', 'kalendora' ),
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
						'kalendora'
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

				<form className="kalendora-exception-form" onSubmit={ submit }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Type', 'kalendora' ) }
						value={ form.type }
						options={ [
							{
								label: __(
									'Closed (holiday, vacation, break)',
									'kalendora'
								),
								value: 'closed',
							},
							{
								label: __(
									'Special opening hours',
									'kalendora'
								),
								value: 'open',
							},
						] }
						onChange={ set( 'type' ) }
					/>
					<div className="kalendora-grid">
						<TextControl
							__nextHasNoMarginBottom
							type="date"
							label={ __( 'From', 'kalendora' ) }
							value={ form.date_start }
							onChange={ set( 'date_start' ) }
							required
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="date"
							label={ __( 'To (optional)', 'kalendora' ) }
							value={ form.date_end }
							min={ form.date_start }
							onChange={ set( 'date_end' ) }
						/>
					</div>
					{ form.type === 'closed' && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'All day', 'kalendora' ) }
							checked={ form.allDay }
							onChange={ set( 'allDay' ) }
						/>
					) }
					{ ( form.type === 'open' || ! form.allDay ) && (
						<div className="kalendora-grid">
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'Start time', 'kalendora' ) }
								value={ form.start_time }
								onChange={ set( 'start_time' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="time"
								label={ __( 'End time', 'kalendora' ) }
								value={ form.end_time }
								onChange={ set( 'end_time' ) }
							/>
						</div>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Note (internal)', 'kalendora' ) }
						value={ form.note }
						onChange={ set( 'note' ) }
						placeholder={ __( 'e.g. Christmas', 'kalendora' ) }
					/>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! form.date_start }
					>
						{ __( 'Add', 'kalendora' ) }
					</Button>
				</form>

				{ ! items && <Spinner /> }
				{ items && items.length === 0 && (
					<p className="kalendora-muted">
						{ __(
							'No upcoming holidays or special opening hours.',
							'kalendora'
						) }
					</p>
				) }
				{ items && items.length > 0 && (
					<ul className="kalendora-exceptions">
						{ items.map( ( ex ) => (
							<li key={ ex.id }>
								<span
									className={ `kalendora-badge kalendora-badge--${ ex.type }` }
								>
									{ ex.type === 'open'
										? __( 'Open', 'kalendora' )
										: __( 'Closed', 'kalendora' ) }
								</span>
								<span className="kalendora-exceptions__when">
									{ describe( ex ) }
								</span>
								{ ex.note && (
									<span className="kalendora-muted">
										{ ex.note }
									</span>
								) }
								{ resourceId > 0 && ex.resource_id === null && (
									<span className="kalendora-muted">
										{ __(
											'(applies to everyone)',
											'kalendora'
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
												'kalendora'
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
			label: __( 'General opening hours (business)', 'kalendora' ),
			value: '0',
		},
		...resources.map( ( r ) => ( {
			label: r.name,
			value: String( r.id ),
		} ) ),
	];

	return (
		<div className="kalendora-screen">
			{ resources.length > 0 && (
				<div className="kalendora-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Show hours for', 'kalendora' ) }
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
