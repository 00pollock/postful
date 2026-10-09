<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Postful menu: content type list, the onboarding wizard (also used for
 * editing a type), settings, and type config JSON import/export.
 */
final class Admin {

	public static function hooks(): void {
		add_action( 'admin_menu', [ self::class, 'menus' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_post_postful_save_type', [ self::class, 'save_type' ] );
		add_action( 'admin_post_postful_delete_type', [ self::class, 'delete_type' ] );
		add_action( 'admin_post_postful_export_type', [ self::class, 'export_type' ] );
		add_action( 'admin_post_postful_import_type', [ self::class, 'import_type' ] );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
	}

	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		return $screen && str_contains( $screen->id, 'postful' ) ? $classes . ' pf-admin' : $classes;
	}

	public static function menus(): void {
		add_menu_page( 'Postful', 'Postful', 'manage_options', 'postful', [ self::class, 'types_page' ], Plugin::menu_icon(), 80 );
		add_submenu_page( 'postful', __( 'Content types', 'postful' ), __( 'Content types', 'postful' ), 'manage_options', 'postful', [ self::class, 'types_page' ] );
		add_submenu_page( 'postful', __( 'Add content type', 'postful' ), __( 'Add content type', 'postful' ), 'manage_options', 'postful-type', [ self::class, 'wizard_page' ] );
		add_submenu_page( 'postful', __( 'Settings', 'postful' ), __( 'Settings', 'postful' ), 'manage_options', 'postful-settings', [ Settings::class, 'render_page' ] );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'postful' ) ) {
			return;
		}
		wp_enqueue_style( 'postful-admin', POSTFUL_URL . 'assets/admin.css', [], POSTFUL_VERSION );
		wp_enqueue_script( 'postful-admin', POSTFUL_URL . 'assets/admin.js', [], POSTFUL_VERSION, true );
		wp_localize_script( 'postful-admin', 'postfulAdmin', [
			'i18n' => [
				'copied'        => __( 'Copied', 'postful' ),
				'copy'          => __( 'Copy', 'postful' ),
				'removeLocked'  => __( "Remove this field?\n\nEntries already have content saved under this field. It stays in the database but will no longer be shown or editable. To rename a key, remove the field and add a new one.", 'postful' ),
				'deleteType'    => __( "Delete this content type?\n\nIts entries are NOT deleted. They stay in the database and come back if you recreate a type with the same internal key.", 'postful' ),
				'needLanguage'  => __( 'Choose at least one language.', 'postful' ),
				'needName'      => __( 'Give it a plural and a singular name.', 'postful' ),
				'needField'     => __( 'Add at least one field.', 'postful' ),
				'fileChosen'    => __( 'Ready to import: %s', 'postful' ),
			],
		] );
	}

	// ------------------------------------------------------------------
	// Flash messages across redirects
	// ------------------------------------------------------------------

	private static function flash( string $message, string $kind = 'success' ): void {
		set_transient( 'postful_flash_' . get_current_user_id(), [ $message, $kind ], MINUTE_IN_SECONDS );
	}

	private static function render_flash(): void {
		$flash = get_transient( 'postful_flash_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'postful_flash_' . get_current_user_id() );
			printf( '<div class="pf-callout pf-callout--%s">%s</div>', esc_attr( $flash[1] ), esc_html( $flash[0] ) );
		}
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce_action ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'postful' ), 403 );
		}
	}

	// ------------------------------------------------------------------
	// Content types list
	// ------------------------------------------------------------------

	public static function types_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$types   = Types::all();
		$new_url = admin_url( 'admin.php?page=postful-type' );
		$post    = admin_url( 'admin-post.php' );
		?>
		<div class="wrap pf-wrap">
			<header class="pf-page-head">
				<div>
					<h1 class="pf-title"><?php esc_html_e( 'Content types', 'postful' ); ?></h1>
					<p class="pf-sub"><?php esc_html_e( 'Structured content with fields and languages, edited side by side.', 'postful' ); ?></p>
				</div>
				<?php if ( $types ) : ?>
					<a class="pf-btn pf-btn--primary" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Add content type', 'postful' ); ?></a>
				<?php endif; ?>
			</header>

			<?php self::render_flash(); ?>

			<?php if ( ! $types ) : ?>
				<div class="pf-empty">
					<div class="pf-empty-art" aria-hidden="true"><?php echo Presets::get( 'recurring_day' )->diagram(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<h2><?php esc_html_e( 'Create your first content type', 'postful' ); ?></h2>
					<p><?php esc_html_e( 'A daily devotional, a lesson series, a glossary — pick a structure, choose languages and fields, and start writing. It takes about a minute.', 'postful' ); ?></p>
					<a class="pf-btn pf-btn--primary pf-btn--lg" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Add content type', 'postful' ); ?></a>
				</div>
			<?php else : ?>
				<div class="pf-cards">
					<?php foreach ( $types as $key => $type ) : ?>
						<?php
						$preset  = Presets::get( $type['preset'] );
						$counts  = wp_count_posts( $key );
						$total   = (int) ( $counts->publish ?? 0 );
						$drafts  = (int) ( $counts->draft ?? 0 );
						$locales = Types::locales( $type );
						?>
						<article class="pf-card">
							<header class="pf-card-head">
								<span class="pf-card-icon dashicons <?php echo esc_attr( $type['icon'] ); ?>" aria-hidden="true"></span>
								<div>
									<h2 class="pf-card-title"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key ) ); ?>"><?php echo esc_html( $type['plural'] ); ?></a></h2>
									<p class="pf-card-meta"><?php echo esc_html( $preset ? $preset->label() : $type['preset'] ); ?> · <code><?php echo esc_html( $key ); ?></code></p>
								</div>
							</header>
							<div class="pf-card-body">
								<p class="pf-card-stat">
									<?php
									/* translators: %d: number of published entries */
									echo esc_html( sprintf( _n( '%d published', '%d published', $total, 'postful' ), $total ) );
									if ( $drafts ) {
										/* translators: %d: number of drafts */
										echo ' · ' . esc_html( sprintf( _n( '%d draft', '%d drafts', $drafts, 'postful' ), $drafts ) );
									}
									?>
								</p>
								<p class="pf-chips">
									<?php foreach ( Types::lang_codes( $type ) as $code ) : ?>
										<span class="pf-chip<?php echo $code === $type['default_lang'] ? ' pf-chip--strong' : ''; ?>"><?php echo esc_html( Languages::name( $locales[ $code ] ) ); ?></span>
									<?php endforeach; ?>
								</p>
								<div class="pf-shortcodes">
									<?php foreach ( [ 'sp_current', 'sp_index', 'sp_nav' ] as $sc ) : ?>
										<?php self::copy_code( sprintf( '[%s type="%s"]', $sc, $key ) ); ?>
									<?php endforeach; ?>
								</div>
							</div>
							<footer class="pf-card-foot">
								<a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $key ) ); ?>"><?php esc_html_e( 'New entry', 'postful' ); ?></a>
								<a class="pf-btn pf-btn--text pf-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=postful-type&type=' . $key ) ); ?>"><?php esc_html_e( 'Edit type', 'postful' ); ?></a>
								<a class="pf-btn pf-btn--text pf-btn--sm" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key . '&page=postful-io-' . $key ) ); ?>"><?php esc_html_e( 'Import / export', 'postful' ); ?></a>
								<span class="pf-spacer"></span>
								<a class="pf-btn pf-btn--text pf-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'postful_export_type', 'type' => $key ], $post ), 'postful_export_type_' . $key ) ); ?>"><?php esc_html_e( 'JSON', 'postful' ); ?></a>
								<form method="post" action="<?php echo esc_url( $post ); ?>" data-pf-confirm="deleteType">
									<input type="hidden" name="action" value="postful_delete_type">
									<input type="hidden" name="type" value="<?php echo esc_attr( $key ); ?>">
									<?php wp_nonce_field( 'postful_delete_type_' . $key ); ?>
									<button type="submit" class="pf-btn pf-btn--text pf-btn--sm pf-btn--danger"><?php esc_html_e( 'Delete', 'postful' ); ?></button>
								</form>
							</footer>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<details class="pf-panel pf-panel--quiet pf-import-json">
				<summary><?php esc_html_e( 'Import a content type from another site', 'postful' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data" class="pf-import-json-form">
					<input type="hidden" name="action" value="postful_import_type">
					<?php wp_nonce_field( 'postful_import_type' ); ?>
					<p class="pf-muted"><?php esc_html_e( 'Upload the JSON file exported from another site’s Postful. This copies the type’s setup only (fields, languages, strings), not its entries — use CSV for those.', 'postful' ); ?></p>
					<input type="file" name="config" accept=".json,application/json" required>
					<button type="submit" class="pf-btn pf-btn--ghost pf-btn--sm"><?php esc_html_e( 'Import', 'postful' ); ?></button>
				</form>
			</details>
		</div>
		<?php
	}

	public static function copy_code( string $code ): void {
		printf(
			'<div class="pf-copy"><code>%1$s</code><button type="button" class="pf-copy-btn" data-pf-copy="%2$s">%3$s</button></div>',
			esc_html( $code ),
			esc_attr( $code ),
			esc_html__( 'Copy', 'postful' )
		);
	}

	// ------------------------------------------------------------------
	// Wizard
	// ------------------------------------------------------------------

	public static function wizard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$key  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable

		$existing = Types::get( $key );
		if ( 'done' === $step && $existing ) {
			self::done_page( $existing );
			return;
		}

		$draft  = get_transient( 'postful_wizard_' . get_current_user_id() );
		delete_transient( 'postful_wizard_' . get_current_user_id() );
		$editing = (bool) $existing;
		$preset  = Presets::get( 'recurring_day' );
		$config  = $existing ?: wp_parse_args( [
			'preset'      => $preset->id(),
			'languages'   => [ 'en_US', 'fr_FR' ],
			'fields'      => $preset->default_fields(),
			'public_urls' => $preset->default_public_urls(),
		], Types::defaults() );
		if ( is_array( $draft ) ) {
			$config = wp_parse_args( $draft, $config );
		}

		$default_locale = Types::locales( $config )[ $config['default_lang'] ] ?? ( $config['languages'][0] ?? 'en_US' );
		$strings        = [];
		foreach ( Languages::common() as $locale => $name ) {
			$code = Languages::code( $locale );
			$strings[ $code ] = [
				'defaults' => Languages::default_strings( $code ),
				'saved'    => $config['strings'][ $code ] ?? [],
			];
		}

		$icons = [
			'dashicons-book-alt'          => __( 'Book', 'postful' ),
			'dashicons-calendar-alt'      => __( 'Calendar', 'postful' ),
			'dashicons-format-quote'      => __( 'Quote', 'postful' ),
			'dashicons-heart'             => __( 'Heart', 'postful' ),
			'dashicons-star-filled'       => __( 'Star', 'postful' ),
			'dashicons-megaphone'         => __( 'Megaphone', 'postful' ),
			'dashicons-welcome-learn-more' => __( 'Lesson', 'postful' ),
			'dashicons-groups'            => __( 'People', 'postful' ),
			'dashicons-microphone'        => __( 'Microphone', 'postful' ),
			'dashicons-media-document'    => __( 'Document', 'postful' ),
			'dashicons-admin-post'        => __( 'Pin', 'postful' ),
		];
		?>
		<div class="wrap pf-wrap pf-wrap--narrow">
			<header class="pf-page-head">
				<div>
					<p class="pf-eyebrow"><a href="<?php echo esc_url( admin_url( 'admin.php?page=postful' ) ); ?>"><?php esc_html_e( 'Content types', 'postful' ); ?></a></p>
					<h1 class="pf-title">
						<?php
						echo $editing
							/* translators: %s: content type plural name */
							? esc_html( sprintf( __( 'Edit %s', 'postful' ), $existing['plural'] ) )
							: esc_html__( 'New content type', 'postful' );
						?>
					</h1>
				</div>
			</header>

			<?php self::render_flash(); ?>

			<form class="pf-wizard" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pf-wizard novalidate>
				<input type="hidden" name="action" value="postful_save_type">
				<input type="hidden" name="editing" value="<?php echo esc_attr( $editing ? $existing['key'] : '' ); ?>">
				<?php wp_nonce_field( 'postful_save_type' ); ?>
				<script type="application/json" data-pf-wizard-data><?php echo wp_json_encode( [ 'strings' => $strings, 'stringKeys' => Languages::string_keys(), 'names' => Languages::common() ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>

				<ol class="pf-stepper" data-pf-stepper>
					<?php foreach ( [ __( 'Name', 'postful' ), __( 'Structure', 'postful' ), __( 'Languages', 'postful' ), __( 'Fields', 'postful' ) ] as $i => $label ) : ?>
						<li><button type="button" data-pf-goto="<?php echo (int) $i + 1; ?>"><span class="pf-stepper-num"><?php echo (int) $i + 1; ?></span><?php echo esc_html( $label ); ?></button></li>
					<?php endforeach; ?>
				</ol>

				<!-- 1. Name -->
				<section class="pf-step" data-pf-step="1">
					<h2 class="pf-step-title"><?php esc_html_e( 'What are you publishing?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Use the words your team already uses. These appear in the admin menu.', 'postful' ); ?></p>
					<div class="pf-form-row pf-form-row--2">
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Plural name', 'postful' ); ?></span>
							<input type="text" class="pf-text" name="plural" value="<?php echo esc_attr( $config['plural'] ); ?>" placeholder="<?php esc_attr_e( 'Devotionals', 'postful' ); ?>" data-pf-plural required>
						</label>
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Singular name', 'postful' ); ?></span>
							<input type="text" class="pf-text" name="singular" value="<?php echo esc_attr( $config['singular'] ); ?>" placeholder="<?php esc_attr_e( 'Devotional', 'postful' ); ?>" data-pf-singular required>
						</label>
					</div>
					<label class="pf-form-field pf-keyfield">
						<span class="pf-form-label"><?php esc_html_e( 'Internal key', 'postful' ); ?></span>
						<input type="text" class="pf-text pf-mono" name="key" value="<?php echo esc_attr( $config['key'] ); ?>" maxlength="20" pattern="[a-z0-9_\-]+" data-pf-key <?php echo $editing ? 'readonly' : ''; ?>>
						<span class="pf-form-help">
							<?php
							echo $editing
								? esc_html__( 'Locked: entries and shortcodes depend on it.', 'postful' )
								: esc_html__( 'Used in shortcodes. Filled in from the name; you can change it now but not after creating.', 'postful' );
							?>
						</span>
					</label>
					<details class="pf-advanced">
						<summary><?php esc_html_e( 'Advanced', 'postful' ); ?></summary>
						<div class="pf-form-row pf-form-row--3">
							<label class="pf-form-field">
								<span class="pf-form-label"><?php esc_html_e( 'Menu icon', 'postful' ); ?></span>
								<select class="pf-text" name="icon">
									<?php foreach ( $icons as $icon => $label ) : ?>
										<option value="<?php echo esc_attr( $icon ); ?>" <?php selected( $config['icon'], $icon ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="pf-form-field">
								<span class="pf-form-label"><?php esc_html_e( 'Menu position', 'postful' ); ?></span>
								<input type="number" class="pf-text" name="position" min="2" max="100" value="<?php echo (int) $config['position']; ?>">
							</label>
							<label class="pf-form-field">
								<span class="pf-form-label"><?php esc_html_e( 'URL slug', 'postful' ); ?></span>
								<input type="text" class="pf-text pf-mono" name="slug" value="<?php echo esc_attr( $config['slug'] ); ?>" placeholder="<?php esc_attr_e( 'same as key', 'postful' ); ?>">
								<span class="pf-form-help"><?php esc_html_e( 'Only used if entries have their own URLs.', 'postful' ); ?></span>
							</label>
						</div>
					</details>
				</section>

				<!-- 2. Structure -->
				<section class="pf-step" data-pf-step="2">
					<h2 class="pf-step-title"><?php esc_html_e( 'How are entries organised?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'This decides what identifies an entry, how they are listed, and which one visitors see first.', 'postful' ); ?></p>
					<div class="pf-presets">
						<?php foreach ( Presets::all() as $p ) : ?>
							<?php $disabled = ! $p->available() || ( $editing && $p->id() !== $config['preset'] ); ?>
							<label class="pf-preset<?php echo $disabled ? ' is-disabled' : ''; ?>">
								<input type="radio" name="preset" value="<?php echo esc_attr( $p->id() ); ?>" <?php checked( $config['preset'], $p->id() ); ?> <?php disabled( $disabled ); ?>>
								<span class="pf-preset-art"><?php echo $p->diagram(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
								<span class="pf-preset-text">
									<span class="pf-preset-name">
										<?php echo esc_html( $p->label() ); ?>
										<?php if ( ! $p->available() ) : ?><span class="pf-tag"><?php esc_html_e( 'Coming soon', 'postful' ); ?></span><?php endif; ?>
									</span>
									<span class="pf-preset-desc"><?php echo esc_html( $p->description() ); ?></span>
									<span class="pf-preset-for"><?php echo esc_html( $p->good_for() ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<?php if ( $editing ) : ?>
						<p class="pf-form-help"><?php esc_html_e( 'The structure can’t be changed after creation, because entries are keyed by it.', 'postful' ); ?></p>
					<?php endif; ?>
					<details class="pf-advanced">
						<summary><?php esc_html_e( 'Advanced', 'postful' ); ?></summary>
						<label class="pf-setting">
							<span class="pf-setting-text">
								<span class="pf-setting-label"><?php esc_html_e( 'Give each entry its own URL', 'postful' ); ?></span>
								<span class="pf-setting-help"><?php esc_html_e( 'Off for Recurring day: everything is read on one page whose address never changes, so a daily email can always link to the same URL.', 'postful' ); ?></span>
							</span>
							<span class="pf-switch">
								<input type="checkbox" name="public_urls" value="1" <?php checked( $config['public_urls'] ); ?>>
								<span class="pf-switch-track" aria-hidden="true"></span>
							</span>
						</label>
					</details>
				</section>

				<!-- 3. Languages -->
				<section class="pf-step" data-pf-step="3">
					<h2 class="pf-step-title"><?php esc_html_e( 'Which languages will you write in?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Each entry holds every language. Visitors see the one your site (or GTranslate) is showing.', 'postful' ); ?></p>
					<div class="pf-lang-grid" data-pf-langs>
						<?php foreach ( Languages::common() as $locale => $name ) : ?>
							<label class="pf-lang-option">
								<input type="checkbox" name="languages[]" value="<?php echo esc_attr( $locale ); ?>" <?php checked( in_array( $locale, $config['languages'], true ) ); ?>>
								<span class="pf-lang-option-name"><?php echo esc_html( $name ); ?></span>
								<span class="pf-lang-option-code"><?php echo esc_html( $locale ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<label class="pf-form-field pf-form-field--inline">
						<span class="pf-form-label"><?php esc_html_e( 'Default language', 'postful' ); ?></span>
						<select class="pf-text" name="default_locale" data-pf-default-locale data-selected="<?php echo esc_attr( $default_locale ); ?>"></select>
						<span class="pf-form-help"><?php esc_html_e( 'Shown to visitors whose language you don’t support.', 'postful' ); ?></span>
					</label>
					<p class="pf-note"><?php esc_html_e( 'Field labels in the editor stay in one language (yours). Only the content is written per language.', 'postful' ); ?></p>
					<details class="pf-advanced">
						<summary><?php esc_html_e( 'Advanced', 'postful' ); ?></summary>
						<fieldset class="pf-radio-cards">
							<legend class="pf-form-label"><?php esc_html_e( 'When a translation is missing', 'postful' ); ?></legend>
							<label><input type="radio" name="fallback" value="field" <?php checked( $config['fallback'], 'field' ); ?>><span><strong><?php esc_html_e( 'Fill the gap from the default language', 'postful' ); ?></strong><?php esc_html_e( 'Per field. A French reader sees French where it exists and English where it doesn’t.', 'postful' ); ?></span></label>
							<label><input type="radio" name="fallback" value="hide" <?php checked( $config['fallback'], 'hide' ); ?>><span><strong><?php esc_html_e( 'Hide the entry', 'postful' ); ?></strong><?php esc_html_e( 'Show the “no entry” message instead of a partly translated one.', 'postful' ); ?></span></label>
						</fieldset>
					</details>
				</section>

				<!-- 4. Fields -->
				<section class="pf-step" data-pf-step="4">
					<h2 class="pf-step-title"><?php esc_html_e( 'What does each entry contain?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Rename, reorder or remove these. Translated fields are written once per language.', 'postful' ); ?></p>
					<ol class="pf-fields" data-pf-fields>
						<?php foreach ( $config['fields'] as $i => $field ) : ?>
							<?php self::field_row( (string) $i, $field, $editing && isset( $existing ) && in_array( $field['key'], array_column( $existing['fields'], 'key' ), true ) ); ?>
						<?php endforeach; ?>
					</ol>
					<template data-pf-field-template><?php self::field_row( '__i__', [ 'key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'help' => '', 'translatable' => true, 'choices' => [] ], false ); ?></template>
					<div class="pf-add-field">
						<span class="pf-muted"><?php esc_html_e( 'Add a field:', 'postful' ); ?></span>
						<?php foreach ( Fields::types() as $t => $label ) : ?>
							<button type="button" class="pf-chip pf-chip--button" data-pf-add-field="<?php echo esc_attr( $t ); ?>">+ <?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
					<details class="pf-advanced">
						<summary><?php esc_html_e( 'Advanced: display', 'postful' ); ?></summary>
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Template', 'postful' ); ?></span>
							<textarea class="pf-text pf-mono" name="template" rows="5" placeholder="<h2>{heading}</h2>&#10;<blockquote>{verse_text} <cite>{verse_reference}</cite></blockquote>&#10;{body}"><?php echo esc_textarea( $config['template'] ); ?></textarea>
							<span class="pf-form-help"><?php esc_html_e( 'Optional. Use {field_key} placeholders and {date}. Leave empty to show fields in order, each wrapped in a .sp-field-{key} class.', 'postful' ); ?></span>
						</label>
						<div class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Words the plugin shows visitors', 'postful' ); ?></span>
							<span class="pf-form-help"><?php esc_html_e( 'One version per language. Leave a box empty to use the default shown in grey.', 'postful' ); ?></span>
							<div class="pf-strings" data-pf-strings></div>
						</div>
					</details>
				</section>

				<footer class="pf-wizard-foot">
					<button type="button" class="pf-btn pf-btn--text" data-pf-back><?php esc_html_e( 'Back', 'postful' ); ?></button>
					<span class="pf-wizard-error" data-pf-error role="alert"></span>
					<button type="button" class="pf-btn pf-btn--primary" data-pf-next><?php esc_html_e( 'Continue', 'postful' ); ?></button>
					<button type="submit" class="pf-btn pf-btn--primary" data-pf-submit>
						<?php echo $editing ? esc_html__( 'Save changes', 'postful' ) : esc_html__( 'Create content type', 'postful' ); ?>
					</button>
				</footer>
			</form>
		</div>
		<?php
	}

	private static function field_row( string $i, array $field, bool $locked ): void {
		$name = fn( $k ) => esc_attr( "fields[{$i}][{$k}]" );
		?>
		<li class="pf-field-row" data-pf-row <?php echo $locked ? 'data-pf-locked' : ''; ?>>
			<div class="pf-field-main">
				<span class="pf-field-order">
					<button type="button" class="pf-icon-btn" data-pf-move="-1" aria-label="<?php esc_attr_e( 'Move up', 'postful' ); ?>">↑</button>
					<button type="button" class="pf-icon-btn" data-pf-move="1" aria-label="<?php esc_attr_e( 'Move down', 'postful' ); ?>">↓</button>
				</span>
				<input type="text" class="pf-text pf-field-label" name="<?php echo $name( 'label' ); ?>" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'Field label', 'postful' ); ?>" aria-label="<?php esc_attr_e( 'Field label', 'postful' ); ?>" data-pf-field-label>
				<select class="pf-text pf-field-type" name="<?php echo $name( 'type' ); ?>" aria-label="<?php esc_attr_e( 'Field type', 'postful' ); ?>" data-pf-field-type>
					<?php foreach ( Fields::types() as $t => $label ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="pf-check" data-pf-translatable-wrap <?php echo $locked ? 'title="' . esc_attr__( 'Fixed once entries exist, so saved content stays where it is.', 'postful' ) . '"' : ''; ?>>
					<?php if ( $locked && ! empty( $field['translatable'] ) ) : ?><input type="hidden" name="<?php echo $name( 'translatable' ); ?>" value="1"><?php endif; ?>
					<input type="checkbox" name="<?php echo $name( 'translatable' ); ?>" value="1" <?php checked( ! empty( $field['translatable'] ) ); ?> <?php disabled( $locked ); ?>>
					<?php esc_html_e( 'Translated', 'postful' ); ?>
				</label>
				<button type="button" class="pf-icon-btn" data-pf-more aria-expanded="false" aria-label="<?php esc_attr_e( 'Field options', 'postful' ); ?>">⋯</button>
				<button type="button" class="pf-icon-btn pf-icon-btn--danger" data-pf-remove aria-label="<?php esc_attr_e( 'Remove field', 'postful' ); ?>">×</button>
			</div>
			<div class="pf-field-more" data-pf-more-panel hidden>
				<label class="pf-form-field">
					<span class="pf-form-label"><?php esc_html_e( 'Key', 'postful' ); ?></span>
					<input type="text" class="pf-text pf-mono" name="<?php echo $name( 'key' ); ?>" value="<?php echo esc_attr( $field['key'] ); ?>" maxlength="40" data-pf-field-key <?php echo $locked ? 'readonly' : ''; ?>>
					<span class="pf-form-help">
						<?php
						echo $locked
							? esc_html__( 'Locked because entries use it. To change it, remove the field and add a new one.', 'postful' )
							: esc_html__( 'Used in CSV columns and templates. Locked after the first save.', 'postful' );
						?>
					</span>
				</label>
				<label class="pf-form-field">
					<span class="pf-form-label"><?php esc_html_e( 'Help text', 'postful' ); ?></span>
					<input type="text" class="pf-text" name="<?php echo $name( 'help' ); ?>" value="<?php echo esc_attr( $field['help'] ); ?>" placeholder="<?php esc_attr_e( 'Shown under the field in the editor', 'postful' ); ?>">
				</label>
				<label class="pf-form-field" data-pf-choices <?php echo 'select' === $field['type'] ? '' : 'hidden'; ?>>
					<span class="pf-form-label"><?php esc_html_e( 'Choices, one per line', 'postful' ); ?></span>
					<textarea class="pf-text" rows="3" name="<?php echo $name( 'choices' ); ?>"><?php echo esc_textarea( implode( "\n", $field['choices'] ) ); ?></textarea>
				</label>
				<label class="pf-check">
					<input type="checkbox" name="<?php echo $name( 'required' ); ?>" value="1" <?php checked( ! empty( $field['required'] ) ); ?>>
					<?php esc_html_e( 'Required — flag entries where this is empty', 'postful' ); ?>
				</label>
			</div>
		</li>
		<?php
	}

	private static function done_page( array $type ): void {
		$key = $type['key'];
		?>
		<div class="wrap pf-wrap pf-wrap--narrow">
			<div class="pf-done">
				<div class="pf-done-mark" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="28" height="28"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<h1 class="pf-title">
					<?php
					/* translators: %s: plural content type name, lowercase */
					echo esc_html( sprintf( __( 'You’re ready to publish %s', 'postful' ), strtolower( $type['plural'] ) ) );
					?>
				</h1>
				<p class="pf-sub"><?php esc_html_e( 'Paste these shortcodes into the page where visitors will read them. Most sites use all three on one page.', 'postful' ); ?></p>

				<div class="pf-panel pf-done-codes">
					<div class="pf-done-code">
						<div><strong><?php esc_html_e( 'Current entry', 'postful' ); ?></strong><span class="pf-muted"><?php esc_html_e( 'Today’s, updated automatically', 'postful' ); ?></span></div>
						<?php self::copy_code( sprintf( '[sp_current type="%s"]', $key ) ); ?>
					</div>
					<div class="pf-done-code">
						<div><strong><?php esc_html_e( 'Previous / next', 'postful' ); ?></strong><span class="pf-muted"><?php esc_html_e( 'Walks through entries in place', 'postful' ); ?></span></div>
						<?php self::copy_code( sprintf( '[sp_nav type="%s"]', $key ) ); ?>
					</div>
					<div class="pf-done-code">
						<div><strong><?php esc_html_e( 'Index', 'postful' ); ?></strong><span class="pf-muted"><?php esc_html_e( 'Month-by-month list of every entry', 'postful' ); ?></span></div>
						<?php self::copy_code( sprintf( '[sp_index type="%s"]', $key ) ); ?>
					</div>
				</div>

				<div class="pf-btn-row pf-btn-row--center">
					<a class="pf-btn pf-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $key ) ); ?>">
						<?php
						/* translators: %s: singular content type name */
						echo esc_html( sprintf( __( 'Write the first %s', 'postful' ), strtolower( $type['singular'] ) ) );
						?>
					</a>
					<a class="pf-btn pf-btn--ghost" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key . '&page=postful-io-' . $key ) ); ?>"><?php esc_html_e( 'Import from a spreadsheet', 'postful' ); ?></a>
				</div>
				<p class="pf-done-foot"><a href="<?php echo esc_url( admin_url( 'admin.php?page=postful' ) ); ?>"><?php esc_html_e( 'Back to content types', 'postful' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Actions
	// ------------------------------------------------------------------

	public static function save_type(): void {
		self::guard( 'postful_save_type' );

		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Types::sanitize()
		$editing  = sanitize_key( $input['editing'] ?? '' );
		$existing = $editing ? Types::get( $editing ) : null;
		$config   = Types::sanitize( $input, $existing );

		if ( is_wp_error( $config ) ) {
			self::flash( $config->get_error_message(), 'error' );
			// Keep everything they typed so the fix is one edit, not a redo.
			set_transient( 'postful_wizard_' . get_current_user_id(), self::draft( $input ), MINUTE_IN_SECONDS );
			wp_safe_redirect( admin_url( 'admin.php?page=postful-type' . ( $existing ? '&type=' . $existing['key'] : '' ) ) );
			exit;
		}

		Types::save( $config );
		wp_safe_redirect( admin_url( 'admin.php?page=postful-type&step=done&type=' . $config['key'] ) );
		exit;
	}

	/** Lightly cleaned copy of the wizard input, shaped like a config, for re-display. */
	private static function draft( array $in ): array {
		$fields = [];
		foreach ( (array) ( $in['fields'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$fields[] = [
				'key'          => sanitize_key( $row['key'] ?? '' ),
				'label'        => sanitize_text_field( $row['label'] ?? '' ),
				'type'         => isset( Fields::types()[ $row['type'] ?? '' ] ) ? $row['type'] : 'text',
				'required'     => ! empty( $row['required'] ),
				'help'         => sanitize_text_field( $row['help'] ?? '' ),
				'translatable' => ! empty( $row['translatable'] ),
				'choices'      => array_filter( array_map( 'sanitize_text_field', explode( "\n", (string) ( $row['choices'] ?? '' ) ) ), 'strlen' ),
			];
		}
		$languages = array_values( array_intersect( (array) ( $in['languages'] ?? [] ), array_keys( Languages::common() ) ) );
		$strings   = [];
		foreach ( (array) ( $in['strings'] ?? [] ) as $code => $set ) {
			foreach ( (array) $set as $k => $v ) {
				$strings[ sanitize_key( $code ) ][ sanitize_key( $k ) ] = sanitize_text_field( $v );
			}
		}
		return [
			'plural'       => sanitize_text_field( $in['plural'] ?? '' ),
			'singular'     => sanitize_text_field( $in['singular'] ?? '' ),
			'key'          => sanitize_key( $in['key'] ?? '' ),
			'icon'         => sanitize_key( $in['icon'] ?? '' ) ?: 'dashicons-book-alt',
			'position'     => (int) ( $in['position'] ?? 25 ),
			'slug'         => sanitize_title( $in['slug'] ?? '' ),
			'public_urls'  => ! empty( $in['public_urls'] ),
			'languages'    => $languages ?: [ 'en_US' ],
			'default_lang' => Languages::code( (string) ( $in['default_locale'] ?? '' ) ) ?: 'en',
			'fallback'     => ( $in['fallback'] ?? '' ) === 'hide' ? 'hide' : 'field',
			'fields'       => $fields,
			'template'     => wp_kses_post( (string) ( $in['template'] ?? '' ) ),
			'strings'      => $strings,
		];
	}

	public static function delete_type(): void {
		$key = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		self::guard( 'postful_delete_type_' . $key );
		$type = Types::get( $key );
		if ( $type ) {
			Types::delete( $key );
			/* translators: %s: plural type name */
			self::flash( sprintf( __( '%s removed. Its entries are still in the database.', 'postful' ), $type['plural'] ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=postful' ) );
		exit;
	}

	public static function export_type(): void {
		$key = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		self::guard( 'postful_export_type_' . $key );
		$type = Types::get( $key );
		if ( ! $type ) {
			wp_die( esc_html__( 'Unknown content type.', 'postful' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="postful-' . $key . '.json"' );
		echo wp_json_encode( [ 'postful' => POSTFUL_VERSION, 'type' => $type ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function import_type(): void {
		self::guard( 'postful_import_type' );
		try {
			$file = $_FILES['config'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! $file || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				throw new \RuntimeException( __( 'Choose a JSON file.', 'postful' ) );
			}
			$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw  = $data['type'] ?? null;
			if ( ! is_array( $raw ) ) {
				throw new \RuntimeException( __( 'That file isn’t a Postful content type export.', 'postful' ) );
			}
			// Same validation as the wizard. Locale list comes back as `languages`.
			$raw['default_locale'] = Types::locales( wp_parse_args( $raw, Types::defaults() ) )[ $raw['default_lang'] ?? '' ] ?? '';
			$config                = Types::sanitize( $raw );
			if ( is_wp_error( $config ) ) {
				throw new \RuntimeException( $config->get_error_message() );
			}
			Types::save( $config );
			/* translators: %s: plural type name */
			self::flash( sprintf( __( '%s imported.', 'postful' ), $config['plural'] ) );
		} catch ( \Throwable $e ) {
			self::flash( $e->getMessage(), 'error' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=postful' ) );
		exit;
	}
}
