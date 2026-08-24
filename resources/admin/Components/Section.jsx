/** A titled settings block. */

import { Card, CardBody, CardHeader } from './Card';

const Section = ( { icon, title, description, children } ) => (
	<Card className="sio-section">
		<CardHeader className="sio-section__header">
			<span className="sio-section__icon">{ icon }</span>
			<span className="sio-section__heading">
				<span className="sio-section__title">{ title }</span>
				{ description && (
					<span className="sio-section__desc">{ description }</span>
				) }
			</span>
		</CardHeader>
		<CardBody>{ children }</CardBody>
	</Card>
);

export default Section;
