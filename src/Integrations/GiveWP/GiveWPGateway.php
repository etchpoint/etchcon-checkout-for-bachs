<?php
/**
 * GiveWP Bachs payment gateway.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GiveWP;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;
use Throwable;

/**
 * Sends one-time GiveWP donations to Bachs hosted checkout.
 */
final class GiveWPGateway extends PaymentGateway {
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- GiveWP gateway API methods.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GiveWP model properties are camelCase.
	/** GiveWP gateway identifier. */
	private const ID = 'bachs';

	/**
	 * Return the stable GiveWP gateway identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return self::ID;
	}

	/**
	 * Return the gateway identifier for older GiveWP consumers.
	 *
	 * @return string
	 */
	public function getId(): string {
		return self::id();
	}

	/**
	 * Return the administrative gateway name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return __( 'Bachs', 'payment-integrations-for-bachs' );
	}

	/**
	 * Return the donor-facing payment method label.
	 *
	 * @return string
	 */
	public function getPaymentMethodLabel(): string {
		return __( 'Bachs', 'payment-integrations-for-bachs' );
	}

	/**
	 * Render the hosted-checkout notice for legacy GiveWP forms.
	 *
	 * @param int                  $form_id GiveWP form identifier.
	 * @param array<string, mixed> $args   Legacy form arguments.
	 * @return string
	 */
	public function getLegacyFormFieldMarkup( int $form_id, array $args ): string {
		unset( $form_id, $args );

		return '<div class="etchpoint-bachs-givewp-help"><p>'
			. esc_html__( 'You will be redirected to Bachs to complete your donation securely.', 'payment-integrations-for-bachs' )
			. '</p></div>';
	}

	/**
	 * Enqueue the gateway UI for GiveWP Visual Form Builder forms.
	 *
	 * @param int $form_id GiveWP form identifier.
	 * @return void
	 */
	public function enqueueScript( int $form_id ): void {
		unset( $form_id );

		wp_enqueue_script(
			'etchpoint-bachs-givewp',
			plugins_url( 'assets/js/givewp.js', dirname( __DIR__, 3 ) . '/payment-integrations-for-bachs.php' ),
			array( 'react', 'wp-element' ),
			'1.0.0',
			true
		);
	}

	/**
	 * Pass donor-facing hosted-checkout text to the Visual Form Builder gateway.
	 *
	 * @param int $form_id GiveWP form identifier.
	 * @return array<string, string>
	 */
	public function formSettings( int $form_id ): array {
		unset( $form_id );

		return array(
			'message' => __( 'You will be redirected to Bachs to complete your donation securely.', 'payment-integrations-for-bachs' ),
		);
	}

	/**
	 * Create or resume a hosted Bachs checkout for the saved GiveWP donation.
	 *
	 * @param Donation $donation    GiveWP donation model.
	 * @param mixed    $gateway_data GiveWP gateway request data.
	 * @return RedirectOffsite
	 *
	 * @throws PaymentGatewayException When hosted checkout cannot be started safely.
	 */
	public function createPayment( Donation $donation, $gateway_data ): RedirectOffsite {
		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				throw new PaymentGatewayException( __( 'Bachs payment configuration is incomplete.', 'payment-integrations-for-bachs' ) );
			}

			$donation_id = (int) $donation->id;
			$form_id     = (int) $donation->formId;
			$amount_data = $donation->amount->toArray();
			$total       = $amount_data['value'] ?? null;
			$currency    = $amount_data['currency'] ?? null;

			if ( 1 > $donation_id || 1 > $form_id || ! is_string( $total ) || ! is_string( $currency ) ) {
				throw new PaymentGatewayException( __( 'GiveWP donation data is incomplete.', 'payment-integrations-for-bachs' ) );
			}

			$client      = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator = new GiveWPCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result      = $coordinator->start(
				$donation_id,
				$form_id,
				$total,
				$currency,
				self::success_url( $gateway_data ),
				give_get_failed_transaction_uri()
			);

			return new RedirectOffsite( $result->redirect_url() );
		} catch ( PaymentGatewayException $exception ) {
			throw $exception;
		} catch ( Throwable ) {
			throw new PaymentGatewayException(
				esc_html__( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' )
			);
		}
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Resolve GiveWP's form-version-aware success destination.
	 *
	 * @param mixed $gateway_data GiveWP gateway request data.
	 * @return string
	 */
	private static function success_url( mixed $gateway_data ): string {
		$fallback = give_get_success_page_uri();

		if ( ! is_array( $gateway_data ) ) {
			return $fallback;
		}

		$candidate = $gateway_data['successUrl'] ?? null;

		if ( ! is_string( $candidate ) || '' === $candidate ) {
			return $fallback;
		}

		return wp_validate_redirect( $candidate, $fallback );
	}

	/**
	 * Create the shared payment intent repository.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}
}
