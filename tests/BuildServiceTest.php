<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\BuildManifest;
use Grav\Plugin\Tailwind4\BuildService;
use Grav\Plugin\Tailwind4\Compiler;
use Grav\Plugin\Tailwind4\Scanner;
use Grav\Plugin\Tailwind4\SourceResolver;
use Grav\Plugin\Tailwind4\ThemeConfig;
use PHPUnit\Framework\TestCase;

final class BuildServiceTest extends TestCase
{
    private const FIXTURE_THEME = __DIR__ . '/fixtures/build/theme';

    private string $root = '';
    private string $themeDir = '';
    private string $manifestDir = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tw4-build-' . uniqid('', true);
        $this->themeDir = $this->root . '/user/themes/fixture';
        $this->manifestDir = $this->root . '/user/data/tailwind4';
        $this->cacheDir = $this->root . '/cache/tailwind4/scan';

        self::copyTree(self::FIXTURE_THEME, $this->themeDir);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            self::rmrf($this->root);
        }
    }

    public function testBuildWritesOutputAndManifest(): void
    {
        $service = $this->makeService();
        $manifest = $service->build();

        self::assertTrue($manifest->success, 'Build should succeed: ' . ($manifest->error ?? ''));
        self::assertNull($manifest->error);
        self::assertSame('fixture', $manifest->theme);

        // Output written to the default theme-relative target, dirs auto-created.
        $outputPath = $this->themeDir . '/build/css/site.css';
        self::assertSame($outputPath, $manifest->outputPath);
        self::assertFileExists($outputPath);

        $css = (string) file_get_contents($outputPath);
        self::assertStringContainsString('.flex', $css, 'Scanned template class must be compiled');
        self::assertStringContainsString('hover\:underline', $css, 'Twig set-variable class must be compiled');
        self::assertStringContainsString('build-fixture-marker', $css, 'Nested relative import must resolve');

        // Manifest numbers reflect reality.
        self::assertSame(\strlen($css), $manifest->outputSize);
        self::assertSame((int) filesize($outputPath), $manifest->outputSize);
        self::assertGreaterThanOrEqual(1, $manifest->filesScanned);
        self::assertGreaterThan(0, $manifest->candidateCount);
        self::assertGreaterThan(0.0, $manifest->compileMs);
        self::assertGreaterThanOrEqual($manifest->compileMs, $manifest->durationMs);
        self::assertSame(hash_file('sha256', $this->themeDir . '/css/site.css'), $manifest->inputHash);
        self::assertMatchesRegularExpression('/^v?\d/', $manifest->engineVersion);

        // Manifest persisted to <manifestDir>/<theme>.json and loadable.
        $manifestPath = $service->manifestPath();
        self::assertSame($this->manifestDir . '/fixture.json', $manifestPath);
        self::assertFileExists($manifestPath);

        $loaded = BuildManifest::load($manifestPath);
        self::assertNotNull($loaded);
        self::assertSame($manifest->theme, $loaded->theme);
        self::assertSame($manifest->outputSize, $loaded->outputSize);
        self::assertSame($manifest->candidateCount, $loaded->candidateCount);
        self::assertSame($manifest->inputHash, $loaded->inputHash);
        self::assertTrue($loaded->success);
    }

    public function testSecondBuildIsServedFromTheScanCache(): void
    {
        $service = $this->makeService();

        $first = $service->build();
        $second = $service->build();

        self::assertTrue($second->success);
        self::assertSame($first->filesScanned, $second->filesScanned);
        self::assertSame(0, $second->filesRead, 'Unchanged files must not be re-read');
        self::assertSame($second->filesScanned, $second->cacheHits);
        self::assertSame($first->candidateCount, $second->candidateCount);
    }

    public function testMissingInputProducesAnErrorManifestAndNoOutput(): void
    {
        $service = $this->makeService(['input' => 'css/does-not-exist.css']);
        $manifest = $service->build();

        self::assertFalse($manifest->success);
        self::assertNotNull($manifest->error);
        self::assertStringContainsString('missing or unreadable', $manifest->error);
        self::assertSame(0, $manifest->outputSize);
        self::assertFileDoesNotExist($this->themeDir . '/build/css/site.css');

        // The failure is persisted too, so the admin report can show it.
        $loaded = BuildManifest::load((string) $service->manifestPath());
        self::assertNotNull($loaded);
        self::assertFalse($loaded->success);
        self::assertSame($manifest->error, $loaded->error);
    }

    public function testFailedBuildNeverClobbersAPreviousOutput(): void
    {
        $good = $this->makeService();
        self::assertTrue($good->build()->success);
        $before = (string) file_get_contents($this->themeDir . '/build/css/site.css');

        $bad = $this->makeService(['input' => 'css/does-not-exist.css']);
        self::assertFalse($bad->build()->success);

        self::assertSame(
            $before,
            (string) file_get_contents($this->themeDir . '/build/css/site.css'),
            'A failed build must leave the previous output untouched',
        );
    }

    public function testBuildAcceptsAPreComputedScan(): void
    {
        $service = $this->makeService();

        $scan = $service->scan();
        $manifest = $service->build($scan);

        self::assertTrue($manifest->success);
        self::assertSame($scan->filesScanned, $manifest->filesScanned);
        self::assertSame($scan->tokenCount(), $manifest->candidateCount);
    }

    public function testManifestPersistenceCanBeDisabled(): void
    {
        $service = $this->makeService(manifestDir: '');

        $manifest = $service->build();

        self::assertTrue($manifest->success);
        self::assertNull($service->manifestPath());
        self::assertFileDoesNotExist($this->manifestDir . '/fixture.json');
    }

    public function testManifestRoundTripsThroughArrayForm(): void
    {
        $manifest = $this->makeService()->build();

        $roundTripped = BuildManifest::fromArray($manifest->toArray());

        self::assertEquals($manifest->toArray(), $roundTripped->toArray());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, mixed> $contract Overrides for the theme contract block.
     */
    private function makeService(array $contract = [], ?string $manifestDir = null): BuildService
    {
        $themeConfig = ThemeConfig::fromArray('fixture', $this->themeDir, $contract + [
            'sources' => ['self://templates'],
        ]);

        return new BuildService(
            themeConfig: $themeConfig,
            resolver: new SourceResolver($this->themeDir, $this->root . '/user', []),
            scanner: new Scanner($this->cacheDir),
            compiler: new Compiler(),
            manifestDir: $manifestDir ?? $this->manifestDir,
            minify: false,
        );
    }

    private static function copyTree(string $from, string $to): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        if (!is_dir($to)) {
            mkdir($to, 0775, true);
        }
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), \strlen($from) + 1);
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
