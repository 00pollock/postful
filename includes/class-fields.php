<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Field types: editor inputs, sanitising, and front-end formatting.
 */
final class Fields {

	public static function types(): array {
		return [
			'text'     => __( 'Single line', 'postful' ),
			'textarea' => __( 'Long text', 'postful' ),
			'richtext' => __( 'Rich text', 'postful' ),
			'number'   => __( 'Number', 'postful' ),
			'date'     => __( 'Date', 'postful' ),
			'url'      => __( 'Link (URL)', 'postful' ),
			'email'    => __( 'Email', 'postful' ),
			'image'    => __( 'Image', 'postful' ),
			'file'     => __( 'File', 'postful' ),
			'select'   => __( 'Choice list', 'postful' ),
			'toggle'   => __( 'Toggle', 'postful' ),
		];
	}

	/** _pf_body_fr for translated fields, _pf_body for shared ones. */
	public static function meta_key( string $field_key, ?string $lang = null ): string {
		return '_pf_' . $field_key . ( $lang ? '_' . $lang : '' );
	}

	/** Raw stored value; translated fields read the given language. */
	public static function value( int $post_id, array $field, string $lang ): string {
		$key = self::meta_key( $field['key'], ! empty( $field['translatable'] ) ? $lang : null );
		return (string) get_post_meta( $post_id, $key, true );
	}

	public static function is_empty( array $field, string $value ): bool {
		if ( 'toggle' === $field['type'] ) {
			return false;
		}
		if ( 'richtext' === $field['type'] ) {
			return '' === trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES ) ) && ! preg_match( '/<(img|iframe|video|audio)\b/i', $value );
		}
		return '' === trim( $value );
	}

	public static function sanitize( array $field, $raw ): string {
		$raw = is_scalar( $raw ) ? (string) $raw : '';
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'richtext':
				return wp_kses_post( $raw );
			case 'number':
				$raw = trim( str_replace( ',', '.', $raw ) );
				return is_numeric( $raw ) ? (string) ( 0 + $raw ) : '';
			case 'date':
				return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $raw ), $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $raw ) : '';
			case 'url':
				return esc_url_raw( trim( $raw ) );
			case 'email':
				return sanitize_email( $raw );
			case 'image':
			case 'file':
				$id = absint( $raw );
				return $id && get_post( $id ) ? (string) $id : '';
			case 'select':
				$raw = sanitize_text_field( $raw );
				return in_array( $raw, $field['choices'], true ) ? $raw : '';
			case 'toggle':
				return in_array( $raw, [ '1', 'yes', 'true', 'on' ], true ) ? '1' : '0';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Editor input.
	 *
	 * @param array  $ctx id (unique, [a-z0-9_]), name, title (bool, large heading style), lang
	 */
	public static function render_input( array $field, string $value, array $ctx ): void {
		$id   = $ctx['id'];
		$name = $ctx['name'];
		$attr = sprintf( 'id="%s" name="%s"', esc_attr( $id ), esc_attr( $name ) );
		$lang = isset( $ctx['html_lang'] ) ? sprintf( ' lang="%s"', esc_attr( $ctx['html_lang'] ) ) : '';

		switch ( $field['type'] ) {
			case 'textarea':
				printf( '<textarea %s class="pf-input pf-textarea" rows="2" data-pf-autogrow%s>%s</textarea>', $attr, $lang, esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'richtext':
				echo '<div class="pf-rich"' . $lang . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				wp_editor( $value, $id, [
					'textarea_name' => $name,
					'media_buttons' => false,
					'textarea_rows' => 12,
					'editor_height' => 260,
					'teeny'         => false,
					'tinymce'       => [
						'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,blockquote,undo,redo',
						'toolbar2'      => '',
						'block_formats' => 'Paragraph=p;Heading=h3;Subheading=h4',
						'statusbar'     => false,
						'resize'        => false,
					],
					'quicktags'     => [ 'buttons' => 'strong,em,link,block,ul,ol,li,close' ],
				] );
				echo '</div>';
				break;

			case 'number':
				printf( '<input type="number" step="any" %s class="pf-input pf-input--short" value="%s">', $attr, esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'date':
				printf( '<input type="date" %s class="pf-input pf-input--short" value="%s">', $attr, esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'url':
				printf( '<input type="url" %s class="pf-input" value="%s" placeholder="https://">', $attr, esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'email':
				printf( '<input type="email" %s class="pf-input" value="%s">', $attr, esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'image':
			case 'file':
				$is_image = 'image' === $field['type'];
				$preview  = '';
				if ( $value ) {
					$preview = $is_image
						? wp_get_attachment_image( (int) $value, 'medium' )
						: esc_html( basename( (string) get_attached_file( (int) $value ) ) );
				}
				printf(
					'<div class="pf-media" data-pf-media="%1$s"><input type="hidden" %2$s value="%3$s"><div class="pf-media-preview" data-pf-media-preview>%4$s</div><div class="pf-media-actions"><button type="button" class="pf-btn pf-btn--ghost" data-pf-media-pick>%5$s</button><button type="button" class="pf-btn pf-btn--text" data-pf-media-clear %6$s>%7$s</button></div></div>',
					esc_attr( $field['type'] ),
					$attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_attr( $value ),
					$preview, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $is_image ? __( 'Choose image', 'postful' ) : __( 'Choose file', 'postful' ) ),
					$value ? '' : 'hidden',
					esc_html__( 'Remove', 'postful' )
				);
				break;

			case 'select':
				printf( '<select %s class="pf-select">', $attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<option value="">' . esc_html__( '— Choose —', 'postful' ) . '</option>';
				foreach ( $field['choices'] as $choice ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $choice ), selected( $value, $choice, false ) );
				}
				echo '</select>';
				break;

			case 'toggle':
				printf(
					'<span class="pf-switch"><input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s><span class="pf-switch-track" aria-hidden="true"></span></span>',
					esc_attr( $name ),
					esc_attr( $id ),
					checked( '1', $value, false )
				);
				break;

			default:
				printf(
					'<input type="text" %s class="pf-input%s" value="%s"%s>',
					$attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					! empty( $ctx['title'] ) ? ' pf-input--title' : '',
					esc_attr( $value ),
					$lang // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
		}
	}

	/**
	 * Front-end HTML for a stored value (already escaped).
	 *
	 * @param string $code   Content language code, for date formatting.
	 * @param string $locale Content locale.
	 */
	public static function format( array $field, string $value, string $code, string $locale ): string {
		switch ( $field['type'] ) {
			case 'textarea':
				return nl2br( esc_html( $value ) );
			case 'richtext':
				return wpautop( wp_kses_post( $value ) );
			case 'date':
				[ $y, $m, $d ] = array_map( 'intval', explode( '-', $value ) );
				return esc_html( Languages::day_label( $m, $d, $code, $locale ) . ' ' . $y );
			case 'url':
				return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $value ), esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $value ) ) ) );
			case 'email':
				$safe = antispambot( $value );
				return sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $safe ) );
			case 'image':
				return wp_get_attachment_image( (int) $value, 'large', false, [ 'loading' => 'lazy' ] );
			case 'file':
				$url = wp_get_attachment_url( (int) $value );
				return $url ? sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( get_the_title( (int) $value ) ?: basename( $url ) ) ) : '';
			case 'toggle':
				return '';
			default:
				return esc_html( $value );
		}
	}
}
