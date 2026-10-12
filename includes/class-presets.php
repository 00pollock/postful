<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Preset registry. Postful is for daily devotionals, so there is one preset:
 * Recurring day. The registry stays so the key logic lives in one place.
 */
final class Presets {

	/** @var array<string,Preset>|null */
	private static ?array $all = null;

	/** @return array<string,Preset> */
	public static function all(): array {
		// Devotionals only: one entry per calendar day, repeating every year.
		if ( null === self::$all ) {
			self::$all = [ 'recurring_day' => new Preset_Recurring_Day() ];
		}
		return self::$all;
	}

	public static function get( string $id ): ?Preset {
		return self::all()[ $id ] ?? null;
	}
}
