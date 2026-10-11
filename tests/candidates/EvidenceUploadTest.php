<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/CandidateFixture.php';
require_once __DIR__ . '/../support/HttpClient.php';
use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Infrastructure\{EvidenceCrypto, ManagedEvidence};

final class EvidenceUploadTest extends TestCase {
    public function test_real_multipart_upload_rejects_php_disguised_as_image_and_oversize_but_encrypts_valid_image(): void {
        [$request] = CandidateFixture::request();
        $admin = (int) get_user_by('login', 'zbm_test_admin')->ID;
        [$cookie, $nonce] = CandidateHttp::auth($admin, 'zbm_test_upload');
        $dir = sys_get_temp_dir() . '/zbm-evidence-' . wp_generate_uuid4();
        self::assertTrue(mkdir($dir, 0700));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', true);
        foreach (['fake.png' => '<?php echo "never execute"; ?>', 'large.png' => str_repeat('x', 5242881), 'valid.png' => $png] as $name => $content) {
            $file = $dir . '/' . $name;
            file_put_contents($file, $content);
            $before = (int) wp_count_posts('attachment')->private;
            $http = CandidateHttp::post('zbm-candidate-upload.php', ['_nonce' => $nonce, 'request_id' => (string) $request->id(), 'evidence' => new CURLFile($file, 'image/png', $name)], $cookie);
            $data = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR);
            if ($name !== 'valid.png') {
                self::assertSame(422, $http['status'], $name);
                self::assertContains($data['error'], ['zbm_evidence_type', 'zbm_evidence_content', 'zbm_evidence_size']);
                self::assertSame($before, (int) wp_count_posts('attachment')->private);
            } else {
                self::assertSame(200, $http['status']);
                self::assertCount(1, $data['ids']);
                $id = $data['ids'][0];
                $path = ManagedEvidence::privatePath($id);
                self::assertIsString($path);
                self::assertNotSame($png, file_get_contents($path));
                self::assertSame($png, (new EvidenceCrypto())->decrypt_file($path));
                self::assertSame(1, (int) get_post_meta($id, '_zbm_evidence_private', true));
                self::assertSame($request->id(), (int) get_post_meta($id, '_zbm_managed_request_id', true));
            }
        }
        wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
    }
    public function test_crypto_bound_rejects_oversize_without_writing_destination(): void {
        $crypto = new EvidenceCrypto();
        self::assertTrue($crypto->ensure_key());
        $source = tempnam(sys_get_temp_dir(), 'zbm-large-');
        file_put_contents($source, str_repeat('x', 5242881));
        $destination = $source . '.encrypted';
        $result = $crypto->encrypt_file($source, $destination);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('zbm_evidence_size', $result->get_error_code());
        self::assertFileDoesNotExist($destination);
    }
}
