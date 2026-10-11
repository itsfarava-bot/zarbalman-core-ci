<?php
declare(strict_types=1);
require __DIR__ . '/wp-load.php'; // Copied only into the disposable local HTTP document root.
if (!defined('ZBM_TEST_SANDBOX') || ZBM_TEST_SANDBOX !== true || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_nonce'] ?? '', 'zbm_test_upload')) { http_response_code(403); exit; }
$crypto = new Zarbalman\Core\Infrastructure\EvidenceCrypto();
$result = (new Zarbalman\Core\Infrastructure\EvidenceUploader($crypto))->upload(0, get_current_user_id(), $_FILES['evidence'] ?? [], (int) ($_POST['request_id'] ?? 0), 'buyer_weight');
header('Content-Type: application/json');
if (is_wp_error($result)) { http_response_code(422); echo wp_json_encode(['error' => $result->get_error_code()]); exit; }
echo wp_json_encode(['ids' => $result]);
