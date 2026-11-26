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
		history: 'undoly',
		restores: 'undoly-restores',
		settings: 'undoly-settings',
	},
	initialPage: 'history',
	userId: 0,
};
