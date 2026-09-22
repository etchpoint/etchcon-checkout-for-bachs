<?php
/**
 * Currency value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Money;

use InvalidArgumentException;

/**
 * Represents a normalized ISO-style currency code and its decimal scale.
 *
 * Provider-specific currency support is intentionally validated by the Bachs
 * API layer. This object is responsible only for canonical currency identity
 * and decimal-scale rules used by exact money comparisons.
 */
final class Currency {
	/**
	 * Currency codes whose ISO 4217 minor-unit scale is not two decimals.
	 *
	 * Codes not listed here use the common two-decimal scale. Provider-specific
	 * support is validated later by the Bachs API layer rather than hard-coded
	 * into this core value object.
	 *
	 * @var array<string, int>
	 */
	private const SCALE_OVERRIDES = array(
		'BIF' => 0,
		'CLF' => 4,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'UYW' => 4,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * Canonical uppercase currency code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Decimal scale for this currency.
	 *
	 * @var int
	 */
	private int $scale;

	/**
	 * Create a currency value object.
	 *
	 * @param string $code  Canonical currency code.
	 * @param int    $scale Decimal scale.
	 */
	private function __construct( string $code, int $scale ) {
		$this->code  = $code;
		$this->scale = $scale;
	}

	/**
	 * Create a currency from a three-letter code.
	 *
	 * @param string $code Currency code.
	 * @return self
	 *
	 * @throws InvalidArgumentException When the code is malformed.
	 */
	public static function from_code( string $code ): self {
		if ( 1 !== preg_match( '/^[A-Za-z]{3}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Currency code must contain exactly three ASCII letters.' );
		}

		$canonical_code = strtoupper( $code );
		$scale          = self::SCALE_OVERRIDES[ $canonical_code ] ?? 2;

		return new self( $canonical_code, $scale );
	}

	/**
	 * Get the canonical uppercase currency code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Get the permitted decimal scale.
	 *
	 * @return int
	 */
	public function decimal_scale(): int {
		return $this->scale;
	}

	/**
	 * Determine whether another currency is identical.
	 *
	 * @param self $other Currency to compare.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->code === $other->code && $this->scale === $other->scale;
	}

	/**
	 * Render the currency as its canonical code.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->code;
	}
}
