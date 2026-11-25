/**
 * Field type catalogue for the builder (labels, icons, defaults).
 * Validation happens server-side in Kalendora\Forms\FieldRegistry.
 */
import { __ } from '@wordpress/i18n';

export const GROUPS = [
	{ id: 'booking', label: __( 'Booking', 'kalendora' ) },
	{ id: 'basic', label: __( 'Basic fields', 'kalendora' ) },
	{ id: 'choice', label: __( 'Choices', 'kalendora' ) },
	{ id: 'advanced', label: __( 'Advanced', 'kalendora' ) },
	{ id: 'layout', label: __( 'Layout', 'kalendora' ) },
];

const options = () => [
	{ label: __( 'Option 1', 'kalendora' ), value: 'option_1' },
	{ label: __( 'Option 2', 'kalendora' ), value: 'option_2' },
	{ label: __( 'Option 3', 'kalendora' ), value: 'option_3' },
];

export const FIELD_TYPES = {
	service: {
		group: 'booking',
		icon: 'clipboard',
		label: __( 'Service', 'kalendora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Service', 'kalendora' ),
			required: true,
		} ),
	},
	resource: {
		group: 'booking',
		icon: 'groups',
		label: __( 'Staff member', 'kalendora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Staff member', 'kalendora' ),
			allowAny: true,
		} ),
	},
	appointment: {
		group: 'booking',
		icon: 'calendar-alt',
		label: __( 'Date & time', 'kalendora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Choose a date and time', 'kalendora' ),
			required: true,
		} ),
	},
	text: {
		group: 'basic',
		icon: 'editor-textcolor',
		label: __( 'Single line text', 'kalendora' ),
		defaults: () => ( { label: __( 'Text', 'kalendora' ) } ),
	},
	name: {
		group: 'basic',
		icon: 'admin-users',
		label: __( 'Name', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Name', 'kalendora' ),
			required: true,
			format: 'first_last',
		} ),
	},
	email: {
		group: 'basic',
		icon: 'email',
		label: __( 'Email', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Email', 'kalendora' ),
			required: true,
			placeholder: 'name@example.com',
		} ),
	},
	phone: {
		group: 'basic',
		icon: 'phone',
		label: __( 'Phone', 'kalendora' ),
		defaults: () => ( { label: __( 'Phone', 'kalendora' ) } ),
	},
	textarea: {
		group: 'basic',
		icon: 'text',
		label: __( 'Paragraph text', 'kalendora' ),
		defaults: () => ( { label: __( 'Message', 'kalendora' ), rows: 4 } ),
	},
	number: {
		group: 'basic',
		icon: 'editor-ol',
		label: __( 'Number', 'kalendora' ),
		defaults: () => ( { label: __( 'Number', 'kalendora' ) } ),
	},
	url: {
		group: 'basic',
		icon: 'admin-links',
		label: __( 'Website / URL', 'kalendora' ),
		defaults: () => ( { label: __( 'Website', 'kalendora' ) } ),
	},
	select: {
		group: 'choice',
		icon: 'arrow-down-alt2',
		label: __( 'Dropdown', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Dropdown', 'kalendora' ),
			options: options(),
		} ),
	},
	radio: {
		group: 'choice',
		icon: 'marker',
		label: __( 'Radio buttons', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Choose one', 'kalendora' ),
			options: options(),
			layout: 'vertical',
		} ),
	},
	checkboxes: {
		group: 'choice',
		icon: 'yes-alt',
		label: __( 'Checkboxes', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Choose any', 'kalendora' ),
			options: options(),
			layout: 'vertical',
		} ),
	},
	consent: {
		group: 'choice',
		icon: 'shield',
		label: __( 'Consent (GDPR)', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Privacy', 'kalendora' ),
			required: true,
			text: __(
				'I agree that my data will be processed to handle my request.',
				'kalendora'
			),
		} ),
	},
	rating: {
		group: 'choice',
		icon: 'star-filled',
		label: __( 'Rating', 'kalendora' ),
		defaults: () => ( { label: __( 'Rating', 'kalendora' ), max: 5 } ),
	},
	range: {
		group: 'choice',
		icon: 'leftright',
		label: __( 'Slider', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Slider', 'kalendora' ),
			min: 0,
			max: 100,
			step: 1,
		} ),
	},
	date: {
		group: 'advanced',
		icon: 'calendar',
		label: __( 'Date', 'kalendora' ),
		defaults: () => ( { label: __( 'Date', 'kalendora' ) } ),
	},
	time: {
		group: 'advanced',
		icon: 'clock',
		label: __( 'Time', 'kalendora' ),
		defaults: () => ( { label: __( 'Time', 'kalendora' ) } ),
	},
	address: {
		group: 'advanced',
		icon: 'location',
		label: __( 'Address', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Address', 'kalendora' ),
			country: true,
		} ),
	},
	file: {
		group: 'advanced',
		icon: 'upload',
		label: __( 'File upload', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Upload file', 'kalendora' ),
			extensions: [ 'jpg', 'png', 'pdf' ],
			maxSize: 5,
			maxFiles: 1,
		} ),
	},
	signature: {
		group: 'advanced',
		icon: 'edit',
		label: __( 'Signature', 'kalendora' ),
		defaults: () => ( { label: __( 'Signature', 'kalendora' ) } ),
	},
	calculation: {
		group: 'advanced',
		icon: 'calculator',
		label: __( 'Calculation', 'kalendora' ),
		defaults: () => ( {
			label: __( 'Total', 'kalendora' ),
			formula: '',
			decimals: 2,
			suffix: '€',
		} ),
	},
	hidden: {
		group: 'advanced',
		icon: 'hidden',
		label: __( 'Hidden field', 'kalendora' ),
		defaults: () => ( { label: __( 'Hidden', 'kalendora' ) } ),
	},
	heading: {
		group: 'layout',
		icon: 'heading',
		label: __( 'Heading', 'kalendora' ),
		defaults: () => ( { label: __( 'Heading', 'kalendora' ), level: 3 } ),
	},
	paragraph: {
		group: 'layout',
		icon: 'editor-paragraph',
		label: __( 'Text block', 'kalendora' ),
		defaults: () => ( {
			content: __(
				'Add an explanation for your visitors here.',
				'kalendora'
			),
		} ),
	},
	divider: {
		group: 'layout',
		icon: 'minus',
		label: __( 'Divider', 'kalendora' ),
		defaults: () => ( {} ),
	},
	step: {
		group: 'layout',
		icon: 'controls-skipforward',
		label: __( 'Page break (step)', 'kalendora' ),
		defaults: () => ( { title: __( 'Next step', 'kalendora' ) } ),
	},
};

export const INPUT_TYPES = Object.keys( FIELD_TYPES ).filter(
	( t ) => ! [ 'heading', 'paragraph', 'divider', 'step' ].includes( t )
);

let counter = 0;

/**
 * Create a new field of the given type with defaults and a unique client id.
 *
 * @param {string} type Field type.
 * @return {Object} Field.
 */
export function createField( type ) {
	counter += 1;
	return {
		id: `f${ Date.now().toString( 36 ) }${ counter }`,
		type,
		width: 'full',
		...FIELD_TYPES[ type ].defaults(),
	};
}
