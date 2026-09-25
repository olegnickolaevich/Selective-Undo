import { useMemo } from '@wordpress/element';
import { uuidV4 } from '../lib/uuid';

const PREFIX = 'selective-undo:idem:';

function read( key: string ): string | null {
	try {
		return window.sessionStorage.getItem( key );
	} catch {
		return null;
	}
}

function write( key: string, value: string ): void {
	try {
		window.sessionStorage.setItem( key, value );
	} catch {
		// Without storage the key lives until reload; the server still protects
		// against duplicates because a plan can be used once.
	}
}

/**
 * One idempotency key per plan: survives a reload, changes with the plan.
 * Duplicate protection lives on the server; the key only makes a retry of a
 * request with a lost response safe.
 * @param planId
 */
export function useIdempotencyKey( planId: string ): string {
	return useMemo( () => {
		const storageKey = PREFIX + planId;
		const existing = read( storageKey );

		if ( existing ) {
			return existing;
		}

		const created = uuidV4();
		write( storageKey, created );
		return created;
	}, [ planId ] );
}
