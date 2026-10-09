<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Preset registry.
 */
final class Presets {

	/** @var array<string,Preset>|null */
	private static ?array $all = null;

	/** @return array<string,Preset> */
	public static function all(): array {
		if ( null === self::$all ) {
			$line = fn( $y ) => sprintf( '<rect x="22" y="%d" width="60" height="5" rx="2" fill="currentColor" opacity=".35"/>', $y );

			self::$all = [
				'recurring_day' => new Preset_Recurring_Day(),
				'dated'         => new Preset_Planned(
					'dated',
					__( 'Dated entry', 'postful' ),
					__( 'Each entry has a publish date. Newest first.', 'postful' ),
					__( 'News, blog, announcements', 'postful' ),
					'<svg viewBox="0 0 92 42" aria-hidden="true">' .
						'<rect x="6" y="5" width="11" height="8" rx="2" fill="currentColor"/>' . $line( 6 ) .
						'<rect x="6" y="18" width="11" height="8" rx="2" fill="none" stroke="currentColor" stroke-opacity=".35"/>' . $line( 19 ) .
						'<rect x="6" y="31" width="11" height="8" rx="2" fill="none" stroke="currentColor" stroke-opacity=".35"/>' . $line( 32 ) .
					'</svg>'
				),
				'sequence'      => new Preset_Planned(
					'sequence',
					__( 'Sequence', 'postful' ),
					__( 'Numbered entries read in order, 1 to n.', 'postful' ),
					__( 'Lessons, a 30-day challenge, chapters', 'postful' ),
					'<svg viewBox="0 0 92 42" aria-hidden="true">' .
						'<circle cx="14" cy="21" r="8" fill="currentColor"/><circle cx="38" cy="21" r="8" fill="none" stroke="currentColor" stroke-opacity=".35"/>' .
						'<circle cx="62" cy="21" r="8" fill="none" stroke="currentColor" stroke-opacity=".35"/><circle cx="84" cy="21" r="4" fill="currentColor" opacity=".35"/>' .
					'</svg>'
				),
				'evergreen'     => new Preset_Planned(
					'evergreen',
					__( 'Evergreen', 'postful' ),
					__( 'No date or number. Listed A–Z or in your own order.', 'postful' ),
					__( 'Staff bios, FAQs, glossary, service pages', 'postful' ),
					'<svg viewBox="0 0 92 42" aria-hidden="true"><text x="5" y="16" font-size="11" font-weight="600" fill="currentColor">A</text>' .
						$line( 9 ) . '<text x="5" y="34" font-size="11" font-weight="600" fill="currentColor" opacity=".5">B</text>' . $line( 27 ) .
					'</svg>'
				),
				'event'         => new Preset_Planned(
					'event',
					__( 'Scheduled event', 'postful' ),
					__( 'Has a start (and optional end) time. Upcoming first.', 'postful' ),
					__( 'Events, classes, services', 'postful' ),
					'<svg viewBox="0 0 92 42" aria-hidden="true"><rect x="4" y="20" width="84" height="2" fill="currentColor" opacity=".35"/>' .
						'<circle cx="20" cy="21" r="4" fill="currentColor" opacity=".35"/><circle cx="46" cy="21" r="6" fill="currentColor"/><circle cx="72" cy="21" r="4" fill="none" stroke="currentColor"/>' .
					'</svg>'
				),
			];
		}
		return self::$all;
	}

	public static function get( string $id ): ?Preset {
		return self::all()[ $id ] ?? null;
	}
}
