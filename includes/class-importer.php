<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Import / export in the shape of the devotional workbooks: one .xlsx per
 * language, one sheet per month, a header row (Date, Title, Scripture of the
 * day, Reference, Text, …) and one row per day.
 *
 * Import is two steps: upload (language chosen) → match columns to fields with
 * a preview → import. Rows are matched to entries by month + day, so the
 * English and French files land on the same devotionals in any order.
 * Empty cells never overwrite. Re-running updates.
 */
final class Importer {

	/** Sheet names used in exports and templates (as in the devotional workbooks). */
	private const SHEETS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'June', 'July', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec' ];

	public static function hooks(): void {
		add_action( 'admin_menu', [ self::class, 'menus' ], 11 );
		add_action( 'admin_post_postful_import_upload', [ self::class, 'upload' ] );
		add_action( 'admin_post_postful_import_run', [ self::class, 'run_import' ] );
		add_action( 'admin_post_postful_export_xlsx', [ self::class, 'export' ] );
		add_filter( 'parent_file', [ self::class, 'parent_file' ], 20 );
		add_filter( 'submenu_file', [ self::class, 'submenu_file' ], 20 );
	}

	private static function current_page_type(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return str_starts_with( $page, 'postful-io-' ) ? substr( $page, 11 ) : null;
	}

	public static function parent_file( $file ) {
		return self::current_page_type() ? 'postful' : $file;
	}

	public static function submenu_file( $file ) {
		$key = self::current_page_type();
		return $key ? 'edit.php?post_type=' . $key : $file;
	}

	public static function url( string $key, array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=postful-io-' . $key ) );
	}

	public static function menus(): void {
		foreach ( Types::all() as $key => $type ) {
			// No menu item of its own (parent options.php keeps it out of the menu);
			// reached from the entries list and the Setup card.
			add_submenu_page(
				'options.php',
				/* translators: %s: plural type name */
				sprintf( __( '%s: import / export', 'postful' ), $type['plural'] ),
				__( 'Import / export', 'postful' ),
				'edit_others_posts',
				'postful-io-' . $key,
				fn() => self::page( $key )
			);
		}
	}

