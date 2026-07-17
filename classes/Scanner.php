<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

/**
 * Oxide-style candidate extractor with a per-file mtime/size cache.
 *
 * TailwindPHP's own directory scanning does not exist in library mode and its
 * two built-in extractors are inadequate for Grav content: they only read
 * class="..." attributes (breaking on nested quotes) and never touch Markdown
 * attribute lists or tables (see PLAN.md, verified facts 2 and 4). This scanner
 * therefore does all extraction itself.
 *
 * The guiding principle is the same one Tailwind's Rust scanner (Oxide) uses:
 * extract every run of characters that could plausibly be a utility candidate,
 * dedupe, and let the compiler discard whatever is invalid. Over-extraction
 * costs only milliseconds; under-extraction silently drops utilities from the
 * final CSS, so we always err toward extracting more.
 */
final class Scanner
{
    /**
     * Directory names we never descend into, regardless of caller config.
     *
     * @var array<int, string>
     */
    private const ALWAYS_EXCLUDE = ['vendor', 'node_modules', '.git'];

    /**
     * Default file extensions worth tokenizing for Grav themes.
     *
     * @var array<int, string>
     */
    private const DEFAULT_EXTENSIONS = ['twig', 'md', 'yaml', 'yml', 'php', 'html', 'htm'];

    /** @var array<int, string> lowercased extensions without the leading dot */
    private array $extensions;

    /** @var array<int, string> absolute realpaths of directories to skip entirely */
    private array $excludeDirs;

