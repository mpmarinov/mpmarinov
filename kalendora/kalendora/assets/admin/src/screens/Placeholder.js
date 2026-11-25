import { __ } from '@wordpress/i18n';
import { Card, CardBody } from '@wordpress/components';

export default function Placeholder() {
	return (
		<Card>
			<CardBody>
				<p>
					{ __(
						'This section is being built and will be available in an upcoming version.',
						'kalendora'
					) }
				</p>
			</CardBody>
		</Card>
	);
}
