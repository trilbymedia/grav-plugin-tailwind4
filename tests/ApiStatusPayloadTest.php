<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\Api\StatusPayload;
use Grav\Plugin\Tailwind4\BuildManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiStatusPayloadTest extends TestCase
{
    private function successManifest(): BuildManifest
    {
        return new BuildManifest(
            theme: 'typhoon',
            success: true,
            error: null,
            timestamp: 1_700_000_000,
            durationMs: 1250.0,
            compileMs: 42.5,
            filesScanned: 120,
            cacheHits: 100,
            filesRead: 20,
            candidateCount: 850,
            outputPath: '/site/user/themes/typhoon/build/css/site.css',
            outputSize: 1536,
            inputHash: 'abc123',
            engineVersion: 'v1.4.2',
            peakMemoryBytes: 12_582_912,
        );
    }

    private function failureManifest(): BuildManifest
    {
        return new BuildManifest(
            theme: 'typhoon',
            success: false,
            error: 'Input CSS not found',
            timestamp: 1_700_000_000,
            durationMs: 5.0,
            compileMs: 0.0,
            filesScanned: 0,
            cacheHits: 0,
            filesRead: 0,
            candidateCount: 0,
            outputPath: '/site/user/themes/typhoon/build/css/site.css',
            outputSize: 0,
            inputHash: '',
            engineVersion: 'v1.4.2',
            peakMemoryBytes: 0,
        );
    }

    /** A translator that resolves keys to a readable string, echoing args. */
    private function translator(): callable
    {
        return static function (string $key, mixed ...$args): string {
            $short = substr($key, strrpos($key, '.') + 1);
            return $args === [] ? $short : $short . ':' . implode('|', $args);
        };
    }

    public function testStatusEmptyStateWhenNoManifest(): void
    {
        $payload = StatusPayload::status(null, 'typhoon');

        self::assertSame('typhoon', $payload['theme']);
        self::assertFalse($payload['compiled']);
        self::assertNull($payload['manifest']);
    }

    public function testStatusWithManifestExposesArray(): void
    {
        $payload = StatusPayload::status($this->successManifest(), 'ignored');

        self::assertTrue($payload['compiled']);
        // Theme comes from the manifest, not the passed-in default.
        self::assertSame('typhoon', $payload['theme']);
        self::assertIsArray($payload['manifest']);
        self::assertSame('v1.4.2', $payload['manifest']['engine_version']);
        self::assertSame(1536, $payload['manifest']['output_size']);
    }

    public function testCompileResultCarriesManifestAndToast(): void
    {
        $manifest = $this->successManifest();
        $toast = StatusPayload::toast($manifest, $this->translator());
        $result = StatusPayload::compileResult($manifest, $toast);

        self::assertTrue($result['compiled']);
        self::assertTrue($result['success']);
        self::assertSame('typhoon', $result['theme']);
        self::assertSame($toast, $result['toast']);
        self::assertSame($toast['message'], $result['message']);
        self::assertIsArray($result['manifest']);
    }

    public function testSuccessToastReportsDurationAndSize(): void
    {
        $toast = StatusPayload::toast($this->successManifest(), $this->translator());

        self::assertSame('success', $toast['type']);
        self::assertSame('success', $toast['status']);
        // COMPILE_SUCCESS resolved with theme, humanized duration and size.
        self::assertSame('COMPILE_SUCCESS:typhoon|1.25 s|1.5 KB', $toast['message']);
    }

    public function testFailureToastIsStickyAndCarriesError(): void
    {
        $toast = StatusPayload::toast($this->failureManifest(), $this->translator());

        self::assertSame('error', $toast['type']);
        self::assertSame(0, $toast['duration']);
        self::assertTrue($toast['dismissible']);
        self::assertSame('COMPILE_FAILED:Input CSS not found', $toast['message']);
    }

    #[DataProvider('byteCases')]
    public function testHumanBytes(int $bytes, string $expected): void
    {
        self::assertSame($expected, StatusPayload::humanBytes($bytes));
    }

    public static function byteCases(): array
    {
        return [
            [0, '0 B'],
            [512, '512 B'],
            [1024, '1 KB'],
            [1536, '1.5 KB'],
            [1_048_576, '1 MB'],
            [12_582_912, '12 MB'],
        ];
    }

    #[DataProvider('durationCases')]
    public function testHumanDuration(float $ms, string $expected): void
    {
        self::assertSame($expected, StatusPayload::humanDuration($ms));
    }

    public static function durationCases(): array
    {
        return [
            [45.2, '45 ms'],
            [999.4, '999 ms'],
            [1000.0, '1 s'],
            [1250.0, '1.25 s'],
            [2500.0, '2.5 s'],
        ];
    }
}
