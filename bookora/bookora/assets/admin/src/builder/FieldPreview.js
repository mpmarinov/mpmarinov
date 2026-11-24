/**
 * Non-interactive preview of a field inside the builder canvas.
 */
import { __ } from '@wordpress/i18n';
import { Dashicon } from '@wordpress/components';

function Label( { field } ) {
	if ( ! field.label ) {
		return null;
	}
	return (
		<span className="bookora-pv__label">
			{ field.label }
			{ field.required && <span className="bookora-pv__req">*</span> }
		</span>
	);
}

function Input( { placeholder = '', icon } ) {
	return (
		<span className="bookora-pv__input">
			<span className="bookora-pv__placeholder">{ placeholder }</span>
			{ icon && <Dashicon icon={ icon } /> }
		</span>
	);
}

function Choices( { field, type } ) {
	return (
		<span
			className={ `bookora-pv__choices bookora-pv__choices--${
				field.layout || 'vertical'
			}` }
		>
			{ ( field.options || [] ).slice( 0, 6 ).map( ( o, i ) => (
				<span key={ i } className="bookora-pv__choice">
					<span className={ `bookora-pv__${ type }` } />
					{ o.label }
				</span>
			) ) }
		</span>
	);
}

function MiniCalendar() {
	const days = Array.from( { length: 14 }, ( _, i ) => i + 1 );
	return (
		<span className="bookora-pv__appointment">
			<span className="bookora-pv__cal">
				{ days.map( ( d ) => (
					<span
						key={ d }
						className={ `bookora-pv__day${
							[ 3, 4, 8, 10 ].includes( d ) ? ' is-free' : ''
						}${ d === 4 ? ' is-selected' : '' }` }
					>
						{ d }
					</span>
				) ) }
			</span>
			<span className="bookora-pv__slots">
				{ [ '09:00', '10:00', '11:30', '14:00' ].map( ( t, i ) => (
					<span
						key={ t }
						className={ `bookora-pv__slot${
							i === 1 ? ' is-selected' : ''
						}` }
					>
						{ t }
					</span>
				) ) }
			</span>
		</span>
	);
}

export default function FieldPreview( { field } ) {
	switch ( field.type ) {
		case 'heading':
			return (
				<span
					className={ `bookora-pv__heading bookora-pv__heading--h${
						field.level || 3
					}` }
				>
					{ field.label || __( 'Heading', 'bookora' ) }
				</span>
			);
		case 'paragraph':
			return (
				<span
					className="bookora-pv__paragraph"
					// Content is sanitized server-side with wp_kses on save; here it is the admin's own input.
					dangerouslySetInnerHTML={ { __html: field.content || '' } }
				/>
			);
		case 'divider':
			return <span className="bookora-pv__divider" />;
		case 'step':
			return (
				<span className="bookora-pv__step">
					<Dashicon icon="controls-skipforward" />
					{ __( 'Page break', 'bookora' ) }
					{ field.title ? ` – ${ field.title }` : '' }
				</span>
			);
		case 'name':
			return (
				<>
					<Label field={ field } />
					{ field.format === 'full' ? (
						<Input placeholder={ field.placeholder } />
					) : (
						<span className="bookora-pv__row">
							<Input
								placeholder={ __( 'First name', 'bookora' ) }
							/>
							<Input
								placeholder={ __( 'Last name', 'bookora' ) }
							/>
						</span>
					) }
				</>
			);
		case 'address':
			return (
				<>
					<Label field={ field } />
					<Input
						placeholder={ __( 'Street and number', 'bookora' ) }
					/>
					<span className="bookora-pv__row">
						<Input placeholder={ __( 'ZIP', 'bookora' ) } />
						<Input placeholder={ __( 'City', 'bookora' ) } />
						{ field.country && (
							<Input
								placeholder={ __( 'Country', 'bookora' ) }
								icon="arrow-down-alt2"
							/>
						) }
					</span>
				</>
			);
		case 'textarea':
			return (
				<>
					<Label field={ field } />
					<span
						className="bookora-pv__input bookora-pv__input--area"
						style={ {
							minHeight: `${
								Math.min( field.rows || 4, 8 ) * 18
							}px`,
						} }
					>
						<span className="bookora-pv__placeholder">
							{ field.placeholder }
						</span>
					</span>
				</>
			);
		case 'select':
		case 'service':
		case 'resource':
			return (
				<>
					<Label field={ field } />
					<Input
						placeholder={
							field.placeholder ||
							( field.type === 'select'
								? field.options?.[ 0 ]?.label
								: __( '— Please choose —', 'bookora' ) )
						}
						icon="arrow-down-alt2"
					/>
				</>
			);
		case 'radio':
			return (
				<>
					<Label field={ field } />
					<Choices field={ field } type="radio" />
				</>
			);
		case 'checkboxes':
			return (
				<>
					<Label field={ field } />
					<Choices field={ field } type="checkbox" />
				</>
			);
		case 'consent':
			return (
				<span className="bookora-pv__choice">
					<span className="bookora-pv__checkbox" />
					<span
						dangerouslySetInnerHTML={ {
							__html: field.text || field.label,
						} }
					/>
					{ field.required && (
						<span className="bookora-pv__req">*</span>
					) }
				</span>
			);
		case 'rating':
			return (
				<>
					<Label field={ field } />
					<span className="bookora-pv__stars">
						{ Array.from( { length: field.max || 5 }, ( _, i ) => (
							<Dashicon
								key={ i }
								icon={ i < 3 ? 'star-filled' : 'star-empty' }
							/>
						) ) }
					</span>
				</>
			);
		case 'range':
			return (
				<>
					<Label field={ field } />
					<span className="bookora-pv__range">
						<span className="bookora-pv__range-fill" />
						<span className="bookora-pv__range-thumb" />
					</span>
				</>
			);
		case 'date':
			return (
				<>
					<Label field={ field } />
					<Input
						placeholder={ field.placeholder || 'TT.MM.JJJJ' }
						icon="calendar"
					/>
				</>
			);
		case 'time':
			return (
				<>
					<Label field={ field } />
					<Input
						placeholder={ field.placeholder || '--:--' }
						icon="clock"
					/>
				</>
			);
		case 'file':
			return (
				<>
					<Label field={ field } />
					<span className="bookora-pv__dropzone">
						<Dashicon icon="upload" />
						{ __(
							'Drop files here or click to upload',
							'bookora'
						) }
						<small>
							{ ( field.extensions || [] )
								.join( ', ' )
								.toUpperCase() }{ ' ' }
							· max. { field.maxSize } MB
						</small>
					</span>
				</>
			);
		case 'signature':
			return (
				<>
					<Label field={ field } />
					<span className="bookora-pv__signature">
						{ __( 'Sign here', 'bookora' ) }
					</span>
				</>
			);
		case 'calculation':
			return (
				<>
					<Label field={ field } />
					<span className="bookora-pv__calc">
						{ [ field.prefix, '0,00', field.suffix ]
							.filter( Boolean )
							.join( ' ' ) }
					</span>
				</>
			);
		case 'hidden':
			return (
				<span className="bookora-pv__hidden">
					<Dashicon icon="hidden" />
					{ field.label } <code>{ field.key || '' }</code>
				</span>
			);
		case 'appointment':
			return (
				<>
					<Label field={ field } />
					<MiniCalendar />
				</>
			);
		default:
			return (
				<>
					<Label field={ field } />
					<Input placeholder={ field.placeholder } />
				</>
			);
	}
}
