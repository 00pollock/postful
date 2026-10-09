<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings (Settings API, single option).
 */
final class Settings {

	public const OPTION = 'postful_settings';

	public static function defaults(): array {
		return [
			'front_styles'        => true,
			'lang_override'       => false,
			'delete_on_uninstall' => false,
		];
	}

	public static function get( string $key ) {
		$all = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );
		return $all[ $key ] ?? null;
	}

	public static function hooks(): void {
		add_action( 'admin_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_setting( 'postful_settings', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize' ],
			'default'           => self::defaults(),
		] );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$out   = [];
		foreach ( array_keys( self::defaults() ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}
		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = [
			'front_styles'        => [
				__( 'Default styles', 'postful' ),
				__( 'Load Postful’s light reading styles on the front end. Turn off to style everything from your theme.', 'postful' ),
			],
			'lang_override'       => [
				__( 'Language preview', 'postful' ),
				__( 'Let editors preview a language by adding ?lang=fr to the page address. Read-only and never remembered. Visitors are unaffected.', 'postful' ),
			],
			'delete_on_uninstall' => [
				__( 'Delete data on uninstall', 'postful' ),
				__( 'When the plugin is deleted, also delete every entry, content type and setting. Deactivating never deletes anything.', 'postful' ),
			],
		];
		?>
		<div class="wrap pf-wrap">
			<header class="pf-page-head">
				<div>
					<h1 class="pf-title"><?php esc_html_e( 'Settings', 'postful' ); ?></h1>
					<p class="pf-sub"><?php esc_html_e( 'These apply to every content type.', 'postful' ); ?></p>
				</div>
			</header>
			<?php settings_errors(); ?>
			<form method="post" action="options.php" class="pf-panel">
				<?php settings_fields( 'postful_settings' ); ?>
				<?php foreach ( $rows as $key => [ $label, $help ] ) : ?>
					<label class="pf-setting">
						<span class="pf-setting-text">
							<span class="pf-setting-label"><?php echo esc_html( $label ); ?></span>
							<span class="pf-setting-help"><?php echo esc_html( $help ); ?></span>
						</span>
						<span class="pf-switch">
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) self::get( $key ) ); ?>>
							<span class="pf-switch-track" aria-hidden="true"></span>
						</span>
					</label>
				<?php endforeach; ?>
				<div class="pf-panel-foot">
					<button type="submit" class="pf-btn pf-btn--primary"><?php esc_html_e( 'Save settings', 'postful' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}
}
