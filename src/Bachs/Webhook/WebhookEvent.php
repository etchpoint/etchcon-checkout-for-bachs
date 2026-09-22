<?php
/**
 * Parsed Bachs webhook event.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use DateTimeImmutable;

/**
 * Immutable subset of a Bachs webhook envelope required by the plugin.
 */
final class WebhookEvent {
	/**
	 * Provider event identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Provider event type.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Provider event creation timestamp.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Provider organization identifier.
	 *
	 * @var string
	 */
	private string $organization_id;

	/**
	 * Optional connected-account identifier.
	 *
	 * @var string|null
	 */
	private ?string $account;

	/**
	 * Event-specific payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create a parsed webhook event.
	 *
	 * @param string               $id              Provider event identifier.
	 * @param string               $type            Provider event type.
	 * @param DateTimeImmutable    $created_at      Event creation timestamp.
	 * @param string               $organization_id Provider organization identifier.
	 * @param string|null          $account         Optional connected-account identifier.
	 * @param array<string, mixed> $data            Event-specific payload.
	 */
	public function __construct(
		string $id,
		string $type,
		DateTimeImmutable $created_at,
		string $organization_id,
		?string $account,
		array $data
	) {
		$this->id              = $id;
		$this->type            = $type;
		$this->created_at      = $created_at;
		$this->organization_id = $organization_id;
		$this->account         = $account;
		$this->data            = $data;
	}

	/**
	 * Get the provider event identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Get the provider event type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Get the event creation timestamp.
	 *
	 * @return DateTimeImmutable
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Get the provider organization identifier.
	 *
	 * @return string
	 */
	public function organization_id(): string {
		return $this->organization_id;
	}

	/**
	 * Get the optional connected-account identifier.
	 *
	 * @return string|null
	 */
	public function account(): ?string {
		return $this->account;
	}

	/**
	 * Get the event-specific payload.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}
}
