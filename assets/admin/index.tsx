import { createRoot } from '@wordpress/element';
import { App } from './App';
import './styles/admin.scss';

const root = document.getElementById( 'selective-undo-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
