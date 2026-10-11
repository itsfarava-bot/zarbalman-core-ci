<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

final class Actor {
	public const ADMIN = 'admin';
	public const VENDOR = 'vendor';
	public const BUYER = 'buyer';
	public const SYSTEM = 'system';

	/** @return list<string> */
	public static function all(): array {
		return array( self::ADMIN, self::VENDOR, self::BUYER, self::SYSTEM );
	}
}

