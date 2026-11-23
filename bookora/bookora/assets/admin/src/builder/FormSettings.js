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

export default function FormSettings( { form, onChange } ) {
	const settings = form.settings || {};
	const set = ( key ) => ( value ) => onChange( { ...form, [ key ]: value } );
	const setting = ( key ) => ( value ) =>
		onChange( { ...form, settings: { ...settings, [ key ]: value } } );

	return (
		<div className="bookora-inspector__form">
			<PanelBody title={ __( 'General', 'bookora' ) } initialOpen>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Form title', 'bookora' ) }
					help={ __( 'Only visible to you.', 'bookora' ) }
					value={ form.title }
					onChange={ set( 'title' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Form type', 'bookora' ) }
					value={ form.type }
					options={ [
						{
							value: 'standard',
							label: __(
								'Standard form (contact, request, survey …)',
								'bookora'
							),
						},
						{
							value: 'booking',
							label: __(
								'Booking form (with calendar)',
								'bookora'
							),
						},
					] }
					onChange={ set( 'type' ) }
					help={
						form.type === 'standard'
							? __(
									'Booking fields are only available in booking forms.',
									'bookora'
							  )
							: undefined
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'bookora' ) }
					value={ form.status }
					options={ [
						{
							value: 'draft',
							label: __(
								'Draft (not visible on the website)',
								'bookora'
							),
						},
						{
							value: 'published',
							label: __( 'Published', 'bookora' ),
						},
					] }
					onChange={ set( 'status' ) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'After submitting', 'bookora' ) }
				initialOpen={ false }
			>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Button text', 'bookora' ) }
					placeholder={
						form.type === 'booking'
							? __( 'Book now', 'bookora' )
							: __( 'Send', 'bookora' )
					}
					value={ settings.submitLabel || '' }
					onChange={ setting( 'submitLabel' ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Success message', 'bookora' ) }
					placeholder={ __(
						'Thank you! We have received your message.',
						'bookora'
					) }
					value={ settings.successMessage || '' }
					onChange={ setting( 'successMessage' ) }
					rows={ 3 }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Redirect to page (optional)', 'bookora' ) }
					placeholder="https://"
					value={ settings.redirectUrl || '' }
					onChange={ setting( 'redirectUrl' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Notify me by email about new entries',
						'bookora'
					) }
					checked={ settings.notifyAdmin !== false }
					onChange={ setting( 'notifyAdmin' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Store entries in the database', 'bookora' ) }
					help={ __(
						'Booking forms always store entries.',
						'bookora'
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
				title={ __( 'Appearance', 'bookora' ) }
				initialOpen={ false }
			>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Labels', 'bookora' ) }
					value={ settings.labelPlacement || 'top' }
					options={ [
						{
							value: 'top',
							label: __( 'Above the field', 'bookora' ),
						},
						{
							value: 'left',
							label: __( 'Left of the field', 'bookora' ),
						},
						{
							value: 'hidden',
							label: __( 'Hidden (placeholder only)', 'bookora' ),
						},
					] }
					onChange={ setting( 'labelPlacement' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Progress for multi-step forms', 'bookora' ) }
					value={ settings.stepProgress || 'bar' }
					options={ [
						{
							value: 'bar',
							label: __( 'Progress bar', 'bookora' ),
						},
						{
							value: 'steps',
							label: __( 'Step names', 'bookora' ),
						},
						{ value: 'none', label: __( 'None', 'bookora' ) },
					] }
					onChange={ setting( 'stepProgress' ) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Custom CSS & JavaScript', 'bookora' ) }
				initialOpen={ false }
			>
				{ ! config.caps?.unfilteredHtml && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Only administrators can edit custom code.',
							'bookora'
						) }
					</Notice>
				) }
				<TextareaControl
					__nextHasNoMarginBottom
					className="bookora-code"
					label={ __( 'CSS for this form', 'bookora' ) }
					help={ sprintf(
						/* translators: %d: form id. */
						__(
							'Scope your rules with .bookora-form-%d.',
							'bookora'
						),
						form.id
					) }
					value={ form.custom_css || '' }
					disabled={ ! config.caps?.unfilteredHtml }
					onChange={ set( 'custom_css' ) }
					rows={ 6 }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					className="bookora-code"
					label={ __( 'JavaScript for this form', 'bookora' ) }
					help={ __(
						'Runs after the form has loaded. Example: bookora.on( "submit", ( e ) => { … } );',
						'bookora'
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
