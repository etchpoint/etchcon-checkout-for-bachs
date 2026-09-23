<?php

declare(strict_types=1);

namespace Give\Framework\Support\ValueObjects {
	class Money {
		/** @return array<string, mixed> */
		public function toArray(): array {
			return array(
				'value'             => '0.00',
				'valueInMinorUnits' => '0',
				'currency'          => 'USD',
			);
		}
	}
}

namespace Give\Donations\ValueObjects {
	class DonationStatus {
		public static function COMPLETE(): self { return new self(); }
		public static function REFUNDED(): self { return new self(); }
		public function isComplete(): bool { return false; }
		public function isRefunded(): bool { return false; }
	}
}

namespace Give\Donations\Models {
	use Give\Donations\ValueObjects\DonationStatus;
	use Give\Framework\Support\ValueObjects\Money;

	class Donation {
		public int $id = 0;
		public int $formId = 0;
		public string $gatewayId = '';
		public string $gatewayTransactionId = '';
		public Money $amount;
		public DonationStatus $status;

		public static function find( $id ): ?self { return new self(); }
		public function save(): void {}
	}

	class DonationNote {
		/** @param array{donationId:int,content:string} $attributes */
		public static function create( array $attributes ): self { return new self(); }
	}
}

namespace Give\Framework\PaymentGateways\Commands {
	interface GatewayCommand {}

	class RedirectOffsite implements GatewayCommand {
		public string $redirectUrl;
		public function __construct( string $redirectUrl ) { $this->redirectUrl = $redirectUrl; }
	}
}

namespace Give\Framework\PaymentGateways\Exceptions {
	class PaymentGatewayException extends \Exception {}
}

namespace Give\Framework\PaymentGateways {
	use Give\Donations\Models\Donation;

	abstract class PaymentGateway {
		abstract public static function id(): string;
		abstract public function getId(): string;
		abstract public function getName(): string;
		abstract public function getPaymentMethodLabel(): string;
		abstract public function createPayment( Donation $donation, $gatewayData );
		public function enqueueScript( int $formId ) {}
		/** @return array<string, mixed> */
		public function formSettings( int $formId ): array { return array(); }
	}

	class PaymentGatewayRegister {
		/** @param class-string<PaymentGateway> $gatewayClass */
		public function registerGateway( string $gatewayClass ): void {}
	}
}

namespace {
	function give_get_success_page_uri(): string { return 'https://example.test/success'; }
	function give_get_failed_transaction_uri(): string { return 'https://example.test/failed'; }
}
