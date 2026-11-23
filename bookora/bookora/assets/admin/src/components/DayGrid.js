/**
 * Preview: which start times a service gets on a typical working day.
 * Mirrors the server-side slot engine (duration must fit, step = interval, daily limit).
 */
import { __, _n, sprintf } from '@wordpress/i18n';

const DAY_NAMES = [
	__( 'Sunday', 'bookora' ),
	__( 'Monday', 'bookora' ),
	__( 'Tuesday', 'bookora' ),
	__( 'Wednesday', 'bookora' ),
	__( 'Thursday', 'bookora' ),
	__( 'Friday', 'bookora' ),
	__( 'Saturday', 'bookora' ),
];

const toMin = ( t ) => {
	const [ h, m ] = t.split( ':' ).map( Number );
	return h * 60 + m;
};
const toTime = ( m ) =>
	`${ String( Math.floor( m / 60 ) ).padStart( 2, '0' ) }:${ String(
		m % 60
	).padStart( 2, '0' ) }`;

export function dayGrid( ranges, { duration, interval, maxPerDay } ) {
	const starts = [];
	ranges.forEach( ( [ open, close ] ) => {
		const end = close === '24:00' ? 1440 : toMin( close );
		for ( let t = toMin( open ); t + duration <= end; t += interval ) {
			starts.push( [ t, t + duration ] );
		}
	} );
	return maxPerDay > 0 ? starts.slice( 0, maxPerDay ) : starts;
}

export default function DayGrid( { weekly, data, weekStart = 1 } ) {
	const duration = Number( data.duration ) || 0;
	const before = Number( data.buffer_before ) || 0;
	const after = Number( data.buffer_after ) || 0;
	const interval = Number( data.slot_interval ) || duration + before + after;
	const maxPerDay = Number( data.max_per_day ) || 0;

	if ( duration < 5 ) {
		return null;
	}

	const order = [ 0, 1, 2, 3, 4, 5, 6 ].map( ( i ) => ( i + weekStart ) % 7 );
	const day = order.find( ( d ) => weekly?.[ d ]?.length );
	if ( day === undefined ) {
		return (
			<p className="bookora-muted">
				{ __(
					'Set your opening hours to see the daily schedule.',
					'bookora'
				) }
			</p>
		);
	}

	const slots = dayGrid( weekly[ day ], {
		duration,
		interval: Math.max( 5, interval ),
		maxPerDay,
	} );
	const hours = weekly[ day ]
		.map( ( [ a, b ] ) => `${ a }–${ b }` )
		.join( ', ' );

	return (
		<div className="bookora-daygrid" aria-live="polite">
			<p className="bookora-daygrid__title">
				{ sprintf(
					/* translators: 1: weekday, 2: opening hours. */
					__( 'Daily schedule on %1$s (%2$s):', 'bookora' ),
					DAY_NAMES[ day ],
					hours
				) }
			</p>
			<div className="bookora-daygrid__slots">
				{ slots.map( ( [ s, e ] ) => (
					<span key={ s } className="bookora-daygrid__slot">
						{ toTime( s ) }–{ toTime( e ) }
					</span>
				) ) }
			</div>
			<p className="bookora-daygrid__total">
				{ sprintf(
					/* translators: %d: number of appointments. */
					_n(
						'= max. %d appointment per day',
						'= max. %d appointments per day',
						slots.length,
						'bookora'
					),
					slots.length
				) }
				{ after > 0 &&
					' · ' +
						sprintf(
							/* translators: %d: minutes. */
							__(
								'%d min break after each appointment',
								'bookora'
							),
							after
						) }
			</p>
		</div>
	);
}
