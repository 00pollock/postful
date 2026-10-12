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
		add_shortcode( 'postful', [ self::class, 'layout' ] );
		add_shortcode( 'sp_current', [ self::class, 'current' ] );
		add_shortcode( 'sp_index', [ self::class, 'index' ] );
		add_shortcode( 'sp_nav', [ self::class, 'nav' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
		add_action( 'wp_footer', [ self::class, 'print_data' ], 5 );
	}

	public static function register_assets(): void {
		wp_register_style( 'postful', POSTFUL_URL . 'assets/front.css', [], Plugin::ver( 'front.css' ) );
		wp_register_script( 'postful', POSTFUL_URL . 'assets/front.js', [], Plugin::ver( 'front.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );

		// Load styles in <head> when we can tell the page uses a shortcode, to avoid a flash.
		$post = get_post();
		if ( is_singular() && $post && preg_match( '/\[(postful|sp_current|sp_index|sp_nav)\b/', $post->post_content ) ) {
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
			'<%1$s class="postful pf-%2$s notranslate" translate="no" data-pf-type="%3$s" data-pf-role="%2$s" data-pf-lang="%4$s"%5$s%6$s>',
			$tag,
			esc_attr( $role ),
			esc_attr( $type['key'] ),
			esc_attr( $type['default_lang'] ),
			$extra,
			self::style_attr( $type )
		);
	}

	/**
	 * The type's Style settings as CSS custom properties, plus classes saying
	 * which colours were chosen (unset colours follow the theme's own link colour).
	 */
	private static function style_attr( array $type ): string {
		$vars    = Types::style_vars( $type );
		$classes = [];
		foreach ( [ 'accent' => 'pf-has-accent', 'nav_color' => 'pf-has-nav-color' ] as $key => $class ) {
			if ( ! empty( $type['style'][ $key ] ) ) {
				$classes[] = $class;
			}
		}
		return ( $vars ? ' style="' . esc_attr( $vars ) . '"' : '' ) . ( $classes ? ' data-pf-colors="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' );
	}

	/** This page's address for a day (?mn=&dn=), or the plain address (today) for 0. */
	private static function day_url( int $sort ): string {
		$base = remove_query_arg( [ 'mn', 'dn' ] );
		return $sort ? add_query_arg( [ 'mn' => intdiv( $sort, 100 ), 'dn' => $sort % 100 ], $base ) : $base;
	}

	/** The published day before / after $sort, wrapping around the year. */
	private static function neighbour( array $sorts, int $sort, int $dir ): int {
		if ( ! $sorts ) {
			return 0;
		}
		if ( $dir > 0 ) {
			foreach ( $sorts as $s ) {
				if ( $s > $sort ) {
					return $s;
				}
			}
			return $sorts[0];
		}
		foreach ( array_reverse( $sorts ) as $s ) {
			if ( $s < $sort ) {
				return $s;
			}
		}
		return end( $sorts );
	}

	/** Does the current page use [postful] or [sp_index] (with its links) for this type? */
	private static function page_has_index_nav( string $key ): bool {
		$post = get_post();
		if ( ! $post || ! preg_match_all( '/\[(postful|sp_index)\b([^\]]*)\]/', $post->post_content, $found, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $found as [ , $tag, $attr_text ] ) {
			$attrs = shortcode_parse_atts( $attr_text );
			$attrs = is_array( $attrs ) ? $attrs : [];
			if ( sanitize_key( $attrs['type'] ?? '' ) === $key && ( 'postful' === $tag || 'no' !== strtolower( (string) ( $attrs['nav'] ?? 'yes' ) ) ) ) {
				return true;
			}
		}
		return false;
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
			// The page was opened on a specific day (?mn=&dn=): don't jump to today.
			'linked'   => null !== Presets::get( $type['preset'] )->requested_sort(),
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

	/**
	 * [postful type="…" layout="…"] — the whole reading experience in one
	 * shortcode, arranged by the type's layout (overridable per page).
	 */
	public static function layout( $atts ): string {
		$atts = shortcode_atts( [ 'type' => '', 'layout' => '' ], $atts, 'postful' );
		$type = self::type( [ 'type' => $atts['type'] ], 'postful' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		// The devotional layout, like AWMI. A two-row grid so the title row and
		// Previous / Today / Next line up:   [ title · date ][ links ]
		//                                    [ devotional   ][ month list ]
		// On phones it stacks: title, devotional, links, month list.
		$args = [ 'type' => $type['key'] ];
		return sprintf(
			'<div class="postful-wrap"><div class="postful-layout"%s><div class="pf-area-head">%s</div><div class="pf-area-nav">%s</div><div class="pf-area-body">%s</div><div class="pf-area-index">%s</div></div></div>',
			self::style_attr( $type ),
			self::current( $args + [ 'part' => 'head' ] ),
			self::nav( $args + [ 'internal' => true ] ),
			self::current( $args + [ 'part' => 'body' ] ),
			self::index( $args + [ 'nav' => 'no' ] )
		);
	}

	public static function current( $atts ): string {
		$type = self::type( $atts, 'sp_current' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		$part   = in_array( $atts['part'] ?? '', [ 'head', 'body' ], true ) ? $atts['part'] : 'all';
		$preset = Presets::get( $type['preset'] );
		$sort   = (int) ( $preset->requested_sort() ?? $preset->current_sort() );
		$id     = Index::find( $type['key'], $sort );
		$inner  = $id ? Renderer::entry( $type, $id, $part ) : Renderer::empty_state( $type, $preset->key_from_sort( $sort ), 'empty', $part );
		// data-pf-part tells front.js which part of a split entry this container shows.
		$extra = ( 'head' === $part ? '' : ' aria-live="polite"' ) . ( 'all' !== $part ? ' data-pf-part="' . esc_attr( $part ) . '"' : '' );
		$open  = self::open( $type, 'current', 'div', $extra );
		if ( 'all' !== $part ) {
			$open = str_replace( 'class="postful pf-current ', 'class="postful pf-current pf-current--' . $part . ' ', $open );
		}
		return $open . $inner . '</div>' . self::data( $type );
	}

	/**
	 * [sp_index] — the month list, with Previous / Today / Next above it.
	 * nav="no" leaves the links out (e.g. when placing [sp_nav] elsewhere).
	 */
	public static function index( $atts ): string {
		$type = self::type( $atts, 'sp_index' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		$with_nav = 'no' !== strtolower( (string) ( $atts['nav'] ?? 'yes' ) );
		return ( $with_nav ? self::nav( [ 'type' => $type['key'], 'internal' => true ] ) : '' ) . self::month_list( $type );
	}

	private static function month_list( array $type ): string {
		$preset  = Presets::get( $type['preset'] );
		$today   = (int) ( $preset->requested_sort() ?? $preset->current_sort() ); // The day shown: linked or today.
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
			$out .= sprintf( '<details class="pf-month" name="pf-months-%s" data-pf-month="%d"%s>', esc_attr( $type['key'] ), $month, $open ? ' open' : '' );
			$out .= '<summary><span class="pf-month-name">' . Renderer::per_lang( $type, fn( $c ) => esc_html( mb_convert_case( Languages::month( $month, $c, $locales[ $c ] ), MB_CASE_TITLE, 'UTF-8' ) ) ) . '</span>';
			$out .= '</summary><ol class="pf-days">';

			foreach ( $items as [ $sort, $id ] ) {
				$heading = $title
					? Renderer::per_lang( $type, fn( $c ) => esc_html( Renderer::display_value( $type, $id, $title, $c ) ) )
					: '';
				$label   = Renderer::per_lang( $type, fn( $c ) => esc_html( $preset->key_label( $preset->key_from_sort( $sort ), $c, $locales[ $c ] ) ) );
				$out    .= sprintf(
					'<li><a class="pf-day%1$s" href="%7$s" data-pf-sort="%2$d"%3$s><span class="pf-day-num">%4$d</span><span class="pf-day-text"><span class="pf-day-label">%5$s</span><span class="pf-day-title">%6$s</span></span></a></li>',
					$sort === $today ? ' is-current' : '',
					$sort,
					$sort === $today ? ' aria-current="true"' : '',
					$sort % 100,
					$label,
					$heading,
					esc_url( self::day_url( $sort ) )
				);
			}
			$out .= '</ol></details>';
		}

		return $out . '</div>' . self::data( $type );
	}

	public static function nav( $atts ): string {
		$compact = 'compact' === ( $atts['style'] ?? '' );
		$type    = self::type( $atts, 'sp_nav' );
		if ( ! is_array( $type ) ) {
			return $type;
		}
		// A standalone [sp_nav] steps aside when the page's month list already
		// shows the links, so they never appear twice.
		if ( empty( $atts['internal'] ) && self::page_has_index_nav( $type['key'] ) ) {
			return '';
		}
		$label = fn( $k ) => Renderer::per_lang( $type, fn( $c ) => esc_html( Languages::strings( $type, $c )[ $k ] ) );

		$out  = self::open( $type, 'nav', 'nav', ' aria-label="' . esc_attr( $type['plural'] ) . '"' );
		if ( $compact ) {
			$out = str_replace( 'class="postful pf-nav ', 'class="postful pf-nav pf-nav--compact ', $out );
		}
		// Real links (so the theme's link colour applies and they can be copied or
		// opened in a new tab); front.js swaps the day in place on a normal click.
		$preset  = Presets::get( $type['preset'] );
		$shown   = (int) ( $preset->requested_sort() ?? $preset->current_sort() );
		$sorts   = array_column( Index::get( $type['key'] ), 0 );
		$prev    = self::neighbour( $sorts, $shown, -1 );
		$next    = self::neighbour( $sorts, $shown, 1 );
		$out .= '<a class="pf-nav-btn pf-nav-prev" data-pf-go="prev" href="' . esc_url( $prev ? self::day_url( $prev ) : self::day_url( 0 ) ) . '">' . $label( 'prev' ) . '</a>';
		$out .= '<a class="pf-nav-btn pf-nav-today" data-pf-go="today" href="' . esc_url( self::day_url( 0 ) ) . '">' . $label( 'today' ) . '</a>';
		$out .= '<a class="pf-nav-btn pf-nav-next" data-pf-go="next" href="' . esc_url( $next ? self::day_url( $next ) : self::day_url( 0 ) ) . '">' . $label( 'next' ) . '</a>';
		return $out . '</nav>' . self::data( $type );
	}
}
