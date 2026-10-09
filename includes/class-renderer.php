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

	public static function entry( array $type, int $post_id ): string {
		$preset  = Presets::get( $type['preset'] );
		$key     = $preset->key_of( $post_id );
		$locales = Types::locales( $type );

		$out = sprintf( '<article class="pf-entry" data-pf-id="%d" data-pf-sort="%d">', $post_id, $key ? $preset->sort_of( $key ) : 0 );
		foreach ( Types::lang_codes( $type ) as $code ) {
			$out .= self::lang_open( $type, $code );
			if ( 'hide' === $type['fallback'] && ! self::complete( $type, $post_id, $code ) ) {
				$out .= self::message( $type, $code, 'empty', $key );
			} else {
				$out .= self::body( $type, $post_id, $code, $locales[ $code ] ?? $code, $key );
			}
			$out .= '</div>';
		}
		return $out . '</article>';
	}

	/** Placeholder article in every language — never a blank box. */
	public static function empty_state( array $type, ?array $key, string $string_key = 'empty' ): string {
		$preset = Presets::get( $type['preset'] );
		$out    = sprintf( '<article class="pf-entry pf-entry--empty" data-pf-id="0" data-pf-sort="%d">', $key ? $preset->sort_of( $key ) : 0 );
		foreach ( Types::lang_codes( $type ) as $code ) {
			$out .= self::lang_open( $type, $code ) . self::message( $type, $code, $string_key, $key ) . '</div>';
		}
		return $out . '</article>';
	}

	private static function message( array $type, string $code, string $string_key, ?array $key ): string {
		$out = '';
		if ( $key ) {
			$locales = Types::locales( $type );
			$out    .= '<p class="pf-date">' . esc_html( Presets::get( $type['preset'] )->key_label( $key, $code, $locales[ $code ] ?? $code ) ) . '</p>';
		}
		return $out . '<p class="pf-message">' . esc_html( Languages::strings( $type, $code )[ $string_key ] ) . '</p>';
	}

	/** Value for display, falling back to the default language per field. */
	public static function display_value( array $type, int $post_id, array $field, string $code ): string {
		$value = Fields::value( $post_id, $field, $code );
		if ( ! empty( $field['translatable'] ) && 'field' === $type['fallback'] && Fields::is_empty( $field, $value ) && $code !== $type['default_lang'] ) {
			$value = Fields::value( $post_id, $field, $type['default_lang'] );
		}
		return $value;
	}

	private static function body( array $type, int $post_id, string $code, string $locale, ?array $key ): string {
		$preset     = Presets::get( $type['preset'] );
		$date       = $key ? esc_html( $preset->key_label( $key, $code, $locale ) ) : '';
		$title      = Types::title_field( $type );
		$title_key  = $title['key'] ?? '';
		$parts      = [];

		foreach ( $type['fields'] as $field ) {
			$value = self::display_value( $type, $post_id, $field, $code );
			$html  = Fields::is_empty( $field, $value ) ? '' : Fields::format( $field, $value, $code, $locale );
			$parts[ $field['key'] ] = [ $field, $value, $html ];
		}

		if ( '' !== trim( $type['template'] ) ) {
			$map = [ '{date}' => $date ];
			foreach ( $parts as $fkey => [ , , $html ] ) {
				$map[ '{' . $fkey . '}' ] = $html;
			}
			return strtr( $type['template'], $map );
		}

		$out = $date ? '<p class="pf-date">' . $date . '</p>' : '';
		foreach ( $parts as $fkey => [ $field, $value, $html ] ) {
			if ( 'toggle' === $field['type'] || '' === $html ) {
				continue;
			}
			$tag   = $fkey === $title_key ? 'h2' : 'div';
			$out  .= sprintf( '<%1$s class="pf-field pf-field--%2$s sp-field-%3$s">%4$s</%1$s>', $tag, esc_attr( $field['type'] ), esc_attr( $fkey ), $html );
		}
		return $out;
	}

	/**
	 * A translation is complete when every translated field that has content in
	 * the default language also has content in this one.
	 */
	public static function complete( array $type, int $post_id, string $code ): bool {
		if ( $code === $type['default_lang'] ) {
			return true;
		}
		foreach ( $type['fields'] as $field ) {
			if ( empty( $field['translatable'] ) ) {
				continue;
			}
			$default = Fields::value( $post_id, $field, $type['default_lang'] );
			if ( ! Fields::is_empty( $field, $default ) && Fields::is_empty( $field, Fields::value( $post_id, $field, $code ) ) ) {
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
			$codes = ! empty( $field['translatable'] ) ? Types::lang_codes( $type ) : [ $type['default_lang'] ];
			foreach ( $codes as $code ) {
				if ( Fields::is_empty( $field, Fields::value( $post_id, $field, $code ) ) ) {
					$missing[] = $field['label'] . ( $multi && ! empty( $field['translatable'] ) ? ' (' . Languages::name( $locales[ $code ] ) . ')' : '' );
				}
			}
		}
		return $missing;
	}
}
