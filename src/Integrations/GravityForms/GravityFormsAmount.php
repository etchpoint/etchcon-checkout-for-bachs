<?php
/**
 * Gravity Forms amount normalization.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use InvalidArgumentException;

/**
 * Converts the Gravity Forms payment boundary into a canonical decimal string.
 */
final class GravityFormsAmount {
	/**
	 * Normalize a Gravity Forms payment amount at the entry currency scale.
	 *
	 * Gravity Forms documents payment_amount as a float. The adapter converts
	 * that boundary value immediately and the payment core continues using only
	 * canonical decimal strings.
	 *
	 * @param mixed    $value    Gravity Forms payment amount.
	 * @param Currency $currency Entry currency.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the amount is missing or malformed.
	 */
	public static function normalize( mixed $value, Currency $currency ): string {
		$scale = $currency->decimal_scale();

		if ( is_int( $value ) ) {
			$integer = (string) $value;

			return 0 === $scale ? $integer : $integer . '.' . str_repeat( '0', $scale );
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || 0 > $value ) {
				throw new InvalidArgumentException( 'Gravity Forms payment amount must be a finite non-negative number.' );
			}

			return self::format_numeric( $value, $scale );
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $value ) ) {
			throw new InvalidArgumentException( 'Gravity Forms payment amount must be a plain non-negative decimal.' );
		}

		$parts    = explode( '.', $value, 2 );
		$fraction = $parts[1] ?? '';

		if ( strlen( $fraction ) > $scale ) {
			throw new InvalidArgumentException( 'Gravity Forms payment amount exceeds the currency decimal scale.' );
		}

		$integer = ltrim( $parts[0], '0' );

		if ( '' === $integer ) {
			$integer = '0';
		}

		if ( 0 === $scale ) {
			return $integer;
		}

		return $integer . '.' . str_pad( $fraction, $scale, '0' );
	}

	/**
	 * Format a finite numeric boundary value at the currency scale.
	 *
	 * @param float $value Numeric value supplied by Gravity Forms.
	 * @param int   $scale Currency decimal scale.
	 * @return string
	 */
	private static function format_numeric( float $value, int $scale ): string {
		return number_format( $value, $scale, '.', '' );
	}
}
