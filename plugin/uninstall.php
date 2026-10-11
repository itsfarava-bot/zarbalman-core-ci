<?php
/**
 * Zarbalman Core uninstall handler.
 *
 * Retain all business tables, evidence, options and encryption keys together.
 * The former destructive ZBM_CORE_REMOVE_DATA flag is intentionally ignored:
 * partial financial retention without its keys must never occur. Data removal
 * requires a separately reviewed export/retention process.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! class_exists( \Zarbalman\Core\Infrastructure\Installer::class ) ) {
	require_once __DIR__ . '/src/Autoloader.php';
	\Zarbalman\Core\Autoloader::register( __DIR__ . '/src' );
}

\Zarbalman\Core\Infrastructure\Installer::deactivate();
