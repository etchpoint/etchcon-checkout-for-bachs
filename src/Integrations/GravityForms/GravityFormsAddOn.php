<?php
/**
 * Gravity Forms payment add-on adapter.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use GFPaymentAddOn;
use Throwable;

/**
 * Connects Gravity Forms' official payment framework to Bachs hosted checkout.
 */
final class GravityFormsAddOn extends GFPaymentAddOn {
	/**
	 * Singleton add-on instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	// These protected property names are defined by Gravity Forms' GFAddOn API.
	// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = '1.0.0';

	/**
	 * Minimum supported Gravity Forms version.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.9';

	/**
	 * Gravity Forms add-on slug.
	 *
	 * @var string
	 */
	protected $_slug = 'gravityformsbachs';

	/**
	 * Plugin path used by the Gravity Forms framework.
	 *
	 * @var string
	 */
	protected $_path = 'etchcon-checkout-for-bachs/etchcon-checkout-for-bachs.php';

	/**
	 * Physical add-on class path.
	 *
	 * @var string
	 */
	protected $_full_path = __FILE__;

	/**
	 * Add-on title.
	 *
	 * @var string
	 */
	protected $_title = 'Gravity Forms Bachs';

	/**
	 * Short add-on title.
	 *
	 * @var string
	 */
	protected $_short_title = 'Bachs';

	/**
	 * Whether Gravity Forms should expose its callback route.
	 *
	 * The shared plugin webhook handles Bachs callbacks instead.
	 *
	 * @var bool
	 */
	protected $_supports_callbacks = false;

	/**
	 * Whether the gateway requires Gravity Forms' local card field.
	 *
	 * Bachs uses hosted checkout, so no local card field is required.
	 *
	 * @var bool
	 */
	protected $_requires_credit_card = false;

	// phpcs:enable PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Get the singleton add-on instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Restrict Bachs feeds to one-time Products and Services payments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function feed_settings_fields(): array {
		$settings = parent::feed_settings_fields();

		return self::restrict_transaction_type( $settings );
	}

	/**
	 * Start or resume hosted checkout for the saved Gravity Forms entry.
	 *
	 * @param array<string, mixed> $feed            Active payment feed.
	 * @param array<string, mixed> $submission_data Gravity Forms payment data.
	 * @param array<string, mixed> $form            Current form.
	 * @param array<string, mixed> $entry           Saved entry.
	 * @return string|false
	 */
	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		$transaction_type = self::nested_string( $feed, 'meta', 'transactionType' );

		if ( 'product' !== $transaction_type ) {
			return false;
		}

		$entry_id = self::positive_id( $entry['id'] ?? null );
		$form_id  = self::positive_id( $form['id'] ?? null );

		if ( null === $entry_id || null === $form_id ) {
			return false;
		}

		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				return false;
			}

			$currency_code = is_string( $entry['currency'] ?? null )
				? $entry['currency']
				: \GFCommon::get_currency();
			$currency      = Currency::from_code( $currency_code );
			$total         = GravityFormsAmount::normalize( $submission_data['payment_amount'] ?? null, $currency );
			$return_url    = self::return_url( $entry );
			$client        = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator   = new GravityFormsCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result        = $coordinator->start(
				$entry_id,
				$form_id,
				$total,
				$currency->code(),
				add_query_arg( 'bachs_payment', 'return', $return_url ),
				add_query_arg( 'bachs_payment', 'cancelled', $return_url )
			);

			gform_update_meta( $entry_id, '_etchpoint_bachs_intent_uuid', $result->intent_uuid(), $form_id );
			gform_update_meta( $entry_id, '_etchpoint_bachs_checkout_id', $result->checkout_id(), $form_id );

			return $result->redirect_url();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Apply a verified payment through Gravity Forms' native payment framework.
	 *
	 * @param array<string, mixed> $entry          Gravity Forms entry.
	 * @param string               $transaction_id Verified Bachs payment identifier.
	 * @param string               $amount         Canonical paid amount.
	 * @return void
	 */
	public function complete_verified_payment( array $entry, string $transaction_id, string $amount ): void {
		$entry_id = self::positive_id( $entry['id'] ?? null );

		if ( null === $entry_id ) {
			return;
		}

		$action = array(
			'id'               => 'bachs_' . hash( 'sha256', $transaction_id ),
			'type'             => 'complete_payment',
			'amount'           => $amount,
			'transaction_type' => 1,
			'transaction_id'   => $transaction_id,
			'entry_id'         => $entry_id,
			'payment_status'   => 'Paid',
			'note'             => __( 'Payment completed through Bachs.', 'etchcon-checkout-for-bachs' ),
		);

		$this->complete_payment( $entry, $action );
	}

	/**
	 * Recursively restrict the framework transaction type selector to products.
	 *
	 * @param array<int, array<string, mixed>> $settings Feed settings sections.
	 * @return array<int, array<string, mixed>>
	 */
	private static function restrict_transaction_type( array $settings ): array {
		foreach ( $settings as &$section ) {
			$fields = $section['fields'] ?? null;

			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as &$field ) {
				if ( ! is_array( $field ) || 'transactionType' !== ( $field['name'] ?? null ) ) {
					continue;
				}

				$field['choices'] = array(
					array(
						'label' => __( 'Products and Services', 'etchcon-checkout-for-bachs' ),
						'value' => 'product',
					),
				);
			}
			unset( $field );
		}
		unset( $section );

		return $settings;
	}

	/**
	 * Read a nested string from an array.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @param string               $first  First key.
	 * @param string               $second Second key.
	 * @return string|null
	 */
	private static function nested_string( array $source, string $first, string $second ): ?string {
		$nested = $source[ $first ] ?? null;

		if ( ! is_array( $nested ) ) {
			return null;
		}

		$value = $nested[ $second ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Normalize a positive identifier.
	 *
	 * @param mixed $value Candidate identifier.
	 * @return int|null
	 */
	private static function positive_id( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}

		$id = (int) $value;

		return 0 < $id ? $id : null;
	}

	/**
	 * Build a same-site browser return destination from the entry source URL.
	 *
	 * @param array<string, mixed> $entry Gravity Forms entry.
	 * @return string
	 */
	private static function return_url( array $entry ): string {
		$fallback = home_url( '/' );
		$source   = $entry['source_url'] ?? null;

		if ( ! is_string( $source ) || '' === $source ) {
			return $fallback;
		}

		return wp_validate_redirect( $source, $fallback );
	}

	/**
	 * Create the payment-intent repository from the active WordPress database.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}
}
