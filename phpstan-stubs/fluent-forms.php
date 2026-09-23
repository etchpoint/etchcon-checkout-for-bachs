<?php

namespace FluentFormPro\Payments\PaymentMethods;

class PaymentTransaction {
	/** @var mixed */
	public $id;
	/** @var mixed */
	public $transaction_hash;
	/** @var mixed */
	public $currency;
	/** @var mixed */
	public $payment_total;
	/** @var mixed */
	public $payment_method;
	/** @var mixed */
	public $charge_id;
	/** @var mixed */
	public $response_id;
}

abstract class BasePaymentMethod {
	/** @var string */
	protected $key = '';

	/** @param string $key */
	public function __construct( $key ) { $this->key = $key; }

	public function init(): void {}

	/** @param array<string, mixed> $methods @return array<string, mixed> */
	public function pushPaymentMethodToForm( $methods ) { return $methods; }

	/** @return array<string, mixed> */
	abstract public function getGlobalFields();

	/** @return array<string, mixed> */
	abstract public function getGlobalSettings();
}

abstract class BaseProcessor {
	/** @var string */
	public $method = '';
	/** @var object */
	protected $form;

	public function init(): void {}

	/**
	 * @param int                  $submissionId
	 * @param array<string, mixed> $submissionData
	 * @param object               $form
	 * @param array<string, mixed> $methodSettings
	 */
	abstract public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings );

	/** @param int $submissionId */
	public function setSubmissionId( $submissionId ): void {}

	/** @param object|false $submission @param bool $hasSubscriptions @return mixed */
	public function createInitialPendingTransaction( $submission = false, $hasSubscriptions = false ) { return null; }

	/** @param int $submissionId @return PaymentTransaction|null */
	public function getLastTransaction( $submissionId ) { return null; }

	/** @param string $transactionId @param string $column @return PaymentTransaction|null */
	public function getTransaction( $transactionId, $column = 'id' ) { return null; }

	/** @param string $name @param mixed $value */
	public function setMetaData( $name, $value ): void {}

	/** @param string $name @return mixed */
	public function getMetaData( $name ) { return null; }

	/** @return mixed */
	public function getReturnData() { return array(); }

	/** @param mixed $returnData */
	public function showPaymentView( $returnData ): void {}

	/** @param int $transactionId @param array<string, mixed> $data */
	public function updateTransaction( $transactionId, $data ): void {}

	/** @param string $newStatus */
	public function changeSubmissionPaymentStatus( $newStatus ): void {}

	/** @param int $transactionId @param string $newStatus */
	public function changeTransactionStatus( $transactionId, $newStatus ): void {}

	public function recalculatePaidTotal(): void {}

	/** @param bool $isAjax @return mixed */
	public function completePaymentSubmission( $isAjax = true ) { return null; }
}
