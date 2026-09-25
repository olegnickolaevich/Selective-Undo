<?php
/**
 * Plugin Name:       Selective Undo
 * Plugin URI:        https://github.com/olegnickolaevich/Selective-Undo
 * Description:       Undo a specific content change and keep everything else. Selective restore of supported post fields with conflict checks.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Selective Undo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       selective-undo
 * Domain Path:       /languages
 *
 * @package SelectiveUndo
 */

// This file must stay parseable by old PHP versions so that the requirements
// notice can be shown instead of a fatal error.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELECTIVE_UNDO_VERSION', '1.0.0' );
define( 'SELECTIVE_UNDO_API_VERSION', '1.0' );
define( 'SELECTIVE_UNDO_FILE', __FILE__ );
define( 'SELECTIVE_UNDO_DIR', __DIR__ );

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Selective Undo requires PHP 8.2 or newer. The plugin is inactive.', 'selective-undo' );
			echo '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( 'SelectiveUndo\\Bootstrap\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SelectiveUndo\\Bootstrap\\Plugin', 'deactivate' ) );

SelectiveUndo\Bootstrap\Plugin::boot();

if ( ! function_exists( 'selective_undo' ) ) {
	/**
	 * Public entry point for integrations.
	 *
	 * @return SelectiveUndo\Api
	 */
	function selective_undo() {
		return SelectiveUndo\Bootstrap\Plugin::api();
	}
}
