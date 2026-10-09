<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Content type registry. Each type is a config record in one option and is
 * registered as a post type at init. Users never touch register_post_type.
 */
final class Types {

	public const OPTION = 'postful_types';

	private static ?array $cache = null;

	public static function defaults(): array {
		return [
			'key'          => '',
			'plural'       => '',
			'singular'     => '',
			'preset'       => 'recurring_day',
			'icon'         => 'dashicons-book-alt',
			'slug'         => '',
			'public_urls'  => false,
			'languages'    => [ 'en_US' ],
			'default_lang' => 'en',
			'fallback'     => 'field',
			'fields'       => [],
			'template'     => '',
			'strings'      => [],
			'layout'       => 'sidebar_right',
			'nav_place'    => 'sidebar',
			'index_place'  => 'sidebar',
			'style'        => [],
		];
	}

	/**
	 * Style panel controls, grouped. Each maps to a CSS custom property read by
	 * front.css; an empty value means "use the theme / default".
	 * kind: size (px), number (unitless), color (hex), or an options array.
	 */
	public static function style_controls(): array {
		$size  = 'size';
		$color = 'color';
		return [
			'page'      => [ __( 'Page', 'postful' ), [
				'width'       => [ __( 'Maximum width', 'postful' ), $size, '--pf-width' ],
				'gap'         => [ __( 'Space between columns', 'postful' ), $size, '--pf-gap' ],
				'aside_width' => [ __( 'Sidebar width', 'postful' ), $size, '--pf-aside-width' ],
				'text_size'   => [ __( 'Text size', 'postful' ), $size, '--pf-text-size' ],
				'text_color'  => [ __( 'Text colour', 'postful' ), $color, '--pf-text' ],
				'accent'      => [ __( 'Link colour', 'postful' ), $color, '--pf-accent' ],
			] ],
			'title'     => [ __( 'Title', 'postful' ), [
				'title_size'   => [ __( 'Size', 'postful' ), $size, '--pf-title-size' ],
				'title_weight' => [ __( 'Weight', 'postful' ), [ '400' => __( 'Regular', 'postful' ), '500' => __( 'Medium', 'postful' ), '600' => __( 'Semibold', 'postful' ), '700' => __( 'Bold', 'postful' ) ], '--pf-title-weight' ],
				'title_color'  => [ __( 'Colour', 'postful' ), $color, '--pf-title-color' ],
			] ],
			'date'      => [ __( 'Day', 'postful' ), [
				'date_size'  => [ __( 'Size', 'postful' ), $size, '--pf-date-size' ],
				'date_style' => [ __( 'Style', 'postful' ), [ 'italic' => __( 'Italic', 'postful' ), 'normal' => __( 'Upright', 'postful' ) ], '--pf-date-style' ],
				'date_color' => [ __( 'Colour', 'postful' ), $color, '--pf-date-color' ],
			] ],
			'highlight' => [ __( 'Highlight fields', 'postful' ), [
				'hl_size'  => [ __( 'Size', 'postful' ), $size, '--pf-hl-size' ],
				'hl_style' => [ __( 'Style', 'postful' ), [ 'italic' => __( 'Italic', 'postful' ), 'normal' => __( 'Upright', 'postful' ) ], '--pf-hl-style' ],
				'hl_align' => [ __( 'Alignment', 'postful' ), [ 'center' => __( 'Centre', 'postful' ), 'left' => __( 'Left', 'postful' ) ], '--pf-hl-align' ],
			] ],
			'label'     => [ __( 'Label fields', 'postful' ), [
				'label_size'  => [ __( 'Size', 'postful' ), $size, '--pf-label-size' ],
				'label_case'  => [ __( 'Letters', 'postful' ), [ 'uppercase' => __( 'CAPITALS', 'postful' ), 'none' => __( 'As typed', 'postful' ) ], '--pf-label-case' ],
				'label_align' => [ __( 'Alignment', 'postful' ), [ 'center' => __( 'Centre', 'postful' ), 'left' => __( 'Left', 'postful' ) ], '--pf-label-align' ],
			] ],
			'body'      => [ __( 'Body fields', 'postful' ), [
				'body_size' => [ __( 'Size', 'postful' ), $size, '--pf-body-size' ],
				'body_line' => [ __( 'Line spacing', 'postful' ), 'number', '--pf-body-line' ],
			] ],
			'spacing'   => [ __( 'Entry spacing', 'postful' ), [
				'field_gap' => [ __( 'Space between fields', 'postful' ), $size, '--pf-field-gap' ],
				'entry_pad' => [ __( 'Inner padding', 'postful' ), $size, '--pf-entry-pad' ],
				'entry_bg'  => [ __( 'Background', 'postful' ), $color, '--pf-entry-bg' ],
			] ],
			'sidebar'   => [ __( 'Sidebar', 'postful' ), [
				'aside_pad' => [ __( 'Inner padding', 'postful' ), $size, '--pf-aside-pad' ],
				'aside_bg'  => [ __( 'Background', 'postful' ), $color, '--pf-aside-bg' ],
			] ],
			'nav'       => [ __( 'Previous / Today / Next', 'postful' ), [
				'nav_size'  => [ __( 'Size', 'postful' ), $size, '--pf-nav-size' ],
				'nav_color' => [ __( 'Colour', 'postful' ), $color, '--pf-nav-color' ],
				'nav_align' => [ __( 'Alignment', 'postful' ), [ 'center' => __( 'Centre', 'postful' ), 'space-between' => __( 'Spread', 'postful' ), 'flex-start' => __( 'Left', 'postful' ) ], '--pf-nav-align' ],
			] ],
			'index'     => [ __( 'Month list', 'postful' ), [
				'month_size' => [ __( 'Month size', 'postful' ), $size, '--pf-month-size' ],
				'day_size'   => [ __( 'Day size', 'postful' ), $size, '--pf-day-size' ],
				'index_line' => [ __( 'Border colour', 'postful' ), $color, '--pf-index-line' ],
			] ],
		];
	}

