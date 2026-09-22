<?php
/**
 * WooCommerce Bachs payment gateway.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
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
		$this->method_description = __( 'Accept payment through Bachs hosted checkout.', 'payment-integrations-for-bachs' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bachs', 'payment-integrations-for-bachs' ) );
		$this->description = $this->get_option(
			'description',
			__( 'Pay securely using Bachs.', 'payment-integrations-for-bachs' )
		);

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
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
			$configuration = RuntimeConfiguration::from_wordpress();
			$client        = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$checkouts     = new CheckoutApi( $client );
			$repository    = self::intent_repository();
			$site_hash     = substr( hash( 'sha256', home_url( '/' ) ), 0, 12 );
			$coordinator   = new WooCheckoutCoordinator(
				$repository,
				$checkouts,
				$configuration->environment(),
				$site_hash
			);
			$result        = $coordinator->start(
				$order->get_id(),
				(string) $order->get_total(),
				$order->get_currency(),
				BrowserReturnController::success_url( $order ),
				$order->get_checkout_payment_url()
			);

			$order->update_meta_data( '_etchpoint_bachs_intent_uuid', $result->intent_uuid() );
			$order->update_meta_data( '_etchpoint_bachs_checkout_id', $result->checkout_id() );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $result->redirect_url(),
			);
		} catch ( Throwable ) {
			wc_add_notice(
				__( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' ),
				'error'
			);

			return array( 'result' => 'failure' );
		}
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
