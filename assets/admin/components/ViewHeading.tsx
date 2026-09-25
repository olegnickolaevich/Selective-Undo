import { useEffect, useRef } from '@wordpress/element';
import type { ReactNode } from 'react';

/**
 * Heading of a view. After in-app navigation the focus moves here so that
 * keyboard and screen reader users land at the start of the new content.
 * @param root0
 * @param root0.children
 * @param root0.focus
 */
export function ViewHeading( {
	children,
	focus = true,
}: {
	children: ReactNode;
	focus?: boolean;
} ) {
	const ref = useRef< HTMLHeadingElement >( null );

	useEffect( () => {
		if ( focus && window.history.state !== undefined ) {
			ref.current?.focus( { preventScroll: false } );
		}
	}, [ focus ] );

	return (
		<h2 className="su-view-title" tabIndex={ -1 } ref={ ref }>
			{ children }
		</h2>
	);
}
