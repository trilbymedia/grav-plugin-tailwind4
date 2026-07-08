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
 * and a failure never leaves a half-written output file behind.
 */
final class BuildService
{
    /**
     * @param ThemeConfig    $themeConfig The theme's tailwind4 contract.
     * @param SourceResolver $resolver    Resolves the contract's sources to paths.
     * @param Scanner        $scanner     Extracts candidates (with per-file cache).
     * @param Compiler       $compiler    Runs the TailwindPHP engine.
     * @param string         $manifestDir Directory for `<theme>.json` manifests.
     *                                    Empty string disables persistence.
     * @param bool           $minify      Minify the compiled output.
     */
    public function __construct(
        private readonly ThemeConfig $themeConfig,
        private readonly SourceResolver $resolver,
        private readonly Scanner $scanner,
        private readonly Compiler $compiler,
        private readonly string $manifestDir = '',
        private readonly bool $minify = true,
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
     * Run the full build. Always returns a manifest; on failure it carries the
     * error message and no output file is written (or overwritten).
     *
     * @param ScanResult|null $scan Reuse an existing scan (from a watch poll)
     *                              instead of scanning again.
     */
    public function build(?ScanResult $scan = null): BuildManifest
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
            );
        }

        $manifestPath = $this->manifestPath();
        if ($manifestPath !== null) {
            $manifest->save($manifestPath);
        }

        return $manifest;
    }

    /**
     * Write the output atomically: the CSS lands in a tmp file next to the
     * target and is renamed into place, so a reader (or a failed write) never
     * sees a truncated stylesheet.
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