	public static function sanitize_style( $in ): array {
		$out = [];
		foreach ( self::style_controls() as [ , $controls ] ) {
			foreach ( $controls as $key => [ , $kind ] ) {
				$v = trim( (string) ( is_array( $in ) ? ( $in[ $key ] ?? '' ) : '' ) );
				if ( '' === $v ) {
					continue;
				}
				if ( 'size' === $kind && is_numeric( $v ) && $v >= 0 && $v <= 2000 ) {
					$out[ $key ] = (string) ( 0 + $v );
				} elseif ( 'number' === $kind && is_numeric( $v ) && $v > 0 && $v <= 5 ) {
					$out[ $key ] = (string) ( 0 + $v );
				} elseif ( 'color' === $kind && sanitize_hex_color( $v ) ) {
					$out[ $key ] = sanitize_hex_color( $v );
				} elseif ( is_array( $kind ) && isset( $kind[ $v ] ) ) {
					$out[ $key ] = $v;
				}
			}
		}
		return $out;
	}

	/** Inline custom properties for a type's front-end wrappers. */
	public static function style_vars( array $type ): string {
		$css = '';
		foreach ( self::style_controls() as [ , $controls ] ) {
			foreach ( $controls as $key => [ , $kind, $var ] ) {
				if ( isset( $type['style'][ $key ] ) ) {
					$css .= $var . ':' . $type['style'][ $key ] . ( 'size' === $kind ? 'px' : '' ) . ';';
				}
			}
		}
		return $css;
	}

	public static function has_sidebar( string $layout ): bool {
		return in_array( $layout, [ 'sidebar_right', 'sidebar_left' ], true );
	}

	/** Where previous/next and the month list can go. */
	public static function places(): array {
		return [
			'nav'   => [ 'above' => __( 'Above entry', 'postful' ), 'below' => __( 'Below entry', 'postful' ), 'sidebar' => __( 'Sidebar', 'postful' ) ],
			'index' => [ 'below' => __( 'Below entry', 'postful' ), 'sidebar' => __( 'Sidebar', 'postful' ), 'hidden' => __( 'Hidden', 'postful' ) ],
		];
	}

	/** Devotionals → Devotional, Stories → Story, Classes → Class. Editable in the wizard. */
	public static function singular_of( string $plural ): string {
		$w = trim( $plural );
		foreach ( [ '/ies$/i' => 'y', '/(ss|x|ch|sh|zz)es$/i' => '$1', '/([^s])s$/i' => '$1' ] as $re => $to ) {
			if ( preg_match( $re, $w ) ) {
				return preg_replace( $re, $to, $w );
			}
		}
		return $w;
	}

