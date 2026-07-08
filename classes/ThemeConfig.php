<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

use RuntimeException;

/**
 * The tailwind4 contract a theme declares in its own yaml (e.g. typhoon.yaml):
 *
 *   tailwind4:
 *     input: css/site.css
 *     output: build/css/site.css
 *     sources: [self://, user://pages, user://config, plugin-templates]
 *     safelist_files: [available-classes.md]
 *
 * Every key is optional. The defaults are chosen so an unmodified Typhoon (or
 * any theme following the same layout) works with no contract at all: input
 * css/site.css, output build/css/site.css, the default source set from
 * {@see SourceResolver}, and no extra safelist files.
 *
 * The class itself is Grav-free (plain values in, absolute paths out) so it can
 * be unit tested directly; {@see fromGrav()} is the only Grav-coupled entry
 * point and is exercised via the CLI in a live site.
 */
final class ThemeConfig
{
    public const DEFAULT_INPUT = 'css/site.css';
    public const DEFAULT_OUTPUT = 'build/css/site.css';

    /**
     * @param string                  $themeName     Theme slug, e.g. "typhoon".
     * @param string                  $themeDir      Absolute path to the theme root (no trailing slash).
     * @param string                  $input         Input CSS path relative to the theme dir.
     * @param string                  $output        Output CSS path relative to the theme dir.
     * @param array<int, string>|null $sources       The contract's `sources` list, or null to use
     *                                               the {@see SourceResolver} default set.
     * @param array<int, string>      $safelistFiles Extra files to scan, relative to the theme dir.
     */
    public function __construct(
        public readonly string $themeName,
        public readonly string $themeDir,
        public readonly string $input = self::DEFAULT_INPUT,
        public readonly string $output = self::DEFAULT_OUTPUT,
        public readonly ?array $sources = null,
        public readonly array $safelistFiles = [],
    ) {
    }

    /**
     * Build a config from a theme's parsed `tailwind4:` block. Anything missing
     * or malformed falls back to the defaults, so a theme with no contract (or
     * a partial one) still compiles.
     *
     * @param mixed $block The value of the theme yaml's `tailwind4` key.
     */
    public static function fromArray(string $themeName, string $themeDir, mixed $block): self
    {
        $block = \is_array($block) ? $block : [];

        return new self(
            themeName: $themeName,
            themeDir: rtrim($themeDir, '/'),
            input: self::relativePath($block['input'] ?? null, self::DEFAULT_INPUT),
            output: self::relativePath($block['output'] ?? null, self::DEFAULT_OUTPUT),
            sources: self::stringList($block['sources'] ?? null),
            safelistFiles: self::stringList($block['safelist_files'] ?? null) ?? [],
        );
    }

    /**
     * Resolve a theme's contract from a booted Grav instance.
     *
     * Lookup order: the merged runtime config (`themes.<name>.tailwind4`, which
     * covers the active theme plus any user override), then the theme's own
     * `<name>.yaml` parsed directly (covers a non-active theme with no user
     * override). Grav is referenced only inside this method so the class stays
     * usable without it.
     *
     * @throws RuntimeException When the theme directory cannot be located.
     */
    public static function fromGrav(string $themeName): self
    {
        $grav = \Grav\Common\Grav::instance();

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $grav['locator'];
        $themeDir = $locator->findResource('themes://' . $themeName, true);
        if (!\is_string($themeDir) || $themeDir === '' || !is_dir($themeDir)) {
            throw new RuntimeException(sprintf('Theme "%s" was not found', $themeName));
        }

        $block = $grav['config']->get('themes.' . $themeName . '.tailwind4');

        if (!\is_array($block)) {
            $block = self::blockFromThemeYaml($themeDir . '/' . $themeName . '.yaml');
        }

        return self::fromArray($themeName, $themeDir, $block);
    }

    /**
     * Absolute path to the input CSS file.
     */
    public function inputPath(): string
    {
        return $this->themeDir . '/' . $this->input;
    }

    /**
     * Absolute path to the compiled output file.
     */
    public function outputPath(): string
    {
        return $this->themeDir . '/' . $this->output;
    }

    /**
     * Absolute path to the top-level directory the output lives in (the first
     * segment of the output path, e.g. `<theme>/build`). The scanner must never
     * descend into it.
     */
    public function outputRootDir(): string
    {
        $first = explode('/', $this->output, 2)[0];

        return $this->themeDir . '/' . $first;
    }

    /**
     * Read the `tailwind4` block straight from a theme yaml file. Uses Symfony
     * Yaml when available (always true inside Grav); returns null otherwise or
     * on any parse error, which means "no contract, use defaults".
     */
    private static function blockFromThemeYaml(string $yamlFile): ?array
    {
        if (!is_file($yamlFile) || !class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return null;
        }

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parseFile($yamlFile);
        } catch (\Throwable) {
            return null;
        }

        $block = \is_array($parsed) ? ($parsed['tailwind4'] ?? null) : null;

        return \is_array($block) ? $block : null;
    }

    /**
     * Normalize a theme-relative path value; falls back when empty or not a
     * string. Leading slashes are stripped so the value stays theme-relative.
     */
    private static function relativePath(mixed $value, string $default): string
    {
        if (!\is_string($value)) {
            return $default;
        }

        $value = trim($value, "/ \t\n\r");

        return $value !== '' ? $value : $default;
    }

    /**
     * Coerce a yaml value into a clean list of non-empty strings, or null when
     * the value is missing/unusable (null means "use defaults" for `sources`).
     *
     * @return array<int, string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list === [] ? null : $list;
    }
}
