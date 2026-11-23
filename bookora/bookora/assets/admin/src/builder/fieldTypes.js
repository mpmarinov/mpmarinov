/**
 * Field type catalogue for the builder (labels, icons, defaults).
 * Validation happens server-side in Bookora\Forms\FieldRegistry.
 */
import { __ } from '@wordpress/i18n';

export const GROUPS = [
	{ id: 'booking', label: __( 'Booking', 'bookora' ) },
	{ id: 'basic', label: __( 'Basic fields', 'bookora' ) },
	{ id: 'choice', label: __( 'Choices', 'bookora' ) },
	{ id: 'advanced', label: __( 'Advanced', 'bookora' ) },
	{ id: 'layout', label: __( 'Layout', 'bookora' ) },
];

const options = () => [
	{ label: __( 'Option 1', 'bookora' ), value: 'option_1' },
	{ label: __( 'Option 2', 'bookora' ), value: 'option_2' },
	{ label: __( 'Option 3', 'bookora' ), value: 'option_3' },
];

export const FIELD_TYPES = {
	service: {
		group: 'booking',
		icon: 'clipboard',
		label: __( 'Service', 'bookora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Service', 'bookora' ),
			required: true,
		} ),
	},
	resource: {
		group: 'booking',
		icon: 'groups',
		label: __( 'Staff member', 'bookora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Staff member', 'bookora' ),
			allowAny: true,
		} ),
	},
	appointment: {
		group: 'booking',
		icon: 'calendar-alt',
		label: __( 'Date & time', 'bookora' ),
		single: true,
		defaults: () => ( {
			label: __( 'Choose a date and time', 'bookora' ),
			required: true,
		} ),
	},
	text: {
		group: 'basic',
		icon: 'editor-textcolor',
		label: __( 'Single line text', 'bookora' ),
		defaults: () => ( { label: __( 'Text', 'bookora' ) } ),
	},
	name: {
		group: 'basic',
		icon: 'admin-users',
		label: __( 'Name', 'bookora' ),
		defaults: () => ( {
			label: __( 'Name', 'bookora' ),
			required: true,
			format: 'first_last',
		} ),
	},
	email: {
		group: 'basic',
		icon: 'email',
		label: __( 'Email', 'bookora' ),
		defaults: () => ( {
			label: __( 'Email', 'bookora' ),
			required: true,
			placeholder: 'name@example.com',
		} ),
	},
	phone: {
		group: 'basic',
		icon: 'phone',
		label: __( 'Phone', 'bookora' ),
		defaults: () => ( { label: __( 'Phone', 'bookora' ) } ),
	},
	textarea: {
		group: 'basic',
		icon: 'text',
		label: __( 'Paragraph text', 'bookora' ),
		defaults: () => ( { label: __( 'Message', 'bookora' ), rows: 4 } ),
	},
	number: {
		group: 'basic',
		icon: 'editor-ol',
		label: __( 'Number', 'bookora' ),
		defaults: () => ( { label: __( 'Number', 'bookora' ) } ),
	},
	url: {
		group: 'basic',
		icon: 'admin-links',
		label: __( 'Website / URL', 'bookora' ),
		defaults: () => ( { label: __( 'Website', 'bookora' ) } ),
	},
	select: {
		group: 'choice',
		icon: 'arrow-down-alt2',
		label: __( 'Dropdown', 'bookora' ),
		defaults: () => ( {
			label: __( 'Dropdown', 'bookora' ),
			options: options(),
		} ),
	},
	radio: {
		group: 'choice',
		icon: 'marker',
		label: __( 'Radio buttons', 'bookora' ),
		defaults: () => ( {
			label: __( 'Choose one', 'bookora' ),
			options: options(),
			layout: 'vertical',
		} ),
	},
	checkboxes: {
		group: 'choice',
		icon: 'yes-alt',
		label: __( 'Checkboxes', 'bookora' ),
		defaults: () => ( {
			label: __( 'Choose any', 'bookora' ),
			options: options(),
			layout: 'vertical',
		} ),
	},
	consent: {
		group: 'choice',
		icon: 'shield',
		label: __( 'Consent (GDPR)', 'bookora' ),
		defaults: () => ( {
			label: __( 'Privacy', 'bookora' ),
			required: true,
			text: __(
				'I agree that my data will be processed to handle my request.',
				'bookora'
			),
		} ),
	},
	rating: {
		group: 'choice',
		icon: 'star-filled',
		label: __( 'Rating', 'bookora' ),
		defaults: () => ( { label: __( 'Rating', 'bookora' ), max: 5 } ),
	},
	range: {
		group: 'choice',
		icon: 'leftright',
		label: __( 'Slider', 'bookora' ),
		defaults: () => ( {
			label: __( 'Slider', 'bookora' ),
			min: 0,
			max: 100,
			step: 1,
		} ),
	},
	date: {
		group: 'advanced',
		icon: 'calendar',
		label: __( 'Date', 'bookora' ),
		defaults: () => ( { label: __( 'Date', 'bookora' ) } ),
	},
	time: {
		group: 'advanced',
		icon: 'clock',
		label: __( 'Time', 'bookora' ),
		defaults: () => ( { label: __( 'Time', 'bookora' ) } ),
	},
	address: {
		group: 'advanced',
		icon: 'location',
		label: __( 'Address', 'bookora' ),
		defaults: () => ( {
			label: __( 'Address', 'bookora' ),
			country: true,
		} ),
	},
	file: {
		group: 'advanced',
		icon: 'upload',
		label: __( 'File upload', 'bookora' ),
		defaults: () => ( {
			label: __( 'Upload file', 'bookora' ),
			extensions: [ 'jpg', 'png', 'pdf' ],
			maxSize: 5,
			maxFiles: 1,
		} ),
	},
	signature: {
		group: 'advanced',
		icon: 'edit',
		label: __( 'Signature', 'bookora' ),
		defaults: () => ( { label: __( 'Signature', 'bookora' ) } ),
	},
	hidden: {
		group: 'advanced',
		icon: 'hidden',
		label: __( 'Hidden field', 'bookora' ),
		defaults: () => ( { label: __( 'Hidden', 'bookora' ) } ),
	},
	heading: {
		group: 'layout',
		icon: 'heading',
		label: __( 'Heading', 'bookora' ),
		defaults: () => ( { label: __( 'Heading', 'bookora' ), level: 3 } ),
	},
	paragraph: {
		group: 'layout',
		icon: 'editor-paragraph',
		label: __( 'Text block', 'bookora' ),
		defaults: () => ( {
			content: __(
				'Add an explanation for your visitors here.',
				'bookora'
			),
		} ),
	},
	divider: {
		group: 'layout',
		icon: 'minus',
		label: __( 'Divider', 'bookora' ),
		defaults: () => ( {} ),
	},
	step: {
		group: 'layout',
		icon: 'controls-skipforward',
		label: __( 'Page break (step)', 'bookora' ),
		defaults: () => ( { title: __( 'Next step', 'bookora' ) } ),
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
