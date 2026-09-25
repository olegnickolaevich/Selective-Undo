import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ObjectSummary } from '../api/types';
import { displayTitle } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';

export function ObjectTitle( {
	object,
	withLinks = true,
}: {
	object: ObjectSummary;
	withLinks?: boolean;
} ) {
	const history = { name: 'object' as const, id: object.id };

	return (
		<span className="su-object">
			<strong className="su-object__title">
				{ displayTitle( object.title, object.id ) }
			</strong>
			<span className="su-object__meta">
				{ object.subtype_label } · #{ object.id }
				{ object.status === 'trash' &&
					` · ${ __( 'in trash', 'selective-undo' ) }` }
			</span>
			{ withLinks && (
				<span className="su-object__links">
					<a
						href={ viewToUrl( history ) }
						onClick={ linkHandler( history ) }
					>
						{ __( 'History of this item', 'selective-undo' ) }
					</a>
					{ object.edit_url && (
						<ExternalLink href={ object.edit_url }>
							{ __( 'Open editor', 'selective-undo' ) }
						</ExternalLink>
					) }
				</span>
			) }
		</span>
	);
}
