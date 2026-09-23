<?php
/**
 * Fluent Forms amount normalization.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use InvalidArgumentException;

/**
 * Converts Fluent Forms integer minor units into an exact decimal amount.
 */
final class FluentFormsAmount {
	/**
	 * Convert trusted Fluent Forms minor units to a canonical decimal string.
	 *
	 * @param int|string $value    Fluent Forms total payable in minor units.
	 * @param Currency   $currency Submission currency.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the minor-unit value is malformed.
	 */
	public static function from_minor_units( int|string $value, Currency $currency ): string {
		$digits = (string) $value;

		if ( 1 !== preg_match( '/^[0-9]+$/D', $digits ) ) {
			throw new InvalidArgumentException( 'Fluent Forms payment amount must contain non-negative integer minor units.' );
		}

		$digits = ltrim( $digits, '0' );
		$digits = '' === $digits ? '0' : $digits;
		$scale  = $currency->decimal_scale();

		if ( 0 === $scale ) {
			return $digits;
		}

		$minimum_length = $scale + 1;
		$padded         = str_pad( $digits, $minimum_length, '0', STR_PAD_LEFT );
		$split          = strlen( $padded ) - $scale;

		return substr( $padded, 0, $split ) . '.' . substr( $padded, $split );
	}

	/**
	 * Convert a canonical decimal amount to Fluent Forms minor units.
	 *
	 * @param string   $amount   Canonical decimal amount.
	 * @param Currency $currency Currency.
	 * @return int|string
	 *
	 * @throws InvalidArgumentException When the decimal amount is malformed.
	 */
	public static function to_minor_units( string $amount, Currency $currency ): int|string {
		$scale = $currency->decimal_scale();
		$money = \Etchpoint\BachsIntegrations\Core\Money\Money::from_decimal( $amount, $currency );
		$minor = 0 === $scale ? $money->amount() : str_replace( '.', '', $money->amount() );
		$minor = ltrim( $minor, '0' );
		$minor = '' === $minor ? '0' : $minor;

		if ( strlen( $minor ) < 18 ) {
			return (int) $minor;
		}

		return $minor;
	}

}
