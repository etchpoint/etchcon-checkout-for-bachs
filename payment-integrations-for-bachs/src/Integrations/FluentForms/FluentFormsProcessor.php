<?php
/**
 * Fluent Forms Bachs payment processor.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use RuntimeException;
use Throwable;

/**
 * Creates hosted Bachs checkout and applies verified payments through Fluent APIs.
 */
final class FluentFormsProcessor extends BaseProcessor {
	/**
	 * Fluent Forms payment method key.
	 *
	 * @var string
	 */
	public $method = 'bachs';

	/**
	 * Current Fluent Forms form object.
	 *
	 * @var object|null
	 */
	protected $form;

	/**
	 * Register the Fluent Forms payment processing hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'fluentform_process_payment_' . $this->method, array( $this, 'handlePaymentAction' ), 10, 6 );
	}

	/**
	 * Start Bachs hosted checkout for a trusted Fluent Forms payment submission.
	 *
	 * @param int                  $submission_id   Fluent Forms submission identifier.
	 * @param array<string, mixed> $submission_data Submitted form data.
	 * @param object               $form            Fluent Forms form object.
	 * @param array<string, mixed> $method_settings Payment method settings.
	 * @param bool                 $has_subscription Whether recurring items are present.
	 * @param int|string           $total_payable   Trusted total in minor currency units.
	 * @return void
	 */
	public function handlePaymentAction(
		$submission_id,
		$submission_data,
		$form,
		$method_settings,
		$has_subscription,
		$total_payable
	): void {
		unset( $submission_data, $method_settings );

		if ( true === $has_subscription ) {
			wp_send_json_error(
				array( 'message' => __( 'Recurring Bachs payments are not supported yet.', 'payment-integrations-for-bachs' ) ),
				422
			);
		}

		$this->form = $form;
		$this->setSubmissionId( (int) $submission_id );

		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				throw new RuntimeException( 'Bachs payment configuration is incomplete.' );
			}

			$transaction = $this->createInitialPendingTransaction( false, false );

			if ( ! is_object( $transaction ) || 1 > (int) ( $transaction->id ?? 0 ) ) {
				throw new RuntimeException( 'Fluent Forms did not create a pending payment transaction.' );
			}

			$currency_code     = (string) ( $transaction->currency ?? '' );
			$currency          = Currency::from_code( $currency_code );
			$transaction_total = FluentFormsAmount::from_minor_units( (string) ( $transaction->payment_total ?? '' ), $currency );
			$hook_total        = FluentFormsAmount::from_minor_units( $total_payable, $currency );
			$form_id           = (int) ( $form->id ?? 0 );

			if ( ! hash_equals( $transaction_total, $hook_total ) ) {
				throw new RuntimeException( 'Fluent Forms payment totals do not match.' );
			}

			if ( 1 > $form_id ) {
				throw new RuntimeException( 'Fluent Forms form ID is missing.' );
			}

			$total = $transaction_total;

			$return_url  = $this->return_url( (int) $submission_id );
			$client      = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator = new FluentFormsCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result      = $coordinator->start(
				(int) $submission_id,
				$form_id,
				$total,
				$currency->code(),
				add_query_arg( 'bachs_payment', 'return', $return_url ),
				add_query_arg( 'bachs_payment', 'cancelled', $return_url )
			);

			$this->setMetaData( '_etchpoint_bachs_intent_uuid', $result->intent_uuid() );
			$this->setMetaData( '_etchpoint_bachs_checkout_id', $result->checkout_id() );

