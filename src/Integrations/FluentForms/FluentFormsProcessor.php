<?php
/**
 * Fluent Forms payment processor.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use RuntimeException;
use Throwable;

/**
 * Starts hosted Bachs checkout and applies only webhook-verified payments.
 */
final class FluentFormsProcessor extends BaseProcessor {
	/**
	 * Fluent Forms payment method identifier.
	 *
	 * @var string
	 */
	public $method = 'bachs';

	/**
	 * Current Fluent Forms form object.
	 *
	 * @var object
	 */
	protected $form;

	/**
	 * Whether this instance has registered processor hooks.
	 *
	 * @var bool
	 */
	private bool $hooks_registered = false;

	/** Plugin fulfillment marker stored as submission metadata. */
	private const FULFILLED_META = '_etchpoint_bachs_fulfilled';

	/** Plugin intent UUID metadata key. */
	private const INTENT_META = '_etchpoint_bachs_intent_uuid';

	/** Provider checkout metadata key. */
	private const CHECKOUT_META = '_etchpoint_bachs_checkout_id';

	/**
	 * Register Fluent Forms payment processing hooks once.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;
		add_action( 'fluentform/process_payment_' . $this->method, array( $this, 'handlePaymentAction' ), 10, 6 );
		add_action( 'fluentform/payment_frameless_' . $this->method, array( $this, 'handleSessionRedirectBack' ) );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fluent Forms API method.
	/**
	 * Create a pending Fluent transaction and redirect to hosted Bachs checkout.
	 *
	 * @param int                    $submission_id   Fluent Forms submission identifier.
	 * @param array<string, mixed>   $submission_data Submitted form data.
	 * @param object                 $form            Fluent Forms form object.
	 * @param array<string, mixed>   $method_settings Selected payment method settings.
	 * @param bool                   $has_subscription Whether the submission contains subscriptions.
	 * @param int                    $total_payable   Trusted Fluent Forms total in minor units.
	 * @return void
	 */
	public function handlePaymentAction( $submission_id, $submission_data, $form, $method_settings, $has_subscription = false, $total_payable = 0 ) {
		// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $submission_data, $method_settings );

		$submission_id = (int) $submission_id;
		$form_values   = get_object_vars( $form );
		$form_id       = isset( $form_values['id'] ) ? (int) $form_values['id'] : 0;

		if ( 1 > $submission_id || 1 > $form_id || true === $has_subscription ) {
			wp_send_json_error( array( 'message' => __( 'Bachs currently supports one-time Fluent Forms payments only.', 'payment-integrations-for-bachs' ) ), 422 );
		}

		$this->form = $form;
		$this->setSubmissionId( $submission_id );

		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				throw new RuntimeException( 'Bachs payment configuration is incomplete.' );
			}

			$this->createInitialPendingTransaction( false, false );
			$transaction = $this->getLastTransaction( $submission_id );

			if ( ! is_object( $transaction ) ) {
				throw new RuntimeException( 'Fluent Forms did not create a pending payment transaction.' );
			}

			$transaction_id   = isset( $transaction->id ) ? (int) $transaction->id : 0;
			$transaction_hash = isset( $transaction->transaction_hash ) && is_string( $transaction->transaction_hash )
				? $transaction->transaction_hash
				: '';
			$currency_code    = isset( $transaction->currency ) && is_string( $transaction->currency )
				? $transaction->currency
				: '';
			$payment_total    = $transaction->payment_total ?? null;

			if ( 1 > $transaction_id || '' === $transaction_hash || '' === $currency_code || ( ! is_int( $payment_total ) && ! is_string( $payment_total ) ) ) {
				throw new RuntimeException( 'Fluent Forms pending transaction is incomplete.' );
			}

			if ( (string) $total_payable !== (string) $payment_total ) {
				throw new RuntimeException( 'Fluent Forms payment total changed during checkout initialization.' );
			}

			$currency    = Currency::from_code( $currency_code );
			$total       = FluentFormsAmount::from_minor_units( $payment_total, $currency );
			$return_args = array(
				'fluentform_payment' => $submission_id,
				'payment_method'     => $this->method,
				'transaction_hash'   => $transaction_hash,
			);
			$success_url = add_query_arg( $return_args + array( 'type' => 'success' ), site_url( '/' ) );
			$cancel_url  = add_query_arg( $return_args + array( 'type' => 'cancelled' ), site_url( '/' ) );
			$client      = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator = new FluentFormsCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result      = $coordinator->start(
				$submission_id,
				$form_id,
				$total,
				$currency->code(),
				$success_url,
				$cancel_url
			);

			$this->setMetaData( self::INTENT_META, $result->intent_uuid() );
			$this->setMetaData( self::CHECKOUT_META, $result->checkout_id() );

