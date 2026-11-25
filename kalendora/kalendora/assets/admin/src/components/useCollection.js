import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { del, errorMessage, get, post, put } from '../api';

/**
 * Load and mutate a REST collection (list, create, update, delete).
 *
 * @param {string} route Collection route, e.g. "/services".
 * @return {Object} State and actions.
 */
// Last loaded list per route: switching screens shows it at once, then refreshes.
const CACHE = new Map();

export default function useCollection( route ) {
	const [ items, setItemsState ] = useState( () =>
		CACHE.has( route ) ? CACHE.get( route ) : null
	);
	const setItems = useCallback(
		( data ) => {
			CACHE.set( route, data );
			setItemsState( data );
		},
		[ route ]
	);
	const [ notice, setNotice ] = useState( null );

	const reload = useCallback( () => {
		return get( route )
			.then( setItems )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
	}, [ route, setItems ] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	const save = ( data ) => {
		const request = data.id
			? put( `${ route }/${ data.id }`, data )
			: post( route, data );

		return request.then( ( saved ) =>
			reload().then( () => {
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'kalendora' ),
				} );
				return saved;
			} )
		);
	};

	const remove = ( id ) =>
		del( `${ route }/${ id }` )
			.then( reload )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __( 'Deleted.', 'kalendora' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	return { items, notice, setNotice, reload, save, remove };
}
