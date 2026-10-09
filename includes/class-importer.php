<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Per-type CSV import / export.
 *
 * Columns: the key columns (month, day), then {field}_{lang} for translated
 * fields and {field} for shared ones, plus an optional status column.
 * Re-runnable: rows match on the key and update; empty cells never overwrite.
 */
final class Importer {

	public static function hooks(): void {
		add_action( 'admin_menu', [ self::class, 'menus' ], 11 );
		add_action( 'admin_post_postful_csv_template', [ self::class, 'download_template' ] );
		add_action( 'admin_post_postful_csv_export', [ self::class, 'export' ] );
		add_action( 'admin_post_postful_csv_import', [ self::class, 'import' ] );
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

	public static function url( string $key ): string {
		return admin_url( 'admin.php?page=postful-io-' . $key );
	}

	public static function menus(): void {
		foreach ( Types::all() as $key => $type ) {
			// No menu item of its own (parent options.php keeps it out of the menu);
			// reached from the entries list and the content type card.
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

	/** Column → [field, lang|null]. Key columns are handled separately. */
	public static function columns( array $type ): array {
		$cols = [];
		foreach ( $type['fields'] as $field ) {
			if ( ! empty( $field['translatable'] ) ) {
				foreach ( Types::lang_codes( $type ) as $code ) {
					$cols[ $field['key'] . '_' . $code ] = [ $field, $code ];
				}
			} else {
				$cols[ $field['key'] ] = [ $field, null ];
			}
		}
		return $cols;
	}

	public static function header( array $type ): array {
		return array_merge( Presets::get( $type['preset'] )->key_columns(), array_keys( self::columns( $type ) ), [ 'status' ] );
	}

	private static function guard( string $action ): array {
		$key  = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$type = Types::get( $key );
		if ( ! $type || ! current_user_can( 'edit_others_posts' ) || ! check_admin_referer( $action . '_' . $key ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'postful' ), 403 );
		}
		return $type;
	}

	private static function send_csv( string $filename, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel opens accents correctly.
		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function download_template(): void {
		$type = self::guard( 'postful_csv_template' );
		self::send_csv( $type['key'] . '-template.csv', [ self::header( $type ) ] );
	}

	public static function export(): void {
		$type   = self::guard( 'postful_csv_export' );
		$preset = Presets::get( $type['preset'] );
		$cols   = self::columns( $type );
		$ids    = get_posts( [
			'post_type'        => $type['key'],
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		] );
		update_meta_cache( 'post', $ids );

		$rows = [];
		foreach ( $ids as $id ) {
			$key = $preset->key_of( (int) $id );
			$row = [];
			foreach ( $preset->key_columns() as $kc ) {
				$row[] = $key[ $kc ] ?? '';
			}
			foreach ( $cols as [ $field, $code ] ) {
				$row[] = Fields::value( (int) $id, $field, $code ?? $type['default_lang'] );
			}
			$row[]  = get_post_status( $id );
			$rows[] = [ $key ? $preset->sort_of( $key ) : 0, $row ];
		}
		usort( $rows, fn( $a, $b ) => $a[0] <=> $b[0] );

		self::send_csv(
			$type['key'] . '-' . gmdate( 'Y-m-d' ) . '.csv',
			array_merge( [ self::header( $type ) ], array_column( $rows, 1 ) )
		);
	}

	public static function import(): void {
		$type     = self::guard( 'postful_csv_import' );
		$redirect = self::url( $type['key'] );
		$status   = isset( $_POST['default_status'] ) && 'draft' === $_POST['default_status'] ? 'draft' : 'publish';

		try {
			$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! $file || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				throw new \RuntimeException( __( 'Choose a CSV file to import.', 'postful' ) );
			}
			$result = self::run( $type, (string) file_get_contents( $file['tmp_name'] ), $status ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} catch ( \Throwable $e ) {
			$result = [ 'fatal' => $e->getMessage() ];
		}

		set_transient( 'postful_import_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'imported', 1, $redirect ) );
		exit;
	}

	/**
	 * Import CSV text. Public so it can be exercised without an upload.
	 *
	 * @return array{created:int,updated:int,skipped:int,errors:array,warnings:array}
	 */
	public static function run( array $type, string $csv, string $default_status = 'publish' ): array {
		$result = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => [] ];

		// Excel often saves Windows-1252; normalise to UTF-8 and drop a BOM.
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			$csv = mb_convert_encoding( $csv, 'UTF-8', 'Windows-1252' );
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		// French Excel uses semicolons.
		$first = (string) strtok( $csv, "\n" );
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';

		$header = fgetcsv( $handle, 0, $delim, '"', '' );
		if ( ! $header ) {
			throw new \RuntimeException( __( 'The file is empty.', 'postful' ) );
		}
		$header = array_map( fn( $h ) => strtolower( trim( (string) $h ) ), $header );

		$preset  = Presets::get( $type['preset'] );
		$cols    = self::columns( $type );
		$missing = array_diff( $preset->key_columns(), $header );
		if ( $missing ) {
			/* translators: %s: column names */
			throw new \RuntimeException( sprintf( __( 'Missing required column(s): %s. Download the template to see the expected header.', 'postful' ), implode( ', ', $missing ) ) );
		}
		$known   = array_merge( $preset->key_columns(), array_keys( $cols ), [ 'status' ] );
		$unknown = array_diff( array_filter( $header ), $known );
		if ( $unknown ) {
			/* translators: %s: column names */
			$result['warnings'][] = sprintf( __( 'Ignored unknown column(s): %s', 'postful' ), implode( ', ', $unknown ) );
		}

		// Existing entries in any non-trashed status, by key.
		$existing = [];
		$ids      = get_posts( [
			'post_type'        => $type['key'],
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] );
		update_meta_cache( 'post', $ids );
		// Prefer the published entry (the one visitors see), then the oldest of any status.
		$published = [];
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

		$title_field = Types::title_field( $type );
		$statuses    = [ 'publish', 'draft', 'pending', 'private' ];
		$line        = 1;

		wp_defer_term_counting( true );
		while ( ( $cells = fgetcsv( $handle, 0, $delim, '"', '' ) ) !== false ) {
			++$line;
			if ( [ null ] === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}
			$row = [];
			foreach ( $header as $i => $col ) {
				if ( '' !== $col ) {
					$row[ $col ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				}
			}

			$key = $preset->parse_key( $row );
			if ( ! is_array( $key ) ) {
				$result['errors'][] = [ $line, is_wp_error( $key ) ? $key->get_error_message() : __( 'No day given.', 'postful' ) ];
				++$result['skipped'];
				continue;
			}

			$values   = [];
			$bad      = [];
			$imported = [];
			foreach ( $cols as $col => [ $field, $code ] ) {
				$raw = $row[ $col ] ?? '';
				if ( '' === $raw ) {
					continue; // Never overwrite with an empty cell.
				}
				$clean = Fields::sanitize( $field, 'toggle' === $field['type'] ? strtolower( $raw ) : $raw );
				if ( Fields::is_empty( $field, $clean ) ) {
					$bad[] = $col;
					continue;
				}
				$values[ Fields::meta_key( $field['key'], $code ) ] = $clean;
				if ( $code && $code !== $type['default_lang'] ) {
					$imported[ $code ][] = $field['key'];
				}
			}
			if ( $bad ) {
				/* translators: %s: column names */
				$result['errors'][] = [ $line, sprintf( __( 'Invalid value in %s (skipped those cells, imported the rest).', 'postful' ), implode( ', ', $bad ) ) ];
			}

			$row_status = strtolower( $row['status'] ?? '' );
			$row_status = in_array( $row_status, $statuses, true ) ? $row_status : '';
			$sort       = $preset->sort_of( $key );
			$post_id    = $existing[ $sort ] ?? 0;

			$title = '';
			if ( $title_field ) {
				$title = $values[ Fields::meta_key( $title_field['key'], ! empty( $title_field['translatable'] ) ? $type['default_lang'] : null ) ] ?? '';
			}

			if ( $post_id ) {
				$update = [ 'ID' => $post_id ];
				if ( '' !== $title ) {
					$update['post_title'] = $title;
				}
				if ( $row_status ) {
					$update['post_status'] = $row_status;
				}
				if ( count( $update ) > 1 ) {
					wp_update_post( wp_slash( $update ) );
				}
				++$result['updated'];
			} else {
				$post_id = wp_insert_post( wp_slash( [
					'post_type'   => $type['key'],
					'post_status' => $row_status ?: $default_status,
					'post_title'  => '' !== $title ? $title : $preset->key_label( $key ),
				] ), true );
				if ( is_wp_error( $post_id ) ) {
					$result['errors'][] = [ $line, $post_id->get_error_message() ];
					++$result['skipped'];
					continue;
				}
				$preset->save_key( $post_id, $key );
				$existing[ $sort ] = $post_id;
				++$result['created'];
			}

			foreach ( $values as $meta_key => $value ) {
				update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
			}
			// Imported text is human-provided: it replaces any machine draft in that field.
			foreach ( $imported as $code => $keys ) {
				Translator::clear_machine( $post_id, $code, $keys );
			}
		}
		wp_defer_term_counting( false );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		Index::flush( $type['key'] );
		return $result;
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
				<p class="pf-muted"><?php esc_html_e( 'Fills only empty fields that are set to auto-translate. Nothing a person wrote is overwritten. Every draft is marked “Needs review” and stays hidden from visitors until someone marks it reviewed.', 'postful' ); ?></p>
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
		$result = null;
		if ( isset( $_GET['imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = get_transient( 'postful_import_' . get_current_user_id() );
			delete_transient( 'postful_import_' . get_current_user_id() );
		}
		$header  = self::header( $type );
		$post    = admin_url( 'admin-post.php' );
		$example = [];
		foreach ( $header as $col ) {
			$example[] = match ( true ) {
				'month' === $col => '3',
				'day' === $col => '5',
				'status' === $col => 'publish',
				default => '…',
			};
		}
		?>
		<div class="wrap pf-wrap">
			<header class="pf-page-head">
				<div>
					<p class="pf-eyebrow"><?php echo esc_html( $type['plural'] ); ?></p>
					<h1 class="pf-title"><?php esc_html_e( 'Import & export', 'postful' ); ?></h1>
					<p class="pf-sub"><?php esc_html_e( 'Bring a whole year of entries in from a spreadsheet, then run it again any time to fill in or correct them.', 'postful' ); ?></p>
				</div>
			</header>

			<?php if ( is_array( $result ) ) : ?>
				<?php if ( ! empty( $result['fatal'] ) ) : ?>
					<div class="pf-callout pf-callout--error"><?php echo esc_html( $result['fatal'] ); ?></div>
				<?php else : ?>
					<div class="pf-panel pf-result">
						<div class="pf-stats">
							<div class="pf-stat"><span class="pf-stat-num"><?php echo (int) $result['created']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'created', 'postful' ); ?></span></div>
							<div class="pf-stat"><span class="pf-stat-num"><?php echo (int) $result['updated']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'updated', 'postful' ); ?></span></div>
							<div class="pf-stat<?php echo $result['skipped'] ? ' pf-stat--warn' : ''; ?>"><span class="pf-stat-num"><?php echo (int) $result['skipped']; ?></span><span class="pf-stat-label"><?php esc_html_e( 'skipped', 'postful' ); ?></span></div>
						</div>
						<?php foreach ( $result['warnings'] as $warning ) : ?>
							<p class="pf-callout pf-callout--warn"><?php echo esc_html( $warning ); ?></p>
						<?php endforeach; ?>
						<?php if ( $result['errors'] ) : ?>
							<table class="pf-table">
								<thead><tr><th><?php esc_html_e( 'Row', 'postful' ); ?></th><th><?php esc_html_e( 'Problem', 'postful' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $result['errors'] as [ $row, $message ] ) : ?>
									<tr><td class="pf-num"><?php echo (int) $row; ?></td><td><?php echo esc_html( $message ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php self::translate_panel( $type ); ?>

			<div class="pf-columns">
				<form class="pf-panel" method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data">
					<h2 class="pf-panel-title"><?php esc_html_e( 'Import', 'postful' ); ?></h2>
					<input type="hidden" name="action" value="postful_csv_import">
					<input type="hidden" name="type" value="<?php echo esc_attr( $key ); ?>">
					<?php wp_nonce_field( 'postful_csv_import_' . $key ); ?>

					<label class="pf-drop" data-pf-drop>
						<input type="file" name="csv" accept=".csv,text/csv" required>
						<span class="pf-drop-text" data-pf-drop-text><?php esc_html_e( 'Drop a CSV here, or click to choose', 'postful' ); ?></span>
					</label>

					<fieldset class="pf-inline-choice">
						<legend><?php esc_html_e( 'New entries are', 'postful' ); ?></legend>
						<label><input type="radio" name="default_status" value="publish" checked> <?php esc_html_e( 'Published', 'postful' ); ?></label>
						<label><input type="radio" name="default_status" value="draft"> <?php esc_html_e( 'Drafts', 'postful' ); ?></label>
					</fieldset>

					<ul class="pf-notes">
						<li><?php esc_html_e( 'Rows are matched by day. Existing entries are updated, new days are created.', 'postful' ); ?></li>
						<li><?php esc_html_e( 'Empty cells are ignored, so you can import English first and French later.', 'postful' ); ?></li>
						<li><?php esc_html_e( 'A problem in one row never stops the rest of the import.', 'postful' ); ?></li>
					</ul>

					<div class="pf-panel-foot">
						<button type="submit" class="pf-btn pf-btn--primary"><?php esc_html_e( 'Import', 'postful' ); ?></button>
					</div>
				</form>

				<div class="pf-panel">
					<h2 class="pf-panel-title"><?php esc_html_e( 'Spreadsheet shape', 'postful' ); ?></h2>
					<p class="pf-muted"><?php esc_html_e( 'One row per entry. Columns, in any order:', 'postful' ); ?></p>
					<div class="pf-codeblock"><code><?php echo esc_html( implode( ',', $header ) ); ?></code><code class="pf-muted"><?php echo esc_html( implode( ',', $example ) ); ?></code></div>
					<p class="pf-muted"><?php esc_html_e( 'The status column is optional (publish or draft). Rich text cells may contain simple HTML.', 'postful' ); ?></p>
					<div class="pf-btn-row">
						<a class="pf-btn pf-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'postful_csv_template', 'type' => $key ], $post ), 'postful_csv_template_' . $key ) ); ?>"><?php esc_html_e( 'Download template', 'postful' ); ?></a>
						<a class="pf-btn pf-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'postful_csv_export', 'type' => $key ], $post ), 'postful_csv_export_' . $key ) ); ?>"><?php esc_html_e( 'Export all entries', 'postful' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
