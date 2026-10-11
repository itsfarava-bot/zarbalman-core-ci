<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

final class TransitionRequest {
	/**
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $to_state,
		public readonly string $actor_type,
		public readonly int $actor_user_id,
		public readonly string $idempotency_key,
		public readonly string $correlation_id,
		public readonly array $metadata = array()
	) {}

	/** @param array<string,mixed> $metadata */
	public function with_metadata( array $metadata ): self {
		return new self(
			$this->order_id,
			$this->to_state,
			$this->actor_type,
			$this->actor_user_id,
			$this->idempotency_key,
			$this->correlation_id,
			$metadata
		);
	}
}
