import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

export function exactDate( iso: string | null ): string {
	if ( ! iso ) {
		return '';
	}
	const settings = getSettings();
	return dateI18n(
		`${ settings.formats.date } ${ settings.formats.time }`,
		iso,
		undefined
	);
}

export function relativeDate( iso: string | null ): string {
	if ( ! iso ) {
		return '';
	}
	return humanTimeDiff( iso, new Date() );
}

export function formatBytes( bytes: number ): string {
	const units = [
		__( 'B', 'selective-undo' ),
		__( 'KB', 'selective-undo' ),
		__( 'MB', 'selective-undo' ),
		__( 'GB', 'selective-undo' ),
	];
	let value = bytes;
	let unit = 0;

	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}

	return `${ value >= 10 || unit === 0 ? Math.round( value ) : value.toFixed( 1 ) } ${ units[ unit ] }`;
}

export function fieldsCount( count: number ): string {
	return sprintf(
		/* translators: %d: number of fields. */
		_n( '%d field', '%d fields', count, 'selective-undo' ),
		count
	);
}

export function itemsCount( count: number ): string {
	return sprintf(
		/* translators: %d: number of posts or pages. */
		_n( '%d item', '%d items', count, 'selective-undo' ),
		count
	);
}
