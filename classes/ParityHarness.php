<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

use RuntimeException;

/**
 * Parity checking against the official Node Tailwind CLI.
 *
 * Raw text comparison between the two builds is meaningless: unminified, the
 * Node CLI emits nested CSS (`&:where(...)`) while TailwindPHP emits flattened
 * selectors, and the two minifiers escape identifiers differently. So this
 * harness compares CLASS SELECTOR SETS instead: every class name appearing in
 * a selector is extracted (with CSS identifier escapes decoded), and the two
 * sets are diffed into missing/extra lists. Semantically identical output
 * yields an empty diff regardless of formatting.
 *
 * Used by both the CLI `--diff` flag and the PHPUnit parity test.
 */
final class ParityHarness
{
    /**
     * Matches a class selector token: a leading dot followed by an identifier
     * whose first character is a letter, dash, underscore or escape (a raw
     * digit start is always escaped in real CSS, which conveniently keeps
     * numeric literals like `.5rem` out of the set). Hex escapes may consume
     * one following whitespace character, per the CSS syntax spec.
     */
    private const CLASS_PATTERN =
        '/\.((?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[A-Za-z_-])(?:\\\\[0-9a-fA-F]{1,6}\s?|\\\\.|[A-Za-z0-9_-])*)/s';

    /**
     * Extract the sorted, deduped set of class names used in selectors.
     * Comments and string literals are stripped first so `content: ".fake"`
     * and data URIs cannot contribute phantom classes.
     *
     * @return array<int, string> Unescaped class names, e.g. "dark:hover:text-white".
     */
    public static function classSet(string $css): array
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', '', $css);
        $css = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '""', $css);
        $css = (string) preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", "''", $css);
        // Unquoted url() tokens (e.g. url(../fonts/Inter.var.woff2)) would
        // otherwise contribute phantom classes like "var" and "woff2".
        $css = (string) preg_replace('/\burl\([^)"\']*\)/i', 'url()', $css);

        preg_match_all(self::CLASS_PATTERN, $css, $matches);

        $set = [];
        foreach ($matches[1] as $raw) {
            $set[self::unescapeIdentifier($raw)] = true;
        }

        $names = array_keys($set);
        sort($names);

        return $names;
    }

    /**
     * Diff two stylesheets by class selector set.
     *
     * @return array{missing: array<int, string>, extra: array<int, string>}
     *         `missing` = classes in the reference but not in the actual output;
     *         `extra`   = classes in the actual output but not in the reference.
     */
    public static function diff(string $referenceCss, string $actualCss): array
    {
        $reference = self::classSet($referenceCss);
        $actual = self::classSet($actualCss);

        return [
            'missing' => array_values(array_diff($reference, $actual)),
            'extra' => array_values(array_diff($actual, $reference)),
        ];
    }

    /**
     * Whether the reference Node toolchain is usable for this theme.
     */
    public static function nodeAvailable(string $themeDir): bool
    {
        return is_dir($themeDir . '/node_modules');
    }

    /**
     * Produce the reference build with the official Node CLI, mirroring the
     * theme's npm workflow: regenerate css/_sources.css via the theme's
     * resolve-sources.js (when present), then run `npx @tailwindcss/cli`.
     *
     * The command runs through `cd <themeDir> && ...` so the shell keeps the
     * logical $PWD; resolve-sources.js depends on that to locate the Grav
     * user/ dir when the theme is symlinked. Pass the symlink path, not the
     * realpath, when building inside a Grav site.
     *
     * @param string $themeDir      Theme root (the site-side symlink path).
     * @param string $inputRelative Input CSS relative to the theme dir.
     * @param bool   $minify        Minify the Node output (recommended; also
     *                              flattens nesting via LightningCSS).
     *
     * @return string The reference CSS.
     *
     * @throws RuntimeException When node_modules is missing or a command fails.
     */
    public static function nodeBuild(
        string $themeDir,
        string $inputRelative = 'css/site.css',
        bool $minify = true,
    ): string {
        if (!self::nodeAvailable($themeDir)) {
            throw new RuntimeException(sprintf('No node_modules directory in "%s"', $themeDir));
        }

        if (is_file($themeDir . '/scripts/resolve-sources.js')) {
            self::run(sprintf('cd %s && node scripts/resolve-sources.js', escapeshellarg($themeDir)));
        }

        $outFile = tempnam(sys_get_temp_dir(), 'tw4-node-');
        if ($outFile === false) {
            throw new RuntimeException('Unable to create a temporary file for the Node build');
        }

        try {
            self::run(sprintf(
                'cd %s && npx @tailwindcss/cli -i %s -o %s%s',
                escapeshellarg($themeDir),
                escapeshellarg('./' . ltrim($inputRelative, '/')),
                escapeshellarg($outFile),
                $minify ? ' --minify' : '',
            ));

            $css = @file_get_contents($outFile);
            if ($css === false) {
                throw new RuntimeException('Node CLI produced no output file');
            }

            return $css;
        } finally {
            @unlink($outFile);
        }
    }

    /**
     * Decode CSS identifier escapes: `\HEX{1,6}` followed by an optional
     * whitespace character, or `\` followed by any literal character.
     */
    private static function unescapeIdentifier(string $identifier): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    $codepoint = (int) hexdec($m[1]);

                    return $codepoint > 0 ? (string) mb_chr($codepoint, 'UTF-8') : '';
                }

                return $m[2] ?? '';
            },
            $identifier,
        );
    }

    /**
     * Run a shell command, throwing with the captured output on failure.
     */
    private static function run(string $command): void
    {
        exec($command . ' 2>&1', $lines, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "Command failed (exit %d): %s\n%s",
                $exitCode,
                $command,
                implode("\n", $lines),
            ));
        }
    }
}
