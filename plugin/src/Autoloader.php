<?php

declare(strict_types=1);

namespace Zarbalman\Core;

final class Autoloader {
	private const PREFIX = 'Zarbalman\\Core\\';

	public static function register( string $base_dir ): void {
		$base_dir = trailingslashit( $base_dir );

		spl_autoload_register(
			static function ( string $class ) use ( $base_dir ): void {
				if ( ! str_starts_with( $class, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}

