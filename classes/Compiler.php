<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

use Throwable;

/**
 * Thin wrapper around the TailwindPHP engine.
 *
 * Responsibilities:
 *  - Load the engine lazily (only inside {@see compile()}), so the plugin's
 *    normal request path never pays for it. See the autoload note below.
 *  - Drive the engine through its low-level pipeline with an explicit candidate
 *    list, while still resolving nested relative `@import` chains the way
 *    `Tailwind::generate(['importPaths' => ...])` does.
 *  - Restore the `container` utility, which is missing from TailwindPHP.
 *  - Translate engine failures into a typed {@see CompileException}.
 *
 * Autoload note: the engine is registered via Composer's `files` autoload, which
 * eagerly includes ~68 files the instant `vendor/autoload.php` is required.
 * This class therefore defers that require to the compile path. Be aware that
 * the plugin class itself may already require `vendor/autoload.php` on
 * `onPluginsInitialized` (to map this namespace), which would defeat the
 * deferral; that is a plugin-wiring decision handled elsewhere. This class does
 * its part: it never references any `\TailwindPHP\*` symbol until compile time.
 */
final class Compiler
{
    /**
     * Restores Tailwind v4's `container` utility for stock engines that do not
     * emit it. The trilbymedia fork ships it natively (upstream PR #5), so this
     * is only injected when `container_fix` is enabled. Declarations mirror the
     * official Node build exactly (width: 100% plus one max-width per default
     * breakpoint; no margin/padding).
     *
     * @see https://github.com/inline0/tailwindphp/pull/5 (native container utility)
     */
    private const CONTAINER_FIX = <<<'CSS'

/* tailwind4 plugin: workaround for missing container utility in tailwindphp, see upstream issue */
@utility container {
  width: 100%;
  @media (width >= 40rem) {
    max-width: 40rem;
  }
  @media (width >= 48rem) {
    max-width: 48rem;
  }
  @media (width >= 64rem) {
    max-width: 64rem;
  }
  @media (width >= 80rem) {
    max-width: 80rem;
  }
  @media (width >= 96rem) {
    max-width: 96rem;
  }
}
CSS;

    /**
     * @param bool        $containerFix Inject the container-utility fallback for stock engines (default off; the trilbymedia fork ships it natively).
     * @param string|null $autoloadPath Path to the engine's Composer autoloader.
     *                                  Defaults to the plugin's own vendor/autoload.php.
     */
    public function __construct(
        private readonly bool $containerFix = false,
        private readonly ?string $autoloadPath = null,
    ) {
    }

    /**
     * Compile Tailwind CSS from an input file plus an explicit candidate list.
     *
     * @param string   $inputCssPath Absolute path to the theme's input CSS
     *                               (e.g. `.../css/site.css`). Its nested relative
     *                               `@import` chains are resolved.
     * @param string[] $candidates   Class-name candidates to generate utilities for.
     * @param bool     $minify       Minify the output.
     *
     * @throws CompileException When the input is unreadable or the engine fails.
     */
    public function compile(string $inputCssPath, array $candidates, bool $minify = true): CompileResult
    {
        $candidateCount = count($candidates);

        $this->loadEngine();

        if (!is_file($inputCssPath) || !is_readable($inputCssPath)) {
            throw CompileException::inputUnreadable($inputCssPath, $candidateCount);
        }

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $start = microtime(true);

        try {
            // Load the input CSS and collect the search paths for @import
            // resolution, exactly as generate(['importPaths' => ...]) does.
            $resolved = \TailwindPHP\resolveImportPaths($inputCssPath);
            $css = $resolved['css'];

            if ($this->containerFix) {
                $css .= "\n" . self::CONTAINER_FIX;
            }

            $options = [];
            if (!empty($resolved['paths'])) {
                $options['importSearchPaths'] = $resolved['paths'];
            }

            // Low-level pipeline: compile once, then build with our candidates.
            $compiled = \TailwindPHP\compile($css, $options);
            $output = $compiled['build']($candidates);

            if ($minify) {
                $output = \TailwindPHP\Minifier\CssMinifier::minify($output);
            }
        } catch (Throwable $e) {
            throw CompileException::engineFailed($inputCssPath, $candidateCount, $e);
        }

        $durationMs = (microtime(true) - $start) * 1000.0;
        $peakBytes = memory_get_peak_usage(true);

        return new CompileResult($output, $durationMs, $candidateCount, $peakBytes);
    }

    /**
     * Lazily require the engine's Composer autoloader.
     *
     * Idempotent: if the plugin has already registered it, the `require_once`
     * is a no-op. Kept out of the constructor so merely instantiating the
     * Compiler never triggers the engine's eager files-autoload.
     */
    private function loadEngine(): void
    {
        if (function_exists('TailwindPHP\\compile')) {
            return;
        }

        $autoloadPath = $this->autoloadPath ?? \dirname(__DIR__) . '/vendor/autoload.php';
        require_once $autoloadPath;
    }
}
