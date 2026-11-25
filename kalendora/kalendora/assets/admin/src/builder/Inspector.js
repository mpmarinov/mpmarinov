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
import { config } from '../api';
import { isValid } from '../../../frontend/src/formula';

// Field types that give a number to formulas.
const NUMERIC = [
	'number',
	'range',
	'rating',
	'select',
	'radio',
	'checkboxes',
	'consent',
	'calculation',
];

function FormulaEditor( { field, fields, formType, onChange } ) {
	const own = fields.indexOf( field );
	const vars = fields
		.filter(
			( f, i ) =>
				f.key &&
				NUMERIC.includes( f.type ) &&
				( f.type !== 'calculation' || i < own )
		)
		.map( ( f ) => ( { key: f.key, label: f.label || f.key } ) );
	if ( formType === 'booking' ) {
		vars.unshift(
			{ key: 'service_price', label: __( 'Service price', 'kalendora' ) },
			{
				key: 'service_duration',
				label: __( 'Service duration (min)', 'kalendora' ),
			}
		);
	}
	const formula = field.formula || '';
	const unknown = ( formula.match( /\{([a-z0-9_]+)\}/g ) || [] )
		.map( ( v ) => v.slice( 1, -1 ) )
		.filter( ( k ) => ! vars.some( ( v ) => v.key === k ) );
	const valid = formula.trim() === '' || isValid( formula );
	const insert = ( key ) =>
		onChange( { ...field, formula: `${ formula }{${ key }}` } );

	return (
		<>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Formula', 'kalendora' ) }
				help={ __(
					'Use + - * / ( ), numbers and the fields below, e.g. {persons} * {service_price}. Functions: round(x, 2), min(a, b), max(a, b), ceil(x), floor(x).',
					'kalendora'
				) }
				value={ formula }
				onChange={ ( v ) => onChange( { ...field, formula: v } ) }
				rows={ 3 }
			/>
			{ unknown.length > 0 && (
				<p className="kalendora-inspector__warning" role="alert">
					{ sprintf(
						/* translators: %s: list of names. */
						__( 'Unknown field: %s', 'kalendora' ),
						unknown.join( ', ' )
					) }
				</p>
			) }
			{ ! valid && (
				<p className="kalendora-inspector__warning" role="alert">
					{ __(
						'The formula cannot be calculated. Check brackets and operators.',
						'kalendora'
					) }
				</p>
			) }
			<p className="kalendora-muted">
				{ __( 'Insert a value:', 'kalendora' ) }
			</p>
			<div className="kalendora-formula__vars">
				{ vars.map( ( v ) => (
					<Button
						key={ v.key }
						variant="secondary"
						size="small"
						onClick={ () => insert( v.key ) }
					>
						{ v.label }
					</Button>
				) ) }
			</div>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Decimal places', 'kalendora' ) }
				value={ String( field.decimals ?? 2 ) }
				options={ [ 0, 1, 2, 3, 4 ].map( ( n ) => ( {
					value: String( n ),
					label: String( n ),
				} ) ) }
				onChange={ ( v ) =>
					onChange( { ...field, decimals: Number( v ) } )
				}
			/>
			<div className="kalendora-inspector__row">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Before the number', 'kalendora' ) }
					value={ field.prefix || '' }
					onChange={ ( v ) => onChange( { ...field, prefix: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'After the number', 'kalendora' ) }
					value={ field.suffix || '' }
					onChange={ ( v ) => onChange( { ...field, suffix: v } ) }
				/>
			</div>
		</>
	);
}

const OPERATORS = [
	{ value: 'is', label: __( 'is', 'kalendora' ) },
	{ value: 'is_not', label: __( 'is not', 'kalendora' ) },
	{ value: 'contains', label: __( 'contains', 'kalendora' ) },
	{ value: 'not_contains', label: __( 'does not contain', 'kalendora' ) },
	{ value: 'empty', label: __( 'is empty', 'kalendora' ) },
	{ value: 'not_empty', label: __( 'is not empty', 'kalendora' ) },
	{ value: 'greater', label: __( 'is greater than', 'kalendora' ) },
	{ value: 'less', label: __( 'is less than', 'kalendora' ) },
];

const slug = ( text ) =>
	String( text )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_|_$/g, '' )
		.slice( 0, 50 );

