<?php
declare(strict_types=1);
require_once __DIR__ . '/../../plugin/src/Integration/ZarinpalVerificationResponse.php';

use PHPUnit\Framework\TestCase;
use Zarbalman\Core\Integration\ZarinpalVerificationResponse;

final class ZarinpalResponseContractTest extends TestCase {
    public static function responses(): array {
        $cases = [];
        foreach ([100, 101] as $code) {
            $cases['verified_' . $code] = [200, ['data' => ['code' => $code, 'ref_id' => '123456'], 'errors' => []], 'verified'];
        }
        foreach ([-51, -53, -54, -55] as $code) {
            foreach (['data', 'errors'] as $envelope) {
                $cases[$envelope . '_denied_' . abs($code)] = [200, [$envelope => ['code' => $code]], 'rejected'];
            }
        }
        foreach ([-50, -52, -12, -999, 0] as $code) {
            foreach (['data', 'errors'] as $envelope) {
                $cases[$envelope . '_uncertain_' . abs($code)] = [200, [$envelope => ['code' => $code]], 'unavailable'];
            }
        }
        $cases['server_error'] = [500, ['data' => ['code' => 100, 'ref_id' => '123456']], 'unavailable'];
        $cases['network_error'] = [0, [], 'unavailable'];
        $cases['missing_reference'] = [200, ['data' => ['code' => 100]], 'unavailable'];
        $cases['conflicting_envelopes'] = [200, ['data' => ['code' => 100, 'ref_id' => '123456'], 'errors' => ['code' => -51]], 'unavailable'];
        return $cases;
    }

    /** @dataProvider responses */
    public function test_response_classification_is_explicit_and_exclusive(int $status, array $payload, string $expected): void {
        $response = ZarinpalVerificationResponse::fromHttp($status, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame($expected === 'verified', $response->isVerified());
        self::assertSame($expected === 'rejected', $response->isRejected());
        self::assertSame($expected === 'unavailable', $response->isUnavailable());
        self::assertSame($expected === 'verified' ? '123456' : null, $response->reference());
    }

    public function test_invalid_json_is_unavailable(): void {
        self::assertTrue(ZarinpalVerificationResponse::fromHttp(200, '{')->isUnavailable());
    }
}
