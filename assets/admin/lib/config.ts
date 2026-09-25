export interface AppConfig {
	version: string;
	adminUrl: string;
	pages: { history: string; restores: string; settings: string };
	initialPage: 'history' | 'restores' | 'settings';
	userId: number;
}

declare global {
	interface Window {
		selectiveUndoConfig?: AppConfig;
	}
}

export const config: AppConfig = window.selectiveUndoConfig ?? {
	version: '0',
	adminUrl: '/wp-admin/',
	pages: {
		history: 'selective-undo',
		restores: 'selective-undo-restores',
		settings: 'selective-undo-settings',
	},
	initialPage: 'history',
	userId: 0,
};
