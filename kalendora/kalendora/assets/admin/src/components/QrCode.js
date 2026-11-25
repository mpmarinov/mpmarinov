/**
 * QR code as inline SVG, generated in the browser (qrcode-generator, MIT, © Kazuhiko Arase).
 * No external service is contacted.
 */
import qrcode from 'qrcode-generator';

/**
 * @param {Object} props
 * @param {string} props.value Text to encode (e.g. a URL).
 * @param {string} props.label Accessible description.
 * @param {number} props.size  Size in pixels.
 */
export default function QrCode( { value, label, size = 220 } ) {
	const qr = qrcode( 0, 'M' );
	qr.addData( value );
	qr.make();
	const count = qr.getModuleCount();
	const margin = 4;
	let path = '';
	for ( let row = 0; row < count; row++ ) {
		for ( let col = 0; col < count; col++ ) {
			if ( qr.isDark( row, col ) ) {
				path += `M${ col + margin } ${ row + margin }h1v1h-1z`;
			}
		}
	}
	const box = count + margin * 2;
	return (
		<svg
			className="kalendora-qr"
			width={ size }
			height={ size }
			viewBox={ `0 0 ${ box } ${ box }` }
			role="img"
			aria-label={ label }
			shapeRendering="crispEdges"
		>
			<rect width={ box } height={ box } fill="#fff" />
			<path d={ path } fill="#000" />
		</svg>
	);
}