			wp_send_json_success(
				array(
					'nextAction'   => 'payment',
					'actionName'   => 'normalRedirect',
					'redirect_url' => $result->redirect_url(),
					'message'      => __( 'Redirecting to Bachs checkout.', 'payment-integrations-for-bachs' ),
					'result'       => array( 'insert_id' => $submission_id ),
				),
				200
			);
		} catch ( Throwable ) {
			wp_send_json_error( array( 'message' => __( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' ) ), 423 );
		}
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fluent Forms API method.
	/**
	 * Render Fluent Forms' return view without accepting browser payment claims.
	 *
	 * @param mixed $data Fluent Forms return-route data.
	 * @return mixed
	 */
	public function handleSessionRedirectBack( $data ) {
		// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! is_array( $data ) ) {
			return null;
		}

		$submission_id    = isset( $data['fluentform_payment'] ) ? absint( $data['fluentform_payment'] ) : 0;
		$transaction_hash = isset( $data['transaction_hash'] ) && is_string( $data['transaction_hash'] )
			? sanitize_text_field( $data['transaction_hash'] )
			: '';

		if ( 1 > $submission_id || '' === $transaction_hash ) {
			return null;
		}

		$transaction = $this->getTransaction( $transaction_hash, 'transaction_hash' );

		if (
			! is_object( $transaction )
			|| (int) ( $transaction->response_id ?? 0 ) !== $submission_id
			|| 'bachs' !== (string) ( $transaction->payment_method ?? '' )
		) {
			return null;
		}

		$this->setSubmissionId( $submission_id );
		$return_data = $this->getReturnData();
		$this->showPaymentView( $return_data );

		return $return_data;
	}

	/**
	 * Apply one already-verified Bachs payment through Fluent Forms APIs.
	 *
	 * @param int    $submission_id Fluent Forms submission identifier.
	 * @param string $charge_id     Verified Bachs payment identifier.
	 * @param Money  $amount        Authoritative paid amount and currency.
	 * @return bool True when newly applied, false when already applied identically.
	 *
	 * @throws RuntimeException When the Fluent Forms transaction does not match.
	 */
	public function fulfill_verified_payment( int $submission_id, string $charge_id, Money $amount ): bool {
		$this->setSubmissionId( $submission_id );
		$transaction = $this->getLastTransaction( $submission_id );

		if ( ! is_object( $transaction ) ) {
			throw new RuntimeException( 'Fluent Forms transaction could not be found.' );
		}

		$transaction_id  = isset( $transaction->id ) ? (int) $transaction->id : 0;
		$method          = isset( $transaction->payment_method ) ? (string) $transaction->payment_method : '';
		$currency_code   = isset( $transaction->currency ) ? (string) $transaction->currency : '';
		$payment_total   = $transaction->payment_total ?? null;
		$existing_charge = isset( $transaction->charge_id ) ? (string) $transaction->charge_id : '';

		if ( 1 > $transaction_id || 'bachs' !== $method || '' === $currency_code || ( ! is_int( $payment_total ) && ! is_string( $payment_total ) ) ) {
			throw new RuntimeException( 'Fluent Forms transaction is incomplete or belongs to another payment method.' );
		}

		$transaction_money = Money::from_decimal(
			FluentFormsAmount::from_minor_units( $payment_total, Currency::from_code( $currency_code ) ),
			Currency::from_code( $currency_code )
		);

		if ( ! $transaction_money->equals( $amount ) ) {
			throw new RuntimeException( 'Verified Bachs amount does not match the Fluent Forms transaction.' );
		}

		if ( '' !== $existing_charge && ! hash_equals( $existing_charge, $charge_id ) ) {
			throw new RuntimeException( 'Fluent Forms transaction already contains a different charge identifier.' );
		}

		$fulfilled = $this->getMetaData( self::FULFILLED_META );

		if ( is_string( $fulfilled ) && '' !== $fulfilled ) {
			if ( ! hash_equals( $fulfilled, $charge_id ) ) {
				throw new RuntimeException( 'Fluent Forms fulfillment marker conflicts with the verified charge.' );
			}

			return false;
		}

		$this->updateTransaction(
			$transaction_id,
			array(
				'charge_id'    => $charge_id,
				'payment_note' => __( 'Payment verified through the signed Bachs webhook.', 'payment-integrations-for-bachs' ),
			)
		);
		$this->changeSubmissionPaymentStatus( 'paid' );
		$this->changeTransactionStatus( $transaction_id, 'paid' );
		$this->recalculatePaidTotal();
		$this->completePaymentSubmission( false );
		$this->setMetaData( self::FULFILLED_META, $charge_id );

		return true;
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
