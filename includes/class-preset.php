<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * A preset decides what identifies an entry (its key), how entries sort and
 * group, and what "current" means. Keys are arrays (e.g. ['month'=>3,'day'=>5])
 * that each preset maps to a single integer sort value used everywhere else.
 */
abstract class Preset {

	abstract public function id(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	abstract public function good_for(): string;

	/** Small inline SVG for the structure picker. */
	abstract public function diagram(): string;

	public function available(): bool {
		return false;
	}

	public function default_fields(): array {
		return [];
	}

	public function default_public_urls(): bool {
		return true;
	}

	/** Meta keys holding the key, for revisions and uninstall. */
	public function meta_keys(): array {
		return [];
	}

	/** CSV columns that make up the key. */
	public function key_columns(): array {
		return [];
	}

	/** Stored key for a post, or null when unset. */
	public function key_of( int $post_id ): ?array {
		return null;
	}

	/**
	 * Normalise raw input (editor POST or CSV row) to a key.
	 *
	 * @return array|null|\WP_Error null = deliberately empty, WP_Error = invalid.
	 */
	public function parse_key( array $input ) {
		return null;
	}

	public function save_key( int $post_id, ?array $key ): void {}

	public function sort_of( array $key ): int {
		return 0;
	}

	public function key_from_sort( int $sort ): ?array {
		return null;
	}

	/** Label in a content language, or in the admin language when $code is null. */
	public function key_label( array $key, ?string $code = null, string $locale = '' ): string {
		return '';
	}

	/** Sort value of the current entry ("today"), or null. */
	public function current_sort(): ?int {
		return null;
	}

	/** Sidebar box on the entry editor. */
	public function render_key_box( \WP_Post $post, array $type ): void {}
}
