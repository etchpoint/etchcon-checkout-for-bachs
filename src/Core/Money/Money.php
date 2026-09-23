<?php
/**
 * Money value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Money;

use InvalidArgumentException;

/**
 * Represents an exact, non-negative monetary value without floating point.
 */
final class Money {
	/**
	 * Canonical decimal amount at the currency's exact scale.
	 *
	 * @var string
	 */
	private string $amount;

	/**
	 * Currency for the amount.
	 *
	 * @var Currency
	 */
	private Currency $currency;

	/**
	 * Create a money value object from already-normalized data.
	 *
	 * @param string   $amount   Canonical amount.
	 * @param Currency $currency Currency.
	 */
	private function __construct( string $amount, Currency $currency ) {
		$this->amount   = $amount;
		$this->currency = $currency;
	}

	/**
	 * Create money from a plain decimal string.
	 *
	 * Accepted input contains digits and an optional decimal point only. Signs,
	 * exponent notation, locale separators and surrounding whitespace are
	 * rejected rather than normalized implicitly.
	 *
	 * @param string   $amount   Decimal amount.
	 * @param Currency $currency Currency.
	 * @return self
	 *
	 * @throws InvalidArgumentException When the amount is malformed or exceeds the currency scale.
	 */
	public static function from_decimal( string $amount, Currency $currency ): self {
		if ( 1 !== preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/D', $amount, $matches ) ) {
			throw new InvalidArgumentException( 'Money amount must be a non-negative plain decimal string.' );
		}

		$integer_part  = ltrim( $matches[1], '0' );
		$fraction_part = $matches[2] ?? '';
		$scale         = $currency->decimal_scale();

		if ( '' === $integer_part ) {
			$integer_part = '0';
		}

		if ( strlen( $fraction_part ) > $scale ) {
			throw new InvalidArgumentException( 'Money amount exceeds the permitted decimal scale for the currency.' );
		}

		if ( 0 === $scale ) {
			$canonical_amount = $integer_part;
		} else {
			$canonical_amount = $integer_part . '.' . str_pad( $fraction_part, $scale, '0' );
		}

		return new self( $canonical_amount, $currency );
	}

	/**
	 * Get the canonical decimal amount.
	 *
	 * @return string
	 */
	public function amount(): string {
		return $this->amount;
	}

	/**
	 * Get the currency.
	 *
	 * @return Currency
	 */
	public function currency(): Currency {
		return $this->currency;
	}

	/**
	 * Determine whether the amount is zero.
	 *
	 * @return bool
	 */
	public function is_zero(): bool {
		$zero = self::from_decimal( '0', $this->currency );

		return $this->amount === $zero->amount;
	}

	/**
	 * Determine whether the amount is greater than zero.
	 *
	 * @return bool
	 */
	public function is_positive(): bool {
		return ! $this->is_zero();
	}

	/**
	 * Determine whether another money value is exactly equal.
	 *
	 * @param self $other Money to compare.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->currency->equals( $other->currency ) && $this->amount === $other->amount;
	}

	/**
	 * Determine whether this amount is less than or equal to another amount.
	 *
	 * @param self $other Money to compare.
	 * @return bool
	 *
	 * @throws InvalidArgumentException When currencies differ.
	 */
	public function is_less_than_or_equal( self $other ): bool {
		if ( ! $this->currency->equals( $other->currency ) ) {
			throw new InvalidArgumentException( 'Money values with different currencies cannot be ordered.' );
		}

		$left  = ltrim( str_replace( '.', '', $this->amount ), '0' );
		$right = ltrim( str_replace( '.', '', $other->amount ), '0' );
		$left  = '' === $left ? '0' : $left;
		$right = '' === $right ? '0' : $right;

		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) < strlen( $right );
		}

		return strcmp( $left, $right ) <= 0;
	}

	/**
	 * Render the canonical decimal amount.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->amount;
	}
}
