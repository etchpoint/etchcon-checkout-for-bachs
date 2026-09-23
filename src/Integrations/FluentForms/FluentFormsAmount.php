<?php
/**
 * Fluent Forms amount conversion.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use InvalidArgumentException;

/**
 * Converts Fluent Forms minor-unit payment totals into canonical decimals.
 */
final class FluentFormsAmount {
	/**
	 * Convert a non-negative minor-unit amount to the currency decimal scale.
	 *
	 * @param int|string $minor_units Fluent Forms payment total in minor units.
	 * @param Currency   $currency    Payment currency.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the minor-unit amount is malformed.
	 */
	public static function from_minor_units( int|string $minor_units, Currency $currency ): string {
		$value = (string) $minor_units;

		if ( 1 !== preg_match( '/\A[0-9]+\z/D', $value ) ) {
			throw new InvalidArgumentException( 'Fluent Forms payment total must contain non-negative minor units.' );
		}

		$value = ltrim( $value, '0' );
		$value = '' === $value ? '0' : $value;
		$scale = $currency->decimal_scale();

		if ( 0 === $scale ) {
			return $value;
		}

		$padded   = str_pad( $value, $scale + 1, '0', STR_PAD_LEFT );
		$position = strlen( $padded ) - $scale;

		return substr( $padded, 0, $position ) . '.' . substr( $padded, $position );
	}
}
