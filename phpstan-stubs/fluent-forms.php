<?php

declare(strict_types=1);

namespace FluentFormPro\Payments\PaymentMethods {
	abstract class BasePaymentMethod {
		/** @var string */
		protected $key = '';

		public function __construct( string $key ) { $this->key = $key; }
		abstract public function getGlobalFields();
		abstract public function getGlobalSettings();
		public function isEnabled(): bool { return true; }
	}

	abstract class BaseProcessor {
		/** @var string */
		public $method = '';
		/** @var object|null */
		protected $form;

		abstract public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscription, $totalPayable );
		public function setSubmissionId( int $submissionId ): void {}
		public function createInitialPendingTransaction( $submission = false, bool $hasSubscriptions = false ): \stdClass|false { return new \stdClass(); }
		public function setMetaData( string $name, mixed $value ): void {}
		public function getSubmission(): \stdClass|false { return new \stdClass(); }
		public function getMetaData( string $metaKey ): mixed { return null; }
		public function getLastTransaction( int $submissionId ): ?\stdClass { return new \stdClass(); }
		/** @param array<string, mixed> $data */
		public function updateTransaction( int $transactionId, array $data ): void {}
		public function changeTransactionStatus( int $transactionId, string $newStatus ): void {}
		public function changeSubmissionPaymentStatus( string $newStatus ): void {}
		public function recalculatePaidTotal(): void {}
		public function completePaymentSubmission( bool $isAjax = true ): mixed { return null; }
		public function refund( int|string $refundAmount, object $transaction, object $submission, string $method = '', string $refundId = '', string $refundNote = 'Refunded' ): mixed { return null; }
	}
}
