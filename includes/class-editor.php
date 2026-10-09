<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Entry edit screen: one canvas holding every language side by side, the key
 * picker in the sidebar, and a single save for all of it.
 */
final class Editor {

	public static function hooks(): void {
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'no_block_editor' ], 10, 2 );
		add_action( 'edit_form_after_title', [ self::class, 'canvas' ] );
		add_action( 'add_meta_boxes', [ self::class, 'meta_boxes' ], 10, 2 );
		add_action( 'save_post', [ self::class, 'save' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ self::class, 'sync_title' ], 10, 2 );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_filter( 'mce_css', [ self::class, 'mce_css' ] );
		add_filter( 'post_updated_messages', [ self::class, 'messages' ] );
		add_action( 'admin_notices', [ self::class, 'key_error_notice' ] );
	}

	/** An invalid day never wipes the saved one; say why it wasn't changed. */
	public static function key_error_notice(): void {
		if ( ! self::screen_type() ) {
			return;
		}
		$error = get_transient( 'postful_key_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'postful_key_error_' . get_current_user_id() );
			/* translators: %s: validation message */
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( __( 'The day was not changed: %s', 'postful' ), $error ) ) );
		}
	}

	private static function screen_type(): ?array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return null;
		}
		return Types::get( $screen->post_type );
	}

	public static function no_block_editor( bool $use, string $post_type ): bool {
		return Types::get( $post_type ) ? false : $use;
	}

	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && Types::get( $screen->post_type ) ) {
			$classes .= ' pf-admin ' . ( 'post' === $screen->base ? 'pf-entry-screen' : 'pf-list-screen' );
		}
		return $classes;
	}

	public static function assets(): void {
		$type = self::screen_type();
		if ( ! $type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'postful-admin', POSTFUL_URL . 'assets/admin.css', [], POSTFUL_VERSION );
		wp_enqueue_style( 'postful-editor', POSTFUL_URL . 'assets/editor.css', [ 'postful-admin' ], POSTFUL_VERSION );
		wp_enqueue_script( 'postful-editor', POSTFUL_URL . 'assets/editor.js', [], POSTFUL_VERSION, true );

		global $post;
		$preset = Presets::get( $type['preset'] );
		$taken  = [];
		$ids    = get_posts( [
			'post_type'        => $type['key'],
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'exclude'          => $post ? [ $post->ID ] : [],
			'suppress_filters' => true,
		] );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$key = $preset->key_of( (int) $id );
			if ( $key ) {
				$taken[ $preset->sort_of( $key ) ] = get_the_title( $id ) ?: __( '(untitled)', 'postful' );
			}
		}

		global $wp_locale;
		wp_localize_script( 'postful-editor', 'postfulEditor', [
			'taken'  => $taken,
			'months' => array_values( $wp_locale->month ),
			'i18n'   => [
				/* translators: %s: title of the other entry */
				'dup'         => __( 'Another entry already uses this day: “%s”. Only one will be shown.', 'postful' ),
				'monthDay'    => _x( '%1$s %2$d', 'month name, day number', 'postful' ),
				'chooseImage' => __( 'Choose image', 'postful' ),
				'chooseFile'  => __( 'Choose file', 'postful' ),
				'use'         => __( 'Use this', 'postful' ),
				/* translators: 1: filled fields, 2: total fields */
				'progress'    => __( '%1$d of %2$d', 'postful' ),
				'complete'    => __( 'Complete', 'postful' ),
			],
		] );
	}

	/** Make TinyMCE content match the canvas typography, on our screens only. */
	public static function mce_css( string $css ): string {
		if ( ! self::screen_type() ) {
			return $css;
		}
		$ours = POSTFUL_URL . 'assets/editor-content.css?ver=' . POSTFUL_VERSION;
		return $css ? $css . ',' . $ours : $ours;
	}

	public static function meta_boxes( string $post_type, $post ): void {
		$type = Types::get( $post_type );
		if ( ! $type ) {
			return;
		}
		$preset = Presets::get( $type['preset'] );
		add_meta_box(
			'postful-key',
			'recurring_day' === $preset->id() ? __( 'Day', 'postful' ) : __( 'Key', 'postful' ),
			fn( $p ) => $preset->render_key_box( $p, $type ),
			$post_type,
			'side',
			'high'
		);
		if ( empty( $type['public_urls'] ) ) {
			remove_meta_box( 'slugdiv', $post_type, 'normal' );
		}
	}

	public static function canvas( \WP_Post $post ): void {
		$type = Types::get( $post->post_type );
		if ( ! $type ) {
			return;
		}
		$codes   = Types::lang_codes( $type );
		$locales = Types::locales( $type );
		$multi   = count( $codes ) > 1;
		$title   = Types::title_field( $type );
		$shared  = array_values( array_filter( $type['fields'], fn( $f ) => empty( $f['translatable'] ) ) );
		$trans   = array_values( array_filter( $type['fields'], fn( $f ) => ! empty( $f['translatable'] ) ) );

		wp_nonce_field( 'postful_entry_' . $post->ID, 'postful_entry_nonce' );

		$missing = 'auto-draft' === $post->post_status ? [] : Renderer::missing_required( $type, $post->ID );
		?>
		<div class="pf-canvas" data-pf-canvas data-pf-default="<?php echo esc_attr( $type['default_lang'] ); ?>">
			<?php if ( $missing ) : ?>
				<div class="pf-callout pf-callout--warn">
					<strong><?php esc_html_e( 'Still empty:', 'postful' ); ?></strong>
					<?php echo esc_html( implode( ', ', $missing ) ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $shared ) : ?>
				<section class="pf-shared" aria-label="<?php esc_attr_e( 'Shared fields', 'postful' ); ?>">
					<?php
					foreach ( $shared as $field ) {
						self::cell( $post, $type, $field, null, '', $title && $field['key'] === $title['key'] );
					}
					?>
				</section>
			<?php endif; ?>

			<?php if ( $trans ) : ?>
				<div class="pf-grid<?php echo $multi ? ' pf-grid--cols-' . count( $codes ) : ' pf-grid--single'; ?>" style="<?php echo esc_attr( sprintf( '--pf-cols:%d;--pf-rows:%d', count( $codes ), count( $trans ) + ( $multi ? 1 : 0 ) ) ); ?>">
					<?php foreach ( $codes as $code ) : ?>
						<?php if ( $multi ) : ?>
							<div class="pf-lang-head" data-pf-lang="<?php echo esc_attr( $code ); ?>">
								<span class="pf-lang-name"><?php echo esc_html( Languages::name( $locales[ $code ] ) ); ?></span>
								<?php if ( $code === $type['default_lang'] ) : ?>
									<span class="pf-lang-tag"><?php esc_html_e( 'Default', 'postful' ); ?></span>
								<?php endif; ?>
								<span class="pf-lang-progress" data-pf-progress></span>
							</div>
						<?php endif; ?>
						<?php
						foreach ( $trans as $field ) {
							self::cell( $post, $type, $field, $code, $locales[ $code ], $title && $field['key'] === $title['key'] );
						}
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function cell( \WP_Post $post, array $type, array $field, ?string $code, string $locale, bool $is_title ): void {
		$lang_key = $code ?? '_';
		$id       = 'pf_' . $field['key'] . '_' . ( $code ?? 'x' );
		$value    = Fields::value( $post->ID, $field, $code ?? $type['default_lang'] );
		$labelled = ! in_array( $field['type'], [ 'richtext', 'image', 'file' ], true );
		?>
		<div class="pf-cell pf-cell--<?php echo esc_attr( $field['type'] ); ?><?php echo $is_title ? ' pf-cell--title' : ''; ?>"
			data-pf-field="<?php echo esc_attr( $field['key'] ); ?>"
			<?php echo $code ? 'data-pf-lang="' . esc_attr( $code ) . '"' : ''; ?>
			<?php echo $field['required'] ? 'data-pf-required' : ''; ?>>
			<?php if ( $labelled ) : ?>
				<label class="pf-label" for="<?php echo esc_attr( $id ); ?>">
			<?php else : ?>
				<span class="pf-label" id="<?php echo esc_attr( $id ); ?>_label">
			<?php endif; ?>
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( $field['required'] ) : ?><span class="pf-req" aria-label="<?php esc_attr_e( 'required', 'postful' ); ?>">•</span><?php endif; ?>
			<?php echo $labelled ? '</label>' : '</span>'; ?>
			<?php
			Fields::render_input( $field, $value, [
				'id'        => $id,
				'name'      => 'pf[' . $field['key'] . '][' . $lang_key . ']',
				'title'     => $is_title,
				'html_lang' => $code ? Languages::html_lang( $locale ) : null,
			] );
			?>
			<?php if ( $field['help'] ) : ?>
				<p class="pf-help"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function verified( int $post_id ): bool {
		$nonce = isset( $_POST['postful_entry_nonce'] ) ? sanitize_key( wp_unslash( $_POST['postful_entry_nonce'] ) ) : '';
		return $nonce && wp_verify_nonce( $nonce, 'postful_entry_' . $post_id );
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		$type = Types::get( $post->post_type );
		if ( ! $type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! self::verified( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$preset = Presets::get( $type['preset'] );
		$raw    = isset( $_POST['pf_key'] ) && is_array( $_POST['pf_key'] ) ? wp_unslash( $_POST['pf_key'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by the preset
		$key    = $preset->parse_key( $raw );
		if ( is_wp_error( $key ) ) {
			set_transient( 'postful_key_error_' . get_current_user_id(), $key->get_error_message(), MINUTE_IN_SECONDS );
		} else {
			$preset->save_key( $post_id, $key );
		}

		$input = isset( $_POST['pf'] ) && is_array( $_POST['pf'] ) ? wp_unslash( $_POST['pf'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below
		foreach ( $type['fields'] as $field ) {
			$codes = ! empty( $field['translatable'] ) ? Types::lang_codes( $type ) : [ null ];
			foreach ( $codes as $code ) {
				$value    = Fields::sanitize( $field, $input[ $field['key'] ][ $code ?? '_' ] ?? '' );
				$meta_key = Fields::meta_key( $field['key'], $code );
				if ( Fields::is_empty( $field, $value ) ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
				}
			}
		}
		Index::flush( $type['key'] );
	}

	/**
	 * The default language's first single-line field becomes post_title, so the
	 * list screen, search and revisions stay readable. Falls back to the day.
	 */
	public static function sync_title( array $data, array $postarr ): array {
		$type = Types::get( $data['post_type'] ?? '' );
		if ( ! $type || empty( $postarr['ID'] ) || ! self::verified( (int) $postarr['ID'] ) ) {
			return $data;
		}
		$input = isset( $_POST['pf'] ) && is_array( $_POST['pf'] ) ? wp_unslash( $_POST['pf'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$field = Types::title_field( $type );
		$title = '';
		if ( $field ) {
			$slot  = ! empty( $field['translatable'] ) ? $type['default_lang'] : '_';
			$title = sanitize_text_field( $input[ $field['key'] ][ $slot ] ?? '' );
		}
		if ( '' === $title ) {
			$preset = Presets::get( $type['preset'] );
			$key    = $preset->parse_key( isset( $_POST['pf_key'] ) && is_array( $_POST['pf_key'] ) ? wp_unslash( $_POST['pf_key'] ) : [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$title  = is_array( $key ) ? $preset->key_label( $key ) : '';
		}
		$data['post_title'] = wp_slash( $title );
		return $data;
	}

	/** Entries have no public URL by default, so drop the "View" links from notices. */
	public static function messages( array $messages ): array {
		global $post;
		$type = $post ? Types::get( $post->post_type ) : null;
		if ( ! $type || ! empty( $type['public_urls'] ) ) {
			return $messages;
		}
		$s = $type['singular'];
		/* translators: %s: singular type name */
		$saved = sprintf( __( '%s saved.', 'postful' ), $s );
		$messages[ $type['key'] ] = [
			0  => '',
			1  => $saved,
			4  => $saved,
			5  => $saved,
			/* translators: %s: singular type name */
			6  => sprintf( __( '%s published.', 'postful' ), $s ),
			7  => $saved,
			/* translators: %s: singular type name */
			8  => sprintf( __( '%s submitted.', 'postful' ), $s ),
			/* translators: %s: singular type name */
			9  => sprintf( __( '%s scheduled.', 'postful' ), $s ),
			/* translators: %s: singular type name */
			10 => sprintf( __( '%s draft updated.', 'postful' ), $s ),
		];
		return $messages;
	}
}
