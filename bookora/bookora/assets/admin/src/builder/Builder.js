/**
 * Drag & drop form builder.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Dashicon,
	Notice,
	SearchControl,
	Spinner,
	TabPanel,
} from '@wordpress/components';
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

import { errorMessage, get, put } from '../api';
import { FIELD_TYPES, GROUPS, INPUT_TYPES, createField } from './fieldTypes';
import FieldPreview from './FieldPreview';
import FormSettings from './FormSettings';
import Inspector from './Inspector';

const slug = ( text ) =>
	String( text || '' )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_|_$/g, '' )
		.slice( 0, 50 ) || 'field';

function uniqueKey( base, fields ) {
	const taken = new Set( fields.map( ( f ) => f.key ).filter( Boolean ) );
	let key = base;
	let i = 2;
	while ( taken.has( key ) ) {
		key = `${ base }_${ i }`;
		i += 1;
	}
	return key;
}

function Palette( { formType, fields, onAdd } ) {
	const [ search, setSearch ] = useState( '' );
	const used = new Set( fields.map( ( f ) => f.type ) );
	const term = search.trim().toLowerCase();

	return (
		<div className="bookora-palette">
			<SearchControl
				__nextHasNoMarginBottom
				value={ search }
				onChange={ setSearch }
				placeholder={ __( 'Search fields', 'bookora' ) }
			/>
			{ GROUPS.filter(
				( g ) => g.id !== 'booking' || formType === 'booking'
			).map( ( group ) => {
				const types = Object.entries( FIELD_TYPES ).filter(
					( [ , def ] ) =>
						def.group === group.id &&
						( ! term || def.label.toLowerCase().includes( term ) )
				);
				if ( ! types.length ) {
					return null;
				}
				return (
					<div className="bookora-palette__group" key={ group.id }>
						<h3>{ group.label }</h3>
						<div className="bookora-palette__items">
							{ types.map( ( [ type, def ] ) => {
								const disabled = def.single && used.has( type );
								return (
									<button
										type="button"
										key={ type }
										className="bookora-palette__item"
										disabled={ disabled }
										title={
											disabled
												? __(
														'This field can only be used once.',
														'bookora'
												  )
												: def.label
										}
										onClick={ () => onAdd( type ) }
									>
										<Dashicon icon={ def.icon } />
										<span>{ def.label }</span>
									</button>
								);
							} ) }
						</div>
					</div>
				);
			} ) }
			{ formType !== 'booking' && (
				<p className="bookora-palette__hint">
					{ __(
						'Want a calendar? Switch the form type to "Booking form" in the Form tab.',
						'bookora'
					) }
				</p>
			) }
		</div>
	);
}

function SortableField( { field, selected, onSelect, onDuplicate, onRemove } ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( {
		id: field.id,
	} );
	const def = FIELD_TYPES[ field.type ] || {};
	const name = sprintf(
		/* translators: 1: field type, 2: field label. */
		__( '%1$s: %2$s', 'bookora' ),
		def.label,
		field.label || ''
	);

	// A click anywhere on the field is a mouse shortcut; keyboard users use the "Edit" button in the toolbar.
	return (
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions
		<div
			ref={ setNodeRef }
			style={ {
				transform: CSS.Transform.toString( transform ),
				transition,
			} }
			className={ [
				'bookora-canvas__field',
				`bookora-canvas__field--${ field.width || 'full' }`,
				selected ? 'is-selected' : '',
				isDragging ? 'is-dragging' : '',
			].join( ' ' ) }
			onClick={ () => onSelect( field.id ) }
			role="group"
			aria-label={ name }
		>
			<div className="bookora-canvas__toolbar">
				<button
					type="button"
					className="bookora-canvas__handle"
					aria-label={ __( 'Drag to reorder', 'bookora' ) }
					{ ...attributes }
					{ ...listeners }
				>
					<Dashicon icon="move" />
				</button>
				<span className="bookora-canvas__type">{ def.label }</span>
				{ field.logic && (
					<span
						className="bookora-canvas__flag"
						title={ __( 'Conditional logic', 'bookora' ) }
					>
						<Dashicon icon="randomize" />
					</span>
				) }
				<span className="bookora-canvas__spacer" />
				<Button
					size="small"
					icon="edit"
					label={ sprintf(
						/* translators: %s: field name, e.g. "E-mail: Your e-mail". */
						__( 'Edit %s', 'bookora' ),
						name
					) }
					aria-pressed={ selected }
					onClick={ ( e ) => {
						e.stopPropagation();
						onSelect( field.id );
					} }
				/>
				{ ! def.single && (
					<Button
						size="small"
						icon="admin-page"
						label={ __( 'Duplicate', 'bookora' ) }
						onClick={ ( e ) => {
							e.stopPropagation();
							onDuplicate( field.id );
						} }
					/>
				) }
				<Button
					size="small"
					icon="trash"
					isDestructive
					label={ __( 'Delete', 'bookora' ) }
					onClick={ ( e ) => {
						e.stopPropagation();
						onRemove( field.id );
					} }
				/>
			</div>
			<div className="bookora-canvas__preview">
				<FieldPreview field={ field } />
				{ field.help && (
					<span className="bookora-pv__help">{ field.help }</span>
				) }
			</div>
		</div>
	);
}

