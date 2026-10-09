<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * A preset shown in the structure picker but not yet selectable.
 */
final class Preset_Planned extends Preset {

	public function __construct(
		private string $id,
		private string $label,
		private string $description,
		private string $good_for,
		private string $diagram
	) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function description(): string {
		return $this->description;
	}

	public function good_for(): string {
		return $this->good_for;
	}

	public function diagram(): string {
		return $this->diagram;
	}
}