	public static function layouts(): array {
		return [
			'sidebar_right' => [ __( 'Sidebar right', 'postful' ), __( 'Entry on the left; previous/next and the month list beside it.', 'postful' ) ],
			'sidebar_left'  => [ __( 'Sidebar left', 'postful' ), __( 'The same, with navigation on the left.', 'postful' ) ],
			'stacked'       => [ __( 'Stacked', 'postful' ), __( 'Entry, then previous/next, then the month list below. Suits narrow themes.', 'postful' ) ],
			'entry_only'    => [ __( 'Entry only', 'postful' ), __( 'Just the current entry with small previous/next links.', 'postful' ) ],
		];
	}

	/** How a field is presented on the page. */
	public static function display_styles(): array {
		return [
			'title'     => __( 'Title', 'postful' ),
			'highlight' => __( 'Highlight', 'postful' ),
			'label'     => __( 'Label', 'postful' ),
			'body'      => __( 'Body', 'postful' ),
			'plain'     => __( 'Plain', 'postful' ),
		];
	}

	/** Sensible style for a field type when none was chosen. */
	public static function default_display( string $field_type, bool $first_text ): string {
		if ( 'text' === $field_type && $first_text ) {
			return 'title';
		}
		return in_array( $field_type, [ 'richtext', 'textarea' ], true ) ? 'body' : 'plain';
	}

