<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * [sp_current] [sp_index] [sp_nav] — each takes type="…".
 *
 * Shortcodes for the same type on one page work together through front.js:
 * the index and prev/next swap the current entry in place without changing
 * the address bar.
 */
final class Shortcodes {

	/** Types used on this page; their JSON data is printed once in the footer. */
	private static array $used = [];

	public static function hooks(): void {
		add_shortcode( 'sp_current', [ self::class, 'current' ] );
		add_shortcode( 'sp_index', [ self::class, 'index' ] );
		add_shortcode( 'sp_nav', [ self::class, 'nav' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
		add_action( 'wp_footer', [ self::class, 'print_data' ], 5 );
	}

	public static function register_assets(): void {
		wp_register_style( 'postful', POSTFUL_URL . 'assets/front.css', [], POSTFUL_VERSION );
		wp_register_script( 'postful', POSTFUL_URL . 'assets/front.js', [], POSTFUL_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );

		// Load styles in <head> when we can tell the page uses a shortcode, to avoid a flash.
		$post = get_post();
		if ( is_singular() && $post && preg_match( '/\[sp_(current|index|nav)\b/', $post->post_content ) ) {
			self::enqueue();
		}
	}

	private static function enqueue(): void {
		if ( ! wp_script_is( 'postful', 'registered' ) ) {
			self::register_assets();
		}
		if ( Settings::get( 'front_styles' ) ) {
			wp_enqueue_style( 'postful' );
		}
		wp_enqueue_script( 'postful' );
	}

	/** Resolve the type attribute, or explain the problem to editors only. */
	private static function type( $atts, string $tag ) {
		$atts = shortcode_atts( [ 'type' => '' ], $atts, $tag );
		$type = Types::get( sanitize_key( $atts['type'] ) );
		if ( ! $type ) {
			return current_user_can( 'edit_posts' )
				/* translators: 1: shortcode name, 2: type key */
				? '<p class="postful-notice">' . esc_html( sprintf( __( 'Postful: [%1$s] needs a valid type, e.g. type="%2$s".', 'postful' ), $tag, array_key_first( Types::all() ) ?: 'devotional' ) ) . '</p>'
				: '';
		}
		self::enqueue();
		return $type;
	}

	private static function open( array $type, string $role, string $tag = 'div', string $extra = '' ): string {
		return sprintf(
			'<%1$s class="postful pf-%2$s notranslate" translate="no" data-pf-type="%3$s" data-pf-role="%2$s" data-pf-lang="%4$s"%5$s>',
			$tag,
			esc_attr( $role ),
			esc_attr( $type['key'] ),
			esc_attr( $type['default_lang'] ),
			$extra
		);
	}

	/** Remember the type; output happens in the footer (see print_data). */
	private static function data( array $type ): string {
		self::$used[ $type['key'] ] = true;
		return '';
	}

	/**
	 * Printed from wp_footer rather than inside the shortcode, so content
	 * rendered and thrown away earlier (SEO plugins, excerpts) can't swallow it.
	 */
	public static function print_data(): void {
		foreach ( array_keys( self::$used ) as $key ) {
			$type = Types::get( $key );
			if ( $type ) {
				echo self::data_script( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HEX_TAG, attribute escaped
			}
		}
	}

	/** Everything front.js needs for a type. */
	private static function data_script( array $type ): string {
		$codes   = Types::lang_codes( $type );
		$strings = [];
		foreach ( $codes as $code ) {
			$strings[ $code ] = Languages::strings( $type, $code );
		}
		$data = [
			'type'     => $type['key'],
			'rest'     => esc_url_raw( rest_url( 'postful/v1/entry' ) ),
			'langs'    => $codes,
			'def'      => $type['default_lang'],
			'entries'  => Index::get( $type['key'] ),
			'today'    => Presets::get( $type['preset'] )->current_sort(),
			'tz'       => wp_timezone_string(),
			'siteLang' => Languages::code( get_locale() ),
			'override' => (bool) Settings::get( 'lang_override' ),
			'strings'  => $strings,
		];
		return sprintf(
			'<script type="application/json" class="pf-data" data-pf-type="%s">%s</script>',
			esc_attr( $type['key'] ),
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE )
		);
	}

	public static function current( $atts ): string {
		$type = self::type( $atts, 'sp_current' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		$preset = Presets::get( $type['preset'] );
		$sort   = (int) $preset->current_sort();
		$id     = Index::find( $type['key'], $sort );
		$inner  = $id ? Renderer::entry( $type, $id ) : Renderer::empty_state( $type, $preset->key_from_sort( $sort ) );

		return self::open( $type, 'current', 'div', ' aria-live="polite"' ) . $inner . '</div>' . self::data( $type );
	}

	public static function index( $atts ): string {
		$type = self::type( $atts, 'sp_index' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		$preset  = Presets::get( $type['preset'] );
		$today   = (int) $preset->current_sort();
		$entries = Index::get( $type['key'] );
		$locales = Types::locales( $type );
		$title   = Types::title_field( $type );

		update_meta_cache( 'post', array_column( $entries, 1 ) );

		$out = self::open( $type, 'index' );
		$out .= '<h2 class="pf-index-title">' . Renderer::per_lang( $type, fn( $c ) => esc_html( Languages::strings( $type, $c )['index'] ) ) . '</h2>';

		if ( ! $entries ) {
			$out .= '<p class="pf-message">' . Renderer::per_lang( $type, fn( $c ) => esc_html( Languages::strings( $type, $c )['empty'] ) ) . '</p>';
			return $out . '</div>' . self::data( $type );
		}

		$by_month = [];
		foreach ( $entries as [ $sort, $id ] ) {
			$by_month[ intdiv( $sort, 100 ) ][] = [ $sort, $id ];
		}

		foreach ( $by_month as $month => $items ) {
			$open = intdiv( $today, 100 ) === $month;
			$out .= sprintf( '<details class="pf-month" data-pf-month="%d"%s>', $month, $open ? ' open' : '' );
			$out .= '<summary><span class="pf-month-name">' . Renderer::per_lang( $type, fn( $c ) => esc_html( Languages::month( $month, $c, $locales[ $c ] ) ) ) . '</span>';
			$out .= '<span class="pf-month-count">' . count( $items ) . '</span></summary><ol class="pf-days">';

			foreach ( $items as [ $sort, $id ] ) {
				$heading = $title
					? Renderer::per_lang( $type, fn( $c ) => esc_html( Renderer::display_value( $type, $id, $title, $c ) ) )
					: '';
				$label   = Renderer::per_lang( $type, fn( $c ) => esc_html( $preset->key_label( $preset->key_from_sort( $sort ), $c, $locales[ $c ] ) ) );
				$out    .= sprintf(
					'<li><button type="button" class="pf-day%1$s" data-pf-sort="%2$d"%3$s><span class="pf-day-num">%4$d</span><span class="pf-day-text"><span class="pf-day-label">%5$s</span><span class="pf-day-title">%6$s</span></span></button></li>',
					$sort === $today ? ' is-current' : '',
					$sort,
					$sort === $today ? ' aria-current="true"' : '',
					$sort % 100,
					$label,
					$heading
				);
			}
			$out .= '</ol></details>';
		}

		return $out . '</div>' . self::data( $type );
	}

	public static function nav( $atts ): string {
		$type = self::type( $atts, 'sp_nav' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		$label = fn( $k ) => Renderer::per_lang( $type, fn( $c ) => esc_html( Languages::strings( $type, $c )[ $k ] ) );

		$out  = self::open( $type, 'nav', 'nav', ' aria-label="' . esc_attr( $type['plural'] ) . '"' );
		$out .= '<button type="button" class="pf-nav-btn pf-nav-prev" data-pf-go="prev"><span aria-hidden="true">&larr;</span> ' . $label( 'prev' ) . '</button>';
		$out .= '<button type="button" class="pf-nav-btn pf-nav-today" data-pf-go="today">' . $label( 'today' ) . '</button>';
		$out .= '<button type="button" class="pf-nav-btn pf-nav-next" data-pf-go="next">' . $label( 'next' ) . ' <span aria-hidden="true">&rarr;</span></button>';
		return $out . '</nav>' . self::data( $type );
	}
}
