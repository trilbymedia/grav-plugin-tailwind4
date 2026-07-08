<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

use RuntimeException;
use Throwable;

/**
 * Orchestrates one full build: resolve sources -> scan candidates -> compile ->
 * write output atomically -> persist a {@see BuildManifest}.
 *
 * The constructor takes explicit collaborators and plain paths so the whole
 * flow is unit testable without Grav; {@see fromGrav()} is the Grav-coupled
 * factory used by the CLI (and later the admin endpoint), wiring the cache dir
 * to `cache://tailwind4/scan` and the manifest to `user-data://tailwind4`.
 *
 * A failed build never throws out of {@see build()}: the error is captured in
 * the manifest (and persisted) so callers get one uniform result to display,
 * and a failure never leaves a half-written output file behind. A failure also
 * remembers the last successful build in the manifest's `last_success` block,
 * so the admin report keeps the previous output path and stats.
 *
 * Concurrency: {@see build()} serializes on a per-theme `flock`. The admin
 * button is the case that matters — a double-click, or a CLI compile racing an
 * admin compile, must never interleave writes. The lock is taken non-blocking
 * and retried for a short bounded window (default 2s): a second click on a fast
 * (sub-second) build simply waits for the first to finish and then recompiles,
 * both writes atomic and ordered. Only if a genuinely long build still holds
 * the lock past the window does the second caller give up and return a
 * transient "build already in progress" error manifest — which is deliberately
 * NOT persisted, so it can never clobber the running build's manifest. This is
 * the safest choice for the button: no interleaved writes, no request that
 * blocks forever, and a non-destructive fallback.
 */
final class BuildService
{
    /** Poll interval while waiting for a contended build lock, microseconds. */
    private const LOCK_POLL_US = 50_000;
    /**
     * @param ThemeConfig    $themeConfig The theme's tailwind4 contract.
     * @param SourceResolver $resolver    Resolves the contract's sources to paths.
     * @param Scanner        $scanner     Extracts candidates (with per-file cache).
     * @param Compiler       $compiler    Runs the TailwindPHP engine.
     * @param string         $manifestDir Directory for `<theme>.json` manifests.
     *                                    Empty string disables persistence.
     * @param bool           $minify      Minify the compiled output.
     * @param string         $lockDir     Directory for the per-theme build lock
     *                                    file. Empty string falls back to the
     *                                    manifest dir, then the system temp dir.
     * @param float          $lockWaitSeconds How long to wait for a contended
     *                                    lock before returning a "build in
     *                                    progress" manifest.
     */
    public function __construct(
        private readonly ThemeConfig $themeConfig,
        private readonly SourceResolver $resolver,
        private readonly Scanner $scanner,
        private readonly Compiler $compiler,
        private readonly string $manifestDir = '',
        private readonly bool $minify = true,
        private readonly string $lockDir = '',
        private readonly float $lockWaitSeconds = 2.0,
    ) {
    }

    /**
     * Wire a BuildService from a booted Grav instance.
     *
     * @param string|null $themeName Theme to build; null means the active theme.
     */
    public static function fromGrav(?string $themeName = null): self
    {
        $grav = \Grav\Common\Grav::instance();

        /** @var \Grav\Common\Config\Config $config */
        $config = $grav['config'];
        $themeName = $themeName ?: (string) $config->get('system.pages.theme');
        $themeConfig = ThemeConfig::fromGrav($themeName);

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $grav['locator'];
        $cacheDir = (string) $locator->findResource('cache://tailwind4/scan', true, true);
        $manifestDir = (string) $locator->findResource('user-data://tailwind4', true, true);

        $extensions = (array) $config->get(
            'plugins.tailwind4.scan_extensions',
            ['twig', 'md', 'yaml', 'yml', 'php', 'html', 'htm', 'js'],
        );
        $minify = (bool) ($config->get('plugins.tailwind4.minify') ?? true);
        $containerFix = (bool) ($config->get('plugins.tailwind4.container_fix') ?? true);

        return new self(
            themeConfig: $themeConfig,
            resolver: SourceResolver::fromGrav($themeConfig->themeDir),
            scanner: new Scanner($cacheDir, $extensions, [$themeConfig->outputRootDir()]),
            compiler: new Compiler($containerFix),
            manifestDir: $manifestDir,
            minify: $minify,
            lockDir: $cacheDir,
        );
    }

