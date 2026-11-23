import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';

/**
 * Destructive button with a confirmation dialog.
 *
 * @param {Object}   props
 * @param {Function} props.onConfirm Called after confirmation.
 * @param {string}   props.message   Question shown in the dialog.
 * @param {string}   props.label     Button label.
 */
export default function ConfirmButton( { onConfirm, message, label } ) {
	const [ open, setOpen ] = useState( false );

	return (
		<>
			<Button
				isDestructive
				variant="tertiary"
				onClick={ () => setOpen( true ) }
			>
				{ label || __( 'Delete', 'bookora' ) }
			</Button>
			{ open && (
				<Modal
					title={ __( 'Are you sure?', 'bookora' ) }
					onRequestClose={ () => setOpen( false ) }
					size="small"
				>
					<p>{ message }</p>
					<div className="bookora-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Cancel', 'bookora' ) }
						</Button>
						<Button
							isDestructive
							variant="primary"
							onClick={ () => {
								setOpen( false );
								onConfirm();
							} }
						>
							{ label || __( 'Delete', 'bookora' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
