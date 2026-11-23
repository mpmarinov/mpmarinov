/**
 * Settings panel for the selected field.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { FIELD_TYPES, INPUT_TYPES } from './fieldTypes';

const OPERATORS = [
	{ value: 'is', label: __( 'is', 'bookora' ) },
	{ value: 'is_not', label: __( 'is not', 'bookora' ) },
	{ value: 'contains', label: __( 'contains', 'bookora' ) },
	{ value: 'not_contains', label: __( 'does not contain', 'bookora' ) },
	{ value: 'empty', label: __( 'is empty', 'bookora' ) },
	{ value: 'not_empty', label: __( 'is not empty', 'bookora' ) },
	{ value: 'greater', label: __( 'is greater than', 'bookora' ) },
	{ value: 'less', label: __( 'is less than', 'bookora' ) },
];

const slug = ( text ) =>
	String( text )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_|_$/g, '' )
		.slice( 0, 50 );

function OptionsEditor( { options = [], onChange } ) {
	const update = ( index, patch ) =>
		onChange(
			options.map( ( o, i ) => ( i === index ? { ...o, ...patch } : o ) )
		);

	const move = ( index, dir ) => {
		const next = [ ...options ];
		const target = index + dir;
		if ( target < 0 || target >= next.length ) {
			return;
		}
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		onChange( next );
	};

	return (
		<div className="bookora-options">
			{ options.map( ( option, index ) => (
				<div className="bookora-options__row" key={ index }>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Option label', 'bookora' ) }
						value={ option.label }
						onChange={ ( label ) =>
							update( index, {
								label,
								value:
									! option.value ||
									option.value === slug( option.label )
										? slug( label )
										: option.value,
							} )
						}
					/>
					<Button
						icon="arrow-up-alt2"
						size="small"
						label={ __( 'Move up', 'bookora' ) }
						disabled={ index === 0 }
						onClick={ () => move( index, -1 ) }
					/>
					<Button
						icon="arrow-down-alt2"
						size="small"
						label={ __( 'Move down', 'bookora' ) }
						disabled={ index === options.length - 1 }
						onClick={ () => move( index, 1 ) }
					/>
					<Button
						icon="no-alt"
						size="small"
						isDestructive
						label={ __( 'Remove option', 'bookora' ) }
						onClick={ () =>
							onChange(
								options.filter( ( _, i ) => i !== index )
							)
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="small"
				onClick={ () => {
					const n = options.length + 1;
					onChange( [
						...options,
						{
							/* translators: %d: option number. */
							label: sprintf( __( 'Option %d', 'bookora' ), n ),
							value: `option_${ n }`,
						},
					] );
				} }
			>
				{ __( 'Add option', 'bookora' ) }
			</Button>
		</div>
	);
}

