<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Optional machine-translated drafts via DeepL, using the site's own API key.
 *
 * Nothing is sent anywhere unless an editor clicks Translate. Every machine
 * draft is flagged "needs review" per language and stays hidden from visitors
 * (the default language shows instead) until someone marks it reviewed.
 */
final class Translator {

	// Double underscore: field keys must start with a letter, so no field meta
	// key (_pf_{key}_{lang}) can ever collide with this one.
	public const REVIEW_META = '_pf__mt_';
	private const BATCH      = 4;

	public static function hooks(): void {
		add_action( 'wp_ajax_postful_translate', [ self::class, 'ajax_translate' ] );
		add_action( 'wp_ajax_postful_translate_batch', [ self::class, 'ajax_batch' ] );
		add_action( 'update_option_postful_settings', fn() => delete_transient( 'postful_deepl_usage' ) );
	}

	public static function key(): string {
		return (string) Settings::get( 'deepl_key' );
	}

	public static function enabled(): bool {
		return '' !== self::key();
	}

	private static function endpoint( string $path ): string {
		// Free-plan keys end in ":fx" and use a separate host.
		$host = str_ends_with( self::key(), ':fx' ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		return $host . $path;
	}

	/** DeepL target code for a type language, or '' if DeepL can't translate into it. */
	public static function target( string $code, string $locale ): string {
		$special = [
			'en' => 'en_GB' === $locale ? 'EN-GB' : 'EN-US',
			'pt' => 'pt_PT' === $locale ? 'PT-PT' : 'PT-BR',
			'zh' => in_array( $locale, [ 'zh_TW', 'zh_HK' ], true ) ? 'ZH-HANT' : 'ZH-HANS',
		];
		if ( isset( $special[ $code ] ) ) {
			return $special[ $code ];
		}
		$plain = [ 'ar', 'bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr', 'uk' ];
		return in_array( $code, $plain, true ) ? strtoupper( $code ) : '';
	}

	/** DeepL source code, or '' if DeepL can't read that language. */
	public static function source( string $code ): string {
		$known = [ 'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr', 'uk', 'zh' ];
		return in_array( $code, $known, true ) ? strtoupper( $code ) : '';
	}

	/** Fields included in machine drafts. */
	public static function machine_fields( array $type ): array {
		return array_values( array_filter( $type['fields'], fn( $f ) => ! empty( $f['machine'] ) && ! empty( $f['translatable'] ) ) );
	}

	public static function can_translate( array $type, string $code ): bool {
		$locales = Types::locales( $type );
		return self::enabled()
			&& $code !== $type['default_lang']
			&& '' !== self::source( $type['default_lang'] )
			&& '' !== self::target( $code, $locales[ $code ] ?? $code )
			&& self::machine_fields( $type );
	}

	/** Field keys holding unreviewed machine text in this language. */
	public static function machine_keys( int $post_id, string $code ): array {
		$keys = get_post_meta( $post_id, self::REVIEW_META . $code, true );
		return is_array( $keys ) ? $keys : [];
	}

	public static function needs_review( int $post_id, string $code ): bool {
		return (bool) self::machine_keys( $post_id, $code );
	}

	public static function is_machine( int $post_id, string $code, string $field_key ): bool {
		return in_array( $field_key, self::machine_keys( $post_id, $code ), true );
	}

	/** Mark fields as machine drafts awaiting review. */
	public static function add_machine( int $post_id, string $code, array $field_keys ): void {
		$keys = array_values( array_unique( array_merge( self::machine_keys( $post_id, $code ), $field_keys ) ) );
		if ( $keys ) {
			update_post_meta( $post_id, self::REVIEW_META . $code, $keys );
		}
	}

	/** Clear the draft mark for some fields (a person wrote or imported them), or all when null. */
	public static function clear_machine( int $post_id, string $code, ?array $field_keys = null ): void {
		$keys = null === $field_keys ? [] : array_values( array_diff( self::machine_keys( $post_id, $code ), $field_keys ) );
		if ( $keys ) {
			update_post_meta( $post_id, self::REVIEW_META . $code, $keys );
		} else {
			delete_post_meta( $post_id, self::REVIEW_META . $code );
		}
	}

	/**
	 * @param string[] $texts
	 * @return string[]|\WP_Error Same order as $texts.
	 */
	public static function translate( array $texts, string $from, string $to, bool $html ) {
		if ( ! $texts ) {
			return [];
		}
		$body = [
			'text'        => array_values( $texts ),
			'source_lang' => $from,
			'target_lang' => $to,
		];
		if ( $html ) {
			$body['tag_handling'] = 'html';
		} else {
			$body['preserve_formatting'] = true;
		}
		$res = wp_remote_post( self::endpoint( '/v2/translate' ), [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'DeepL-Auth-Key ' . self::key(),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $status ) {
			return new \WP_Error( 'deepl', self::status_message( $status ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		$out  = array_column( $json['translations'] ?? [], 'text' );
		return count( $out ) === count( $texts ) ? $out : new \WP_Error( 'deepl', __( 'DeepL returned an unexpected response.', 'postful' ) );
	}

	private static function status_message( int $status ): string {
		return match ( $status ) {
			403     => __( 'DeepL rejected the API key. Check it under Postful → Settings.', 'postful' ),
			456     => __( 'Your DeepL character allowance for this month is used up.', 'postful' ),
			429     => __( 'DeepL is busy (too many requests). Wait a moment and try again.', 'postful' ),
			default => sprintf(
				/* translators: %d: HTTP status code */
				__( 'DeepL could not translate right now (error %d).', 'postful' ),
				$status
			),
		};
	}

	/** @return array{count:int,limit:int}|\WP_Error */
	public static function usage() {
		$cached = get_transient( 'postful_deepl_usage' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( self::endpoint( '/v2/usage' ), [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'DeepL-Auth-Key ' . self::key() ],
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $status ) {
			return new \WP_Error( 'deepl', self::status_message( $status ) );
		}
		$json  = json_decode( wp_remote_retrieve_body( $res ), true );
		$usage = [ 'count' => (int) ( $json['character_count'] ?? 0 ), 'limit' => (int) ( $json['character_limit'] ?? 0 ) ];
		set_transient( 'postful_deepl_usage', $usage, HOUR_IN_SECONDS );
		return $usage;
	}

	/**
	 * Translate field values (keyed by field key) from the default language.
	 * Rich text keeps its HTML; plain fields keep line breaks.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function translate_values( array $type, array $values, string $code ) {
		$locales = Types::locales( $type );
		$to      = self::target( $code, $locales[ $code ] ?? $code );
		$from    = self::source( $type['default_lang'] );
		$groups  = [ 'html' => [], 'plain' => [] ];
		foreach ( self::machine_fields( $type ) as $field ) {
			$value = (string) ( $values[ $field['key'] ] ?? '' );
			if ( ! Fields::is_empty( $field, $value ) ) {
				$groups[ 'richtext' === $field['type'] ? 'html' : 'plain' ][ $field['key'] ] = $value;
			}
		}
		$out = [];
		foreach ( $groups as $kind => $texts ) {
			if ( ! $texts ) {
				continue;
			}
			$result = self::translate( array_values( $texts ), $from, $to, 'html' === $kind );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$out = array_merge( $out, array_combine( array_keys( $texts ), $result ) );
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Editor: fill one language column from what's currently in the form.
	// ------------------------------------------------------------------

	public static function ajax_translate(): void {
		try {
			$post_id = absint( $_POST['post_id'] ?? 0 );
			if ( ! check_ajax_referer( 'postful_translate_' . $post_id, '_wpnonce', false ) || ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( [ 'message' => __( 'Security check failed. Refresh the page and try again.', 'postful' ) ] );
			}
			$type = Types::get( get_post_type( $post_id ) );
			$code = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
			if ( ! $type || ! self::can_translate( $type, $code ) ) {
				wp_send_json_error( [ 'message' => __( 'Translation isn’t available for this language.', 'postful' ) ] );
			}
			$raw    = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field
			$values = [];
			foreach ( self::machine_fields( $type ) as $field ) {
				$values[ $field['key'] ] = Fields::sanitize( $field, $raw[ $field['key'] ] ?? '' );
			}
			$result = self::translate_values( $type, $values, $code );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}
			$clean = [];
			foreach ( self::machine_fields( $type ) as $field ) {
				if ( isset( $result[ $field['key'] ] ) ) {
					$clean[ $field['key'] ] = Fields::sanitize( $field, $result[ $field['key'] ] );
				}
			}
			wp_send_json_success( [ 'values' => $clean ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// ------------------------------------------------------------------
	// Bulk: fill empty fields of one language across every entry, a few at a time.
	// ------------------------------------------------------------------

	/** Entries with a machine field that has source text but no translation. */
	public static function pending( array $type, string $code, array $skip = [] ): array {
		$ids = get_posts( [
			'post_type'        => $type['key'],
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] );
		update_meta_cache( 'post', $ids );
		$out   = [];
		$chars = 0;
		foreach ( array_diff( $ids, $skip ) as $id ) {
			$need = 0;
			foreach ( self::machine_fields( $type ) as $field ) {
				$source = Fields::value( (int) $id, $field, $type['default_lang'] );
				if ( ! Fields::is_empty( $field, $source ) && Fields::is_empty( $field, Fields::value( (int) $id, $field, $code ) ) ) {
					$need  += mb_strlen( $source );
				}
			}
			if ( $need ) {
				$out[]  = (int) $id;
				$chars += $need;
			}
		}
		return [ 'ids' => $out, 'chars' => $chars ];
	}

	public static function ajax_batch(): void {
		try {
			$key  = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
			$type = Types::get( $key );
			if ( ! $type || ! check_ajax_referer( 'postful_translate_batch_' . $key, '_wpnonce', false ) || ! current_user_can( 'edit_others_posts' ) ) {
				wp_send_json_error( [ 'message' => __( 'Security check failed. Refresh the page and try again.', 'postful' ) ] );
			}
			$code = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
			if ( ! self::can_translate( $type, $code ) ) {
				wp_send_json_error( [ 'message' => __( 'Translation isn’t available for this language.', 'postful' ) ] );
			}

			// Entries that failed earlier in this run are skipped, so they aren't billed again.
			$skip    = array_map( 'absint', (array) ( $_POST['skip'] ?? [] ) );
			$pending = self::pending( $type, $code, $skip );
			$batch   = array_slice( $pending['ids'], 0, self::BATCH );
			$failed  = [];
			foreach ( $batch as $id ) {
				$source = [];
				foreach ( self::machine_fields( $type ) as $field ) {
					// Only fill gaps — never overwrite a translation someone wrote.
					if ( Fields::is_empty( $field, Fields::value( $id, $field, $code ) ) ) {
						$source[ $field['key'] ] = Fields::value( $id, $field, $type['default_lang'] );
					}
				}
				$result = self::translate_values( $type, $source, $code );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( [ 'message' => $result->get_error_message() ] );
				}
				$filled = [];
				foreach ( self::machine_fields( $type ) as $field ) {
					$value = isset( $result[ $field['key'] ] ) ? Fields::sanitize( $field, $result[ $field['key'] ] ) : '';
					if ( ! Fields::is_empty( $field, $value ) ) {
						update_post_meta( $id, Fields::meta_key( $field['key'], $code ), wp_slash( $value ) );
						$filled[] = $field['key'];
					}
				}
				self::add_machine( $id, $code, $filled );
				if ( count( $filled ) < count( $source ) ) {
					$failed[] = $id;
				}
			}
			Index::flush( $key );

			wp_send_json_success( [
				'done'      => count( $batch ),
				'failed'    => $failed,
				'remaining' => max( 0, count( $pending['ids'] ) - count( $batch ) ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
