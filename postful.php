<?php
/**
 * Plugin Name:       Postful
 * Plugin URI:        https://github.com/00pollock/postful
 * Description:       Structured, multilingual content types — daily devotionals, sequences, events — with hand-written translations side by side.
 * Version:           0.3.1
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            George Okanga
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postful
 */

defined( 'ABSPATH' ) || exit;

define( 'POSTFUL_VERSION', '0.3.1' );
define( 'POSTFUL_FILE', __FILE__ );
define( 'POSTFUL_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTFUL_URL', plugin_dir_url( __FILE__ ) );

// Auto-updates via GitHub releases
require_once POSTFUL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$postful_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/00pollock/postful/',
	__FILE__,
	'postful'
);
$postful_updater->getVcsApi()->enableReleaseAssets();

// Autoloader: Postful\Some_Class → includes/class-some-class.php
spl_autoload_register( function ( string $fqcn ) {
	if ( ! str_starts_with( $fqcn, 'Postful\\' ) ) {
		return;
	}
	$class = substr( $fqcn, strlen( 'Postful\\' ) );
	$file  = POSTFUL_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, function () {
	try {
		Postful\Types::register_all();
		flush_rewrite_rules();
	} catch ( \Throwable $e ) {
		wp_die( esc_html( 'Postful activation failed: ' . $e->getMessage() ) );
	}
} );

// Deactivation never touches content — only rewrite rules.
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action( 'plugins_loaded', function () {
	try {
		Postful\Plugin::boot();
	} catch ( \Throwable $e ) {
		add_action( 'admin_notices', function () use ( $e ) {
			echo '<div class="notice notice-error"><p><strong>Postful:</strong> ' . esc_html( $e->getMessage() ) . '</p></div>';
		} );
	}
} );