	private static function guard( string $action ): array {
		$key  = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$type = Types::get( $key );
		if ( ! $type || ! current_user_can( 'edit_others_posts' ) || ! check_admin_referer( $action . '_' . $key ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'postful' ), 403 );
		}
		return $type;
	}

	private static function lang_param( array $type ): string {
		$code = isset( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by guard()
		return in_array( $code, Types::lang_codes( $type ), true ) ? $code : $type['default_lang'];
	}

	// ------------------------------------------------------------------
	// Dates: "1er janvier", "January 1", "Jan 1", "1 Jan", 2026-01-01, Excel dates
	// ------------------------------------------------------------------

	private static function month_words(): array {
		$words = [
			1  => [ 'january', 'jan', 'janvier', 'janv' ],
			2  => [ 'february', 'feb', 'fevrier', 'fev', 'fevr' ],
			3  => [ 'march', 'mar', 'mars' ],
			4  => [ 'april', 'apr', 'avril', 'avr' ],
			5  => [ 'may', 'mai' ],
			6  => [ 'june', 'jun', 'juin' ],
			7  => [ 'july', 'jul', 'juillet', 'juil' ],
			8  => [ 'august', 'aug', 'aout' ],
			9  => [ 'september', 'sep', 'sept', 'septembre' ],
			10 => [ 'october', 'oct', 'octobre' ],
			11 => [ 'november', 'nov', 'novembre' ],
			12 => [ 'december', 'dec', 'decembre' ],
		];
		$out = [];
		foreach ( $words as $m => $list ) {
			foreach ( $list as $w ) {
				$out[ $w ] = $m;
			}
		}
		return $out;
	}

	private static function norm( string $s ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $s ) ) ) );
	}

	/** Month from free text (a sheet name such as "Jan" or "Sept"), or 0. */
	public static function month_in( string $text ): int {
		$words = self::month_words();
		foreach ( explode( ' ', self::norm( $text ) ) as $token ) {
			if ( isset( $words[ $token ] ) ) {
				return $words[ $token ];
			}
		}
		return 0;
	}

	/**
	 * @param ?array $cell  Spreadsheet cell.
	 * @param string $sheet Sheet name, for the month when the cell only has a day.
	 * @return array|null ['month' => m, 'day' => d]
	 */
	public static function day_of( ?array $cell, string $sheet ): ?array {
		if ( ! $cell ) {
			return null;
		}
		$m = 0;
		$d = 0;
		if ( null !== $cell['num'] && $cell['num'] > 20000 ) {
			// Excel date serial (days since 1899-12-30).
			$ts = (int) round( ( $cell['num'] - 25569 ) * DAY_IN_SECONDS );
			$m  = (int) gmdate( 'n', $ts );
			$d  = (int) gmdate( 'j', $ts );
		} elseif ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', trim( $cell['text'] ), $iso ) ) {
			$m = (int) $iso[2];
			$d = (int) $iso[3];
		} else {
			$words = self::month_words();
			foreach ( explode( ' ', self::norm( $cell['text'] ) ) as $token ) {
				if ( ! $m && isset( $words[ $token ] ) ) {
					$m = $words[ $token ];
				} elseif ( ! $d && preg_match( '/^(\d{1,2})(er|re|e|st|nd|rd|th)?$/', $token, $num ) ) {
					$d = (int) $num[1];
				}
			}
			if ( ! $m ) {
				$m = self::month_in( $sheet );
			}
		}
		return Preset_Recurring_Day::valid( $m, $d ) ? [ 'month' => $m, 'day' => $d ] : null;
	}

	// ------------------------------------------------------------------
	// Step 1: upload → parse → store temporarily
	// ------------------------------------------------------------------

	private static function tmp_dir(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'postful-tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		// Forget uploads older than a day.
		foreach ( (array) glob( $dir . '/*.json' ) as $old ) {
			if ( filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		return $dir;
	}

	private static function tmp_path( string $token ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$path = self::tmp_dir() . '/' . $token . '.json';
		return is_file( $path ) ? $path : null;
	}

	public static function upload(): void {
		$type = self::guard( 'postful_import_upload' );
		$lang = self::lang_param( $type );
		try {
			$file = $_FILES['sheet'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! $file || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				throw new \RuntimeException( __( 'Choose an Excel (.xlsx) or CSV file to import.', 'postful' ) );
			}
			$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'xlsx', 'csv' ], true ) ) {
				throw new \RuntimeException( __( 'Use an Excel workbook (.xlsx) or a CSV file. Older .xls files: open in Excel and “Save as” .xlsx.', 'postful' ) );
			}
			if ( $file['size'] > 30 * MB_IN_BYTES ) {
				throw new \RuntimeException( __( 'That file is larger than 30 MB.', 'postful' ) );
			}
			$sheets = Spreadsheet::read( $file['tmp_name'], $ext );
			if ( is_wp_error( $sheets ) ) {
				throw new \RuntimeException( $sheets->get_error_message() );
			}

			$parsed = [];
			foreach ( $sheets as $sheet ) {
				$header_at = null;
				foreach ( $sheet['rows'] as $i => $row ) {
					if ( count( array_filter( $row ) ) >= 2 ) {
						$header_at = $i;
						break;
					}
				}
				if ( null === $header_at ) {
					continue; // Empty sheet.
				}
				$headers = array_map( fn( $c ) => $c ? trim( $c['text'] ) : '', $sheet['rows'][ $header_at ] );
				$rows    = [];
				foreach ( array_slice( $sheet['rows'], $header_at + 1, null, true ) as $i => $row ) {
					if ( array_filter( $row ) ) {
						$rows[] = [ 'line' => $i + 1, 'cells' => $row ];
					}
				}
				$parsed[] = [ 'name' => $sheet['name'], 'headers' => $headers, 'rows' => $rows ];
			}
			if ( ! $parsed ) {
				throw new \RuntimeException( __( 'No rows found. The file needs a header row (Date, Title, …) and one row per day.', 'postful' ) );
			}

			$token = bin2hex( random_bytes( 16 ) );
			file_put_contents( self::tmp_dir() . '/' . $token . '.json', wp_json_encode( [ 'type' => $type['key'], 'lang' => $lang, 'file' => sanitize_file_name( (string) $file['name'] ), 'sheets' => $parsed ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_safe_redirect( self::url( $type['key'], [ 'match' => $token ] ) );
		} catch ( \Throwable $e ) {
			set_transient( 'postful_import_' . get_current_user_id(), [ 'fatal' => $e->getMessage() ], 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::url( $type['key'], [ 'imported' => 1 ] ) );
		}
		exit;
	}

	private static function load( array $type, string $token ): ?array {
		$path = self::tmp_path( $token );
		if ( ! $path ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) && ( $data['type'] ?? '' ) === $type['key'] ? $data : null;
	}

	/** Every distinct header in the file: normalised => as written. */
	private static function headers( array $data ): array {
		$out = [];
		foreach ( $data['sheets'] as $sheet ) {
			foreach ( $sheet['headers'] as $h ) {
				if ( '' !== $h && ! isset( $out[ self::norm( $h ) ] ) ) {
					$out[ self::norm( $h ) ] = $h;
				}
			}
		}
		return $out;
	}

	/** Suggested target per header: 'date', a field key, or '' (ignore). Remembers past choices. */
	private static function suggest( array $type, array $headers ): array {
		$saved = (array) ( get_option( 'postful_import_map', [] )[ $type['key'] ] ?? [] );
		$keys  = array_column( $type['fields'], 'key' );
		$out   = [];
		foreach ( $headers as $norm => $label ) {
			if ( isset( $saved[ $norm ] ) && ( 'date' === $saved[ $norm ] || '' === $saved[ $norm ] || in_array( $saved[ $norm ], $keys, true ) ) ) {
				$out[ $norm ] = $saved[ $norm ];
				continue;
			}
			if ( in_array( $norm, [ 'date', 'day', 'jour' ], true ) ) {
				$out[ $norm ] = 'date';
				continue;
			}
			$out[ $norm ] = '';
			foreach ( $type['fields'] as $field ) {
				if ( self::norm( $field['label'] ) === $norm || self::norm( str_replace( '_', ' ', $field['key'] ) ) === $norm ) {
					$out[ $norm ] = $field['key'];
					break;
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Step 2: rows → entries
	// ------------------------------------------------------------------

	/** A cell's value in the form a field stores it. */
	private static function cell_value( array $field, ?array $cell ): string {
		if ( ! $cell ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'richtext':
				return $cell['html'];
			case 'textarea':
				return $cell['text'];
			case 'image':
			case 'file':
				return '';
			default:
				return trim( preg_replace( '/\s+/u', ' ', $cell['text'] ) );
		}
	}

	/**
	 * Turn parsed sheets into rows ready to save.
	 *
	 * @param array $map normalised header => 'date' | field key | ''
	 * @return array list of ['where' => 'Jan, row 3', 'key' => array|null, 'raw_date' => string, 'values' => [field key => value]]
	 */
	public static function rows( array $type, array $data, array $map ): array {
		$fields = [];
		foreach ( $type['fields'] as $field ) {
			$fields[ $field['key'] ] = $field;
		}
		$out = [];
		foreach ( $data['sheets'] as $sheet ) {
			$cols = [];
			foreach ( $sheet['headers'] as $i => $h ) {
				$target = $map[ self::norm( $h ) ] ?? '';
				if ( '' !== $target ) {
					$cols[ $i ] = $target;
				}
			}
			foreach ( $sheet['rows'] as $row ) {
				$date   = null;
				$raw    = '';
				$values = [];
				foreach ( $cols as $i => $target ) {
					$cell = $row['cells'][ $i ] ?? null;
					if ( 'date' === $target ) {
						$raw  = $cell ? $cell['text'] : '';
						$date = self::day_of( $cell, $sheet['name'] );
					} elseif ( isset( $fields[ $target ] ) ) {
						$values[ $target ] = self::cell_value( $fields[ $target ], $cell );
					}
				}
				$out[] = [
					/* translators: 1: sheet name, 2: row number */
					'where'    => '' !== $sheet['name'] ? sprintf( __( '%1$s, row %2$d', 'postful' ), $sheet['name'], $row['line'] ) : sprintf( __( 'Row %d', 'postful' ), $row['line'] ),
					'key'      => $date,
					'raw_date' => $raw,
					'values'   => $values,
				];
			}
		}
		return $out;
	}

	/**
	 * Save rows for one language. Matches existing entries by day (preferring
	 * the published one), creates the rest. Empty values never overwrite.
	 *
	 * @return array{created:int,updated:int,skipped:int,errors:array,warnings:array}
	 */
	public static function apply( array $type, string $lang, array $rows, string $default_status = 'publish' ): array {
		$result = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => [] ];
		$preset = Presets::get( $type['preset'] );
		$fields = [];
		foreach ( $type['fields'] as $field ) {
			$fields[ $field['key'] ] = $field;
		}

		// Existing entries by day: the published one if there is one, else the oldest.
		$existing  = [];
		$published = [];
		$ids       = get_posts( [
			'post_type'        => $type['key'],
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$key = $preset->key_of( (int) $id );
			if ( ! $key ) {
				continue;
			}
			$sort = $preset->sort_of( $key );
			if ( 'publish' === get_post_status( $id ) && ! isset( $published[ $sort ] ) ) {
				$published[ $sort ] = (int) $id;
			}
			if ( ! isset( $existing[ $sort ] ) ) {
				$existing[ $sort ] = (int) $id;
			}
		}
		$existing = array_replace( $existing, $published );
		$title    = Types::title_field( $type );
		$seen     = [];

		wp_defer_term_counting( true );
		foreach ( $rows as $row ) {
			if ( ! $row['key'] ) {
				$result['errors'][] = [
					$row['where'],
					'' === trim( $row['raw_date'] )
						? __( 'No date in this row.', 'postful' )
						/* translators: %s: the date text from the file */
						: sprintf( __( 'Couldn’t read the date “%s”.', 'postful' ), $row['raw_date'] ),
				];
				++$result['skipped'];
				continue;
			}
			$sort = $preset->sort_of( $row['key'] );
			if ( isset( $seen[ $sort ] ) ) {
				/* translators: 1: day label, 2: earlier row */
				$result['warnings'][] = sprintf( __( '%1$s appears twice in the file (also %2$s); the later row was used.', 'postful' ), $preset->key_label( $row['key'] ), $seen[ $sort ] );
			}
			$seen[ $sort ] = $row['where'];

			$meta = [];
			$bad  = [];
			foreach ( $row['values'] as $fkey => $raw ) {
				$field = $fields[ $fkey ];
				if ( '' === trim( wp_strip_all_tags( (string) $raw ) ) && ! preg_match( '/<img\b/i', (string) $raw ) ) {
					continue; // Empty cells never overwrite.
				}
				if ( empty( $field['translatable'] ) && $lang !== $type['default_lang'] ) {
					continue; // Shared fields come from the default-language file.
				}
				$clean = Fields::sanitize( $field, $raw );
				if ( Fields::is_empty( $field, $clean ) ) {
					$bad[] = $field['label'];
					continue;
				}
				$meta[ $fkey ] = $clean;
			}
			if ( $bad ) {
				/* translators: %s: field names */
				$result['errors'][] = [ $row['where'], sprintf( __( 'Couldn’t read %s (skipped those cells, imported the rest).', 'postful' ), implode( ', ', $bad ) ) ];
			}
			if ( ! $meta ) {
				++$result['skipped'];
				continue;
			}

			$post_id   = $existing[ $sort ] ?? 0;
			$new_title = $title && $lang === $type['default_lang'] && isset( $meta[ $title['key'] ] ) ? wp_strip_all_tags( $meta[ $title['key'] ] ) : '';
			if ( $post_id ) {
				if ( '' !== $new_title ) {
					wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_title' => $new_title ] ) );
				}
				++$result['updated'];
			} else {
				$post_id = wp_insert_post( wp_slash( [
					'post_type'   => $type['key'],
					'post_status' => $default_status,
					'post_title'  => '' !== $new_title ? $new_title : $preset->key_label( $row['key'] ),
				] ), true );
				if ( is_wp_error( $post_id ) ) {
					$result['errors'][] = [ $row['where'], $post_id->get_error_message() ];
					++$result['skipped'];
					continue;
				}
				$preset->save_key( $post_id, $row['key'] );
				$existing[ $sort ] = $post_id;
				++$result['created'];
			}

			foreach ( $meta as $fkey => $value ) {
				$code = ! empty( $fields[ $fkey ]['translatable'] ) ? $lang : null;
				update_post_meta( $post_id, Fields::meta_key( $fkey, $code ), wp_slash( $value ) );
			}
			// Imported text is human-provided: it replaces any machine draft in those fields.
			if ( $lang !== $type['default_lang'] ) {
				Translator::clear_machine( $post_id, $lang, array_keys( $meta ) );
			}
		}
		wp_defer_term_counting( false );
		Index::flush( $type['key'] );
		return $result;
	}

	public static function run_import(): void {
		$type  = self::guard( 'postful_import_run' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = self::load( $type, $token );
		try {
			if ( ! $data ) {
				throw new \RuntimeException( __( 'That upload has expired. Please choose the file again.', 'postful' ) );
			}
			$lang   = self::lang_param( $type );
			$status = isset( $_POST['default_status'] ) && 'draft' === $_POST['default_status'] ? 'draft' : 'publish';
			$keys   = array_column( $type['fields'], 'key' );
			$map    = [];
			foreach ( (array) ( $_POST['map'] ?? [] ) as $norm => $target ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below
				$target = sanitize_key( (string) $target );
				$map[ self::norm( (string) $norm ) ] = 'date' === $target || in_array( $target, $keys, true ) ? $target : '';
			}
			if ( ! in_array( 'date', $map, true ) ) {
				throw new \RuntimeException( __( 'Choose which column holds the date.', 'postful' ) );
			}
			// Remember the matching for next time (the French file uses the same columns).
			$all                  = (array) get_option( 'postful_import_map', [] );
			$all[ $type['key'] ]  = array_merge( (array) ( $all[ $type['key'] ] ?? [] ), $map );
			update_option( 'postful_import_map', $all, false );

			$result         = self::apply( $type, $lang, self::rows( $type, $data, $map ), $status );
			$result['lang'] = $lang;
			$result['file'] = $data['file'] ?? '';
			wp_delete_file( (string) self::tmp_path( $token ) );
		} catch ( \Throwable $e ) {
			$result = [ 'fatal' => $e->getMessage() ];
		}
		set_transient( 'postful_import_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $type['key'], [ 'imported' => 1 ] ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Export: one workbook per language, one sheet per month
	// ------------------------------------------------------------------

	public static function workbook( array $type, string $lang, bool $empty = false ): array {
		$preset  = Presets::get( $type['preset'] );
		$locales = Types::locales( $type );
		$header  = array_merge( [ __( 'Date', 'postful' ) ], array_column( $type['fields'], 'label' ) );
		$months  = array_fill( 1, 12, [] );

		if ( ! $empty ) {
			$ids = get_posts( [
				'post_type'        => $type['key'],
				'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			] );
			update_meta_cache( 'post', $ids );
			$by_sort = [];
			foreach ( $ids as $id ) {
				$key = $preset->key_of( (int) $id );
				if ( $key && ! isset( $by_sort[ $preset->sort_of( $key ) ] ) ) {
					$by_sort[ $preset->sort_of( $key ) ] = [ (int) $id, $key ];
				}
			}
			ksort( $by_sort );
			foreach ( $by_sort as [ $id, $key ] ) {
				$row = [ $preset->key_label( $key, $lang, $locales[ $lang ] ?? $lang ) ];
				foreach ( $type['fields'] as $field ) {
					$value = Fields::value( $id, $field, $lang );
					$row[] = 'richtext' === $field['type'] ? [ 'html' => $value ] : $value;
				}
				$months[ $key['month'] ][] = $row;
			}
		}

		$sheets = [];
		foreach ( $months as $m => $rows ) {
			$sheets[] = [ 'name' => self::SHEETS[ $m - 1 ], 'rows' => array_merge( [ $header ], $rows ) ];
		}
		return $sheets;
	}

	public static function export(): void {
		$type  = self::guard( 'postful_export_xlsx' );
		$lang  = self::lang_param( $type );
		$empty = ! empty( $_GET['template'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by guard()
		$path  = Spreadsheet::write( self::workbook( $type, $lang, $empty ) );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}
		$name = $empty ? $type['key'] . '-template.xlsx' : $type['key'] . '-' . $lang . '-' . gmdate( 'Y-m-d' ) . '.xlsx';
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $path );
		exit;
	}

	/** Bulk machine drafts for empty translations. */
	private static function translate_panel( array $type ): void {
		$locales = Types::locales( $type );
		$others  = array_filter( Types::lang_codes( $type ), fn( $c ) => $c !== $type['default_lang'] );
		if ( ! $others || ! Translator::machine_fields( $type ) ) {
			return;
		}
		$source = Languages::name( $locales[ $type['default_lang'] ] );
		?>
		<div class="pf-panel pf-translate-panel">
			<h2 class="pf-panel-title"><?php esc_html_e( 'Translation drafts', 'postful' ); ?></h2>
			<?php if ( ! Translator::enabled() ) : ?>
				<p class="pf-muted">
					<?php
					printf(
						/* translators: %s: link to settings */
						esc_html__( 'Add a DeepL API key in %s to fill missing translations automatically. Drafts stay hidden from visitors until reviewed.', 'postful' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=postful-settings' ) ) . '">' . esc_html__( 'Postful → Settings', 'postful' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<p class="pf-muted"><?php esc_html_e( 'Fills only empty text fields. Nothing a person wrote is overwritten. Every draft is marked “Needs review” and stays hidden from visitors until someone marks it reviewed.', 'postful' ); ?></p>
				<?php foreach ( $others as $code ) : ?>
					<?php
					$name = Languages::name( $locales[ $code ] );
					if ( ! Translator::can_translate( $type, $code ) ) {
						/* translators: %s: language name */
						echo '<p class="pf-muted">' . esc_html( sprintf( __( 'DeepL can’t translate into %s.', 'postful' ), $name ) ) . '</p>';
						continue;
					}
					$pending = Translator::pending( $type, $code );
					?>
					<div class="pf-bulk" data-pf-bulk data-type="<?php echo esc_attr( $type['key'] ); ?>" data-lang="<?php echo esc_attr( $code ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'postful_translate_batch_' . $type['key'] ) ); ?>" data-total="<?php echo (int) count( $pending['ids'] ); ?>">
						<div class="pf-bulk-text">
							<strong>
								<?php
								/* translators: 1: number of entries, 2: language name */
								echo esc_html( sprintf( _n( '%1$d entry is missing %2$s', '%1$d entries are missing %2$s', count( $pending['ids'] ), 'postful' ), count( $pending['ids'] ), $name ) );
								?>
							</strong>
							<?php if ( $pending['chars'] ) : ?>
								<span class="pf-muted">
									<?php
									/* translators: %s: number of characters */
									echo esc_html( sprintf( __( 'About %s characters of your DeepL allowance.', 'postful' ), number_format_i18n( $pending['chars'] ) ) );
									?>
								</span>
							<?php endif; ?>
							<div class="pf-progress" hidden><span class="pf-progress-bar" data-pf-bar></span></div>
							<span class="pf-bulk-status" data-pf-status role="status"></span>
						</div>
						<button type="button" class="pf-btn pf-btn--soft" data-pf-bulk-go <?php disabled( ! $pending['ids'] ); ?>>
							<?php
							/* translators: 1: target language, 2: source language */
							echo esc_html( sprintf( __( 'Translate into %1$s from %2$s', 'postful' ), $name, $source ) );
							?>
						</button>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function page( string $key ): void {
		$type = Types::get( $key );
		if ( ! $type || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = null;
		if ( isset( $_GET['imported'] ) ) {
			$result = get_transient( 'postful_import_' . get_current_user_id() );
			delete_transient( 'postful_import_' . get_current_user_id() );
		}
		$token = isset( $_GET['match'] ) ? sanitize_key( wp_unslash( $_GET['match'] ) ) : '';
		// phpcs:enable
		$locales = Types::locales( $type );
		$post    = admin_url( 'admin-post.php' );
		?>
		<div class="wrap pf-wrap">
			<header class="pf-page-head">
				<div>
					<p class="pf-eyebrow"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key ) ); ?>"><?php echo esc_html( $type['plural'] ); ?></a></p>
					<h1 class="pf-title"><?php esc_html_e( 'Import & export', 'postful' ); ?></h1>
					<p class="pf-sub"><?php esc_html_e( 'One Excel workbook per language, one sheet per month, one row per day. Import English and French separately, in any order: rows are matched by date.', 'postful' ); ?></p>
				</div>
			</header>

			<?php self::render_result( $type, $result ); ?>

			<?php
			$data = $token ? self::load( $type, $token ) : null;
			if ( $token && ! $data ) {
				echo '<div class="pf-callout pf-callout--error">' . esc_html__( 'That upload has expired. Please choose the file again.', 'postful' ) . '</div>';
			}
			if ( $data ) {
				self::match_screen( $type, $token, $data );
				echo '</div>';
				return;
			}
			?>

			<div class="pf-columns">
				<form class="pf-panel" method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data">
					<h2 class="pf-panel-title"><?php esc_html_e( 'Import a workbook', 'postful' ); ?></h2>
					<input type="hidden" name="action" value="postful_import_upload">
					<input type="hidden" name="type" value="<?php echo esc_attr( $key ); ?>">
					<?php wp_nonce_field( 'postful_import_upload_' . $key ); ?>

					<fieldset class="pf-inline-choice pf-inline-choice--block">
						<legend><?php esc_html_e( 'This file is in', 'postful' ); ?></legend>
						<?php foreach ( Types::lang_codes( $type ) as $i => $code ) : ?>
							<label><input type="radio" name="lang" value="<?php echo esc_attr( $code ); ?>" <?php checked( 0, $i ); ?>> <?php echo esc_html( Languages::name( $locales[ $code ] ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>

					<label class="pf-drop" data-pf-drop>
						<input type="file" name="sheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
						<span class="pf-drop-text" data-pf-drop-text><?php esc_html_e( 'Drop your Excel workbook here, or click to choose', 'postful' ); ?></span>
					</label>

					<ul class="pf-notes">
						<li><?php esc_html_e( 'Every month sheet is read. Dates like “1er janvier” or “January 1” both work.', 'postful' ); ?></li>
						<li><?php esc_html_e( 'Next you’ll match the columns to your fields and see a preview. Nothing is saved until you confirm.', 'postful' ); ?></li>
						<li><?php esc_html_e( 'Empty cells never overwrite. Importing the same file again just updates.', 'postful' ); ?></li>
					</ul>

					<div class="pf-panel-foot">
						<button type="submit" class="pf-btn pf-btn--primary"><?php esc_html_e( 'Next: match columns', 'postful' ); ?></button>
					</div>
				</form>

				<div class="pf-panel">
					<h2 class="pf-panel-title"><?php esc_html_e( 'Export', 'postful' ); ?></h2>
					<p class="pf-muted"><?php esc_html_e( 'Download everything in the same shape: one workbook per language, a sheet per month, with these columns:', 'postful' ); ?></p>
					<div class="pf-codeblock"><code><?php echo esc_html( implode( ' · ', array_merge( [ __( 'Date', 'postful' ) ], array_column( $type['fields'], 'label' ) ) ) ); ?></code></div>
					<div class="pf-btn-row">
						<?php foreach ( Types::lang_codes( $type ) as $code ) : ?>
							<a class="pf-btn pf-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'postful_export_xlsx', 'type' => $key, 'lang' => $code ], $post ), 'postful_export_xlsx_' . $key ) ); ?>">
								<?php
								/* translators: %s: language name */
								echo esc_html( sprintf( __( '%s workbook', 'postful' ), Languages::name( $locales[ $code ] ) ) );
								?>
							</a>
						<?php endforeach; ?>
						<a class="pf-btn pf-btn--text" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'postful_export_xlsx', 'type' => $key, 'template' => 1 ], $post ), 'postful_export_xlsx_' . $key ) ); ?>"><?php esc_html_e( 'Empty template', 'postful' ); ?></a>
					</div>
				</div>
			</div>

			<?php self::translate_panel( $type ); ?>
		</div>
		<?php
	}

	private static function render_result( array $type, $result ): void {
		if ( ! is_array( $result ) ) {
			return;
		}
		if ( ! empty( $result['fatal'] ) ) {
			echo '<div class="pf-callout pf-callout--error">' . esc_html( $result['fatal'] ) . '</div>';
			return;
		}
		$locales = Types::locales( $type );
		?>
		<div class="pf-panel pf-result">
			<p class="pf-result-head">
				<?php
				/* translators: 1: file name, 2: language name */
				echo esc_html( sprintf( __( 'Imported %1$s as %2$s.', 'postful' ), $result['file'] ?: __( 'the file', 'postful' ), Languages::name( $locales[ $result['lang'] ?? $type['default_lang'] ] ?? '' ) ) );
				?>
			</p>
			<div class="pf-stats">
				<div class="pf-stat"><span class="pf-stat-num"><?php echo (int) $result['created']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'new days', 'postful' ); ?></span></div>
				<div class="pf-stat"><span class="pf-stat-num"><?php echo (int) $result['updated']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'updated', 'postful' ); ?></span></div>
				<div class="pf-stat<?php echo $result['skipped'] ? ' pf-stat--warn' : ''; ?>"><span class="pf-stat-num"><?php echo (int) $result['skipped']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'skipped', 'postful' ); ?></span></div>
			</div>
			<?php foreach ( $result['warnings'] as $warning ) : ?>
				<p class="pf-callout pf-callout--warn"><?php echo esc_html( $warning ); ?></p>
			<?php endforeach; ?>
			<?php if ( $result['errors'] ) : ?>
				<table class="pf-table">
					<thead><tr><th><?php esc_html_e( 'Where', 'postful' ); ?></th><th><?php esc_html_e( 'Problem', 'postful' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $result['errors'] as [ $where, $message ] ) : ?>
						<tr><td class="pf-num"><?php echo esc_html( (string) $where ); ?></td><td><?php echo esc_html( $message ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function match_screen( array $type, string $token, array $data ): void {
		$headers = self::headers( $data );
		$suggest = self::suggest( $type, $headers );
		$locales = Types::locales( $type );
		$rows    = self::rows( $type, $data, $suggest );
		$total   = count( $rows );
		$readable = count( array_filter( $rows, fn( $r ) => $r['key'] ) );
		$preset  = Presets::get( $type['preset'] );
		$title   = Types::title_field( $type );
		$lang    = $data['lang'];
		?>
		<form class="pf-panel pf-match" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pf-match>
			<input type="hidden" name="action" value="postful_import_run">
			<input type="hidden" name="type" value="<?php echo esc_attr( $type['key'] ); ?>">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'postful_import_run_' . $type['key'] ); ?>

			<h2 class="pf-panel-title"><?php esc_html_e( 'Match the columns', 'postful' ); ?></h2>
			<p class="pf-muted">
				<?php
				/* translators: 1: file name, 2: number of sheets, 3: number of rows */
				echo esc_html( sprintf( __( '%1$s: %2$d sheet(s), %3$d rows.', 'postful' ), $data['file'], count( $data['sheets'] ), $total ) );
				?>
			</p>

			<fieldset class="pf-inline-choice pf-inline-choice--block">
				<legend><?php esc_html_e( 'This file is in', 'postful' ); ?></legend>
				<?php foreach ( Types::lang_codes( $type ) as $code ) : ?>
					<label><input type="radio" name="lang" value="<?php echo esc_attr( $code ); ?>" <?php checked( $lang, $code ); ?>> <?php echo esc_html( Languages::name( $locales[ $code ] ) ); ?></label>
				<?php endforeach; ?>
			</fieldset>

			<table class="pf-table pf-match-table">
				<thead><tr><th><?php esc_html_e( 'Column in your file', 'postful' ); ?></th><th><?php esc_html_e( 'Goes into', 'postful' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $headers as $norm => $label ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<select class="pf-text" name="map[<?php echo esc_attr( $norm ); ?>]">
								<option value=""><?php esc_html_e( '— Don’t import —', 'postful' ); ?></option>
								<option value="date" <?php selected( $suggest[ $norm ], 'date' ); ?>><?php esc_html_e( 'Date (which day)', 'postful' ); ?></option>
								<?php foreach ( $type['fields'] as $field ) : ?>
									<option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $suggest[ $norm ], $field['key'] ); ?>><?php echo esc_html( $field['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="pf-panel-subtitle"><?php esc_html_e( 'Preview', 'postful' ); ?></h3>
			<p class="pf-muted">
				<?php
				/* translators: 1: rows with a readable date, 2: all rows */
				echo esc_html( sprintf( __( '%1$d of %2$d rows have a date Postful can read.', 'postful' ), $readable, $total ) );
				?>
			</p>
			<table class="pf-table pf-preview-table">
				<thead><tr><th><?php esc_html_e( 'In the file', 'postful' ); ?></th><th><?php esc_html_e( 'Day', 'postful' ); ?></th><th><?php echo esc_html( $title ? $title['label'] : __( 'Content', 'postful' ) ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 5 ) as $row ) : ?>
					<tr>
						<td class="pf-num"><?php echo esc_html( $row['where'] . ( '' !== $row['raw_date'] ? ' · ' . $row['raw_date'] : '' ) ); ?></td>
						<td><?php echo $row['key'] ? esc_html( $preset->key_label( $row['key'] ) ) : '<span class="pf-key--unset">' . esc_html__( 'Unreadable', 'postful' ) . '</span>'; ?></td>
						<td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) ( $title ? ( $row['values'][ $title['key'] ] ?? '' ) : '' ) ), 12 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="pf-muted pf-small"><?php esc_html_e( 'The preview uses the matching shown when this page opened; change a column above and it applies when you import.', 'postful' ); ?></p>

			<fieldset class="pf-inline-choice">
				<legend><?php esc_html_e( 'New days are', 'postful' ); ?></legend>
				<label><input type="radio" name="default_status" value="publish" checked> <?php esc_html_e( 'Published', 'postful' ); ?></label>
				<label><input type="radio" name="default_status" value="draft"> <?php esc_html_e( 'Drafts', 'postful' ); ?></label>
			</fieldset>

			<div class="pf-panel-foot">
				<a class="pf-btn pf-btn--text" href="<?php echo esc_url( self::url( $type['key'] ) ); ?>"><?php esc_html_e( 'Cancel', 'postful' ); ?></a>
				<button type="submit" class="pf-btn pf-btn--primary">
					<?php
					/* translators: %d: number of rows */
					echo esc_html( sprintf( __( 'Import %d rows', 'postful' ), $total ) );
					?>
				</button>
			</div>
		</form>
		<?php
	}
}
