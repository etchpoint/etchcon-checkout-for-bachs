<?php
/**
 * WooCommerce hosted checkout coordinator.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Closure;
use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Persistence\CheckoutIntentStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use RuntimeException;

/**
 * Creates or safely resumes the Bachs checkout associated with a Woo order.
 */
final class WooCheckoutCoordinator {
	/** WooCommerce integration identifier. */
	private const INTEGRATION = 'woocommerce';

	/** WooCommerce local object type. */
	private const OBJECT_TYPE = 'order';

	/**
	 * Payment intent store.
	 *
	 * @var CheckoutIntentStore
	 */
	private CheckoutIntentStore $intents;

	/**
	 * Hosted checkout provider.
	 *
	 * @var CheckoutProvider
	 */
	private CheckoutProvider $checkouts;

	/**
	 * Active Bachs environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Stable site hash used in idempotency keys.
	 *
	 * @var string
	 */
	private string $site_hash;

	/**
	 * UUID generator.
	 *
	 * @var Closure(): string
	 */
	private Closure $uuid_generator;

	/**
	 * Opaque reference suffix generator.
	 *
	 * @var Closure(): string
	 */
	private Closure $reference_generator;

	/**
	 * Create the coordinator.
	 *
	 * @param CheckoutIntentStore $intents             Payment intent store.
	 * @param CheckoutProvider    $checkouts           Hosted checkout provider.
	 * @param Environment         $environment         Bachs environment.
	 * @param string              $site_hash           Stable lowercase site hash.
	 * @param Closure|null        $uuid_generator      UUID generator for tests.
	 * @param Closure|null        $reference_generator Reference suffix generator for tests.
	 *
	 * @throws RuntimeException When the site hash is malformed.
	 */
	public function __construct(
		CheckoutIntentStore $intents,
		CheckoutProvider $checkouts,
		Environment $environment,
		string $site_hash,
		?Closure $uuid_generator = null,
		?Closure $reference_generator = null
	) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{12}\z/D', $site_hash ) ) {
			throw new RuntimeException( 'Woo checkout site hash must contain 12 lowercase hexadecimal characters.' );
		}

		$this->intents             = $intents;
		$this->checkouts           = $checkouts;
		$this->environment         = $environment;
		$this->site_hash           = $site_hash;
		$this->uuid_generator      = $uuid_generator ?? static fn (): string => wp_generate_uuid4();
		$this->reference_generator = $reference_generator ?? static fn (): string => bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Start or resume hosted checkout for a WooCommerce order.
	 *
	 * @param int    $order_id    WooCommerce order identifier.
	 * @param string $total       Trusted WooCommerce order total.
	 * @param string $currency    Trusted WooCommerce order currency.
	 * @param string $success_url Browser success URL.
	 * @param string $cancel_url  Browser cancel URL.
	 * @return WooCheckoutResult
	 *
	 * @throws RuntimeException When checkout creation or persistence cannot be completed safely.
	 */
	public function start(
		int $order_id,
		string $total,
		string $currency,
		string $success_url,
		string $cancel_url
	): WooCheckoutResult {
		if ( 1 > $order_id ) {
			throw new RuntimeException( 'WooCommerce order ID must be positive.' );
		}

		$money  = Money::from_decimal( $total, Currency::from_code( $currency ) );
		$latest = $this->intents->find_latest_for_local_object(
			self::INTEGRATION,
			self::OBJECT_TYPE,
			(string) $order_id
		);

		if ( null !== $latest ) {
			$existing = $this->resume_or_classify_existing( $latest, $money, $success_url );

			if ( $existing instanceof WooCheckoutResult ) {
				return $existing;
			}

			if ( $existing instanceof IntentRecord ) {
				return $this->create_provider_checkout( $existing->id(), $existing->intent(), $order_id, $success_url, $cancel_url );
			}
		}

		$attempt = null === $latest ? 1 : $latest->intent()->attempt() + 1;
		$intent  = $this->create_intent( $order_id, $money, $attempt );
		$row_id  = $this->intents->create( $intent );

		return $this->create_provider_checkout( $row_id, $intent, $order_id, $success_url, $cancel_url );
	}

	/**
	 * Resume an existing attempt or determine whether a new attempt is safe.
	 *
	 * Returning null means a fresh logical attempt may be created. Returning the
	 * existing record means its idempotency key must be reused because no provider
	 * checkout was attached yet.
	 *
	 * @param IntentRecord $latest      Latest persisted Woo intent.
	 * @param Money        $money       Current trusted order amount.
	 * @param string       $success_url Browser success URL.
	 * @return WooCheckoutResult|IntentRecord|null
	 *
	 * @throws RuntimeException When an existing payment state makes a new charge unsafe.
	 */
	private function resume_or_classify_existing(
		IntentRecord $latest,
		Money $money,
		string $success_url
	): WooCheckoutResult|IntentRecord|null {
		$intent = $latest->intent();

		if ( ApplicationStatus::APPLIED === $intent->application_status() ) {
			throw new RuntimeException( 'WooCommerce payment intent is already applied.' );
		}

		if (
			ApplicationStatus::REQUIRES_REVIEW === $intent->application_status()
			|| ProviderStatus::UNDERPAID === $intent->provider_status()
			|| ProviderStatus::SUCCEEDED === $intent->provider_status()
			|| ProviderStatus::REFUNDED === $intent->provider_status()
			|| ProviderStatus::PARTIALLY_REFUNDED === $intent->provider_status()
		) {
			throw new RuntimeException( 'WooCommerce payment intent requires review before another checkout can be created.' );
		}

		$same_snapshot = $this->environment->value === $intent->environment()
			&& $intent->expected_amount()->equals( $money );
		$checkout_id   = $latest->checkout_id();

		if ( null === $checkout_id ) {
			return $same_snapshot ? $latest : null;
		}

		$session = $this->checkouts->get( $checkout_id );
		$status  = strtolower( $session->status() );

		if ( 'open' === $status ) {
			if ( ! $same_snapshot ) {
				throw new RuntimeException( 'WooCommerce order changed while an earlier Bachs checkout is still open.' );
			}

			return self::result_from_session( $latest, $session );
		}

		if ( 'completed' === $status ) {
			return new WooCheckoutResult( $success_url, $intent->uuid(), $session->checkout_id() );
		}

		if ( in_array( $status, array( 'expired', 'cancelled', 'canceled' ), true ) ) {
			return null;
		}

		throw new RuntimeException( 'Existing Bachs checkout is in an unexpected state.' );
	}

	/**
	 * Create the provider checkout and attach it to the persisted intent.
	 *
	 * @param int           $row_id      Intent database row identifier.
	 * @param PaymentIntent $intent      Trusted payment intent.
	 * @param int           $order_id    WooCommerce order identifier.
	 * @param string        $success_url Browser success URL.
	 * @param string        $cancel_url  Browser cancel URL.
	 * @return WooCheckoutResult
	 *
	 * @throws RuntimeException When the provider response or local attachment is incomplete.
	 */
	private function create_provider_checkout(
		int $row_id,
		PaymentIntent $intent,
		int $order_id,
		string $success_url,
		string $cancel_url
	): WooCheckoutResult {
		$session = $this->checkouts->create_raw_checkout(
			$intent,
			$success_url,
			$cancel_url,
			array( 'local_id' => (string) $order_id )
		);
		$url     = $session->checkout_url();

		if ( null === $url || '' === $url ) {
			throw new RuntimeException( 'Bachs checkout session did not include a hosted checkout URL.' );
		}

		if ( ! $this->intents->attach_checkout( $row_id, $session->checkout_id() ) ) {
			throw new RuntimeException( 'Bachs checkout was created but could not be attached to the local payment intent.' );
		}

		return new WooCheckoutResult( $url, $intent->uuid(), $session->checkout_id() );
	}

	/**
	 * Create a new immutable Woo payment intent.
	 *
	 * @param int   $order_id WooCommerce order identifier.
	 * @param Money $money    Trusted order money.
	 * @param int   $attempt  Logical checkout attempt.
	 * @return PaymentIntent
	 */
	private function create_intent( int $order_id, Money $money, int $attempt ): PaymentIntent {
		$uuid            = ( $this->uuid_generator )();
		$reference       = 'etp_bch_' . ( $this->reference_generator )();
		$idempotency_key = sprintf(
			'etp:%s:woo:%d:checkout:%d',
			$this->site_hash,
			$order_id,
			$attempt
		);

		return PaymentIntent::create(
			$uuid,
			self::INTEGRATION,
			self::OBJECT_TYPE,
			(string) $order_id,
			$this->environment->value,
			$reference,
			$idempotency_key,
			$money,
			$attempt
		);
	}

	/**
	 * Build a result from an already-open provider checkout.
	 *
	 * @param IntentRecord    $intent  Persisted intent.
	 * @param CheckoutSession $session Existing provider checkout.
	 * @return WooCheckoutResult
	 *
	 * @throws RuntimeException When the provider checkout URL is absent.
	 */
	private static function result_from_session(
		IntentRecord $intent,
		CheckoutSession $session
	): WooCheckoutResult {
		$url = $session->checkout_url();

		if ( null === $url || '' === $url ) {
			throw new RuntimeException( 'Bachs checkout session did not include a hosted checkout URL.' );
		}

		return new WooCheckoutResult( $url, $intent->intent()->uuid(), $session->checkout_id() );
	}
}
