/**
 * Calculated fields – same grammar and rules as Kalendora\Forms\Formula (PHP).
 * Numbers, {variables}, + - * / ( ), unary minus, round/min/max/ceil/floor/abs.
 * No eval: shunting-yard to reverse polish notation.
 */
const FUNCTIONS = [ 'round', 'min', 'max', 'ceil', 'floor', 'abs' ];
const PREC = { '+': 1, '-': 1, '*': 2, '/': 2, neg: 3 };
const cache = new Map();

function tokens( formula ) {
	const out = [];
	let i = 0;
	const re = {
		num: /^\d+(?:\.\d+)?/,
		variable: /^\{([a-z0-9_]+)\}/,
		fn: /^([a-z]+)\s*\(/,
	};
	while ( i < formula.length ) {
		const rest = formula.slice( i );
		let m;
		if ( /^\s/.test( rest ) ) {
			i++;
		} else if ( ( m = rest.match( re.num ) ) ) {
			out.push( [ 'num', m[ 0 ] ] );
			i += m[ 0 ].length;
		} else if ( ( m = rest.match( re.variable ) ) ) {
			out.push( [ 'var', m[ 1 ] ] );
			i += m[ 0 ].length;
		} else if ( ( m = rest.match( re.fn ) ) ) {
			if ( ! FUNCTIONS.includes( m[ 1 ] ) ) {
				return null;
			}
			out.push( [ 'fn', m[ 1 ] ], [ '(', '(' ] );
			i += m[ 0 ].length;
		} else if ( '+-*/(),'.includes( rest[ 0 ] ) ) {
			out.push( [ rest[ 0 ], rest[ 0 ] ] );
			i++;
		} else {
			return null;
		}
	}
	return out;
}

function rpn( formula ) {
	if ( cache.has( formula ) ) {
		return cache.get( formula );
	}
	const list = tokens( formula );
	let result = null;
	if ( list && list.length && list.length <= 300 ) {
		result = build( list );
	}
	cache.set( formula, result );
	return result;
}

function build( list ) {
	const out = [];
	const ops = [];
	const argc = [];
	let expect = true;
	let depth = 0;
	const opOut = ( op ) =>
		op[ 0 ] === 'neg' ? [ 'neg', 'neg', 1 ] : [ 'op', op[ 1 ], 2 ];
	const top = () => ops[ ops.length - 1 ];

	for ( const [ kind, value ] of list ) {
		if ( kind === 'num' || kind === 'var' ) {
			if ( ! expect ) {
				return null;
			}
			out.push( [ kind, value, 0 ] );
			expect = false;
		} else if ( kind === 'fn' ) {
			if ( ! expect ) {
				return null;
			}
			ops.push( [ 'fn', value ] );
		} else if ( kind === '(' ) {
			if ( ! expect ) {
				return null;
			}
			ops.push( [ '(', '(' ] );
			argc.push( 1 );
			depth++;
		} else if ( kind === ',' ) {
			if ( expect || ! argc.length ) {
				return null;
			}
			while ( ops.length && top()[ 0 ] !== '(' ) {
				out.push( opOut( ops.pop() ) );
			}
			argc[ argc.length - 1 ]++;
			expect = true;
		} else if ( kind === ')' ) {
			if ( expect || depth < 1 ) {
				return null;
			}
			while ( ops.length && top()[ 0 ] !== '(' ) {
				out.push( opOut( ops.pop() ) );
			}
			ops.pop();
			const n = argc.pop();
			depth--;
			if ( ops.length && top()[ 0 ] === 'fn' ) {
				out.push( [ 'fn', ops.pop()[ 1 ], n ] );
			} else if ( n > 1 ) {
				return null;
			}
			expect = false;
		} else if ( expect ) {
			if ( kind === '-' ) {
				ops.push( [ 'neg', 'neg' ] );
			} else if ( kind !== '+' ) {
				return null;
			}
		} else {
			while (
				ops.length &&
				PREC[ top()[ 1 ] ] !== undefined &&
				PREC[ top()[ 1 ] ] >= PREC[ kind ]
			) {
				out.push( opOut( ops.pop() ) );
			}
			ops.push( [ 'op', kind ] );
			expect = true;
		}
	}
	if ( expect || depth !== 0 ) {
		return null;
	}
	while ( ops.length ) {
		const op = ops.pop();
		if ( op[ 0 ] === '(' || op[ 0 ] === 'fn' ) {
			return null;
		}
		out.push( opOut( op ) );
	}
	return out;
}

function call( name, args ) {
	const x = args[ 0 ] || 0;
	switch ( name ) {
		case 'round': {
			const d = Math.max(
				0,
				Math.min( 6, Math.trunc( args[ 1 ] || 0 ) )
			);
			return round( x, d );
		}
		case 'min':
			return args.length ? Math.min( ...args ) : 0;
		case 'max':
			return args.length ? Math.max( ...args ) : 0;
		case 'ceil':
			return Math.ceil( x );
		case 'floor':
			return Math.floor( x );
		default:
			return Math.abs( x );
	}
}

/**
 * Round half away from zero, like PHP round().
 *
 * @param {number} x        Number.
 * @param {number} decimals Decimals.
 * @return {number} Rounded.
 */
export function round( x, decimals ) {
	const f = 10 ** decimals;
	const v = Math.abs( x ) * f;
	return ( Math.sign( x ) * Math.round( Number( v.toPrecision( 15 ) ) ) ) / f;
}

/**
 * Can the formula be calculated (syntax check)?
 *
 * @param {string} formula Formula.
 * @return {boolean} Valid.
 */
export function isValid( formula ) {
	return rpn( String( formula || '' ).trim() ) !== null;
}

/**
 * Evaluate a formula. Unknown variables, errors and division by zero give 0.
 *
 * @param {string} formula Formula.
 * @param {Object} vars    Variables.
 * @return {number} Result.
 */
export function evaluate( formula, vars ) {
	const program = rpn( String( formula || '' ) );
	if ( ! program ) {
		return 0;
	}
	const stack = [];
	for ( const [ kind, value, n ] of program ) {
		if ( kind === 'num' ) {
			stack.push( Number( value ) );
		} else if ( kind === 'var' ) {
			stack.push( Number( vars[ value ] ) || 0 );
		} else if ( kind === 'neg' ) {
			stack.push( -stack.pop() );
		} else if ( kind === 'op' ) {
			const b = stack.pop();
			const a = stack.pop();
			if ( value === '+' ) {
				stack.push( a + b );
			} else if ( value === '-' ) {
				stack.push( a - b );
			} else if ( value === '*' ) {
				stack.push( a * b );
			} else {
				stack.push( b === 0 ? 0 : a / b );
			}
		} else {
			stack.push( call( value, n ? stack.splice( -n ) : [] ) );
		}
	}
	const result = stack[ 0 ] || 0;
	return Number.isFinite( result ) ? result : 0;
}

/**
 * Format like PHP number_format_i18n with prefix/suffix.
 *
 * @param {Object} def    Field definition.
 * @param {number} value  Value.
 * @param {Object} config Form config (decimal, thousands).
 * @return {string} Text.
 */
export function format( def, value, config ) {
	const decimals = Math.max( 0, Math.min( 4, def.decimals ?? 2 ) );
	const [ int, frac ] = Math.abs( round( value, decimals ) )
		.toFixed( decimals )
		.split( '.' );
	const grouped = int.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		config.thousands ?? ','
	);
	const number =
		( value < 0 && round( value, decimals ) !== 0 ? '-' : '' ) +
		grouped +
		( frac ? ( config.decimal ?? '.' ) + frac : '' );
	return [ def.prefix, number, def.suffix ]
		.filter( ( p ) => p !== undefined && p !== '' )
		.join( ' ' );
}
