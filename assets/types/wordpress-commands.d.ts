/**
 * The part of `@wordpress/commands` this plugin uses; the package ships no types.
 */
declare module '@wordpress/commands' {
	import type { ReduxStoreConfig, StoreDescriptor } from '@wordpress/data';

	export interface WPCommand {
		name: string;
		label: string;
		icon?: unknown;
		callback: ( options: { close: () => void } ) => void;
	}

	/**
	 * Registers a command while the calling component is mounted.
	 * @param command
	 */
	export function useCommand( command: WPCommand ): void;

	export const store: StoreDescriptor<
		ReduxStoreConfig<
			unknown,
			{ registerCommand: ( command: WPCommand ) => { type: string } },
			Record< string, never >
		>
	>;
}
