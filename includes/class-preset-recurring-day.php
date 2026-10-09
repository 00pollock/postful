<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring day: keyed by month + day, no year. The March 5 entry is the same
 * entry every year. The WordPress publish date is never used.
 */
final class Preset_Recurring_Day extends Preset {

	public const META_MONTH = '_pf_month';
	public const META_DAY   = '_pf_day';
	public const META_SORT  = '_pf_sort';

	public function id(): string {
		return 'recurring_day';
	}

	public function label(): string {
		return __( 'Recurring day', 'postful' );
	}

	public function description(): string {
		return __( 'One entry per calendar day that repeats every year. Visitors see today’s.', 'postful' );
	}

	public function good_for(): string {
		return __( 'Devotionals, daily quotes, saint of the day, on-this-day', 'postful' );
	}

	public function available(): bool {
		return true;
	}

	public function default_public_urls(): bool {
		return false;
	}

	public function diagram(): string {
		$cells = '';
		for ( $i = 0; $i < 14; $i++ ) {
			$x      = 4 + ( $i % 7 ) * 12;
			$y      = 16 + intdiv( $i, 7 ) * 12;
			$fill   = 9 === $i ? 'currentColor' : 'none';
			$cells .= sprintf( '<rect x="%d" y="%d" width="9" height="9" rx="2" fill="%s" stroke="currentColor" stroke-opacity=".35"/>', $x, $y, $fill );
		}
		return '<svg viewBox="0 0 92 42" aria-hidden="true"><rect x="4" y="3" width="30" height="7" rx="2" fill="currentColor" opacity=".35"/>' . $cells . '</svg>';
	}

	/** A neutral start; the user names and adds fields in the wizard. */
	public function default_fields(): array {
		return [
			[ 'key' => 'title', 'label' => __( 'Title', 'postful' ), 'type' => 'text', 'display' => 'title', 'required' => true, 'help' => '', 'translatable' => true, 'machine' => true, 'choices' => [] ],
			[ 'key' => 'content', 'label' => __( 'Content', 'postful' ), 'type' => 'richtext', 'display' => 'body', 'required' => true, 'help' => '', 'translatable' => true, 'machine' => true, 'choices' => [] ],
		];
	}

	public function meta_keys(): array {
		return [ self::META_MONTH, self::META_DAY, self::META_SORT ];
	}

	public function key_columns(): array {
		return [ 'month', 'day' ];
	}

	public function key_of( int $post_id ): ?array {
		$m = (int) get_post_meta( $post_id, self::META_MONTH, true );
		$d = (int) get_post_meta( $post_id, self::META_DAY, true );
		return self::valid( $m, $d ) ? [ 'month' => $m, 'day' => $d ] : null;
	}

	public function parse_key( array $input ) {
		$m = trim( (string) ( $input['month'] ?? '' ) );
		$d = trim( (string) ( $input['day'] ?? '' ) );
		if ( '' === $m && '' === $d ) {
			return null;
		}
		if ( ! ctype_digit( $m ) || ! ctype_digit( $d ) || ! self::valid( (int) $m, (int) $d ) ) {
			/* translators: 1: month value, 2: day value */
			return new \WP_Error( 'key', sprintf( __( 'Month %1$s, day %2$s is not a calendar date.', 'postful' ), $m ?: '—', $d ?: '—' ) );
		}
		return [ 'month' => (int) $m, 'day' => (int) $d ];
	}

	public function save_key( int $post_id, ?array $key ): void {
		if ( $key ) {
			update_post_meta( $post_id, self::META_MONTH, $key['month'] );
			update_post_meta( $post_id, self::META_DAY, $key['day'] );
			update_post_meta( $post_id, self::META_SORT, $this->sort_of( $key ) );
		} else {
			delete_post_meta( $post_id, self::META_MONTH );
			delete_post_meta( $post_id, self::META_DAY );
			update_post_meta( $post_id, self::META_SORT, 0 );
		}
	}

	public function sort_of( array $key ): int {
		return (int) $key['month'] * 100 + (int) $key['day'];
	}

	public function key_from_sort( int $sort ): ?array {
		$m = intdiv( $sort, 100 );
		$d = $sort % 100;
		return self::valid( $m, $d ) ? [ 'month' => $m, 'day' => $d ] : null;
	}

	public function key_label( array $key, ?string $code = null, string $locale = '' ): string {
		if ( null === $code ) {
			/* translators: date format for a day without year, e.g. "March 5". See https://www.php.net/date */
			return date_i18n( _x( 'F j', 'recurring day', 'postful' ), gmmktime( 12, 0, 0, $key['month'], $key['day'], 2024 ) );
		}
		return Languages::day_label( $key['month'], $key['day'], $code, $locale );
	}

	/** Today in the site timezone. */
	public function current_sort(): ?int {
		return (int) wp_date( 'n' ) * 100 + (int) wp_date( 'j' );
	}

	/** Leap year, so Feb 29 is a valid entry. */
	public static function valid( int $m, int $d ): bool {
		return $m >= 1 && $m <= 12 && $d >= 1 && checkdate( $m, $d, 2024 );
	}

	public function render_key_box( \WP_Post $post, array $type ): void {
		global $wp_locale;
		$key   = $this->key_of( $post->ID );
		$month = $key['month'] ?? 0;
		$day   = $key['day'] ?? 0;
		?>
		<div class="pf-keybox" data-pf-keybox>
			<div class="pf-keybox-row">
				<label class="screen-reader-text" for="pf_key_month"><?php esc_html_e( 'Month', 'postful' ); ?></label>
				<select name="pf_key[month]" id="pf_key_month" class="pf-select" data-pf-month>
					<option value=""><?php esc_html_e( 'Month', 'postful' ); ?></option>
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo (int) $m; ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( $wp_locale->get_month( $m ) ); ?></option>
					<?php endfor; ?>
				</select>
				<label class="screen-reader-text" for="pf_key_day"><?php esc_html_e( 'Day', 'postful' ); ?></label>
				<select name="pf_key[day]" id="pf_key_day" class="pf-select pf-select--day" data-pf-day>
					<option value=""><?php esc_html_e( 'Day', 'postful' ); ?></option>
					<?php for ( $d = 1; $d <= 31; $d++ ) : ?>
						<option value="<?php echo (int) $d; ?>" <?php selected( $day, $d ); ?>><?php echo (int) $d; ?></option>
					<?php endfor; ?>
				</select>
			</div>
			<p class="pf-keybox-hint" data-pf-key-hint <?php echo $key ? '' : 'hidden'; ?>>
				<?php
				printf(
					/* translators: %s: a day such as "March 5" */
					esc_html__( 'Shows on %s, every year.', 'postful' ),
					'<strong data-pf-key-label>' . esc_html( $key ? $this->key_label( $key ) : '' ) . '</strong>'
				);
				?>
			</p>
			<p class="pf-keybox-warn" data-pf-key-warn <?php echo $key ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'No day set yet. This entry won’t appear anywhere until you pick one.', 'postful' ); ?>
			</p>
			<p class="pf-keybox-dup" data-pf-key-dup hidden></p>
			<p class="pf-keybox-note"><?php esc_html_e( 'Entries repeat every year. The publish date is not used.', 'postful' ); ?></p>
		</div>
		<?php
	}
}
