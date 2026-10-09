<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component's hooks. Admin-only pieces load inside is_admin().
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', [ Types::class, 'register_all' ] );
		add_action( 'init', [ self::class, 'maybe_flush_rewrites' ], 99 );

		Index::hooks();
		Shortcodes::hooks();
		Rest::hooks();

		if ( is_admin() ) {
			Settings::hooks();
			Admin::hooks();
			Editor::hooks();
			List_Screen::hooks();
			Importer::hooks();
		}
	}

	/**
	 * Flush once after a plugin update or after a type's URL settings change.
	 * Never on every request.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( get_option( 'postful_version' ) !== POSTFUL_VERSION || get_option( 'postful_flush_rewrites' ) ) {
			flush_rewrite_rules();
			update_option( 'postful_version', POSTFUL_VERSION );
			delete_option( 'postful_flush_rewrites' );
		}
	}

	/**
	 * Inline SVG used for the admin menu icon (no external assets).
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path d="M5 2.5h7.2L16 6.3V16a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 4 16V4a1.5 1.5 0 0 1 1-1.5Zm6.5 1.3V7h3.2L11.5 3.8ZM6.5 10.2v1.3h7v-1.3h-7Zm0 2.8v1.3h4.6V13H6.5Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
