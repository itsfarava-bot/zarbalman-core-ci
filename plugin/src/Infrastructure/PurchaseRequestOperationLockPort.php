<?php

declare(strict_types=1);

namespace Zarbalman\Core\Infrastructure;

interface PurchaseRequestOperationLockPort {
	public function acquire( int $request_id, int $timeout_seconds = 10 ): void;

	public function release( int $request_id ): void;
}