	/** @return array<string,array> keyed by type key */
	public static function all(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, [] );
			self::$cache = [];
			foreach ( is_array( $raw ) ? $raw : [] as $key => $config ) {
				if ( is_array( $config ) ) {
					self::$cache[ $key ] = wp_parse_args( $config, self::defaults() );
				}
			}
		}
		return self::$cache;
	}

	public static function get( ?string $key ): ?array {
		if ( ! $key ) {
			return null;
		}
		return self::all()[ $key ] ?? null;
	}

	public static function save( array $config ): void {
		$all                    = get_option( self::OPTION, [] );
		$all                    = is_array( $all ) ? $all : [];
		$previous               = $all[ $config['key'] ] ?? null;
		$all[ $config['key'] ]  = $config;
		update_option( self::OPTION, $all, true );
		self::$cache = null;

		if ( ! $previous || $previous['public_urls'] !== $config['public_urls'] || $previous['slug'] !== $config['slug'] ) {
			update_option( 'postful_flush_rewrites', 1 );
		}
		Index::flush( $config['key'] );
	}

	/** Removes the config only. Entries stay in the database. */
	public static function delete( string $key ): void {
		$all = get_option( self::OPTION, [] );
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, true );
		self::$cache = null;
		update_option( 'postful_flush_rewrites', 1 );
		Index::flush( $key );
	}

	/** Language codes in configured order, default first. */
	public static function lang_codes( array $type ): array {
		$codes = array_values( array_unique( array_map( [ Languages::class, 'code' ], $type['languages'] ) ) );
		usort( $codes, fn( $a, $b ) => ( $b === $type['default_lang'] ) <=> ( $a === $type['default_lang'] ) );
		return $codes;
	}

	/** code → locale for this type. */
	public static function locales( array $type ): array {
		$out = [];
		foreach ( $type['languages'] as $locale ) {
			$out[ Languages::code( $locale ) ] = $locale;
		}
		return $out;
	}

	public static function is_multilingual( array $type ): bool {
		return count( self::lang_codes( $type ) ) > 1;
	}

	/** The field that feeds post_title: the first single-line field. */
	/** The field shown as the entry's heading: styled Title (explicitly or by default). */
	public static function header_field( array $type ): ?array {
		foreach ( $type['fields'] as $field ) {
			if ( 'title' === ( $field['display'] ?? '' ) ) {
				return $field;
			}
		}
		return null;
	}

	/** The field that feeds post_title: a Title-styled field, else the first single line. */
	public static function title_field( array $type ): ?array {
		foreach ( $type['fields'] as $field ) {
			if ( 'title' === ( $field['display'] ?? '' ) && 'text' === $field['type'] ) {
				return $field;
			}
		}
		foreach ( $type['fields'] as $field ) {
			if ( 'text' === $field['type'] ) {
				return $field;
			}
		}
		return null;
	}

	public static function register_all(): void {
		foreach ( self::all() as $key => $type ) {
			if ( post_type_exists( $key ) ) {
				continue;
			}
			$public   = (bool) $type['public_urls'];
			$plural   = $type['plural'] ?: $key;
			$singular = $type['singular'] ?: $plural;

			register_post_type( $key, [
				'labels'              => [
					'name'               => $plural,
					'singular_name'      => $singular,
					'menu_name'          => $plural,
					'all_items'          => $plural,
					/* translators: %s: singular content type name */
					'add_new_item'       => sprintf( __( 'New %s', 'postful' ), $singular ),
					/* translators: %s: singular content type name */
					'edit_item'          => sprintf( __( 'Edit %s', 'postful' ), $singular ),
					/* translators: %s: singular content type name */
					'new_item'           => sprintf( __( 'New %s', 'postful' ), $singular ),
					/* translators: %s: plural content type name */
					'search_items'       => sprintf( __( 'Search %s', 'postful' ), $plural ),
					/* translators: %s: plural content type name */
					'not_found'          => sprintf( __( 'No %s yet.', 'postful' ), strtolower( $plural ) ),
					/* translators: %s: plural content type name */
					'not_found_in_trash' => sprintf( __( 'No %s in the trash.', 'postful' ), strtolower( $plural ) ),
				],
				'public'              => $public,
				'publicly_queryable'  => $public,
				'exclude_from_search' => ! $public,
				'has_archive'         => $public,
				'show_ui'             => true,
				'show_in_menu'        => 'postful', // Entries live inside the Postful menu.
				'show_in_nav_menus'   => $public,
				'show_in_rest'        => false,
				'menu_icon'           => $type['icon'] ?: 'dashicons-book-alt',
				'supports'            => [ 'revisions' ],
				'rewrite'             => $public ? [ 'slug' => $type['slug'] ?: $key, 'with_front' => false ] : false,
				'query_var'           => $public,
			] );
		}

		add_filter( 'wp_sitemaps_post_types', [ self::class, 'filter_sitemaps' ] );
		add_filter( 'wp_post_revision_meta_keys', [ self::class, 'revision_meta_keys' ], 10, 2 );
	}

	/** Keep URL-less types out of sitemaps even if something else re-adds them. */
	public static function filter_sitemaps( array $types ): array {
		foreach ( self::all() as $key => $type ) {
			if ( empty( $type['public_urls'] ) ) {
				unset( $types[ $key ] );
			}
		}
		return $types;
	}

	/** Revisions keep every language's field values (WP 6.4+). */
	public static function revision_meta_keys( array $keys, string $post_type = '' ): array {
		$type = self::get( $post_type );
		if ( ! $type ) {
			return $keys;
		}
		foreach ( $type['fields'] as $field ) {
			if ( ! empty( $field['translatable'] ) ) {
				foreach ( self::lang_codes( $type ) as $code ) {
					$keys[] = Fields::meta_key( $field['key'], $code );
				}
			} else {
				$keys[] = Fields::meta_key( $field['key'] );
			}
		}
		return array_merge( $keys, Presets::get( $type['preset'] )->meta_keys() );
	}

	// ------------------------------------------------------------------
	// Validation (shared by the wizard and JSON import)
	// ------------------------------------------------------------------

	/**
	 * Turn raw input into a clean config. Returns WP_Error on anything that would
	 * produce a broken type.
	 *
	 * @param array      $in       Raw (unslashed) input.
	 * @param array|null $existing Current config when editing; locks key and existing field keys.
	 */
	public static function sanitize( array $in, ?array $existing = null ) {
		$out = self::defaults();

		// Crafted input (key[]=x, or "layout": [] in JSON) must not reach string functions.
		foreach ( [ 'plural', 'singular', 'key', 'preset', 'icon', 'slug', 'template', 'default_locale', 'fallback', 'layout', 'nav_place', 'index_place' ] as $text_key ) {
			if ( isset( $in[ $text_key ] ) && ! is_string( $in[ $text_key ] ) ) {
				$in[ $text_key ] = '';
			}
		}

		$out['plural']   = sanitize_text_field( $in['plural'] ?? '' );
		$out['singular'] = sanitize_text_field( $in['singular'] ?? '' );
		if ( '' === $out['singular'] ) {
			$out['singular'] = self::singular_of( $out['plural'] );
		}
		if ( '' === $out['plural'] ) {
			return new \WP_Error( 'name', __( 'Give the content type a name.', 'postful' ) );
		}

		if ( $existing ) {
			$out['key'] = $existing['key'];
		} else {
			$key = substr( sanitize_key( $in['key'] ?? '' ), 0, 20 );
			if ( '' === $key ) {
				$key = substr( sanitize_key( $out['singular'] ), 0, 20 );
			}
			$reserved = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'action', 'author', 'order', 'theme', 'type' ];
			global $wp;
			$query_vars = $wp instanceof \WP ? $wp->public_query_vars : [];
			if ( '' === $key || in_array( $key, $reserved, true ) || in_array( $key, $query_vars, true ) || taxonomy_exists( $key ) ) {
				return new \WP_Error( 'key', __( 'That internal key is reserved by WordPress. Choose another.', 'postful' ) );
			}
			if ( self::get( $key ) || post_type_exists( $key ) ) {
				return new \WP_Error( 'key', __( 'Another content type already uses that internal key.', 'postful' ) );
			}
			$out['key'] = $key;
		}

		$preset = Presets::get( sanitize_key( $in['preset'] ?? '' ) );
		if ( ! $preset || ! $preset->available() ) {
			return new \WP_Error( 'preset', __( 'Choose an available structure.', 'postful' ) );
		}
		$out['preset'] = $existing ? $existing['preset'] : $preset->id();

		$out['icon']        = preg_match( '/^dashicons-[a-z0-9-]+$/', (string) ( $in['icon'] ?? '' ) ) ? $in['icon'] : 'dashicons-book-alt';
		$out['slug']        = sanitize_title( $in['slug'] ?? '' );
		$out['public_urls'] = ! empty( $in['public_urls'] );

		// Languages
		$known   = Languages::common();
		$locales = [];
		$codes   = [];
		foreach ( (array) ( $in['languages'] ?? [] ) as $locale ) {
			$locale = (string) $locale;
			if ( ! isset( $known[ $locale ] ) ) {
				continue;
			}
			$code = Languages::code( $locale );
			if ( isset( $codes[ $code ] ) ) {
				return new \WP_Error( 'languages', sprintf(
					/* translators: 1, 2: language names */
					__( '%1$s and %2$s are the same language. Pick one.', 'postful' ),
					$known[ $codes[ $code ] ],
					$known[ $locale ]
				) );
			}
			$codes[ $code ] = $locale;
			$locales[]      = $locale;
		}
		if ( ! $locales ) {
			return new \WP_Error( 'languages', __( 'Choose at least one language.', 'postful' ) );
		}
		$out['languages']    = $locales;
		$default_locale      = (string) ( $in['default_locale'] ?? '' );
		$out['default_lang'] = in_array( $default_locale, $locales, true ) ? Languages::code( $default_locale ) : Languages::code( $locales[0] );
		$out['fallback']     = ( $in['fallback'] ?? '' ) === 'hide' ? 'hide' : 'field';

		// Fields
		$fields = self::sanitize_fields( (array) ( $in['fields'] ?? [] ), array_keys( $codes ), $existing );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$out['fields'] = $fields;

		$out['template'] = wp_kses_post( (string) ( $in['template'] ?? '' ) );
		$str                = fn( $v ) => is_string( $v ) ? $v : '';
		$out['layout']      = isset( self::layouts()[ $str( $in['layout'] ?? '' ) ] ) ? $in['layout'] : 'sidebar_right';
		$out['nav_place']   = isset( self::places()['nav'][ $str( $in['nav_place'] ?? '' ) ] ) ? $in['nav_place'] : 'sidebar';
		$out['index_place'] = isset( self::places()['index'][ $str( $in['index_place'] ?? '' ) ] ) ? $in['index_place'] : 'sidebar';
		$out['style']       = self::sanitize_style( $in['style'] ?? [] );

		foreach ( array_keys( $codes ) as $code ) {
			foreach ( array_keys( Languages::string_keys() ) as $skey ) {
				$val = sanitize_text_field( $in['strings'][ $code ][ $skey ] ?? '' );
				if ( '' !== $val && Languages::default_strings( $code )[ $skey ] !== $val ) {
					$out['strings'][ $code ][ $skey ] = $val;
				}
			}
		}

		return $out;
	}

	private static function sanitize_fields( array $rows, array $codes, ?array $existing = null ) {
		// Saved fields keep their storage shape: flipping "Translated" (or turning a
		// translated field into a toggle) would move content to other meta keys.
		$saved = [];
		foreach ( $existing['fields'] ?? [] as $field ) {
			$saved[ $field['key'] ] = $field;
		}
		// CSV key/status columns. ("title" is fine: it's the usual first field.)
		$reserved = [ 'month', 'day', 'status', 'id', 'sort', 'type', 'lang' ];
		$types    = Fields::types();
		$out      = [];
		$seen     = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( $row['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( str_replace( '-', '_', (string) ( $row['key'] ?? '' ) ) );
			if ( '' === $key ) {
				$key = sanitize_key( str_replace( [ ' ', '-' ], '_', remove_accents( strtolower( $label ) ) ) );
			}
			$key = substr( preg_replace( '/[^a-z0-9_]/', '', $key ), 0, 40 );
			if ( '' === $key || ! preg_match( '/^[a-z]/', $key ) || in_array( $key, $reserved, true ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_key', sprintf( __( 'The field “%s” needs a key that starts with a letter and isn’t a reserved word.', 'postful' ), $label ) );
			}
			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: field key */
				return new \WP_Error( 'field_key', sprintf( __( 'Two fields share the key “%s”. Keys must be unique.', 'postful' ), $key ) );
			}
			$seen[ $key ] = true;

			$row  = array_map( fn( $v ) => is_scalar( $v ) || is_array( $v ) ? $v : '', $row );
			$type = is_string( $row['type'] ?? null ) && isset( $types[ $row['type'] ] ) ? $row['type'] : 'text';
			$translatable = 'toggle' !== $type && ! empty( $row['translatable'] );
			if ( isset( $saved[ $key ] ) ) {
				$translatable = ! empty( $saved[ $key ]['translatable'] );
				if ( $translatable && 'toggle' === $type ) {
					$type = $saved[ $key ]['type'];
				}
			}

			$choices = [];
			if ( 'select' === $type ) {
				$raw     = is_array( $row['choices'] ?? null ) ? $row['choices'] : explode( "\n", (string) ( $row['choices'] ?? '' ) );
				$choices = array_values( array_filter( array_map( 'sanitize_text_field', $raw ), 'strlen' ) );
			}

			$display = is_string( $row['display'] ?? null ) && isset( self::display_styles()[ $row['display'] ] )
				? $row['display']
				: self::default_display( $type, ! array_filter( $out, fn( $f ) => 'text' === $f['type'] ) );

			$out[] = [
				'key'          => $key,
				'label'        => $label,
				'type'         => $type,
				'display'      => $display,
				// Sidebar layouts only; the title always stays in the main area.
				'region'       => ( $row['region'] ?? '' ) === 'sidebar' && 'title' !== $display ? 'sidebar' : 'main',
				'required'     => ! empty( $row['required'] ),
				'help'         => sanitize_text_field( $row['help'] ?? '' ),
				'translatable' => $translatable,
				// Included in "Translate from …" drafts. Off for e.g. Bible verses.
				'machine'      => $translatable && in_array( $type, [ 'text', 'textarea', 'richtext' ], true ) && ! empty( $row['machine'] ),
				'choices'      => $choices,
			];
		}

		// body + fr must not collide with a non-translatable "body_fr".
		foreach ( $out as $field ) {
			if ( empty( $field['translatable'] ) ) {
				continue;
			}
			foreach ( $codes as $code ) {
				if ( isset( $seen[ $field['key'] . '_' . $code ] ) ) {
					/* translators: %s: field key */
					return new \WP_Error( 'field_key', sprintf( __( 'The key “%s” clashes with a translated field’s language column. Rename it.', 'postful' ), $field['key'] . '_' . $code ) );
				}
			}
		}

		if ( ! $out ) {
			return new \WP_Error( 'fields', __( 'Add at least one field.', 'postful' ) );
		}
		return $out;
	}
}