    public function themeConfig(): ThemeConfig
    {
        return $this->themeConfig;
    }

    /**
     * Path the manifest is persisted to, or null when persistence is disabled.
     */
    public function manifestPath(): ?string
    {
        if ($this->manifestDir === '') {
            return null;
        }

        return rtrim($this->manifestDir, '/') . '/' . $this->themeConfig->themeName . '.json';
    }

    /**
     * Resolve the contract's sources and scan them for candidates. Public so
     * the CLI watch loop can poll cheaply (unchanged files are cache hits) and
     * hand the result straight to {@see build()}.
     */
    public function scan(): ScanResult
    {
        $sources = $this->resolver->resolve($this->themeConfig->sources, $this->themeConfig->safelistFiles);

        return $this->scanner->scan($sources);
    }

    /**
     * Run the full build under the per-theme concurrency lock. Always returns a
     * manifest; on failure it carries the error message and no output file is
     * written (or overwritten). If another build already holds the lock and does
     * not release it within the wait window, returns a transient "build in
     * progress" manifest without persisting or writing anything.
     *
     * @param ScanResult|null $scan Reuse an existing scan (from a watch poll)
     *                              instead of scanning again.
     */
    public function build(?ScanResult $scan = null): BuildManifest
    {
        $lockFile = $this->lockFilePath();
        $handle = $lockFile !== '' ? @fopen($lockFile, 'c') : false;

        // No lock file possible (or it could not be opened): fall back to an
        // unlocked build rather than refusing to compile at all.
        if ($handle === false) {
            return $this->runBuild($scan);
        }

        try {
            if (!$this->acquireLock($handle)) {
                return $this->buildInProgressManifest();
            }

            return $this->runBuild($scan);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * The build itself, assumed to hold the lock. Persists the resulting
     * manifest (success or failure) to `<theme>.json`.
     */
    private function runBuild(?ScanResult $scan): BuildManifest
    {
        $start = microtime(true);
        $config = $this->themeConfig;

        try {
            $scan ??= $this->scan();

            $result = $this->compiler->compile($config->inputPath(), $scan->tokens, $this->minify);

            $outputPath = $config->outputPath();
            $this->writeAtomic($outputPath, $result->css);

            $manifest = new BuildManifest(
                theme: $config->themeName,
                success: true,
                error: null,
                timestamp: time(),
                durationMs: (microtime(true) - $start) * 1000.0,
                compileMs: $result->durationMs,
                filesScanned: $scan->filesScanned,
                cacheHits: $scan->cacheHits,
                filesRead: $scan->filesRead,
                candidateCount: $result->candidateCount,
                outputPath: $outputPath,
                outputSize: \strlen($result->css),
                inputHash: (string) @hash_file('sha256', $config->inputPath()),
                engineVersion: self::engineVersion(),
                peakMemoryBytes: $result->peakMemoryBytes,
            );
        } catch (Throwable $e) {
            $manifest = new BuildManifest(
                theme: $config->themeName,
                success: false,
                error: $e->getMessage(),
                timestamp: time(),
                durationMs: (microtime(true) - $start) * 1000.0,
                compileMs: 0.0,
                filesScanned: $scan?->filesScanned ?? 0,
                cacheHits: $scan?->cacheHits ?? 0,
                filesRead: $scan?->filesRead ?? 0,
                candidateCount: $scan !== null ? $scan->tokenCount() : 0,
                outputPath: $config->outputPath(),
                outputSize: 0,
                inputHash: '',
                engineVersion: self::engineVersion(),
                peakMemoryBytes: 0,
                lastSuccess: $this->previousSuccess(),
            );
        }

        $manifestPath = $this->manifestPath();
        if ($manifestPath !== null) {
            $manifest->save($manifestPath);
        }

        return $manifest;
    }

    /**
     * Absolute path of the per-theme build lock file, or '' when no directory
     * can host it (in which case the build runs unlocked). Prefers an explicit
     * lock dir, then the manifest dir, then the system temp dir.
     */
    private function lockFilePath(): string
    {
        $dir = $this->lockDir !== '' ? $this->lockDir : $this->manifestDir;
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }
        if ($dir === '') {
            return '';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        return rtrim($dir, '/') . '/build-' . $this->themeConfig->themeName . '.lock';
    }

    /**
     * Take the exclusive lock non-blocking, retrying for the wait window so a
     * quick double-click serializes cleanly. Returns false only if a build is
     * still running when the window elapses.
     *
     * @param resource $handle Open handle to the lock file.
     */
    private function acquireLock($handle): bool
    {
        $deadline = microtime(true) + max(0.0, $this->lockWaitSeconds);

        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(self::LOCK_POLL_US);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * A non-persisted manifest returned when another build holds the lock past
     * the wait window. It carries the last successful build so the admin report
     * still shows a compiled state, and is never written to disk (the running
     * build owns the manifest file).
     */
    private function buildInProgressManifest(): BuildManifest
    {
        $config = $this->themeConfig;

        return new BuildManifest(
            theme: $config->themeName,
            success: false,
            error: 'A build is already in progress for this theme',
            timestamp: time(),
            durationMs: 0.0,
            compileMs: 0.0,
            filesScanned: 0,
            cacheHits: 0,
            filesRead: 0,
            candidateCount: 0,
            outputPath: $config->outputPath(),
            outputSize: 0,
            inputHash: '',
            engineVersion: self::engineVersion(),
            peakMemoryBytes: 0,
            lastSuccess: $this->previousSuccess(),
        );
    }

    /**
     * The last successful build recorded for this theme, read from the persisted
     * manifest so a fresh failure can retain it. Returns the file directly when
     * it was itself a success, or its embedded `last_success` otherwise; null
     * when nothing has ever succeeded (or persistence is disabled).
     */
    private function previousSuccess(): ?BuildManifest
    {
        $path = $this->manifestPath();
        if ($path === null) {
            return null;
        }

        $previous = BuildManifest::load($path);
        if ($previous === null) {
            return null;
        }

        return $previous->success ? $previous : $previous->lastSuccess;
    }

    /**
     * Write the output atomically: the CSS lands in a tmp file next to the
     * target and is renamed into place, so a reader (or a failed write) never
     * sees a truncated stylesheet. The tmp file is always cleaned up on failure,
     * so a botched write leaves neither a partial output nor a stray tmp behind.
     *
     * @throws RuntimeException When the directory or file cannot be written.
     */
    private function writeAtomic(string $path, string $css): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create output directory "%s"', $dir));
        }

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $css) === false) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to write output file "%s"', $tmp));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to move output into place at "%s"', $path));
        }
    }

    /**
     * TailwindPHP engine version, read from the plugin's own Composer metadata.
     * Read directly from vendor/composer/installed.php (no autoload needed) so
     * it works whether or not the engine has been loaded, and never collides
     * with Grav's own Composer runtime.
     */
    public static function engineVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        $file = \dirname(__DIR__) . '/vendor/composer/installed.php';
        if (is_file($file)) {
            $data = include $file;
            $pretty = $data['versions']['tailwindphp/tailwindphp']['pretty_version'] ?? null;
            if (\is_string($pretty) && $pretty !== '') {
                return $version = $pretty;
            }
        }

        return $version = 'unknown';
    }
}
