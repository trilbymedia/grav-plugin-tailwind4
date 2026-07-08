<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

/**
 * Resolves a theme's tailwind4 `sources` contract into a flat list of absolute
 * files and directories for the {@see Scanner} to walk.
 *
 * This class is deliberately Grav-free so it can be unit tested without a booted
 * Grav instance: the constructor takes explicit paths (theme dir, user dir) and
 * either a list of plugin-template dirs or a callable that yields them lazily. A
 * thin static factory that builds those inputs from Grav streams
 * (`self://`, `user://`, enabled-plugin discovery) belongs in the build service.
 *
 * TODO(WP3): add SourceResolver::fromGrav() that reads Grav streams and the
 * enabled-plugins list, then delegates to this constructor. Keep the Grav
 * dependency there, never here.
 *
 * The default source set (used when the theme yaml declares no
 * `tailwind4.sources`) mirrors Typhoon's scripts/resolve-sources.js:
 *   - the theme's own templates/ dir
 *   - the theme root's Markdown safelist files (e.g. available-classes.md)
 *   - user://pages
 *   - user://config
 *   - every enabled plugin's templates/ dir
 */
final class SourceResolver
{
    /**
     * Magic tokens understood in a theme's `sources` list. Anything else is
     * treated as a path relative to the theme dir (or absolute as-is).
     */
    private const TOKEN_SELF_TEMPLATES = 'self://templates';
    private const TOKEN_USER_PAGES = 'user://pages';
    private const TOKEN_USER_CONFIG = 'user://config';
    private const TOKEN_PLUGIN_TEMPLATES = 'plugin-templates';

    /** @var array<int, string>|(callable(): array<int, string>) */
    private $pluginTemplateDirs;

    /**
     * @param string                                             $themeDir           Absolute path to the theme root.
     * @param string                                             $userDir            Absolute path to Grav's user/ dir.
     * @param array<int, string>|(callable(): array<int,string>) $pluginTemplateDirs List of absolute plugin
     *                                                                               templates/ dirs, or a callable
     *                                                                               that returns such a list.
     */
    public function __construct(
        private readonly string $themeDir,
        private readonly string $userDir,
        array|callable $pluginTemplateDirs = [],
    ) {
        $this->pluginTemplateDirs = $pluginTemplateDirs;
    }

    /**
     * Resolve a source list into absolute, existing, deduped paths.
     *
     * @param array<int, string>|null $sources       The theme contract's `sources`
     *                                                list. Null or empty means use
     *                                                the default set.
     * @param array<int, string>      $safelistFiles Extra files (relative to the
     *                                                theme dir, or absolute) to scan,
     *                                                e.g. ['available-classes.md'].
     * @return array<int, string>
     */
    public function resolve(?array $sources = null, array $safelistFiles = []): array
    {
        $resolved = [];

        if ($sources === null || $sources === []) {
            foreach ($this->defaultSources() as $path) {
                $resolved[$path] = true;
            }
        } else {
            foreach ($sources as $source) {
                foreach ($this->resolveToken($source) as $path) {
                    $resolved[$path] = true;
                }
            }
        }

        foreach ($safelistFiles as $file) {
            foreach ($this->resolveSafelistFile($file) as $path) {
                $resolved[$path] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * The default source set when the theme declares no `sources`.
     *
     * @return array<int, string>
     */
    private function defaultSources(): array
    {
        $paths = [];

        $templates = $this->themeDir . '/templates';
        if (is_dir($templates)) {
            $paths[] = $templates;
        }

        foreach ($this->themeRootMarkdown() as $md) {
            $paths[] = $md;
        }

        $pages = $this->userDir . '/pages';
        if (is_dir($pages)) {
            $paths[] = $pages;
        }

        $config = $this->userDir . '/config';
        if (is_dir($config)) {
            $paths[] = $config;
        }

        foreach ($this->resolvePluginTemplates() as $dir) {
            $paths[] = $dir;
        }

        return $paths;
    }

    /**
     * Resolve one entry from a theme's `sources` list.
     *
     * @return array<int, string>
     */
    private function resolveToken(string $token): array
    {
        $token = trim($token);

        return match ($token) {
            self::TOKEN_SELF_TEMPLATES => $this->existingDir($this->themeDir . '/templates'),
            self::TOKEN_USER_PAGES => $this->existingDir($this->userDir . '/pages'),
            self::TOKEN_USER_CONFIG => $this->existingDir($this->userDir . '/config'),
            self::TOKEN_PLUGIN_TEMPLATES => $this->resolvePluginTemplates(),
            default => $this->resolvePathToken($token),
        };
    }

    /**
     * Resolve a non-magic source entry. `self://x` and `user://x` prefixes map
     * to the theme dir and user dir respectively; a bare relative path is taken
     * relative to the theme dir; an absolute path is used verbatim.
     *
     * @return array<int, string>
     */
    private function resolvePathToken(string $token): array
    {
        if (str_starts_with($token, 'self://')) {
            $path = $this->themeDir . '/' . ltrim(substr($token, 7), '/');
        } elseif (str_starts_with($token, 'user://')) {
            $path = $this->userDir . '/' . ltrim(substr($token, 7), '/');
        } elseif (str_starts_with($token, '/')) {
            $path = $token;
        } else {
            $path = $this->themeDir . '/' . $token;
        }

        if (is_dir($path) || is_file($path)) {
            return [$path];
        }

        return [];
    }

    /**
     * Resolve a safelist file entry to an absolute existing path.
     *
     * @return array<int, string>
     */
    private function resolveSafelistFile(string $file): array
    {
        $file = trim($file);
        if ($file === '') {
            return [];
        }

        $path = str_starts_with($file, '/') ? $file : $this->themeDir . '/' . $file;

        return is_file($path) ? [$path] : [];
    }

    /**
     * Markdown files sitting in the theme root (Typhoon keeps its class safelist
     * documentation there as available-classes.md).
     *
     * @return array<int, string>
     */
    private function themeRootMarkdown(): array
    {
        $matches = glob($this->themeDir . '/*.md');

        return $matches !== false ? $matches : [];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePluginTemplates(): array
    {
        $dirs = \is_callable($this->pluginTemplateDirs)
            ? ($this->pluginTemplateDirs)()
            : $this->pluginTemplateDirs;

        $out = [];
        foreach ((array) $dirs as $dir) {
            if (\is_string($dir) && is_dir($dir)) {
                $out[] = $dir;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function existingDir(string $path): array
    {
        return is_dir($path) ? [$path] : [];
    }
}