export default function Builder( { formId, onBack } ) {
	const [ form, setForm ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ services, setServices ] = useState( [] );
	const [ tab, setTab ] = useState( { name: 'field', key: 0 } );

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	useEffect( () => {
		get( `/forms/${ formId }` )
			.then( ( data ) => {
				setForm( data );
				setSaved( JSON.stringify( data ) );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			);
		get( '/services' )
			.then( setServices )
			.catch( () => {} );
	}, [ formId ] );

	const dirty = form && saved !== JSON.stringify( form );

	useEffect( () => {
		const warn = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const save = useCallback( () => {
		if ( ! form ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		put( `/forms/${ form.id }`, form )
			.then( ( data ) => {
				setForm( data );
				setSaved( JSON.stringify( data ) );
				setNotice( {
					status: 'success',
					text: __( 'Form saved.', 'bookora' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorMessage( e ) } )
			)
			.finally( () => setBusy( false ) );
	}, [ form ] );

	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
				e.preventDefault();
				save();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ save ] );

	const fields = useMemo( () => form?.fields || [], [ form ] );
	const selected = fields.find( ( f ) => f.id === selectedId ) || null;

	if ( ! form ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const setFields = ( next ) => setForm( { ...form, fields: next } );

	const select = ( id ) => {
		setSelectedId( id );
		setTab( ( t ) =>
			t.name === 'field' ? t : { name: 'field', key: t.key + 1 }
		);
	};

	const addField = ( type ) => {
		const field = createField( type );
		if ( INPUT_TYPES.includes( type ) ) {
			field.key = uniqueKey(
				type === 'email' && ! fields.some( ( f ) => f.key === 'email' )
					? 'email'
					: slug( field.label ),
				fields
			);
		}
		const index = selected
			? fields.findIndex( ( f ) => f.id === selected.id ) + 1
			: fields.length;
		const next = [ ...fields ];
		next.splice( index, 0, field );
		setFields( next );
		select( field.id );
	};

	const updateField = ( field ) =>
		setFields( fields.map( ( f ) => ( f.id === field.id ? field : f ) ) );

	const duplicateField = ( id ) => {
		const index = fields.findIndex( ( f ) => f.id === id );
		const copy = {
			...createField( fields[ index ].type ),
			...fields[ index ],
			id: createField( fields[ index ].type ).id,
		};
		if ( copy.key ) {
			copy.key = uniqueKey( copy.key, fields );
		}
		const next = [ ...fields ];
		next.splice( index + 1, 0, copy );
		setFields( next );
		select( copy.id );
	};

	const removeField = ( id ) => {
		setFields( fields.filter( ( f ) => f.id !== id ) );
		if ( selectedId === id ) {
			setSelectedId( null );
		}
	};

	// Screen reader texts for keyboard reordering (dnd-kit only ships English).
	const fieldName = ( id ) =>
		fields.find( ( f ) => f.id === id )?.label || __( 'Field', 'bookora' );
	const position = ( id ) => fields.findIndex( ( f ) => f.id === id ) + 1;
	const dragA11y = {
		screenReaderInstructions: {
			draggable: __(
				'To move a field, press Space or Enter, use the arrow keys to choose the new position and press Space or Enter again. Escape cancels.',
				'bookora'
			),
		},
		announcements: {
			onDragStart: ( { active } ) =>
				sprintf(
					/* translators: 1: field label, 2: position. */
					__( 'Picked up %1$s at position %2$d.', 'bookora' ),
					fieldName( active.id ),
					position( active.id )
				),
			onDragOver: ( { active, over } ) =>
				over
					? sprintf(
							/* translators: 1: field label, 2: position. */
							__( '%1$s is now at position %2$d.', 'bookora' ),
							fieldName( active.id ),
							position( over.id )
					  )
					: '',
			onDragEnd: ( { active, over } ) =>
				over
					? sprintf(
							/* translators: 1: field label, 2: position. */
							__( '%1$s was moved to position %2$d.', 'bookora' ),
							fieldName( active.id ),
							position( over.id )
					  )
					: '',
			onDragCancel: ( { active } ) =>
				sprintf(
					/* translators: %s: field label. */
					__( 'Moving %s was cancelled.', 'bookora' ),
					fieldName( active.id )
				),
		},
	};

	const onDragEnd = ( { active, over } ) => {
		if ( over && active.id !== over.id ) {
			const from = fields.findIndex( ( f ) => f.id === active.id );
			const to = fields.findIndex( ( f ) => f.id === over.id );
			setFields( arrayMove( fields, from, to ) );
		}
	};

	const missingBooking =
		form.type === 'booking' &&
		! fields.some( ( f ) => f.type === 'appointment' );
	const missingEmail = ! fields.some( ( f ) => f.type === 'email' );
	const steps = fields.filter( ( f ) => f.type === 'step' ).length;

	return (
		<div className={ `bookora-builder bookora-builder--${ device }` }>
			<div className="bookora-builder__bar">
				<Button
					icon="arrow-left-alt2"
					onClick={ () => {
						if (
							! dirty ||
							// eslint-disable-next-line no-alert
							window.confirm(
								__( 'Discard unsaved changes?', 'bookora' )
							)
						) {
							onBack();
						}
					} }
				>
					{ __( 'All forms', 'bookora' ) }
				</Button>
				<strong className="bookora-builder__title">
					{ form.title }
				</strong>
				<span
					className={ `bookora-badge bookora-badge--${ form.status }` }
				>
					{ form.status === 'published'
						? __( 'Published', 'bookora' )
						: __( 'Draft', 'bookora' ) }
				</span>
				<span className="bookora-canvas__spacer" />
				<code
					className="bookora-builder__shortcode"
					title={ __(
						'Paste this shortcode into any page, or use the Bookora block.',
						'bookora'
					) }
				>
					{ form.shortcode }
				</code>
				<div
					className="bookora-builder__devices"
					role="group"
					aria-label={ __( 'Preview size', 'bookora' ) }
				>
					<Button
						icon="desktop"
						isPressed={ device === 'desktop' }
						label={ __( 'Desktop', 'bookora' ) }
						onClick={ () => setDevice( 'desktop' ) }
					/>
					<Button
						icon="smartphone"
						isPressed={ device === 'mobile' }
						label={ __( 'Mobile', 'bookora' ) }
						onClick={ () => setDevice( 'mobile' ) }
					/>
				</div>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy || ! dirty }
				>
					{ dirty
						? __( 'Save', 'bookora' )
						: __( 'Saved', 'bookora' ) }
				</Button>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<div className="bookora-builder__body">
				<aside
					className="bookora-builder__left"
					aria-label={ __( 'Add fields', 'bookora' ) }
				>
					<Palette
						formType={ form.type }
						fields={ fields }
						onAdd={ addField }
					/>
				</aside>

				<section
					className="bookora-builder__center"
					aria-label={ __( 'Form preview', 'bookora' ) }
				>
					{ ( missingBooking || missingEmail ) && (
						<div className="bookora-builder__hints">
							{ missingBooking && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'A booking form needs a "Date & time" field.',
										'bookora'
									) }{ ' ' }
									<Button
										variant="link"
										onClick={ () =>
											addField( 'appointment' )
										}
									>
										{ __( 'Add it', 'bookora' ) }
									</Button>
								</Notice>
							) }
							{ missingEmail && (
								<Notice status="info" isDismissible={ false }>
									{ __(
										'Add an email field so you can reply and send confirmations.',
										'bookora'
									) }{ ' ' }
									<Button
										variant="link"
										onClick={ () => addField( 'email' ) }
									>
										{ __( 'Add it', 'bookora' ) }
									</Button>
								</Notice>
							) }
						</div>
					) }

					<div
						className={ `bookora-canvas bookora-canvas--labels-${
							form.settings?.labelPlacement || 'top'
						}` }
					>
						{ steps > 0 && (
							<div className="bookora-canvas__steps">
								{ sprintf(
									/* translators: %d: number of steps. */
									__(
										'Multi-step form with %d steps',
										'bookora'
									),
									steps + 1
								) }
							</div>
						) }
						{ fields.length === 0 ? (
							<div className="bookora-canvas__empty">
								<Dashicon icon="welcome-add-page" />
								<p>
									{ __(
										'Click a field on the left to add it to your form.',
										'bookora'
									) }
								</p>
							</div>
						) : (
							<DndContext
								sensors={ sensors }
								collisionDetection={ closestCenter }
								onDragEnd={ onDragEnd }
								accessibility={ dragA11y }
							>
								<SortableContext
									items={ fields.map( ( f ) => f.id ) }
									strategy={ verticalListSortingStrategy }
								>
									<div className="bookora-canvas__fields">
										{ fields.map( ( field ) => (
											<SortableField
												key={ field.id }
												field={ field }
												selected={
													field.id === selectedId
												}
												onSelect={ select }
												onDuplicate={ duplicateField }
												onRemove={ removeField }
											/>
										) ) }
									</div>
								</SortableContext>
							</DndContext>
						) }
						<div className="bookora-canvas__submit">
							<span className="bookora-pv__button">
								{ form.settings?.submitLabel ||
									( form.type === 'booking'
										? __( 'Book now', 'bookora' )
										: __( 'Send', 'bookora' ) ) }
							</span>
						</div>
					</div>
				</section>

				<aside
					className="bookora-builder__right"
					aria-label={ __( 'Settings', 'bookora' ) }
				>
					<TabPanel
						key={ tab.key }
						className="bookora-inspector"
						initialTabName={ tab.name }
						onSelect={ ( name ) => {
							if ( name !== tab.name ) {
								setTab( { name, key: tab.key } );
							}
						} }
						tabs={ [
							{ name: 'field', title: __( 'Field', 'bookora' ) },
							{ name: 'form', title: __( 'Form', 'bookora' ) },
						] }
					>
						{ ( current ) => {
							if ( current.name === 'form' ) {
								return (
									<FormSettings
										form={ form }
										onChange={ setForm }
									/>
								);
							}
							return selected ? (
								<Inspector
									key={ selected.id }
									field={ selected }
									fields={ fields }
									services={ services }
									formType={ form.type }
									onChange={ updateField }
								/>
							) : (
								<p className="bookora-inspector__empty">
									{ __(
										'Select a field in the form to edit its settings.',
										'bookora'
									) }
								</p>
							);
						} }
					</TabPanel>
				</aside>
			</div>
		</div>
	);
}