function OptionsEditor( { options = [], onChange, showNumbers = false } ) {
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
		<div className="kalendora-options">
			{ options.map( ( option, index ) => (
				<div className="kalendora-options__row" key={ index }>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Option label', 'kalendora' ) }
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
					{ showNumbers && (
						<TextControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							className="kalendora-options__number"
							label={ __(
								'Value for calculations',
								'kalendora'
							) }
							placeholder="0"
							inputMode="decimal"
							value={ option.price ?? '' }
							onChange={ ( price ) => update( index, { price } ) }
						/>
					) }
					<Button
						icon="arrow-up-alt2"
						size="small"
						label={ __( 'Move up', 'kalendora' ) }
						disabled={ index === 0 }
						onClick={ () => move( index, -1 ) }
					/>
					<Button
						icon="arrow-down-alt2"
						size="small"
						label={ __( 'Move down', 'kalendora' ) }
						disabled={ index === options.length - 1 }
						onClick={ () => move( index, 1 ) }
					/>
					<Button
						icon="no-alt"
						size="small"
						isDestructive
						label={ __( 'Remove option', 'kalendora' ) }
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
							label: sprintf( __( 'Option %d', 'kalendora' ), n ),
							value: `option_${ n }`,
						},
					] );
				} }
			>
				{ __( 'Add option', 'kalendora' ) }
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
				<p className="kalendora-muted">
					{ __(
						'Show or hide this field depending on other answers.',
						'kalendora'
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
					{ __( 'Add condition', 'kalendora' ) }
				</Button>
				{ candidates.length === 0 && (
					<p className="kalendora-muted">
						{ __( 'Add other fields first.', 'kalendora' ) }
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
		<div className="kalendora-logic">
			<div className="kalendora-logic__head">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Action', 'kalendora' ) }
					value={ logic.action }
					options={ [
						{
							value: 'show',
							label: __( 'Show this field', 'kalendora' ),
						},
						{
							value: 'hide',
							label: __( 'Hide this field', 'kalendora' ),
						},
					] }
					onChange={ ( action ) => onChange( { ...logic, action } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Match', 'kalendora' ) }
					value={ logic.match }
					options={ [
						{
							value: 'all',
							label: __( 'if all conditions match', 'kalendora' ),
						},
						{
							value: 'any',
							label: __(
								'if any condition matches',
								'kalendora'
							),
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
					<div className="kalendora-logic__rule" key={ index }>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Field', 'kalendora' ) }
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
							label={ __( 'Condition', 'kalendora' ) }
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
									label={ __( 'Value', 'kalendora' ) }
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
									label={ __( 'Value', 'kalendora' ) }
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
							{ __( 'Remove condition', 'kalendora' ) }
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
				{ __( 'Add condition', 'kalendora' ) }
			</Button>
		</div>
	);
}

export default function Inspector( {
	field,
	fields,
	services,
	formType,
	onChange,
} ) {
	const type = FIELD_TYPES[ field.type ];
	const isInput = INPUT_TYPES.includes( field.type );
	const set = ( key ) => ( value ) =>
		onChange( { ...field, [ key ]: value } );
	const num = ( key ) => ( value ) =>
		set( key )( value === '' ? '' : Number( value ) );

	return (
		<div className="kalendora-inspector__field">
			<div className="kalendora-inspector__type">
				<span className={ `dashicons dashicons-${ type.icon }` } />
				{ type.label }
			</div>

			<PanelBody title={ __( 'General', 'kalendora' ) } initialOpen>
				{ ( isInput || field.type === 'heading' ) && (
					<TextControl
						__nextHasNoMarginBottom
						label={
							field.type === 'heading'
								? __( 'Text', 'kalendora' )
								: __( 'Label', 'kalendora' )
						}
						value={ field.label || '' }
						onChange={ set( 'label' ) }
					/>
				) }

				{ field.type === 'heading' && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Size', 'kalendora' ) }
						value={ String( field.level || 3 ) }
						options={ [
							{ value: '2', label: __( 'Large', 'kalendora' ) },
							{ value: '3', label: __( 'Medium', 'kalendora' ) },
							{ value: '4', label: __( 'Small', 'kalendora' ) },
						] }
						onChange={ ( v ) => set( 'level' )( Number( v ) ) }
					/>
				) }

				{ field.type === 'paragraph' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text', 'kalendora' ) }
						help={ __(
							'Allowed: <strong>, <em>, <a>, <br>.',
							'kalendora'
						) }
						value={ field.content || '' }
						onChange={ set( 'content' ) }
						rows={ 5 }
					/>
				) }

				{ field.type === 'step' && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title of the next step', 'kalendora' ) }
						value={ field.title || '' }
						onChange={ set( 'title' ) }
					/>
				) }

				{ field.type === 'consent' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Consent text', 'kalendora' ) }
						help={ __(
							'You can link your privacy policy with <a href="…">…</a>.',
							'kalendora'
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
						'calculation',
					].includes( field.type ) && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Placeholder', 'kalendora' ) }
							value={ field.placeholder || '' }
							onChange={ set( 'placeholder' ) }
						/>
					) }

				{ isInput && field.type !== 'hidden' && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Help text', 'kalendora' ) }
						value={ field.help || '' }
						onChange={ set( 'help' ) }
					/>
				) }

				{ isInput &&
					! [ 'hidden', 'calculation' ].includes( field.type ) && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Required', 'kalendora' ) }
							checked={ !! field.required }
							onChange={ set( 'required' ) }
						/>
					) }

				{ [ 'select', 'radio', 'checkboxes' ].includes(
					field.type
				) && (
					<fieldset className="kalendora-fieldset">
						<legend>{ __( 'Options', 'kalendora' ) }</legend>
						<OptionsEditor
							options={ field.options }
							onChange={ set( 'options' ) }
							showNumbers={ fields.some(
								( f ) => f.type === 'calculation'
							) }
						/>
						{ fields.some( ( f ) => f.type === 'calculation' ) && (
							<p className="kalendora-muted">
								{ __(
									'The number next to each option is used in calculations (e.g. the price of an extra).',
									'kalendora'
								) }
							</p>
						) }
					</fieldset>
				) }

				{ field.type === 'calculation' && (
					<FormulaEditor
						field={ field }
						fields={ fields }
						formType={ formType }
						onChange={ onChange }
					/>
				) }

				{ field.type === 'name' && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Format', 'kalendora' ) }
						value={ field.format || 'first_last' }
						options={ [
							{
								value: 'first_last',
								label: __( 'First and last name', 'kalendora' ),
							},
							{
								value: 'full',
								label: __( 'One field', 'kalendora' ),
							},
						] }
						onChange={ set( 'format' ) }
					/>
				) }

				{ field.type === 'service' && services.length > 0 && (
					<fieldset className="kalendora-fieldset">
						<legend>
							{ __( 'Offer these services', 'kalendora' ) }
						</legend>
						<p className="kalendora-muted">
							{ __(
								'None selected = all active services.',
								'kalendora'
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
							'kalendora'
						) }
						checked={ field.allowAny !== false }
						onChange={ set( 'allowAny' ) }
					/>
				) }
			</PanelBody>

			{ isInput && field.type !== 'appointment' && (
				<PanelBody
					title={ __( 'Advanced', 'kalendora' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Field key', 'kalendora' ) }
						help={ __(
							'Used in email placeholders, e.g. {key}. Leave empty to generate it from the label.',
							'kalendora'
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
							label={ __( 'Default value', 'kalendora' ) }
							help={
								field.type === 'hidden'
									? __(
											'Tip: use {url:param} or {user:email} for dynamic values.',
											'kalendora'
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
								'kalendora'
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
							label={ __( 'Rows', 'kalendora' ) }
							value={ field.rows || 4 }
							onChange={ num( 'rows' ) }
						/>
					) }
					{ [ 'number', 'range' ].includes( field.type ) && (
						<div className="kalendora-grid kalendora-grid--3">
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Min', 'kalendora' ) }
								value={ field.min ?? '' }
								onChange={ num( 'min' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Max', 'kalendora' ) }
								value={ field.max ?? '' }
								onChange={ num( 'max' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								label={ __( 'Step', 'kalendora' ) }
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
							label={ __( 'Number of stars', 'kalendora' ) }
							value={ field.max || 5 }
							onChange={ num( 'max' ) }
						/>
					) }
					{ [ 'radio', 'checkboxes' ].includes( field.type ) && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Layout', 'kalendora' ) }
							value={ field.layout || 'vertical' }
							options={ [
								{
									value: 'vertical',
									label: __(
										'Below each other',
										'kalendora'
									),
								},
								{
									value: 'inline',
									label: __( 'Side by side', 'kalendora' ),
								},
							] }
							onChange={ set( 'layout' ) }
						/>
					) }
					{ field.type === 'address' && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Ask for country', 'kalendora' ) }
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
									'kalendora'
								) }
								help={ __(
									'Uses the general opening hours, holidays and vacation.',
									'kalendora'
								) }
								checked={ !! field.workingDaysOnly }
								onChange={ set( 'workingDaysOnly' ) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Also choose a time (within opening hours)',
									'kalendora'
								) }
								help={ __(
									'For requests without fixed booking. For real bookings with blocked times, use a booking form.',
									'kalendora'
								) }
								checked={ !! field.withTime }
								disabled={ ! field.workingDaysOnly }
								onChange={ set( 'withTime' ) }
							/>
						</>
					) }
					{ field.type === 'date' && (
						<div className="kalendora-grid">
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Earliest date', 'kalendora' ) }
								help={ __(
									'e.g. today, +1 day, 2026–12–01',
									'kalendora'
								) }
								value={ field.minDate || '' }
								onChange={ set( 'minDate' ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Latest date', 'kalendora' ) }
								help={ __( 'e.g. +3 months', 'kalendora' ) }
								value={ field.maxDate || '' }
								onChange={ set( 'maxDate' ) }
							/>
						</div>
					) }
					{ field.type === 'file' && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								label={ __(
									'Allowed file types',
									'kalendora'
								) }
								help={ __(
									'Comma separated, e.g. jpg, png, pdf',
									'kalendora'
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
							<div className="kalendora-grid">
								<TextControl
									__nextHasNoMarginBottom
									type="number"
									min={ 1 }
									max={ 50 }
									label={ __(
										'Max. size (MB)',
										'kalendora'
									) }
									help={
										( field.maxSize || 5 ) >
										( config.maxUploadMb || 50 )
											? sprintf(
													/* translators: %d: megabytes. */
													__(
														'Your server allows only %d MB per file – this limit applies.',
														'kalendora'
													),
													config.maxUploadMb
											  )
											: undefined
									}
									value={ field.maxSize || 5 }
									onChange={ num( 'maxSize' ) }
								/>
								<TextControl
									__nextHasNoMarginBottom
									type="number"
									min={ 1 }
									max={ 10 }
									label={ __( 'Max. files', 'kalendora' ) }
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
					title={ __( 'Layout', 'kalendora' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Width', 'kalendora' ) }
						value={ field.width || 'full' }
						options={ [
							{
								value: 'full',
								label: __( 'Full width', 'kalendora' ),
							},
							{
								value: 'half',
								label: __( 'Half width', 'kalendora' ),
							},
							{
								value: 'third',
								label: __( 'One third', 'kalendora' ),
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
							? __( 'Conditional logic (active)', 'kalendora' )
							: __( 'Conditional logic', 'kalendora' )
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
