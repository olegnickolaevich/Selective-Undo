/**
 * crypto.randomUUID() exists only in secure contexts (HTTPS or localhost). An admin
 * served over plain http on a local network is not one, so fall back to
 * crypto.getRandomValues(), which is available everywhere.
 */
export function uuidV4(): string {
	const c: Crypto | undefined = globalThis.crypto;

	if (
		c &&
		typeof c.randomUUID === 'function' &&
		globalThis.isSecureContext
	) {
		return c.randomUUID();
	}

	if ( ! c || typeof c.getRandomValues !== 'function' ) {
		throw new Error( 'Secure random generator is unavailable' );
	}

	const bytes = c.getRandomValues( new Uint8Array( 16 ) );
	// eslint-disable-next-line no-bitwise -- setting the UUID version bits
	bytes[ 6 ] = ( bytes[ 6 ]! & 0x0f ) | 0x40;
	// eslint-disable-next-line no-bitwise -- setting the RFC 4122 variant bits
	bytes[ 8 ] = ( bytes[ 8 ]! & 0x3f ) | 0x80;
	const hex = Array.from( bytes, ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );

	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice( 12, 16 ) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
}
