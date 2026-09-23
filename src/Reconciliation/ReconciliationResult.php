<?php
/**
 * Reconciliation result value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Reconciliation;

/**
 * Immutable summary of one reconciliation attempt.
 */
final class ReconciliationResult {
	/**
	 * Result disposition.
	 *
	 * @var ReconciliationDisposition
	 */
	private ReconciliationDisposition $disposition;

	/**
	 * Intent row identifier when known.
	 *
	 * @var int|null
	 */
	private ?int $intent_id;

	/**
	 * Stable diagnostic code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Create a reconciliation result.
	 *
	 * @param ReconciliationDisposition $disposition Result disposition.
	 * @param int|null                  $intent_id   Intent row identifier.
	 * @param string                    $code        Stable diagnostic code.
	 */
	public function __construct( ReconciliationDisposition $disposition, ?int $intent_id, string $code ) {
		$this->disposition = $disposition;
		$this->intent_id   = $intent_id;
		$this->code        = $code;
	}

	/**
	 * Get the reconciliation disposition.
	 *
	 * @return ReconciliationDisposition
	 */
	public function disposition(): ReconciliationDisposition {
		return $this->disposition;
	}

	/**
	 * Get the intent row identifier.
	 *
	 * @return int|null
	 */
	public function intent_id(): ?int {
		return $this->intent_id;
	}

	/**
	 * Get the stable result code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}
}
