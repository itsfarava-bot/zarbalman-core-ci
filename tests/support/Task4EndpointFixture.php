<?php
declare(strict_types=1);
use Zarbalman\Core\Infrastructure\EvidenceCrypto;

final class Task4EndpointFixture {
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=';
    /** A synthetic encrypted attachment exercises the production downloader; upload is tested separately. */
    public static function evidence(int $orderId, int $buyerId, ?int $requestId = null): array {
        $binary = base64_decode(self::PNG, true);
        $crypto = new EvidenceCrypto();
        if (!$crypto->ensure_key()) { throw new RuntimeException('Real evidence encryption key unavailable.'); }
        $uploads = wp_upload_dir(null, false);
        $dir = $uploads['basedir'] . '/zbm-private-evidence/' . gmdate('Y/m');
        if (!wp_mkdir_p($dir)) { throw new RuntimeException('Private fixture directory unavailable.'); }
        $source = wp_tempnam('task4-image');
        file_put_contents($source, $binary);
        $path = $dir . '/zbm-' . bin2hex(random_bytes(16)) . '.zbm';
        $encrypted = $crypto->encrypt_file($source, $path);
        unlink($source);
        if (is_wp_error($encrypted)) { throw new RuntimeException($encrypted->get_error_message()); }
        $id = wp_insert_attachment(['post_status' => 'private', 'post_title' => 'Task4 synthetic private image',
            'post_author' => $buyerId, 'post_mime_type' => 'application/octet-stream'], $path, 0, true);
        if (is_wp_error($id)) { throw new RuntimeException('Evidence fixture insert failed.'); }
        update_attached_file($id, $path);
        foreach (['_zbm_evidence_private' => 1, '_zbm_evidence_encrypted' => 1, '_zbm_evidence_order_id' => $orderId,
            '_zbm_evidence_actor_id' => $buyerId, '_zbm_evidence_sha256' => $encrypted['sha256'],
            '_zbm_evidence_original_size' => $encrypted['size'], '_zbm_evidence_original_mime' => 'image/png'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        if ($requestId !== null) {
            update_post_meta($id, '_zbm_managed_request_id', $requestId);
            update_post_meta($id, '_zbm_managed_purpose', 'buyer_weight');
        }
        return [$id, $binary];
    }
}
