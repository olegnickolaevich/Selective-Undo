/**
 * The part of `@wordpress/block-editor` this plugin uses; the package ships no types.
 */
declare module '@wordpress/block-editor' {
	import type { ReduxStoreConfig, StoreDescriptor } from '@wordpress/data';
	import type { ComponentType, ReactNode } from 'react';

	export const InspectorControls: ComponentType< { children?: ReactNode } >;

	export const store: StoreDescriptor<
		ReduxStoreConfig<
			unknown,
			Record< string, never >,
			{
				getBlockIndex: ( state: unknown, clientId: string ) => number;
				getBlockRootClientId: (
					state: unknown,
					clientId: string
				) => string | null;
			}
		>
	>;
}
