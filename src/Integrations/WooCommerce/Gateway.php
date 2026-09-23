<?php
/**
 * WooCommerce Bachs payment gateway.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Redirects WooCommerce customers to Bachs hosted checkout.
 */
final class Gateway extends WC_Payment_Gateway {
	/** WooCommerce gateway identifier. */
	public const ID = 'bachs';

	/**
	 * Create the payment gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Bachs', 'payment-integrations-for-bachs' );
		$this->method_description = __( 'Accept payment through Bachs hosted checkout. Configure shared Bachs credentials under Bachs Payments > Settings.', 'payment-integrations-for-bachs' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bachs', 'payment-integrations-for-bachs' ) );
		$this->description = $this->get_option(
			'description',
			__( 'Pay securely using Bachs.', 'payment-integrations-for-bachs' )
		);

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_gateway_settings' ) );
	}

	/**
	 * Save gateway settings from the WooCommerce admin screen.
	 *
	 * @return void
	 */
	public function save_gateway_settings(): void {
		$this->process_admin_options();
	}

	/**
	 * Define merchant-facing WooCommerce gateway fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'payment-integrations-for-bachs' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bachs hosted checkout', 'payment-integrations-for-bachs' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'payment-integrations-for-bachs' ),
				'type'        => 'text',
				'description' => __( 'The payment method title shown at checkout.', 'payment-integrations-for-bachs' ),
				'default'     => __( 'Bachs', 'payment-integrations-for-bachs' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'payment-integrations-for-bachs' ),
				'type'        => 'textarea',
				'description' => __( 'The payment method description shown at checkout.', 'payment-integrations-for-bachs' ),
				'default'     => __( 'Pay securely using Bachs.', 'payment-integrations-for-bachs' ),
			),
		);
	}

	/**
	 * Determine whether the gateway can safely accept a checkout.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		try {
			return RuntimeConfiguration::from_wordpress()->is_payment_ready();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Create or resume Bachs hosted checkout for a Woo order.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Unable to load this order for payment.', 'payment-integrations-for-bachs' ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		try {
			$configuration  = RuntimeConfiguration::from_wordpress();
			$client         = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$checkouts      = new CheckoutApi( $client );
			$repository     = self::intent_repository();
			$site_hash      = substr( hash( 'sha256', home_url( '/' ) ), 0, 12 );
			$coordinator    = new WooCheckoutCoordinator(
				$repository,
				$checkouts,
				$configuration->environment(),
				$site_hash
			);
			$customer_email = trim( $order->get_billing_email() );

			if ( '' === $customer_email || false === filter_var( $customer_email, FILTER_VALIDATE_EMAIL ) ) {
				self::log_checkout_error(
					(int) $order_id,
					new RuntimeException( 'WooCommerce order does not contain a valid billing email for Bachs checkout.' )
				);
				wc_add_notice(
					__( 'Bachs checkout could not be started. Please check your billing email and try again.', 'payment-integrations-for-bachs' ),
					'error'
				);

				return array( 'result' => 'failure' );
			}

			$customer_name = trim( $order->get_formatted_billing_full_name() );
			$customer      = array( 'email' => $customer_email );
			$billing_phone = trim( $order->get_billing_phone() );

			if ( '' !== $customer_name ) {
				$customer['name'] = $customer_name;
			}

			if ( 1 === preg_match( '/\A\+[1-9][0-9]{7,14}\z/D', $billing_phone ) ) {
				$customer['phone_number'] = $billing_phone;
			}

			$result = $coordinator->start(
				$order->get_id(),
				(string) $order->get_total(),
				$order->get_currency(),
				BrowserReturnController::success_url( $order ),
				$order->get_checkout_payment_url(),
				$customer
			);

			$order->update_meta_data( '_etchpoint_bachs_intent_uuid', $result->intent_uuid() );
			$order->update_meta_data( '_etchpoint_bachs_checkout_id', $result->checkout_id() );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $result->redirect_url(),
			);
		} catch ( Throwable $exception ) {
			self::log_checkout_error( (int) $order_id, $exception );
			wc_add_notice(
				__( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' ),
				'error'
			);

			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Log a checkout initialization failure without exposing credentials.
	 *
	 * @param int       $order_id  WooCommerce order identifier.
	 * @param Throwable $exception Checkout failure.
	 * @return void
	 */
	private static function log_checkout_error( int $order_id, Throwable $exception ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context = array(
			'source'    => 'bachs',
			'order_id'  => $order_id,
			'exception' => get_class( $exception ),
			'message'   => $exception->getMessage(),
		);

		if ( $exception instanceof ApiException ) {
			$context['error_code']  = $exception->error_code();
			$context['http_status'] = $exception->http_status();
		}

		wc_get_logger()->error( 'Bachs checkout initialization failed.', $context );
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
