<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Renders entries for the front end. Every supported language is rendered into
 * the page; the default is visible and the others carry `hidden`. Front-end JS
 * reveals the one matching the visitor. Never branch on visitor language here —
 * pages are cached.
 */
final class Renderer {

	/** Opening tag of one language section. */
	public static function lang_open( array $type, string $code, string $tag = 'div', string $class = 'pf-lang' ): string {
		$locales = Types::locales( $type );
		return sprintf(
			'<%1$s class="%2$s" data-lang="%3$s" lang="%4$s"%5$s%6$s>',
			$tag,
			esc_attr( $class ),
			esc_attr( $code ),
			esc_attr( Languages::html_lang( $locales[ $code ] ?? $code ) ),
			Languages::is_rtl( $code ) ? ' dir="rtl"' : '',
			$code === $type['default_lang'] ? '' : ' hidden'
		);
	}

	/** Same text in every language, as hidden-toggled spans. */
	public static function per_lang( array $type, callable $text, string $tag = 'span' ): string {
		$out = '';
		foreach ( Types::lang_codes( $type ) as $code ) {
			$out .= self::lang_open( $type, $code, $tag ) . $text( $code ) . '</' . $tag . '>';
		}
		return $out;
	}

	/**
	 * @param string $part 'all'; or, when the layout puts the title row beside the
	 *                     navigation, 'head' (title + date) and 'body' (the fields).
	 */
	public static function entry( array $type, int $post_id, string $part = 'all' ): string {
		$preset  = Presets::get( $type['preset'] );
		$key     = $preset->key_of( $post_id );
		$locales = Types::locales( $type );

		$out = sprintf( '<article class="pf-entry pf-entry--%s" data-pf-id="%d" data-pf-sort="%d">', esc_attr( $part ), $post_id, $key ? $preset->sort_of( $key ) : 0 );
		foreach ( Types::lang_codes( $type ) as $code ) {
			$out .= self::lang_open( $type, $code );
			if ( 'hide' === $type['fallback'] && ! self::complete( $type, $post_id, $code ) ) {
				$out .= self::message( $type, $code, 'empty', $key, $part );
			} else {
				$out .= self::body( $type, $post_id, $code, $locales[ $code ] ?? $code, $key, $part );
			}
			$out .= '</div>';
		}
		return $out . '</article>';
	}

	/** Placeholder in every language — never a blank box. */
	public static function empty_state( array $type, ?array $key, string $string_key = 'empty', string $part = 'all' ): string {
		$preset = Presets::get( $type['preset'] );
		$out    = sprintf( '<article class="pf-entry pf-entry--empty pf-entry--%s" data-pf-id="0" data-pf-sort="%d">', esc_attr( $part ), $key ? $preset->sort_of( $key ) : 0 );
		foreach ( Types::lang_codes( $type ) as $code ) {
			$out .= self::lang_open( $type, $code ) . self::message( $type, $code, $string_key, $key, $part ) . '</div>';
		}
		return $out . '</article>';
	}

	private static function message( array $type, string $code, string $string_key, ?array $key, string $part = 'all' ): string {
		$date = '';
		if ( $key ) {
			$locales = Types::locales( $type );
			$date    = '<p class="pf-date">' . esc_html( Presets::get( $type['preset'] )->key_label( $key, $code, $locales[ $code ] ?? $code ) ) . '</p>';
		}
		$text = '<p class="pf-message">' . esc_html( Languages::strings( $type, $code )[ $string_key ] ) . '</p>';
		if ( 'head' === $part ) {
			return $date ? '<header class="pf-entry-head">' . $date . '</header>' : '';
		}
		return 'body' === $part ? $text : $date . $text;
	}

	/**
	 * Value shown to visitors. A machine draft awaiting review counts as empty,
	 * then the default language fills the gap per field (fallback "field").
	 */
	public static function display_value( array $type, int $post_id, array $field, string $code ): string {
		$translated = ! empty( $field['translatable'] ) && $code !== $type['default_lang'];
		$value      = $translated && Translator::is_machine( $post_id, $code, $field['key'] ) ? '' : Fields::value( $post_id, $field, $code );
		if ( $translated && 'field' === $type['fallback'] && Fields::is_empty( $field, $value ) ) {
			$value = Fields::value( $post_id, $field, $type['default_lang'] );
		}
		return $value;
	}

