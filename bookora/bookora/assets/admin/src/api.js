/**
 * Thin wrapper around apiFetch for the Bookora REST namespace.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = window.bookoraAdmin?.restPath || '/bookora/v1';

export const config = window.bookoraAdmin || {};

export function get( route, query = {} ) {
	return apiFetch( { path: addQueryArgs( BASE + route, query ) } );
}

export function post( route, data ) {
	return apiFetch( { path: BASE + route, method: 'POST', data } );
}

export function put( route, data ) {
	return apiFetch( { path: BASE + route, method: 'PUT', data } );
}

export function del( route ) {
	return apiFetch( { path: BASE + route, method: 'DELETE' } );
}

/**
 * Human readable error message from a REST error.
 *
 * @param {Object} error Error thrown by apiFetch.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	return error?.message || String( error );
}

/**
 * All IANA timezones supported by the browser.
 *
 * @return {string[]} Timezones.
 */
export function timezones() {
	try {
		return Intl.supportedValuesOf( 'timeZone' );
	} catch ( e ) {
		return [ 'UTC', 'Europe/Berlin', 'Europe/Sofia', 'Europe/London' ];
	}
}
