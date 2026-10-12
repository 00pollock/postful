<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Admin list screen per type: key column, translation status per language,
 * sort and filter by key.
 */
final class List_Screen {

	public static function hooks(): void {
		add_action( 'current_screen', [ self::class, 'setup' ] );
		add_action( 'pre_get_posts', [ self::class, 'query' ] );
	}

	public static function setup( \WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base || ! Types::get( $screen->post_type ) ) {
			return;
		}
		$pt = $screen->post_type;
		add_filter( "manage_{$pt}_posts_columns", [ self::class, 'columns' ] );
		add_action( "manage_{$pt}_posts_custom_column", [ self::class, 'column' ], 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns", [ self::class, 'sortable' ] );
		add_action( 'restrict_manage_posts', [ self::class, 'filter' ] );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'disable_months_dropdown', '__return_true' ); // Publish dates aren't the key.
		add_filter( "views_edit-{$pt}", [ self::class, 'views' ] );
		add_filter( "bulk_actions-edit-{$pt}", [ self::class, 'bulk_actions' ] );
		add_action( 'admin_enqueue_scripts', fn() => wp_enqueue_style( 'postful-admin', POSTFUL_URL . 'assets/admin.css', [], Plugin::ver( 'admin.css' ) ) );
	}

	private static function type(): ?array {
		$screen = get_current_screen();
		return $screen ? Types::get( $screen->post_type ) : null;
	}

	public static function columns( array $cols ): array {
		$type    = self::type();
		$locales = Types::locales( $type );
		$out     = [
			'cb'      => $cols['cb'] ?? '',
			'pf_key'  => 'recurring_day' === $type['preset'] ? __( 'Day', 'postful' ) : __( 'Key', 'postful' ),
			'title'   => __( 'Title', 'postful' ),
		];
		foreach ( Types::lang_codes( $type ) as $code ) {
			if ( $code !== $type['default_lang'] ) {
				$out[ 'pf_lang_' . $code ] = Languages::name( $locales[ $code ] );
			}
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		$type = self::type();
		if ( 'pf_key' === $col ) {
			$preset = Presets::get( $type['preset'] );
			$key    = $preset->key_of( $post_id );
			echo $key
				? '<span class="pf-key">' . esc_html( $preset->key_label( $key ) ) . '</span>'
				: '<span class="pf-key pf-key--unset">' . esc_html__( 'Not set', 'postful' ) . '</span>';
			return;
		}
		if ( str_starts_with( $col, 'pf_lang_' ) ) {
			$code = substr( $col, 8 );
			$complete = Renderer::complete( $type, $post_id, $code );
			printf(
				'<span class="pf-pill pf-pill--%s">%s</span>',
				$complete ? 'ok' : 'todo',
				$complete ? esc_html__( 'Complete', 'postful' ) : esc_html__( 'Incomplete', 'postful' )
			);
		}
	}

	public static function sortable( array $cols ): array {
		$cols['pf_key'] = 'pf_key';
		return $cols;
	}

	public static function filter( string $post_type ): void {
		$type = Types::get( $post_type );
		if ( ! $type || 'recurring_day' !== $type['preset'] ) {
			return;
		}
		global $wp_locale;
		$current = isset( $_GET['pf_month'] ) ? (int) $_GET['pf_month'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="screen-reader-text" for="pf_month_filter">' . esc_html__( 'Filter by month', 'postful' ) . '</label>';
		echo '<select name="pf_month" id="pf_month_filter"><option value="">' . esc_html__( 'All months', 'postful' ) . '</option>';
		for ( $m = 1; $m <= 12; $m++ ) {
			printf( '<option value="%d" %s>%s</option>', (int) $m, selected( $current, $m, false ), esc_html( $wp_locale->get_month( $m ) ) );
		}
		echo '<option value="-1" ' . selected( $current, -1, false ) . '>' . esc_html__( 'Day not set', 'postful' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Default order is by key. A named-clause OR keeps entries with no key in
	 * the list (they sort first) instead of silently dropping them.
	 */
	public static function query( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$type = Types::get( (string) $q->get( 'post_type' ) );
		if ( ! $type || 'recurring_day' !== $type['preset'] ) {
			return;
		}
		$orderby = $q->get( 'orderby' );
		$meta    = [
			'relation' => 'OR',
			'pf_sort'  => [ 'key' => Preset_Recurring_Day::META_SORT, 'type' => 'NUMERIC' ],
			'pf_none'  => [ 'key' => Preset_Recurring_Day::META_SORT, 'compare' => 'NOT EXISTS' ],
		];

		$month = isset( $_GET['pf_month'] ) ? (int) $_GET['pf_month'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( -1 === $month ) {
			$meta = [
				'relation' => 'OR',
				[ 'key' => Preset_Recurring_Day::META_MONTH, 'compare' => 'NOT EXISTS' ],
			];
		} elseif ( $month >= 1 && $month <= 12 ) {
			$meta = [
				'relation' => 'AND',
				'pf_sort'  => [ 'key' => Preset_Recurring_Day::META_SORT, 'type' => 'NUMERIC' ],
				[ 'key' => Preset_Recurring_Day::META_MONTH, 'value' => $month, 'type' => 'NUMERIC' ],
			];
		}
		$q->set( 'meta_query', $meta );

		if ( ! $orderby || 'pf_key' === $orderby ) {
			if ( -1 !== $month ) {
				$q->set( 'orderby', [ 'pf_sort' => $q->get( 'order' ) ?: 'ASC' ] );
			}
		}
	}

	/** Import / export lives with the entries, not in the menu. */
	public static function views( array $views ): array {
		$type = self::type();
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return $views;
		}
		$views['pf_io'] = '<a href="' . esc_url( Importer::url( $type['key'] ) ) . '">' . esc_html__( 'Import / export', 'postful' ) . '</a>';
		return $views;
	}

	public static function bulk_actions( array $actions ): array {
		unset( $actions['edit'] ); // Bulk edit can't touch per-language fields.
		return $actions;
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		$type = Types::get( $post->post_type );
		if ( $type ) {
			unset( $actions['inline hide-if-no-js'] );
			if ( empty( $type['public_urls'] ) ) {
				unset( $actions['view'] );
			}
		}
		return $actions;
	}
}
