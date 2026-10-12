<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Postful menu: content type list, the onboarding wizard (also used for
 * editing a type), settings, and type config JSON import/export.
 */
final class Admin {

	public static function hooks(): void {
		// Order: Setup (9) → Devotionals entries (core, 10) → Settings (11).
		add_action( 'admin_menu', [ self::class, 'menus' ], 9 );
		add_action( 'admin_menu', [ self::class, 'menus_after' ], 11 );
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
		// edit_posts so editors reach their entries; type setup pages still need manage_options.
		add_menu_page( 'Postful', 'Postful', Types::all() ? 'edit_posts' : 'manage_options', 'postful', [ self::class, 'types_page' ], Plugin::menu_icon(), 26 );
		add_submenu_page( 'postful', __( 'Setup', 'postful' ), __( 'Setup', 'postful' ), 'manage_options', 'postful', [ self::class, 'types_page' ] );
	}

	public static function menus_after(): void {
		// The setup wizard has no menu item of its own; it opens from Setup.
		add_submenu_page( 'options.php', __( 'Devotional setup', 'postful' ), __( 'Devotional setup', 'postful' ), 'manage_options', 'postful-type', [ self::class, 'wizard_page' ] );
		add_submenu_page( 'postful', __( 'Settings', 'postful' ), __( 'Settings', 'postful' ), 'manage_options', 'postful-settings', [ Settings::class, 'render_page' ] );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'postful' ) ) {
			return;
		}
		wp_enqueue_style( 'postful-admin', POSTFUL_URL . 'assets/admin.css', [], Plugin::ver( 'admin.css' ) );
		wp_enqueue_script( 'postful-admin', POSTFUL_URL . 'assets/admin.js', [], Plugin::ver( 'admin.js' ), true );
		wp_localize_script( 'postful-admin', 'postfulAdmin', [
			'i18n' => [
				'copied'        => __( 'Copied', 'postful' ),
				'copy'          => __( 'Copy', 'postful' ),
				'removeLocked'  => __( "Remove this field?\n\nEntries already have content saved under this field. It stays in the database but will no longer be shown or editable. To rename a key, remove the field and add a new one.", 'postful' ),
				'deleteType'    => __( "Delete this devotional setup?\n\nThe devotionals themselves are NOT deleted. They stay in the database and come back if you recreate a type with the same internal key.", 'postful' ),
				'needLanguage'  => __( 'Choose at least one language.', 'postful' ),
				'needName'      => __( 'Give it a name.', 'postful' ),
				'needField'     => __( 'Add at least one field.', 'postful' ),
				'fileChosen'    => __( 'Ready to import: %s', 'postful' ),
				'main'          => __( 'Main', 'postful' ),
				'sidebar'       => __( 'Sidebar', 'postful' ),
				/* translators: 1: entries done, 2: total */
				'bulkProgress'  => __( '%1$d of %2$d translated…', 'postful' ),
				'bulkDone'      => __( 'Done. The drafts are marked “Needs review” in the list and stay hidden from visitors until reviewed.', 'postful' ),
				'bulkStuck'     => __( 'Stopped: some entries could not be translated. Check them in the editor.', 'postful' ),
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
	// Setup page (the devotional type's card)
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
					<h1 class="pf-title"><?php esc_html_e( 'Setup', 'postful' ); ?></h1>
					<p class="pf-sub"><?php esc_html_e( 'Your daily devotionals: fields, languages and how they look.', 'postful' ); ?></p>
				</div>
			</header>

			<?php self::render_flash(); ?>

			<?php if ( ! $types ) : ?>
				<div class="pf-empty">
					<h2><?php esc_html_e( 'Set up your devotionals', 'postful' ); ?></h2>
					<p><?php esc_html_e( 'One devotional for every day of the year, in English and French side by side. Choose the fields to match your spreadsheet and how they look. It takes about a minute.', 'postful' ); ?></p>
					<a class="pf-btn pf-btn--primary pf-btn--lg" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Set up devotionals', 'postful' ); ?></a>
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
								<?php self::copy_code( sprintf( '[postful type="%s"]', $key ) ); ?>
							</div>
							<footer class="pf-card-foot">
								<a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $key ) ); ?>"><?php esc_html_e( 'New entry', 'postful' ); ?></a>
								<a class="pf-btn pf-btn--text pf-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=postful-type&type=' . $key ) ); ?>"><?php esc_html_e( 'Edit type', 'postful' ); ?></a>
								<a class="pf-btn pf-btn--text pf-btn--sm" href="<?php echo esc_url( Importer::url( $key ) ); ?>"><?php esc_html_e( 'Import / export', 'postful' ); ?></a>
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
				<summary><?php esc_html_e( 'Copy the setup from another site', 'postful' ); ?></summary>
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
			'plural'      => __( 'Devotionals', 'postful' ),
			'singular'    => __( 'Devotional', 'postful' ),
			'key'         => 'devotional',
			'preset'      => $preset->id(),
			'languages'   => [ 'en_US', 'fr_CA' ],
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

		?>
		<div class="wrap pf-wrap pf-wrap--wizard">
			<header class="pf-page-head">
				<div>
					<p class="pf-eyebrow"><a href="<?php echo esc_url( admin_url( 'admin.php?page=postful' ) ); ?>"><?php esc_html_e( 'Setup', 'postful' ); ?></a></p>
					<h1 class="pf-title">
						<?php
						echo $editing
							/* translators: %s: content type plural name */
							? esc_html( sprintf( __( 'Edit %s', 'postful' ), $existing['plural'] ) )
							: esc_html__( 'Set up devotionals', 'postful' );
						?>
					</h1>
				</div>
			</header>

			<?php self::render_flash(); ?>

			<form class="pf-wizard" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pf-wizard data-pf-start="<?php echo isset( $_GET['pf_step'] ) ? (int) $_GET['pf_step'] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" novalidate>
				<input type="hidden" name="action" value="postful_save_type">
				<input type="hidden" name="editing" value="<?php echo esc_attr( $editing ? $existing['key'] : '' ); ?>">
				<?php wp_nonce_field( 'postful_save_type' ); ?>
				<script type="application/json" data-pf-wizard-data><?php
				echo wp_json_encode(
					[
						'strings'    => $strings,
						'stringKeys' => Languages::string_keys(),
						'names'      => Languages::common(),
						'fieldStyle' => (object) $config['field_style'],
						'fieldProps' => Types::field_style_props(),
						'i18n'       => [ 'theme' => __( 'Theme', 'postful' ), 'themeDefault' => __( 'Theme default', 'postful' ), 'title' => __( 'title', 'postful' ) ],
					],
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
				);
				?></script>

				<ol class="pf-stepper" data-pf-stepper>
					<?php foreach ( [ __( 'Name', 'postful' ), __( 'Languages', 'postful' ), __( 'Fields', 'postful' ), __( 'Style', 'postful' ) ] as $i => $label ) : ?>
						<li><button type="button" data-pf-goto="<?php echo (int) $i + 1; ?>"><span class="pf-stepper-num"><?php echo (int) $i + 1; ?></span><?php echo esc_html( $label ); ?></button></li>
					<?php endforeach; ?>
				</ol>

				<!-- 1. Name -->
				<section class="pf-step" data-pf-step="1">
					<h2 class="pf-step-title"><?php esc_html_e( 'What are you publishing?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Use the words your team already uses. These appear in the admin menu.', 'postful' ); ?></p>
					<div class="pf-form-row pf-form-row--2">
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Name', 'postful' ); ?> <span class="pf-form-hint"><?php esc_html_e( 'plural, as in the menu', 'postful' ); ?></span></span>
							<input type="text" class="pf-text" name="plural" value="<?php echo esc_attr( $config['plural'] ); ?>" data-pf-plural required>
						</label>
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Singular name', 'postful' ); ?> <span class="pf-form-hint"><?php esc_html_e( 'filled in for you', 'postful' ); ?></span></span>
							<input type="text" class="pf-text" name="singular" value="<?php echo esc_attr( $config['singular'] ); ?>" data-pf-singular required>
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
						<div class="pf-form-row">
							<label class="pf-form-field pf-form-field--half">
								<span class="pf-form-label"><?php esc_html_e( 'URL slug', 'postful' ); ?></span>
								<input type="text" class="pf-text pf-mono" name="slug" value="<?php echo esc_attr( $config['slug'] ); ?>" placeholder="<?php esc_attr_e( 'same as key', 'postful' ); ?>">
								<span class="pf-form-help"><?php esc_html_e( 'Only used if entries have their own URLs.', 'postful' ); ?></span>
							</label>
						</div>
					</details>
				</section>

				<?php // Devotionals only: every entry is keyed by month and day. ?>
				<input type="hidden" name="preset" value="recurring_day">

				<!-- 2. Languages -->
				<section class="pf-step" data-pf-step="2">
					<h2 class="pf-step-title"><?php esc_html_e( 'Which languages will you write in?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Each entry holds every language. Visitors see the one your site (or GTranslate) is showing.', 'postful' ); ?></p>
					<div class="pf-lang-grid" data-pf-langs>
						<?php foreach ( Languages::common() as $locale => $name ) : ?>
							<label class="pf-lang-option">
								<input type="checkbox" name="languages[]" value="<?php echo esc_attr( $locale ); ?>" <?php checked( in_array( Languages::code( $locale ), array_map( [ Languages::class, 'code' ], $config['languages'] ), true ) ); ?>>
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

				<!-- 3. Fields -->
				<section class="pf-step" data-pf-step="3">
					<h2 class="pf-step-title"><?php esc_html_e( 'What does each entry contain?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'Name each part of an entry. The first field is the title. Every field can be written in each language; an empty translation shows the default language instead.', 'postful' ); ?></p>
					<ol class="pf-fields" data-pf-fields>
						<?php foreach ( $config['fields'] as $i => $field ) : ?>
							<?php self::field_row( (string) $i, $field, $editing && isset( $existing ) && in_array( $field['key'], array_column( $existing['fields'], 'key' ), true ) ); ?>
						<?php endforeach; ?>
					</ol>
					<template data-pf-field-template><?php self::field_row( '__i__', [ 'key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'help' => '', 'translatable' => true, 'choices' => [] ], false ); ?></template>
					<div class="pf-add-field">
						<span class="pf-muted"><?php esc_html_e( 'Add a field:', 'postful' ); ?></span>
						<?php foreach ( self::offered_types() as $t => $label ) : ?>
							<button type="button" class="pf-chip pf-chip--button" data-pf-add-field="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
				</section>


				<!-- 4. Style -->
				<section class="pf-step" data-pf-step="4">
					<h2 class="pf-step-title"><?php esc_html_e( 'How should it look?', 'postful' ); ?></h2>
					<p class="pf-step-sub"><?php esc_html_e( 'The entry reads in the main column; previous / today / next and the month list sit in the sidebar. Style each part below. Anything left empty uses your theme’s look.', 'postful' ); ?></p>
					<?php // One layout for now (like AWMI). The engine supports more; they come back later. ?>
					<input type="hidden" name="layout" value="sidebar_right">
					<input type="hidden" name="nav_place" value="sidebar">
					<input type="hidden" name="index_place" value="sidebar">

					<div class="pf-style" data-pf-style>
						<p class="pf-form-help pf-style-note"><?php esc_html_e( 'The preview uses a stand-in font; your site uses your theme’s.', 'postful' ); ?></p>
						<div class="pf-preview">
							<iframe title="<?php esc_attr_e( 'Layout preview', 'postful' ); ?>" data-pf-preview data-css="<?php echo esc_url( POSTFUL_URL . 'assets/front.css?ver=' . Plugin::ver( 'front.css' ) ); ?>"></iframe>
						</div>
						<h3 class="pf-placement-title"><?php esc_html_e( 'Fields', 'postful' ); ?></h3>
						<div class="pf-style-groups" data-pf-field-styles></div>
						<h3 class="pf-placement-title"><?php esc_html_e( 'Page', 'postful' ); ?></h3>
						<div class="pf-style-groups">
							<?php $first = false; ?>
							<?php foreach ( Types::style_controls() as $group => [ $group_label, $controls ] ) : ?>
								<details class="pf-style-group" <?php echo $first ? 'open' : ''; ?>>
									<summary><?php echo esc_html( $group_label ); ?></summary>
									<div class="pf-style-fields">
										<?php foreach ( $controls as $skey => [ $slabel, $kind, $var ] ) : ?>
											<?php $val = $config['style'][ $skey ] ?? ''; ?>
											<label class="pf-style-field">
												<span class="pf-style-label"><?php echo esc_html( $slabel ); ?></span>
												<?php if ( 'color' === $kind ) : ?>
													<span class="pf-color">
														<input type="color" value="<?php echo esc_attr( $val ?: '#000000' ); ?>" data-pf-swatch aria-label="<?php echo esc_attr( $slabel ); ?>">
														<input type="text" class="pf-text pf-mono" name="style[<?php echo esc_attr( $skey ); ?>]" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php esc_attr_e( 'Theme', 'postful' ); ?>" maxlength="7" data-pf-var="<?php echo esc_attr( $var ); ?>" data-kind="color">
													</span>
												<?php elseif ( is_array( $kind ) ) : ?>
													<select class="pf-text" name="style[<?php echo esc_attr( $skey ); ?>]" data-pf-var="<?php echo esc_attr( $var ); ?>" data-kind="select">
														<option value=""><?php esc_html_e( 'Theme default', 'postful' ); ?></option>
														<?php foreach ( $kind as $ov => $ol ) : ?>
															<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $val, (string) $ov ); ?>><?php echo esc_html( $ol ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<span class="pf-unit">
														<input type="number" class="pf-text" name="style[<?php echo esc_attr( $skey ); ?>]" value="<?php echo esc_attr( $val ); ?>" min="0" max="<?php echo 'number' === $kind ? 5 : 2000; ?>" step="<?php echo 'number' === $kind ? '0.05' : '1'; ?>" placeholder="<?php esc_attr_e( 'Theme', 'postful' ); ?>" data-pf-var="<?php echo esc_attr( $var ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
														<?php if ( 'size' === $kind ) : ?><em>px</em><?php endif; ?>
													</span>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
									</div>
								</details>
								<?php $first = false; ?>
							<?php endforeach; ?>
						</div>
						<button type="button" class="pf-btn pf-btn--text pf-btn--sm" data-pf-style-reset><?php esc_html_e( 'Clear all styles', 'postful' ); ?></button>
					</div>
					<details class="pf-advanced">
						<summary><?php esc_html_e( 'Advanced', 'postful' ); ?></summary>
						<label class="pf-form-field">
							<span class="pf-form-label"><?php esc_html_e( 'Template', 'postful' ); ?></span>
							<textarea class="pf-text pf-mono" name="template" rows="5" placeholder="<h2>{title}</h2>&#10;<p>{date}</p>&#10;{content}"><?php echo esc_textarea( $config['template'] ); ?></textarea>
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
						<?php echo $editing ? esc_html__( 'Save changes', 'postful' ) : esc_html__( 'Save setup', 'postful' ); ?>
					</button>
				</footer>
			</form>
		</div>
		<?php
	}

	/** Field types offered in setup (Choice list needs options, so it isn't offered for now). */
	private static function offered_types( string $current = '' ): array {
		return array_filter( Fields::types(), fn( $t ) => 'select' !== $t || 'select' === $current, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * One field in setup: name, type, order, remove. The key is generated from
	 * the name; help text, required and choices from older setups are kept.
	 */
	private static function field_row( string $i, array $field, bool $locked ): void {
		$name = fn( $k ) => esc_attr( "fields[{$i}][{$k}]" );
		?>
		<li class="pf-field-row" data-pf-row <?php echo $locked ? 'data-pf-locked' : ''; ?>>
			<input type="hidden" name="<?php echo $name( 'key' ); ?>" value="<?php echo esc_attr( $field['key'] ); ?>" data-pf-field-key <?php echo $locked ? 'data-locked' : ''; ?>>
			<input type="hidden" name="<?php echo $name( 'help' ); ?>" value="<?php echo esc_attr( $field['help'] ); ?>">
			<input type="hidden" name="<?php echo $name( 'choices' ); ?>" value="<?php echo esc_attr( implode( "\n", $field['choices'] ) ); ?>">
			<?php if ( ! empty( $field['required'] ) ) : ?><input type="hidden" name="<?php echo $name( 'required' ); ?>" value="1"><?php endif; ?>
			<div class="pf-field-main">
				<input type="text" class="pf-text pf-field-label" name="<?php echo $name( 'label' ); ?>" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'Field name', 'postful' ); ?>" aria-label="<?php esc_attr_e( 'Field name', 'postful' ); ?>" data-pf-field-label>
				<select class="pf-text pf-field-type" name="<?php echo $name( 'type' ); ?>" aria-label="<?php esc_attr_e( 'Field type', 'postful' ); ?>" data-pf-field-type>
					<?php foreach ( self::offered_types( $field['type'] ) as $t => $label ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="pf-field-actions">
					<button type="button" class="pf-text-btn" data-pf-move="-1"><?php esc_html_e( 'Up', 'postful' ); ?></button>
					<button type="button" class="pf-text-btn" data-pf-move="1"><?php esc_html_e( 'Down', 'postful' ); ?></button>
					<button type="button" class="pf-text-btn pf-text-btn--danger" data-pf-remove><?php esc_html_e( 'Remove', 'postful' ); ?></button>
				</span>
			</div>
		</li>
		<?php
	}

	private static function done_page( array $type ): void {
		$key = $type['key'];
		?>
		<div class="wrap pf-wrap pf-wrap--narrow">
			<div class="pf-done">
				<h1 class="pf-title">
					<?php
					/* translators: %s: plural content type name */
					echo esc_html( sprintf( __( '%s: all set', 'postful' ), $type['plural'] ) );
					?>
				</h1>
				<p class="pf-sub"><?php esc_html_e( 'Paste this shortcode into the page where visitors will read it.', 'postful' ); ?></p>

				<div class="pf-done-main-code"><?php self::copy_code( sprintf( '[postful type="%s"]', $key ) ); ?></div>

				<details class="pf-advanced pf-done-parts">
					<summary><?php esc_html_e( 'Placing the parts separately', 'postful' ); ?></summary>
					<p class="pf-muted"><?php esc_html_e( 'For unusual page designs you can place each part on its own instead. They still work together on the same page.', 'postful' ); ?></p>
					<div class="pf-shortcodes">
						<?php self::copy_code( sprintf( '[sp_current type="%s"]', $key ) ); ?>
						<?php self::copy_code( sprintf( '[sp_nav type="%s"]', $key ) ); ?>
						<?php self::copy_code( sprintf( '[sp_index type="%s"]', $key ) ); ?>
					</div>
				</details>

				<div class="pf-btn-row pf-btn-row--center">
					<a class="pf-btn pf-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $key ) ); ?>">
						<?php
						/* translators: %s: singular content type name */
						echo esc_html( sprintf( __( 'Add the first %s', 'postful' ), strtolower( $type['singular'] ) ) );
						?>
					</a>
					<a class="pf-btn pf-btn--ghost" href="<?php echo esc_url( Importer::url( $key ) ); ?>"><?php esc_html_e( 'Import from a spreadsheet', 'postful' ); ?></a>
				</div>
				<p class="pf-done-foot"><a href="<?php echo esc_url( admin_url( 'admin.php?page=postful' ) ); ?>"><?php esc_html_e( 'Back to setup', 'postful' ); ?></a></p>
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
			// Reopen the wizard on the step that needs fixing.
			$steps = [ 'name' => 1, 'key' => 1, 'preset' => 1, 'languages' => 2, 'field_key' => 3, 'fields' => 3 ];
			$step  = $steps[ $config->get_error_code() ] ?? 1;
			wp_safe_redirect( admin_url( 'admin.php?page=postful-type&pf_step=' . $step . ( $existing ? '&type=' . $existing['key'] : '' ) ) );
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
			'layout'       => sanitize_key( $in['layout'] ?? '' ) ?: 'sidebar_right',
			'nav_place'    => sanitize_key( $in['nav_place'] ?? '' ) ?: 'sidebar',
			'style'        => Types::sanitize_style( $in['style'] ?? [] ),
			'field_style'  => Types::sanitize_field_style( $in['field_style'] ?? [], $fields ),
			'index_place'  => sanitize_key( $in['index_place'] ?? '' ) ?: 'sidebar',
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
			wp_die( esc_html__( 'Unknown devotional setup.', 'postful' ) );
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
				throw new \RuntimeException( __( 'That file isn’t a Postful setup export.', 'postful' ) );
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
