<?php
/**
 * Diagnostic check value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Diagnostics;

/**
 * Immutable safe diagnostic result with no secret material.
 */
final class DiagnosticCheck {
	/**
	 * Stable check key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Check status.
	 *
	 * @var DiagnosticStatus
	 */
	private DiagnosticStatus $status;

	/**
	 * Safe result detail.
	 *
	 * @var string
	 */
	private string $detail;

	/**
	 * Create a diagnostic result.
	 *
	 * @param string           $key    Stable check key.
	 * @param string           $label  Human-readable label.
	 * @param DiagnosticStatus $status Check status.
	 * @param string           $detail Safe result detail.
	 */
	public function __construct( string $key, string $label, DiagnosticStatus $status, string $detail ) {
		$this->key    = $key;
		$this->label  = $label;
		$this->status = $status;
		$this->detail = $detail;
	}

	/**
	 * Get the stable check key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Get the check status.
	 *
	 * @return DiagnosticStatus
	 */
	public function status(): DiagnosticStatus {
		return $this->status;
	}

	/**
	 * Get the safe result detail.
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}
}
