import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from './api/client';
import type { Overview } from './api/types';
import { AppHeader } from './components/AppHeader';
import { ErrorNotice } from './components/Feedback';
import { Navigation } from './components/Navigation';
import { useView } from './lib/router';
import { ChangesetPage } from './pages/ChangesetPage';
import { HistoryPage } from './pages/HistoryPage';
import { JobPage } from './pages/JobPage';
import { ObjectPage } from './pages/ObjectPage';
import { PreviewPage } from './pages/PreviewPage';
import { RestoresPage } from './pages/RestoresPage';
import { SettingsPage } from './pages/SettingsPage';

export function App() {
	const view = useView();
	const [ overview, setOverview ] = useState< Overview | null >( null );
	const [ error, setError ] = useState< unknown >( null );
	const [ tick, setTick ] = useState( 0 );

	useEffect( () => {
		const controller = new AbortController();
		api.overview( controller.signal )
			.then( setOverview )
			.catch( ( e: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError( e );
				}
			} );
		return () => controller.abort();
	}, [ tick, view.name ] );

	const refreshOverview = () => setTick( ( t ) => t + 1 );

	return (
		<div className="su-app">
			<div className="su-shell">
				<AppHeader overview={ overview } />
				<Navigation
					view={ view }
					canManage={ overview?.user.can.manage_settings ?? false }
				/>
				{ error !== null && (
					<ErrorNotice error={ error } onRetry={ refreshOverview } />
				) }
				<main
					className="su-main"
					aria-label={ __( 'Selective Undo', 'selective-undo' ) }
				>
					{ view.name === 'history' && (
						<HistoryPage overview={ overview } />
					) }
					{ view.name === 'changeset' && (
						<ChangesetPage
							key={ view.id }
							id={ view.id }
							overview={ overview }
						/>
					) }
					{ view.name === 'object' && (
						<ObjectPage
							key={ view.id }
							id={ view.id }
							overview={ overview }
						/>
					) }
					{ view.name === 'preview' && (
						<PreviewPage key={ view.id } id={ view.id } />
					) }
					{ view.name === 'restores' && <RestoresPage /> }
					{ view.name === 'job' && (
						<JobPage key={ view.id } id={ view.id } />
					) }
					{ view.name === 'settings' && (
						<SettingsPage
							tab={ view.tab }
							overview={ overview }
							onChanged={ refreshOverview }
						/>
					) }
				</main>
			</div>
		</div>
	);
}
