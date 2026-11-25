/**
 * Form-level settings (right panel, "Form" tab).
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { config } from '../api';
import CodeEditor from '../components/CodeEditor';

export default function FormSettings( { form, onChange } ) {
	const settings = form.settings || {};
	const set = ( key ) => ( value ) => onChange( { ...form, [ key ]: value } );
	const setting = ( key ) => ( value ) =>
		onChange( { ...form, settings: { ...settings, [ key ]: value } } );

	return (
		<div className="kalendora-inspector__form">
			<PanelBody title={ __( 'General', 'kalendora' ) } initialOpen>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Form title', 'kalendora' ) }
					help={ __( 'Only visible to you.', 'kalendora' ) }
					value={ form.title }
					onChange={ set( 'title' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Form type', 'kalendora' ) }
					value={ form.type }
					options={ [
						{
							value: 'standard',
							label: __(
								'Standard form (contact, request, survey …)',
								'kalendora'
							),
						},
						{
							value: 'booking',
							label: __(
								'Booking form (with calendar)',
								'kalendora'
							),
						},
					] }
					onChange={ set( 'type' ) }
					help={
						form.type === 'standard'
							? __(
									'Booking fields are only available in booking forms.',
									'kalendora'
							  )
							: undefined
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'kalendora' ) }
					value={ form.status }
					options={ [
						{
							value: 'draft',
							label: __(
								'Draft (not visible on the website)',
								'kalendora'
							),
						},
						{
							value: 'published',
							label: __( 'Published', 'kalendora' ),
						},
					] }
					onChange={ set( 'status' ) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'After submitting', 'kalendora' ) }
				initialOpen={ false }
			>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Button text', 'kalendora' ) }
					placeholder={
						form.type === 'booking'
							? __( 'Book now', 'kalendora' )
							: __( 'Send', 'kalendora' )
					}
					value={ settings.submitLabel || '' }
					onChange={ setting( 'submitLabel' ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Success message', 'kalendora' ) }
					placeholder={ __(
						'Thank you! We have received your message.',
						'kalendora'
					) }
					value={ settings.successMessage || '' }
					onChange={ setting( 'successMessage' ) }
					rows={ 3 }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Redirect to page (optional)', 'kalendora' ) }
					placeholder="https://"
					value={ settings.redirectUrl || '' }
					onChange={ setting( 'redirectUrl' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Notify me by email about new entries',
						'kalendora'
					) }
					checked={ settings.notifyAdmin !== false }
					onChange={ setting( 'notifyAdmin' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Store entries in the database', 'kalendora' ) }
					help={ __(
						'Booking forms always store entries.',
						'kalendora'
					) }
					checked={
						form.type === 'booking' ||
						settings.storeEntries !== false
					}
					disabled={ form.type === 'booking' }
					onChange={ setting( 'storeEntries' ) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Newsletter', 'kalendora' ) }
				initialOpen={ !! settings.newsletter }
			>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __(
						'Sign up for the newsletter with',
						'kalendora'
					) }
					value={ settings.newsletter || '' }
					options={ [
						{
							value: '',
							label: __( 'No newsletter', 'kalendora' ),
						},
						{ value: 'mailchimp', label: 'Mailchimp' },
						{ value: 'brevo', label: 'Brevo' },
					] }
					onChange={ setting( 'newsletter' ) }
				/>
				{ !! settings.newsletter && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __(
							'Only if this box is ticked',
							'kalendora'
						) }
						help={ __(
							'Add a consent field such as “Yes, I would like to receive the newsletter.” The visitor then gets a confirmation email from the newsletter service (double opt-in). Access data: Kalendora → Integrations.',
							'kalendora'
						) }
						value={ settings.newsletterField || '' }
						options={ [
							{
								value: '',
								label: __( '– choose a field –', 'kalendora' ),
							},
							...( form.fields || [] )
								.filter( ( f ) =>
									[ 'consent', 'checkboxes' ].includes(
										f.type
									)
								)
								.map( ( f ) => ( {
									value: f.key,
									label: f.label || f.key,
								} ) ),
						] }
						onChange={ setting( 'newsletterField' ) }
					/>
				) }
				{ !! settings.newsletter && ! settings.newsletterField && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Without a consent field nobody is signed up.',
							'kalendora'
						) }
					</Notice>
				) }
			</PanelBody>

			<PanelBody
				title={ __( 'Appearance', 'kalendora' ) }
				initialOpen={ false }
			>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Labels', 'kalendora' ) }
					value={ settings.labelPlacement || 'top' }
					options={ [
						{
							value: 'top',
							label: __( 'Above the field', 'kalendora' ),
						},
						{
							value: 'left',
							label: __( 'Left of the field', 'kalendora' ),
						},
						{
							value: 'hidden',
							label: __(
								'Hidden (placeholder only)',
								'kalendora'
							),
						},
					] }
					onChange={ setting( 'labelPlacement' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Progress for multi-step forms', 'kalendora' ) }
					value={ settings.stepProgress || 'bar' }
					options={ [
						{
							value: 'bar',
							label: __( 'Progress bar', 'kalendora' ),
						},
						{
							value: 'steps',
							label: __( 'Step names', 'kalendora' ),
						},
						{ value: 'none', label: __( 'None', 'kalendora' ) },
					] }
					onChange={ setting( 'stepProgress' ) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Custom CSS & JavaScript', 'kalendora' ) }
				initialOpen={ false }
			>
				{ ! config.caps?.unfilteredHtml && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Only administrators can edit custom code.',
							'kalendora'
						) }
					</Notice>
				) }
				<CodeEditor
					mode="css"
					label={ __( 'CSS for this form', 'kalendora' ) }
					help={ sprintf(
						/* translators: %d: form id. */
						__(
							'Scope your rules with .kalendora-form-%d.',
							'kalendora'
						),
						form.id
					) }
					value={ form.custom_css || '' }
					disabled={ ! config.caps?.unfilteredHtml }
					onChange={ set( 'custom_css' ) }
					rows={ 6 }
				/>
				<CodeEditor
					mode="js"
					label={ __( 'JavaScript for this form', 'kalendora' ) }
					help={ __(
						'Runs when the form is ready. Example: kalendora.on( "slot", ( slot ) => { … } ); – see the developer documentation.',
						'kalendora'
					) }
					value={ form.custom_js || '' }
					disabled={ ! config.caps?.unfilteredHtml }
					onChange={ set( 'custom_js' ) }
					rows={ 6 }
				/>
			</PanelBody>
		</div>
	);
}
