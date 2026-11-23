/**
 * Code field with WordPress' built-in CodeMirror editor (syntax highlighting, CSS/JS linting).
 * Falls back to a plain textarea if the user turned off syntax highlighting in the profile.
 */
import { useEffect, useRef } from '@wordpress/element';
import { BaseControl } from '@wordpress/components';

import { config } from '../api';

let counter = 0;

export default function CodeEditor( {
	label,
	help,
	value,
	onChange,
	mode = 'css',
	disabled = false,
	rows = 8,
} ) {
	const textarea = useRef( null );
	const editor = useRef( null );
	const changeRef = useRef( onChange );
	const idRef = useRef( `bookora-code-${ ++counter }` );
	changeRef.current = onChange;

	useEffect( () => {
		const settings = config.codeEditor?.[ mode ];
		if ( ! settings || ! window.wp?.codeEditor || ! textarea.current ) {
			return undefined;
		}
		const instance = window.wp.codeEditor.initialize( textarea.current, {
			...settings,
			codemirror: {
				...settings.codemirror,
				readOnly: disabled ? 'nocursor' : false,
				lineWrapping: true,
				viewportMargin: Infinity,
			},
		} );
		const cm = instance.codemirror;
		cm.setSize( null, Math.max( 140, rows * 22 ) );
		cm.on( 'change', () => changeRef.current( cm.getValue() ) );
		editor.current = cm;
		return () => {
			cm.toTextArea();
			editor.current = null;
		};
	}, [ mode, disabled, rows ] );

	// Outside changes (e.g. reset) are pushed into the editor.
	useEffect( () => {
		const cm = editor.current;
		if ( cm && cm.getValue() !== ( value || '' ) ) {
			cm.setValue( value || '' );
		}
	}, [ value ] );

	return (
		<BaseControl
			__nextHasNoMarginBottom
			id={ idRef.current }
			label={ label }
			help={ help }
			className="bookora-code-editor"
		>
			<textarea
				ref={ textarea }
				id={ idRef.current }
				className="bookora-code"
				rows={ rows }
				value={ value || '' }
				disabled={ disabled }
				onChange={ ( e ) => onChange( e.target.value ) }
				spellCheck={ false }
			/>
		</BaseControl>
	);
}