	private static function body( array $type, int $post_id, string $code, string $locale, ?array $key, string $part = 'all' ): string {
		$preset = Presets::get( $type['preset'] );
		$date   = $key ? esc_html( $preset->key_label( $key, $code, $locale ) ) : '';
		$title  = Types::header_field( $type );
		$parts  = [];

		foreach ( $type['fields'] as $field ) {
			$value = self::display_value( $type, $post_id, $field, $code );
			$html  = Fields::is_empty( $field, $value ) ? '' : Fields::format( $field, $value, $code, $locale );
			$parts[ $field['key'] ] = [ $field, $html ];
		}

		// A custom template controls the whole entry; it goes in the body part.
		if ( '' !== trim( $type['template'] ) ) {
			if ( 'head' === $part ) {
				return '';
			}
			$map = [ '{date}' => $date ];
			foreach ( $parts as $fkey => [ , $html ] ) {
				$map[ '{' . $fkey . '}' ] = $html;
			}
			return strtr( $type['template'], $map );
		}

		// Header: the title, with the day beside it.
		$head = '';
		if ( $title && '' !== $parts[ $title['key'] ][1] ) {
			$head .= sprintf( '<h2 class="pf-entry-title sp-field-%s"%s>%s</h2>', esc_attr( $title['key'] ), self::style_attr( $type, $title['key'] ), $parts[ $title['key'] ][1] );
		}
		if ( $date ) {
			$head .= '<p class="pf-date">' . $date . '</p>';
		}
		$head = $head ? '<header class="pf-entry-head">' . $head . '</header>' : '';
		if ( 'head' === $part ) {
			return $head;
		}
		$out = 'body' === $part ? '' : $head;

		foreach ( $parts as $fkey => [ $field, $html ] ) {
			if ( 'toggle' === $field['type'] || '' === $html || ( $title && $fkey === $title['key'] ) ) {
				continue;
			}
			$out .= sprintf(
				'<div class="pf-field pf-field--%1$s sp-field-%2$s"%3$s>%4$s</div>',
				esc_attr( $field['type'] ),
				esc_attr( $fkey ),
				self::style_attr( $type, $fkey ),
				$html
			);
		}
		return $out;
	}

	/** Style step settings for one field, as a validated inline style attribute. */
	private static function style_attr( array $type, string $field_key ): string {
		$css = Types::field_css( $type, $field_key );
		return $css ? ' style="' . esc_attr( $css ) . '"' : '';
	}

	/**
	 * A translation is complete when every translated field that has content in
	 * the default language also has content in this one.
	 */
	public static function complete( array $type, int $post_id, string $code, bool $for_visitors = true ): bool {
		if ( $code === $type['default_lang'] ) {
			return true;
		}
		$machine = $for_visitors ? Translator::machine_keys( $post_id, $code ) : [];
		foreach ( $type['fields'] as $field ) {
			if ( empty( $field['translatable'] ) ) {
				continue;
			}
			$default = Fields::value( $post_id, $field, $type['default_lang'] );
			$value = in_array( $field['key'], $machine, true ) ? '' : Fields::value( $post_id, $field, $code );
			if ( ! Fields::is_empty( $field, $default ) && Fields::is_empty( $field, $value ) ) {
				return false;
			}
		}
		return true;
	}

	/** Required fields that are empty, as "Label (Language)" strings, for the editor. */
	public static function missing_required( array $type, int $post_id ): array {
		$missing = [];
		$locales = Types::locales( $type );
		$multi   = Types::is_multilingual( $type );
		foreach ( $type['fields'] as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			// Translations are optional (they fall back to the default language).
			foreach ( [ $type['default_lang'] ] as $code ) {
				if ( Fields::is_empty( $field, Fields::value( $post_id, $field, $code ) ) ) {
					$missing[] = $field['label'] . ( $multi && ! empty( $field['translatable'] ) ? ' (' . Languages::name( $locales[ $code ] ) . ')' : '' );
				}
			}
		}
		return $missing;
	}
}
