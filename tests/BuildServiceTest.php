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
        // A tagged engine reports "v1.4.2"; the trilbymedia fork branch reports
        // "dev-trilby@<short-sha>". Both identify an exact engine source.
        self::assertMatchesRegularExpression('/^(v?\d|dev-\S+@[0-9a-f]{7})/', $manifest->engineVersion);

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

    public function testUnreadableSourceDirProducesErrorManifestAndNoOutput(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('root bypasses directory permission bits');
        }

        // A source directory that exists but cannot be read (0000). The walk
        // must fail into a clean error manifest, never a half-written output.
        $blocked = $this->root . '/user/pages';
        mkdir($blocked, 0775, true);
        file_put_contents($blocked . '/page.md', "class: p-4\n");
        chmod($blocked, 0000);

        try {
            $service = $this->makeService(sources: [$blocked]);
            $manifest = $service->build();
        } finally {
            chmod($blocked, 0775);
        }

        self::assertFalse($manifest->success);
        self::assertNotNull($manifest->error);
        self::assertSame(0, $manifest->outputSize);
        self::assertFileDoesNotExist($this->themeDir . '/build/css/site.css');
    }

    public function testEngineExceptionMidCompilePreservesPreviousOutput(): void
    {
        // A first good build establishes the baseline output.
        self::assertTrue($this->makeService()->build()->success);
        $outputPath = $this->themeDir . '/build/css/site.css';
        $before = (string) file_get_contents($outputPath);

        // Corrupt the input CSS so the engine throws part-way through compile
        // (not the missing-input path — the file is present and readable).
        file_put_contents($this->themeDir . '/css/site.css', "@import \"tailwindcss\";\n.x { color:");

        $manifest = $this->makeService()->build();

        self::assertFalse($manifest->success);
        self::assertNotNull($manifest->error);
        self::assertSame(0, $manifest->outputSize);

        // Previous output survives byte-identical.
        self::assertSame($before, (string) file_get_contents($outputPath));

        // No stray tmp file was left behind next to the output.
        $strays = glob($this->themeDir . '/build/css/*.tmp') ?: [];
        self::assertSame([], $strays, 'A failed build must leave no partial .tmp file');
    }

    public function testFailedBuildRetainsLastSuccessInManifest(): void
    {
        $good = $this->makeService()->build();
        self::assertTrue($good->success);

        // Now break the input and rebuild; the failure manifest must remember
        // the last good build (output path + size) rather than losing it.
        file_put_contents($this->themeDir . '/css/site.css', "@import \"tailwindcss\";\n.x { color:");
        $service = $this->makeService();
        $failed = $service->build();

        self::assertFalse($failed->success);
        self::assertNotNull($failed->lastSuccess);
        self::assertTrue($failed->lastSuccess->success);
        self::assertSame($good->outputPath, $failed->lastSuccess->outputPath);
        self::assertSame($good->outputSize, $failed->lastSuccess->outputSize);

        // It round-trips through the persisted JSON, so the admin report sees it.
        $loaded = BuildManifest::load((string) $service->manifestPath());
        self::assertNotNull($loaded);
        self::assertNotNull($loaded->lastSuccess);
        self::assertSame($good->outputSize, $loaded->lastSuccess->outputSize);
        // Retention is one level deep only.
        self::assertNull($loaded->lastSuccess->lastSuccess);
    }

    public function testConcurrentBuildReturnsInProgressWithoutClobbering(): void
    {
        // Establish a good build, then hold the build lock as if another process
        // were mid-compile.
        self::assertTrue($this->makeService()->build()->success);
        $outputPath = $this->themeDir . '/build/css/site.css';
        $before = (string) file_get_contents($outputPath);
        $manifestBefore = (string) file_get_contents((string) $this->makeService()->manifestPath());

        $held = fopen($this->lockFile(), 'c');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB), 'Test must be able to hold the lock');

        try {
            // Short wait window so the contended call gives up quickly.
            $manifest = $this->makeService(lockWaitSeconds: 0.2)->build();
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        self::assertFalse($manifest->success);
        self::assertStringContainsString('already in progress', (string) $manifest->error);
        // Non-destructive: the held build's output and manifest are untouched.
        self::assertSame($before, (string) file_get_contents($outputPath));
        self::assertSame($manifestBefore, (string) file_get_contents((string) $this->makeService()->manifestPath()));
        // The transient in-progress result still reports the prior good build.
        self::assertNotNull($manifest->lastSuccess);
        self::assertTrue($manifest->lastSuccess->success);
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
     * @param array<int, string>   $sources  Source list; defaults to the theme templates.
     */
    private function makeService(
        array $contract = [],
        ?string $manifestDir = null,
        float $lockWaitSeconds = 2.0,
        ?array $sources = null,
    ): BuildService {
        $themeConfig = ThemeConfig::fromArray('fixture', $this->themeDir, $contract + [
            'sources' => $sources ?? ['self://templates'],
        ]);

        return new BuildService(
            themeConfig: $themeConfig,
            resolver: new SourceResolver($this->themeDir, $this->root . '/user', []),
            scanner: new Scanner($this->cacheDir),
            compiler: new Compiler(),
            manifestDir: $manifestDir ?? $this->manifestDir,
            minify: false,
            lockDir: $this->cacheDir,
            lockWaitSeconds: $lockWaitSeconds,
        );
    }

    /** Absolute path of the lock file makeService() builds serialize on. */
    private function lockFile(): string
    {
        return $this->cacheDir . '/build-fixture.lock';
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