			wp_send_json_success(
				array(
					'nextAction'   => 'payment',
					'actionName'   => 'normalRedirect',
					'redirect_url' => $result->redirect_url(),
					'message'      => __( 'Redirecting to Bachs secure checkout.', 'payment-integrations-for-bachs' ),
					'result'       => array( 'insert_id' => (int) $submission_id ),
				)
			);
		} catch ( Throwable ) {
			wp_send_json_error(
				array( 'message' => __( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' ) ),
				422
			);
		}
	}

	/**
	 * Return Fluent Forms' native test/live mode for the active Bachs environment.
	 *
	 * @return string
	 */
	public function getPaymentMode(): string {
		$environment = RuntimeConfiguration::from_wordpress()->environment();

		return 'live' === $environment->value ? 'live' : 'test';
	}

	/**
	 * Read current native Fluent Forms payment state for a submission.
	 *
	 * @param int $submission_id Fluent Forms submission identifier.
	 * @return array{submission_status:string,transaction_status:string,charge_id:string,payment_method:string,transaction_id:int}
	 *
	 * @throws RuntimeException When the native payment records cannot be resolved.
	 */
	public function verified_state( int $submission_id ): array {
		$this->setSubmissionId( $submission_id );
		$submission  = $this->getSubmission();
		$transaction = $this->getLastTransaction( $submission_id );

		if ( ! is_object( $submission ) || ! is_object( $transaction ) ) {
			throw new RuntimeException( 'Fluent Forms payment records could not be resolved.' );
		}

		$transaction_id = (int) ( $transaction->id ?? 0 );

		if ( 1 > $transaction_id ) {
			throw new RuntimeException( 'Fluent Forms payment transaction is missing.' );
		}

		return array(
			'submission_status'  => (string) ( $submission->payment_status ?? '' ),
			'transaction_status' => (string) ( $transaction->status ?? '' ),
			'charge_id'          => (string) ( $transaction->charge_id ?? '' ),
			'payment_method'     => (string) ( $transaction->payment_method ?? '' ),
			'transaction_id'     => $transaction_id,
		);
	}

	/**
	 * Apply verified Bachs evidence through Fluent Forms' processor APIs.
	 *
	 * @param int    $submission_id Fluent Forms submission identifier.
	 * @param string $charge_id     Verified Bachs payment identifier.
	 * @return void
	 *
	 * @throws RuntimeException When native Fluent Forms state conflicts or cannot be updated.
	 */
	public function complete_verified_payment( int $submission_id, string $charge_id ): void {
		$state = $this->verified_state( $submission_id );

		if ( 'bachs' !== $state['payment_method'] ) {
			throw new RuntimeException( 'Fluent Forms transaction belongs to another payment method.' );
		}

		if ( '' !== $state['charge_id'] && ! hash_equals( $state['charge_id'], $charge_id ) ) {
			throw new RuntimeException( 'Fluent Forms transaction already has a different charge ID.' );
		}

		if ( 'paid' === strtolower( $state['submission_status'] ) && 'paid' === strtolower( $state['transaction_status'] ) ) {
			if ( ! hash_equals( $state['charge_id'], $charge_id ) ) {
				throw new RuntimeException( 'Fluent Forms paid transaction does not match the verified charge.' );
			}

			return;
		}

		$this->updateTransaction(
			$state['transaction_id'],
			array(
				'charge_id'    => $charge_id,
				'payment_note' => __( 'Payment verified through the signed Bachs webhook.', 'payment-integrations-for-bachs' ),
			)
		);
		$this->changeTransactionStatus( $state['transaction_id'], 'paid' );
		$this->changeSubmissionPaymentStatus( 'paid' );
		$this->recalculatePaidTotal();
		$this->setMetaData( '_etchpoint_bachs_fulfilled_charge', $charge_id );
		$this->completePaymentSubmission( false );
	}

	/**
	 * Build a safe browser return URL without treating it as payment evidence.
	 *
	 * @param int $submission_id Fluent Forms submission identifier.
	 * @return string
	 */
	private function return_url( int $submission_id ): string {
		$submission = $this->getSubmission();
		$fallback   = home_url( '/' );
		$source_url = is_object( $submission ) ? (string) ( $submission->source_url ?? '' ) : '';
		$base       = '' === $source_url ? $fallback : wp_validate_redirect( $source_url, $fallback );

		return add_query_arg(
			array(
				'fluentform_payment' => $submission_id,
				'payment_method'     => $this->method,
			),
			$base
		);
	}

	/**
	 * Create the payment-intent repository.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}
}