function LogicEditor( { field, fields, onChange } ) {
	const logic = field.logic || null;
	const candidates = fields.filter(
		( f ) => f.id !== field.id && f.key && INPUT_TYPES.includes( f.type )
	);

	if ( ! logic ) {
		return (
			<>
				<p className="bookora-muted">
					{ __(
						'Show or hide this field depending on other answers.',
						'bookora'
					) }
				</p>
				<Button
					variant="secondary"
					size="small"
					disabled={ candidates.length === 0 }
					onClick={ () =>
						onChange( {
							action: 'show',
							match: 'all',
							rules: [
								{
									field: candidates[ 0 ].key,
									operator: 'is',
									value: '',
								},
							],
						} )
					}
				>
					{ __( 'Add condition', 'bookora' ) }
				</Button>
				{ candidates.length === 0 && (
					<p className="bookora-muted">
						{ __( 'Add other fields first.', 'bookora' ) }
					</p>
				) }
			</>
		);
	}

	const setRule = ( index, patch ) =>
		onChange( {
			...logic,
			rules: logic.rules.map( ( r, i ) =>
				i === index ? { ...r, ...patch } : r
			),
		} );

	return (
		<div className="bookora-logic">
			<div className="bookora-logic__head">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Action', 'bookora' ) }
					value={ logic.action }
					options={ [
						{
							value: 'show',
							label: __( 'Show this field', 'bookora' ),
						},
						{
							value: 'hide',
							label: __( 'Hide this field', 'bookora' ),
						},
					] }
					onChange={ ( action ) => onChange( { ...logic, action } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Match', 'bookora' ) }
					value={ logic.match }
					options={ [
						{
							value: 'all',
							label: __( 'if all conditions match', 'bookora' ),
						},
						{
							value: 'any',
							label: __( 'if any condition matches', 'bookora' ),
						},
					] }
					onChange={ ( match ) => onChange( { ...logic, match } ) }
				/>
			</div>
			{ logic.rules.map( ( rule, index ) => {
				const source = candidates.find( ( f ) => f.key === rule.field );
				const needsValue = ! [ 'empty', 'not_empty' ].includes(
					rule.operator
				);
				return (
					<div className="bookora-logic__rule" key={ index }>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Field', 'bookora' ) }
							value={ rule.field }
							options={ candidates.map( ( f ) => ( {
								value: f.key,
								label: f.label || f.key,
							} ) ) }
							onChange={ ( value ) =>
								setRule( index, { field: value, value: '' } )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Condition', 'bookora' ) }
							value={ rule.operator }
							options={ OPERATORS }
							onChange={ ( operator ) =>
								setRule( index, { operator } )
							}
						/>
						{ needsValue &&
							( source?.options?.length ? (
								<SelectControl
									__nextHasNoMarginBottom
									label={ __( 'Value', 'bookora' ) }
									value={ rule.value }
									options={ [
										{ value: '', label: '—' },
										...source.options.map( ( o ) => ( {
											value: o.value,
											label: o.label,
										} ) ),
									] }
									onChange={ ( value ) =>
										setRule( index, { value } )
									}
								/>
							) : (
								<TextControl
									__nextHasNoMarginBottom
									label={ __( 'Value', 'bookora' ) }
									value={ rule.value }
									onChange={ ( value ) =>
										setRule( index, { value } )
									}
								/>
							) ) }
						<Button
							isDestructive
							variant="link"
							onClick={ () => {
								const rules = logic.rules.filter(
									( _, i ) => i !== index
								);
								onChange(
									rules.length ? { ...logic, rules } : null
								);
							} }
						>
							{ __( 'Remove condition', 'bookora' ) }
						</Button>
					</div>
				);
			} ) }
			<Button
				variant="secondary"
				size="small"
				onClick={ () =>
					onChange( {
						...logic,
						rules: [
							...logic.rules,
							{
								field: candidates[ 0 ]?.key || '',
								operator: 'is',
								value: '',
							},
						],
					} )
				}
			>
				{ __( 'Add condition', 'bookora' ) }
			</Button>
		</div>
	);
}

export default function Inspector( { field, fields, services, onChange } ) {
	const type = FIELD_TYPES[ field.type ];
	const isInput = INPUT_TYPES.includes( field.type );
	const set = ( key ) => ( value ) =>
		onChange( { ...field, [ key ]: value } );
	const num = ( key ) => ( value ) =>
		set( key )( value === '' ? '' : Number( value ) );

	return (
		<div className="bookora-inspector__field">
			<div className="bookora-inspector__type">
				<span className={ `dashicons dashicons-${ type.icon }` } />
				{ type.label }
			</div>

			<PanelBody title={ __( 'General', 'bookora' ) } initialOpen>
				{ ( isInput || field.type === 'heading' ) && (
					<TextControl
						__nextHasNoMarginBottom
						label={
							field.type === 'heading'
								? __( 'Text', 'bookora' )
								: __( 'Label', 'bookora' )
						}
						value={ field.label || '' }
						onChange={ set( 'label' ) }
					/>
				) }

				{ field.type === 'heading' && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Size', 'bookora' ) }
						value={ String( field.level || 3 ) }
						options={ [
							{ value: '2', label: __( 'Large', 'bookora' ) },
							{ value: '3', label: __( 'Medium', 'bookora' ) },
							{ value: '4', label: __( 'Small', 'bookora' ) },
						] }
						onChange={ ( v ) => set( 'level' )( Number( v ) ) }
					/>
				) }

				{ field.type === 'paragraph' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text', 'bookora' ) }
						help={ __(
							'Allowed: <strong>, <em>, <a>, <br>.',
							'bookora'
						) }
						value={ field.content || '' }
						onChange={ set( 'content' ) }
						rows={ 5 }
					/>
				) }

				{ field.type === 'step' && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title of the next step', 'bookora' ) }
						value={ field.title || '' }
						onChange={ set( 'title' ) }
					/>
				) }

				{ field.type === 'consent' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Consent text', 'bookora' ) }
						help={ __(
							'You can link your privacy policy with <a href="…">…</a>.',
							'bookora'
						) }
						value={ field.text || '' }
						onChange={ set( 'text' ) }
						rows={ 3 }
					/>
				) }

				{ isInput &&
					! [
						'consent',
						'hidden',
						'appointment',
						'rating',
						'range',
						'checkboxes',
						'radio',
						'file',
						'signature',
					].includes( field.type ) && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Placeholder', 'bookora' ) }
							value={ field.placeholder || '' }
							onChange={ set( 'placeholder' ) }
						/>
					) }

				{ isInput && field.type !== 'hidden' && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Help text', 'bookora' ) }
						value={ field.help || '' }
						onChange={ set( 'help' ) }
					/>
				) }

				{ isInput && field.type !== 'hidden' && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Required', 'bookora' ) }
						checked={ !! field.required }
						onChange={ set( 'required' ) }
					/>
				) }

				{ [ 'select', 'radio', 'checkboxes' ].includes(
					field.type
				) && (
					<fieldset className="bookora-fieldset">
						<legend>{ __( 'Options', 'bookora' ) }</legend>
						<OptionsEditor
							options={ field.options }
							onChange={ set( 'options' ) }
						/>
					</fieldset>
				) }

				{ field.type === 'name' && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Format', 'bookora' ) }
						value={ field.format || 'first_last' }
						options={ [
							{
								value: 'first_last',
								label: __( 'First and last name', 'bookora' ),
							},
							{
								value: 'full',
								label: __( 'One field', 'bookora' ),
							},
						] }
						onChange={ set( 'format' ) }
					/>
				) }

				{ field.type === 'service' && services.length > 0 && (
					<fieldset className="bookora-fieldset">
						<legend>
							{ __( 'Offer these services', 'bookora' ) }
						</legend>
						<p className="bookora-muted">
							{ __(
								'None selected = all active services.',
								'bookora'
							) }
						</p>
						{ services.map( ( s ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ s.id }
								label={ s.name }
								checked={ ( field.serviceIds || [] ).includes(
									s.id
								) }
								onChange={ ( checked ) =>
									set( 'serviceIds' )(
										checked
											? [
													...( field.serviceIds ||
														[] ),
													s.id,
											  ]
											: ( field.serviceIds || [] ).filter(
													( id ) => id !== s.id
											  )
									)
								}
							/>
						) ) }
					</fieldset>
				) }

				{ field.type === 'resource' && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Offer "any available staff member"',
							'bookora'
						) }
						checked={ field.allowAny !== false }
						onChange={ set( 'allowAny' ) }
					/>
				) }
			</PanelBody>

			{ isInput && field.type !== 'appointment' && (
				<PanelBody
					title={ __( 'Advanced', 'bookora' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Field key', 'bookora' ) }
						help={ __(
							'Used in email placeholders, e.g. {key}. Leave empty to generate it from the label.',
							'bookora'
						) }
						value={ field.key || '' }
						onChange={ ( v ) => set( 'key' )( slug( v ) ) }
					/>
					{ ! [
						'file',
						'signature',
						'consent',
						'checkboxes',
					].includes( field.type ) && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Default value', 'bookora' ) }
							help={
								field.type === 'hidden'
									? __(
											'Tip: use {url:param} or {user:email} for dynamic values.',
											'bookora'
									  )
									: undefined
							}
							value={ field.defaultValue || '' }
							onChange={ set( 'defaultValue' ) }
						/>
					) }
					{ field.type === 'text' && (
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							label={ __(
								'Maximum characters (0 = unlimited)',
								'bookora'
							) }
							value={ field.maxLength || 0 }
							onChange={ num( 'maxLength' ) }
						/>
					) }
					{ field.type === 'textarea' && (
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 2 }
							max={ 20 }
							label={ __( 'Rows', 'bookora' ) }
							value={ field.rows || 4 }
							onChange={ num( 'rows' ) }
						/>
					) }
					{ [ 'number', 'range' ].includes( field.type ) && (
						<div className="bookora-grid bookora-grid--3">
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Min', 'bookora' ) }
								value={ field.min ?? '' }
								onChange={ num( 'min' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Max', 'bookora' ) }
								value={ field.max ?? '' }
								onChange={ num( 'max' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Step', 'bookora' ) }
								value={ field.step ?? 1 }
								onChange={ num( 'step' ) }
							/>
						</div>
					) }
					{ field.type === 'rating' && (
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 3 }
							max={ 10 }
							label={ __( 'Number of stars', 'bookora' ) }
							value={ field.max || 5 }
							onChange={ num( 'max' ) }
						/>
					) }
					{ [ 'radio', 'checkboxes' ].includes( field.type ) && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Layout', 'bookora' ) }
							value={ field.layout || 'vertical' }
							options={ [
								{
									value: 'vertical',
									label: __( 'Below each other', 'bookora' ),
								},
								{
									value: 'inline',
									label: __( 'Side by side', 'bookora' ),
								},
							] }
							onChange={ set( 'layout' ) }
						/>
					) }
					{ field.type === 'address' && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Ask for country', 'bookora' ) }
							checked={ !! field.country }
							onChange={ set( 'country' ) }
						/>
					) }
					{ field.type === 'date' && (
						<>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Only working days can be selected',
									'bookora'
								) }
								help={ __(
									'Uses the general opening hours, holidays and vacation.',
									'bookora'
								) }
								checked={ !! field.workingDaysOnly }
								onChange={ set( 'workingDaysOnly' ) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Also choose a time (within opening hours)',
									'bookora'
								) }
								help={ __(
									'For requests without fixed booking. For real bookings with blocked times, use a booking form.',
									'bookora'
								) }
								checked={ !! field.withTime }
								disabled={ ! field.workingDaysOnly }
								onChange={ set( 'withTime' ) }
							/>
						</>
					) }
					{ field.type === 'date' && (
						<div className="bookora-grid">
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Earliest date', 'bookora' ) }
								help={ __(
									'e.g. today, +1 day, 2026–12–01',
									'bookora'
								) }
								value={ field.minDate || '' }
								onChange={ set( 'minDate' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Latest date', 'bookora' ) }
								help={ __( 'e.g. +3 months', 'bookora' ) }
								value={ field.maxDate || '' }
								onChange={ set( 'maxDate' ) }
							/>
						</div>
					) }
					{ field.type === 'file' && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Allowed file types', 'bookora' ) }
								help={ __(
									'Comma separated, e.g. jpg, png, pdf',
									'bookora'
								) }
								value={ ( field.extensions || [] ).join(
									', '
								) }
								onChange={ ( v ) =>
									set( 'extensions' )(
										v
											.split( ',' )
											.map( ( e ) =>
												e.trim().toLowerCase()
											)
											.filter( Boolean )
									)
								}
							/>
							<div className="bookora-grid">
								<TextControl
									__nextHasNoMarginBottom
									type="number"
									min={ 1 }
									max={ 50 }
									label={ __( 'Max. size (MB)', 'bookora' ) }
									value={ field.maxSize || 5 }
									onChange={ num( 'maxSize' ) }
								/>
								<TextControl
									__nextHasNoMarginBottom
									type="number"
									min={ 1 }
									max={ 10 }
									label={ __( 'Max. files', 'bookora' ) }
									value={ field.maxFiles || 1 }
									onChange={ num( 'maxFiles' ) }
								/>
							</div>
						</>
					) }
				</PanelBody>
			) }

			{ field.type !== 'step' && (
				<PanelBody
					title={ __( 'Layout', 'bookora' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Width', 'bookora' ) }
						value={ field.width || 'full' }
						options={ [
							{
								value: 'full',
								label: __( 'Full width', 'bookora' ),
							},
							{
								value: 'half',
								label: __( 'Half width', 'bookora' ),
							},
							{
								value: 'third',
								label: __( 'One third', 'bookora' ),
							},
						] }
						onChange={ set( 'width' ) }
					/>
				</PanelBody>
			) }

			{ field.type !== 'step' && (
				<PanelBody
					title={
						field.logic
							? __( 'Conditional logic (active)', 'bookora' )
							: __( 'Conditional logic', 'bookora' )
					}
					initialOpen={ !! field.logic }
				>
					<LogicEditor
						field={ field }
						fields={ fields }
						onChange={ set( 'logic' ) }
					/>
				</PanelBody>
			) }
		</div>
	);
}
