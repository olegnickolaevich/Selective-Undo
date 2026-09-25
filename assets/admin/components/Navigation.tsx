import { __ } from '@wordpress/i18n';
import type { View } from '../lib/router';
import { viewToUrl } from '../lib/router';

function sectionOf( view: View ): string {
	if ( view.name === 'restores' || view.name === 'job' ) {
		return 'restores';
	}
	if ( view.name === 'settings' ) {
		return 'settings';
	}
	return 'history';
}

export function Navigation( {
	view,
	canManage,
}: {
	view: View;
	canManage: boolean;
} ) {
	const current = sectionOf( view );
	const items: Array< { key: string; label: string; view: View } > = [
		{
			key: 'history',
			label: __( 'History', 'selective-undo' ),
			view: { name: 'history' },
		},
		{
			key: 'restores',
			label: __( 'Restores', 'selective-undo' ),
			view: { name: 'restores' },
		},
	];

	if ( canManage ) {
		items.push( {
			key: 'settings',
			label: __( 'Settings', 'selective-undo' ),
			view: { name: 'settings', tab: 'recording' },
		} );
	}

	return (
		<nav
			className="su-nav"
			aria-label={ __( 'Selective Undo sections', 'selective-undo' ) }
		>
			<ul>
				{ items.map( ( item ) => (
					<li key={ item.key }>
						<a
							href={ viewToUrl( item.view ) }
							aria-current={
								current === item.key ? 'page' : undefined
							}
						>
							{ item.label }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
