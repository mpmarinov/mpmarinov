/**
 * Build a REST URL that works with and without pretty permalinks
 * ("/wp-json/bookora/v1" or "/index.php?rest_route=/bookora/v1").
 *
 * @param {string} base   REST base from the config.
 * @param {string} path   Route, e.g. "/availability".
 * @param {Object} params Query parameters.
 * @return {string} URL.
 */
export default function apiUrl( base, path, params = {} ) {
	const query = new URLSearchParams( params ).toString();
	const url = base.replace( /\/$/, '' ) + path;
	if ( ! query ) {
		return url;
	}
	return url + ( url.includes( '?' ) ? '&' : '?' ) + query;
}
