<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Sorted key → post ID index of published entries, built once and kept in a
 * transient until an entry of that type changes.
 */
final class Index {

	/** @var array<string,array> per-request copy */
	private static array $memo = [];

	public static function hooks(): void {
		add_action( 'save_post', [ self::class, 'on_change' ] );
		add_action( 'before_delete_post', [ self::class, 'on_change' ] );
		add_action( 'trashed_post', [ self::class, 'on_change' ] );
		add_action( 'untrashed_post', [ self::class, 'on_change' ] );
		add_action( 'deleted_post', [ self::class, 'on_change' ] );
	}

	public static function on_change( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( $type && Types::get( $type ) ) {
			self::flush( $type );
		}
	}

	public static function flush( string $type ): void {
		unset( self::$memo[ $type ] );
		delete_transient( 'postful_idx_' . $type );
	}

	/**
	 * @return array<int,array{0:int,1:int}> list of [sort, post_id], ascending by sort.
	 *         When two entries share a key, the older one (lower ID) wins.
	 */
	public static function get( string $type_key ): array {
		if ( isset( self::$memo[ $type_key ] ) ) {
			return self::$memo[ $type_key ];
		}
		$cached = get_transient( 'postful_idx_' . $type_key );
		if ( is_array( $cached ) ) {
			return self::$memo[ $type_key ] = $cached;
		}

		$type   = Types::get( $type_key );
		$preset = $type ? Presets::get( $type['preset'] ) : null;
		$list   = [];

		if ( $preset ) {
			$ids = get_posts( [
				'post_type'        => $type_key,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'has_password'     => false,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			] );
			update_meta_cache( 'post', $ids );

			$by_sort = [];
			foreach ( $ids as $id ) {
				$key = $preset->key_of( (int) $id );
				if ( $key ) {
					$sort = $preset->sort_of( $key );
					if ( ! isset( $by_sort[ $sort ] ) ) {
						$by_sort[ $sort ] = (int) $id;
					}
				}
			}
			ksort( $by_sort );
			foreach ( $by_sort as $sort => $id ) {
				$list[] = [ (int) $sort, $id ];
			}
		}

		set_transient( 'postful_idx_' . $type_key, $list, WEEK_IN_SECONDS );
		return self::$memo[ $type_key ] = $list;
	}

	public static function find( string $type_key, int $sort ): int {
		foreach ( self::get( $type_key ) as [ $s, $id ] ) {
			if ( $s === $sort ) {
				return $id;
			}
		}
		return 0;
	}
}
