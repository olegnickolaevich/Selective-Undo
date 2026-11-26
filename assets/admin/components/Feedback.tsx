import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { ApiError } from '../api/client';

export function errorMessage( error: unknown ): string {
	if ( error instanceof ApiError && error.message ) {
		return error.message;
	}
	if ( error instanceof ApiError && error.code === 'fetch_error' ) {
		return __(
			'The server could not be reached. Check your connection and try again.',
			'undoly'
		);
	}
	return __( 'Something went wrong. Try again.', 'undoly' );
}

export function ErrorNotice( {
	error,
	onRetry,
}: {
	error: unknown;
	onRetry?: () => void;
} ) {
	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={
				onRetry
					? [
							{
								label: __( 'Try again', 'undoly' ),
								onClick: onRetry,
							},
						]
					: []
			}
		>
			{ errorMessage( error ) }
		</Notice>
	);
}

export function Loading( { label }: { label?: string } ) {
	return (
		<p className="su-loading" role="status">
			<Spinner />
			<span>{ label ?? __( 'Loading…', 'undoly' ) }</span>
		</p>
	);
}

export function EmptyState( {
	title,
	children,
}: {
	title: string;
	children?: ReactNode;
} ) {
	return (
		<div className="su-empty">
			<p className="su-empty__title">{ title }</p>
			{ children }
		</div>
	);
}

export function LoadMore( {
	onClick,
	busy,
}: {
	onClick: () => void;
	busy: boolean;
} ) {
	return (
		<div className="su-load-more">
			<Button
				variant="secondary"
				onClick={ onClick }
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Show more', 'undoly' ) }
			</Button>
		</div>
	);
}
