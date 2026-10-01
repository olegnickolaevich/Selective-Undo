import { Button, RadioControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api/client';
import type {
	ConflictSide,
	MergeConflictText,
	PlanItem,
	RestorePlan,
} from '../api/types';
import { useAsync } from '../hooks/useAsync';
import { navigate } from '../lib/router';
import { ErrorNotice, Loading } from './Feedback';

function resolutionKey( item: PlanItem ): string {
	return `${ item.object.type }:${ item.object.id }:${ item.field }`;
}

function Side( { title, text }: { title: string; text: string } ) {
	return (
		<div className="su-conflict__side">
			<p className="su-conflict__side-title">{ title }</p>
			<p className="su-conflict__text">
				{ text === '' ? (
					<em>{ __( '(removed)', 'selective-undo' ) }</em>
				) : (
					text
				) }
			</p>
		</div>
	);
}

/**
 * Parts of a field changed both by the undone change and by later edits: the user
 * chooses a side for each, and the preview is checked again with that choice.
 * @param root0
 * @param root0.plan
 * @param root0.item
 */
export function MergeConflicts( {
	plan,
	item,
}: {
	plan: RestorePlan;
	item: PlanItem;
} ) {
	const { data, error, loading, reload } = useAsync< {
		items: MergeConflictText[];
	} >(
		( signal ) => api.planItemConflicts( plan.id, item.id, signal ),
		[ plan.id, item.id ]
	);
	const [ choice, setChoice ] = useState< Record< number, ConflictSide > >(
		{}
	);
	const [ busy, setBusy ] = useState( false );
	const [ planError, setPlanError ] = useState< unknown >( null );

	if ( loading && ! data ) {
		return <Loading label={ __( 'Loading…', 'selective-undo' ) } />;
	}
	if ( error || ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}

	const recheck = async () => {
		if ( ! plan.selection ) {
			return;
		}
		setBusy( true );
		setPlanError( null );
		const key = resolutionKey( item );
		try {
			const next = await api.createPlan( {
				...plan.selection,
				resolutions: {
					...( plan.selection.resolutions ?? {} ),
					[ key ]: {
						...( plan.selection.resolutions?.[ key ] ?? {} ),
						...choice,
					},
				},
			} );
			navigate( { name: 'preview', id: next.id }, true );
		} catch ( e: unknown ) {
			setPlanError( e );
			setBusy( false );
		}
	};

	return (
		<div className="su-conflicts">
			<p>
				{ __(
					'These parts were changed again after the change you are undoing. Choose what to keep for each part; everything else keeps its later edits.',
					'selective-undo'
				) }
			</p>
			<ol className="su-conflicts__list">
				{ data.items.map( ( conflict ) => (
					<li key={ conflict.index } className="su-conflict">
						<p className="su-conflict__label">
							{ sprintf(
								/* translators: %s: block name, e.g. "paragraph". */
								__( 'Block: %s', 'selective-undo' ),
								conflict.label
							) }
						</p>
						<div className="su-conflict__sides">
							<Side
								title={ __( 'Now', 'selective-undo' ) }
								text={ conflict.current_text }
							/>
							<Side
								title={ __( 'Comes back', 'selective-undo' ) }
								text={ conflict.restored_text }
							/>
						</div>
						<RadioControl
							label={ __( 'Keep', 'selective-undo' ) }
							selected={ choice[ conflict.index ] ?? 'current' }
							options={ [
								{
									label: __(
										'The current text',
										'selective-undo'
									),
									value: 'current',
								},
								{
									label: __(
										'The earlier text',
										'selective-undo'
									),
									value: 'restored',
								},
							] }
							onChange={ ( side ) =>
								setChoice( ( prev ) => ( {
									...prev,
									[ conflict.index ]: side as ConflictSide,
								} ) )
							}
						/>
					</li>
				) ) }
			</ol>
			{ planError !== null && <ErrorNotice error={ planError } /> }
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || ! plan.selection }
				accessibleWhenDisabled
				onClick={ recheck }
			>
				{ __( 'Check with this choice', 'selective-undo' ) }
			</Button>
		</div>
	);
}