    /**
     * @param string             $cacheDir    Directory for per-file scan caches. May be
     *                                        the empty string to disable caching (each
     *                                        file is always read + tokenized).
     * @param array<int, string> $extensions  File extensions to scan (no leading dot).
     * @param array<int, string> $excludeDirs Extra directories to skip. Typically the
     *                                        theme's build-output dir. Absolute paths.
     */
    public function __construct(
        private readonly string $cacheDir = '',
        array $extensions = self::DEFAULT_EXTENSIONS,
        array $excludeDirs = [],
    ) {
        $this->extensions = array_map(
            static fn (string $ext): string => strtolower(ltrim($ext, '.')),
            $extensions,
        );

        $normalized = [];
        foreach ($excludeDirs as $dir) {
            $real = realpath($dir);
            $normalized[] = $real !== false ? $real : rtrim($dir, '/');
        }
        $this->excludeDirs = $normalized;

        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Scan a mix of files and directories, returning the deduped candidate set
     * plus cache stats.
     *
     * @param array<int, string> $sources Absolute file or directory paths.
     */
    public function scan(array $sources): ScanResult
    {
        $files = $this->collectFiles($sources);

        $tokens = [];
        $filesScanned = 0;
        $cacheHits = 0;
        $filesRead = 0;

        foreach ($files as $file) {
            ++$filesScanned;

            $cached = $this->readCache($file);
            if ($cached !== null) {
                ++$cacheHits;
                foreach ($cached as $token) {
                    $tokens[$token] = true;
                }
                continue;
            }

            ++$filesRead;
            $content = @file_get_contents($file);
            if ($content === false) {
                // Unreadable file: record an empty cache entry so a retry is
                // still a cache hit, and move on. A missing source must never
                // abort the whole scan.
                $this->writeCache($file, []);
                continue;
            }

            $fileTokens = self::tokenize($content);
            $this->writeCache($file, $fileTokens);
            foreach ($fileTokens as $token) {
                $tokens[$token] = true;
            }
        }

        $list = array_keys($tokens);
        sort($list);

        return new ScanResult($list, $filesScanned, $cacheHits, $filesRead);
    }

    /**
     * Extract candidate tokens from a single string of content.
     *
     * Public and static so tests (and other callers) can tokenize raw strings
     * directly without touching the filesystem or cache.
     *
     * @return array<int, string> Deduped tokens (insertion order preserved).
     */
    public static function tokenize(string $content): array
    {
        $tokens = [];
        self::extractInto($content, $tokens);

        return array_keys($tokens);
    }

    /**
     * Recursive worker for {@see tokenize()}. Walks the string, emitting every
     * maximal run of candidate characters. Bracketed arbitrary values
     * (`p-[3.5rem]`, `max-w-(--breakpoint-xl)`) are kept whole via balanced
     * matching, and the interior of any bracket group is re-scanned so that
     * nested quoted tokens — e.g. flex and gap-2 inside
     * class="{{ ['flex','gap-2']|join(' ') }}" — are also captured.
     *
     * @param array<string, true> $tokens Accumulator keyed by token for dedupe.
     */
    private static function extractInto(string $content, array &$tokens): void
    {
        $len = \strlen($content);
        $i = 0;

        while ($i < $len) {
            $ch = $content[$i];

            if (!self::isStartChar($ch)) {
                ++$i;
                continue;
            }

            $start = $i;
            $hadBracket = false;

            while ($i < $len) {
                $c = $content[$i];

                if ($c === '[' || $c === '(') {
                    $hadBracket = true;
                    $i = self::skipBalanced($content, $i);
                    continue;
                }

                if (self::isCandidateChar($c)) {
                    ++$i;
                    continue;
                }

                break;
            }

            $raw = substr($content, $start, $i - $start);
            $token = self::normalizeToken($raw);

            if ($token !== '' && self::isPlausible($token) && self::isEmittable($token)) {
                $tokens[$token] = true;
            }

            // Re-scan the interior of any bracket group for nested tokens that a
            // single pass would swallow (Twig arrays, join() arguments, etc.).
            if ($hadBracket) {
                self::extractBracketInteriors($raw, $tokens);
            }
        }
    }

    /**
     * Find every top-level bracket/paren group in a token and re-scan its
     * interior for additional tokens.
     *
     * @param array<string, true> $tokens
     */
    private static function extractBracketInteriors(string $raw, array &$tokens): void
    {
        $len = \strlen($raw);
        $i = 0;

        while ($i < $len) {
            $c = $raw[$i];
            if ($c === '[' || $c === '(') {
                $end = self::skipBalanced($raw, $i);
                // Interior excludes the outer brackets themselves.
                $interior = substr($raw, $i + 1, $end - $i - 2);
                if ($interior !== '') {
                    self::extractInto($interior, $tokens);
                }
                $i = $end;
                continue;
            }
            ++$i;
        }
    }

    /**
     * Given an opening bracket at $i, return the index just past its matching
     * close, counting nested [] and () together. Runs to end-of-string if the
     * input is unbalanced.
     */
    private static function skipBalanced(string $content, int $i): int
    {
        $len = \strlen($content);
        $depth = 0;

        while ($i < $len) {
            $c = $content[$i];
            if ($c === '[' || $c === '(') {
                ++$depth;
            } elseif ($c === ']' || $c === ')') {
                --$depth;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            ++$i;
        }

        return $len;
    }

    /**
     * Characters that may begin a candidate. A leading dot is deliberately not
     * a start char, so Markdown `.text-center` and table `.bg-blue-100` tokens
     * yield the dot-stripped class name automatically.
     */
    private static function isStartChar(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z')
            || ($c >= 'A' && $c <= 'Z')
            || ($c >= '0' && $c <= '9')
            || $c === '-'   // negative utilities, e.g. -mt-4
            || $c === '@'   // container-query variants, e.g. @md:flex
            || $c === '!'   // important (leading form)
            || $c === '[';  // bare arbitrary properties, e.g. [mask-type:alpha]
    }

    /**
     * Characters allowed to continue a candidate outside of brackets. Brackets
     * and parens are handled separately by balanced matching.
     */
    private static function isCandidateChar(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z')
            || ($c >= 'A' && $c <= 'Z')
            || ($c >= '0' && $c <= '9')
            || $c === '-'
            || $c === '_'
            || $c === ':'
            || $c === '/'
            || $c === '.'
            || $c === '%'
            || $c === '!'
            || $c === '@'
            || $c === '*'
            || $c === '#'
            || $c === '&';
    }

    /**
     * Trim punctuation that can cling to a token but never validly terminates a
     * Tailwind candidate (`color:` from YAML, trailing commas, sentence dots).
     * Trailing `!` is preserved because it is the v4 important marker.
     */
    private static function normalizeToken(string $token): string
    {
        return rtrim($token, ":;,.&");
    }

    /**
     * Cheap plausibility gate on the final token: it must contain at least one
     * ASCII letter (drops bare numbers, hex fragments, punctuation runs). This
     * never rejects a real utility, since every Tailwind candidate contains a
     * letter, and keeps the candidate list and cache from bloating with noise.
     */
    private static function isPlausible(string $token): bool
    {
        return (bool) preg_match('/[A-Za-z]/', $token);
    }

    /**
     * Reject captures that can never be a real Tailwind candidate but would
     * still be emitted as malformed CSS if handed to the engine.
     *
     * The scanner treats `[` as the start of an arbitrary-property candidate
     * (`[mask-type:alpha]`), but Grav and Markdown shortcodes use the very same
     * brackets — e.g. `[figure caption="Source: Archives Service"]`. Because the
     * balanced-bracket capture swallows the whole shortcode, it reaches the
     * compiler as a bare arbitrary property and is emitted as a broken rule
     * (`.\[figure…\] { figure caption="Source: Archives Service" }`). The browser
     * CSS parser aborts on that declaration ("Expected declaration…") and its
     * error recovery skips following rules too, silently dropping real utilities.
     *
     * A genuine arbitrary property or value never contains whitespace (Tailwind
     * escapes spaces as `_`), an `=`, or a double quote, so any capture carrying
     * one of those is a shortcode false positive and is dropped here. Prefixed
     * arbitrary values that legitimately use single quotes (`content-['*']`) are
     * untouched, since this only rejects whitespace, `=`, and double quotes.
     */
    private static function isEmittable(string $token): bool
    {
        return !preg_match('/[\s="]/', $token);
    }

    /**
     * Expand the source list into a flat, deduped list of files to scan.
     *
     * @param array<int, string> $sources
     * @return array<int, string>
     */
    private function collectFiles(array $sources): array
    {
        $files = [];

        foreach ($sources as $source) {
            if (is_file($source)) {
                if ($this->hasScannableExtension($source) && !$this->isExcluded($source)) {
                    $files[$source] = true;
                }
                continue;
            }

            if (is_dir($source)) {
                foreach ($this->walkDir($source) as $file) {
                    $files[$file] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Recursively yield scannable files under a directory, skipping excluded
     * directories.
     *
     * An unreadable subdirectory (e.g. a locked-down folder deep in user/pages)
     * is pruned by the filter callback before recursion is attempted, so one bad
     * branch never aborts the whole scan. An unreadable top-level source dir
     * still throws from the RecursiveDirectoryIterator constructor below, which
     * the build treats as a hard error. (CATCH_GET_CHILD is deliberately not
     * used: it collapses traversal when combined with a callback filter.)
     *
     * @return \Generator<int, string>
     */
    private function walkDir(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        $pathname = $current->getPathname();

                        return is_readable($pathname)
                            && !$this->isExcludedDir($current->getFilename(), $pathname);
                    }

                    return true;
                },
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if ($this->hasScannableExtension($path)) {
                yield $path;
            }
        }
    }

    private function hasScannableExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && \in_array($ext, $this->extensions, true);
    }

    private function isExcludedDir(string $name, string $pathname): bool
    {
        if (\in_array($name, self::ALWAYS_EXCLUDE, true)) {
            return true;
        }

        $real = realpath($pathname);
        $real = $real !== false ? $real : rtrim($pathname, '/');

        return \in_array($real, $this->excludeDirs, true);
    }

    private function isExcluded(string $path): bool
    {
        $real = realpath($path);
        $real = $real !== false ? $real : $path;

        foreach ($this->excludeDirs as $dir) {
            if (str_starts_with($real, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return cached tokens for a file if the cache entry is fresh (matching
     * mtime and size), otherwise null.
     *
     * @return array<int, string>|null
     */
    private function readCache(string $file): ?array
    {
        if ($this->cacheDir === '') {
            return null;
        }

        $cacheFile = $this->cachePath($file);
        if (!is_file($cacheFile)) {
            return null;
        }

        $json = @file_get_contents($cacheFile);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!\is_array($data) || !isset($data['mtime'], $data['size'], $data['tokens'])) {
            return null;
        }

        if ((int) $data['mtime'] !== (int) @filemtime($file)
            || (int) $data['size'] !== (int) @filesize($file)) {
            return null;
        }

        return \is_array($data['tokens']) ? array_values($data['tokens']) : [];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function writeCache(string $file, array $tokens): void
    {
        if ($this->cacheDir === '') {
            return;
        }

        $payload = [
            'mtime' => (int) @filemtime($file),
            'size' => (int) @filesize($file),
            'tokens' => $tokens,
        ];

        $cacheFile = $this->cachePath($file);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        // Atomic-ish write: tmp file + rename so a concurrent reader never sees
        // a half-written cache entry.
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $cacheFile);
        }
    }

    private function cachePath(string $file): string
    {
        $real = realpath($file);
        $key = $real !== false ? $real : $file;

        return $this->cacheDir . '/' . md5($key) . '.json';
    }
}
