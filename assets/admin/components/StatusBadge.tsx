import { Icon, check, caution, error, info, pending } from '@wordpress/icons';
import type { Tone } from '../lib/labels';

const ICONS = {
	success: check,
	warning: caution,
	error,
	info: pending,
	neutral: info,
} as const;

/**
 * Status is never conveyed by colour alone: icon + text.
 * @param root0
 * @param root0.tone
 * @param root0.children
 */
export function StatusBadge( {
	tone,
	children,
}: {
	tone: Tone;
	children: string;
} ) {
	return (
		<span className={ `su-badge su-badge--${ tone }` }>
			<Icon icon={ ICONS[ tone ] } size={ 16 } />
			<span>{ children }</span>
		</span>
	);
}
