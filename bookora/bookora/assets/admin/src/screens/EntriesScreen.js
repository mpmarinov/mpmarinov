import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Dashicon,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import { config, errorMessage, get, post } from '../api';
import { formatDateTime, refreshBadges } from '../components/entryUi';

const TABS = [
	{ id: '', label: __( 'All', 'bookora' ) },
	{ id: 'new', label: __( 'Unread', 'bookora' ) },
	{ id: 'starred', label: __( 'Starred', 'bookora' ) },
	{ id: 'spam', label: __( 'Spam', 'bookora' ) },
	{ id: 'trash', label: __( 'Trash', 'bookora' ) },
];

const canManage = !! config.caps?.manageBookings;

function EntryDetail( { id, onClose, onAction } ) {
	const [ entry, setEntry ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		get( `/entries/${ id }` )
			.then( ( e ) => {
				setEntry( e );
				refreshBadges();
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ id ] );

	return (
		<Modal
			title={
				entry
					? entry.name || entry.email || entry.form_title
					: __( 'Entry', 'bookora' )
			}
			onRequestClose={ onClose }
			className="bookora-modal"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! entry && ! error && <Spinner /> }
			{ entry && (
				<div className="bookora-detail">
					<p className="bookora-muted">
						{ entry.form_title } ·{ ' ' }
						{ formatDateTime( entry.created_at ) }
					</p>
					<dl className="bookora-detail__values">
						{ entry.values.map( ( v ) => (
							<div key={ v.key }>
								<dt>{ v.label }</dt>
								<dd>
									{ v.type === 'email' ? (
										<a href={ `mailto:${ v.value }` }>
											{ v.value }
										</a>
									) : (
										v.value
									) }
								</dd>
							</div>
						) ) }
					</dl>
					{ entry.source_url && (
						<p className="bookora-muted">
							{ __( 'Sent from:', 'bookora' ) }{ ' ' }
							<a
								href={ entry.source_url }
								target="_blank"
								rel="noreferrer noopener"
							>
								{ entry.source_url }
							</a>
						</p>
					) }
					<div className="bookora-modal-actions">
						{ entry.email && (
							<Button
								variant="primary"
								href={ `mailto:${ entry.email }` }
								icon="email"
							>
								{ __( 'Reply', 'bookora' ) }
							</Button>
						) }
						{ canManage && (
							<>
								<Button
									variant="secondary"
									icon={
										entry.status === 'starred'
											? 'star-filled'
											: 'star-empty'
									}
									onClick={ () =>
										onAction(
											[ id ],
											entry.status === 'starred'
												? 'read'
												: 'starred'
										)
									}
								>
									{ entry.status === 'starred'
										? __( 'Unstar', 'bookora' )
										: __( 'Star', 'bookora' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => onAction( [ id ], 'spam' ) }
								>
									{ __( 'Spam', 'bookora' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										onAction( [ id ], 'trash' )
									}
								>
									{ __( 'Move to trash', 'bookora' ) }
								</Button>
							</>
						) }
					</div>
				</div>
			) }
		</Modal>
	);
}

export default function EntriesScreen() {
	const [ forms, setForms ] = useState( [] );
	const [ formId, setFormId ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const [ open, setOpen ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		get( '/forms' )
			.then( ( list ) =>
				setForms( list.filter( ( f ) => f.type === 'standard' ) )
			)
			.catch( () => {} );
	}, [] );

	const load = useCallback(
		( p = 1 ) => {
			get( '/entries', {
				form_id: formId,
				status,
				search,
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
		[ formId, status, search ]
	);

	useEffect( () => {
		setSelected( [] );
		const timer = setTimeout( () => load( 1 ), search ? 300 : 0 );
		return () => clearTimeout( timer );
	}, [ load, search ] );

	const action = ( ids, act ) =>
		post( '/entries/bulk', { ids, action: act } )
			.then( ( res ) => {
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %d: number of entries. */
						_n(
							'%d entry updated.',
							'%d entries updated.',
							res.affected,
							'bookora'
						),
						res.affected
					),
				} );
				setSelected( [] );
				if ( [ 'trash', 'spam', 'delete' ].includes( act ) ) {
					setOpen( null );
				}
				load( 1 );
				refreshBadges();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);

	const items = data?.items || [];
	const allSelected = items.length > 0 && selected.length === items.length;
	const toggle = ( id ) =>
		setSelected(
			selected.includes( id )
				? selected.filter( ( x ) => x !== id )
				: [ ...selected, id ]
		);

	const BULK = {
		trash: [
			[ 'restore', __( 'Restore', 'bookora' ) ],
			[ 'delete', __( 'Delete permanently', 'bookora' ) ],
		],
		spam: [
			[ 'restore', __( 'Not spam', 'bookora' ) ],
			[ 'trash', __( 'Move to trash', 'bookora' ) ],
		],
		default: [
			[ 'read', __( 'Mark as read', 'bookora' ) ],
			[ 'new', __( 'Mark as unread', 'bookora' ) ],
			[ 'starred', __( 'Star', 'bookora' ) ],
			[ 'spam', __( 'Spam', 'bookora' ) ],
			[ 'trash', __( 'Move to trash', 'bookora' ) ],
		],
	};
	const bulkOptions = BULK[ status ] || BULK.default;

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

			<p className="description">
				{ __(
					'Messages from your contact and request forms. Bookings with an appointment are under "Bookings".',
					'bookora'
				) }
			</p>

			<div className="bookora-tabs" role="tablist">
				{ TABS.map( ( t ) => (
					<button
						type="button"
						role="tab"
						key={ t.id || 'all' }
						aria-selected={ status === t.id }
						className={ `bookora-tabs__tab${
							status === t.id ? ' is-active' : ''
						}` }
						onClick={ () => {
							setData( null );
							setStatus( t.id );
						} }
					>
						{ t.label }
						{ t.id && data?.counts?.[ t.id ] > 0 && (
							<span
								className={ `bookora-tabs__count${
									t.id === 'new' ? '' : ' is-muted'
								}` }
							>
								{ data.counts[ t.id ] }
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
					placeholder={ __( 'Search entries', 'bookora' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Form', 'bookora' ) }
					value={ formId }
					options={ [
						{ label: __( 'All forms', 'bookora' ), value: '' },
						...forms.map( ( f ) => ( {
							label: f.title,
							value: String( f.id ),
						} ) ),
					] }
					onChange={ setFormId }
				/>
				{ formId && (
					<Button
						variant="secondary"
						icon="download"
						href={ `${ config.exportUrl }&form_id=${ formId }` }
					>
						{ __( 'Export CSV', 'bookora' ) }
					</Button>
				) }
			</div>

			{ canManage && selected.length > 0 && (
				<div className="bookora-bulkbar">
					<span>
						{ sprintf(
							/* translators: %d: number of selected entries. */
							_n(
								'%d selected',
								'%d selected',
								selected.length,
								'bookora'
							),
							selected.length
						) }
					</span>
					{ bulkOptions.map( ( [ act, label ] ) => (
						<Button
							key={ act }
							size="compact"
							variant="secondary"
							isDestructive={
								act === 'delete' || act === 'trash'
							}
							onClick={ () => action( selected, act ) }
						>
							{ label }
						</Button>
					) ) }
				</div>
			) }

			{ ! data && <Spinner /> }

			{ data && items.length === 0 && (
				<Card>
					<CardBody className="bookora-empty">
						<p>{ __( 'No entries found.', 'bookora' ) }</p>
					</CardBody>
				</Card>
			) }

			{ items.length > 0 && (
				<div className="bookora-entries">
					{ canManage && (
						<div className="bookora-entries__head">
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Select all', 'bookora' ) }
								checked={ allSelected }
								onChange={ () =>
									setSelected(
										allSelected
											? []
											: items.map( ( i ) => i.id )
									)
								}
							/>
						</div>
					) }
					{ items.map( ( e ) => (
						<div
							key={ e.id }
							className={ `bookora-entry${
								e.status === 'new' ? ' is-new' : ''
							}` }
						>
							{ canManage && (
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %s: sender name. */
										__( 'Select %s', 'bookora' ),
										e.name || e.email || e.id
									) }
									className="bookora-entry__check"
									checked={ selected.includes( e.id ) }
									onChange={ () => toggle( e.id ) }
								/>
							) }
							<button
								type="button"
								className="bookora-entry__star"
								aria-label={
									e.status === 'starred'
										? __( 'Unstar', 'bookora' )
										: __( 'Star', 'bookora' )
								}
								disabled={ ! canManage }
								onClick={ () =>
									action(
										[ e.id ],
										e.status === 'starred'
											? 'read'
											: 'starred'
									)
								}
							>
								<Dashicon
									icon={
										e.status === 'starred'
											? 'star-filled'
											: 'star-empty'
									}
								/>
							</button>
							<button
								type="button"
								className="bookora-entry__main"
								onClick={ () => setOpen( e.id ) }
							>
								<span className="bookora-entry__who">
									{ e.name ||
										e.email ||
										__( '(no name)', 'bookora' ) }
								</span>
								<span className="bookora-entry__preview">
									{ e.preview }
								</span>
								<span className="bookora-entry__meta">
									{ e.form_title } ·{ ' ' }
									{ formatDateTime( e.created_at ) }
								</span>
							</button>
						</div>
					) ) }
				</div>
			) }

			{ data && items.length < data.total && (
				<Button variant="secondary" onClick={ () => load( page + 1 ) }>
					{ __( 'Load more', 'bookora' ) }
				</Button>
			) }

			{ open && (
				<EntryDetail
					id={ open }
					onClose={ () => {
						setOpen( null );
						load( 1 );
					} }
					onAction={ action }
				/>
			) }
		</div>
	);
}
