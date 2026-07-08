<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\Scanner;
use Grav\Plugin\Tailwind4\ScanResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase
{
    private string $cacheDir = '';

    /** @var array<int, string> */
    private array $cleanupTrees = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/tw4-scan-' . uniqid('', true);
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            self::rmrf($this->cacheDir);
        }
        foreach ($this->cleanupTrees as $tree) {
            self::rmrf($tree);
        }
        $this->cleanupTrees = [];
    }

    /**
     * Every extraction case the PLAN calls out as one a naive approach misses.
     * Each row: raw content, then the tokens that MUST be present.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function extractionCases(): array
    {
        return [
            'double-quoted class list' => ['<div class="flex gap-2">', ['flex', 'gap-2']],
            'single-quoted class list' => ["<div class='flex gap-2'>", ['flex', 'gap-2']],
            'static classes around a Twig expression' => [
                '<div class="mb-4 {{ dynamic }} text-lg">',
                ['mb-4', 'text-lg'],
            ],
            'Twig set tag with quoted classes' => [
                "{% set x = 'bg-blue-500 hover:bg-blue-700' %}",
                ['bg-blue-500', 'hover:bg-blue-700'],
            ],
            'nested quotes inside a Twig array + join filter' => [
                'class="{{ [\'flex\',\'gap-2\']|join(\' \') }}"',
                ['flex', 'gap-2'],
            ],
            'Markdown attribute list' => ['# Heading {.text-center .lead}', ['text-center', 'lead']],
            'Markdown table row with leading-dot class' => [
                '| .bg-blue-100 | background-color: #ebf8ff; |',
                ['bg-blue-100'],
            ],
            'YAML scalar css_class' => ["css_class: 'py-12'", ['py-12']],
            'YAML scalar with multiple classes' => [
                'hero_classes: "bg-blue-500 md:grid-cols-2"',
                ['bg-blue-500', 'md:grid-cols-2'],
            ],
            'arbitrary bracket value' => ['<div class="p-[3.5rem]">', ['p-[3.5rem]']],
            'CSS-variable shorthand value' => [
                '<div class="max-w-(--breakpoint-xl)">',
                ['max-w-(--breakpoint-xl)'],
            ],
            'important suffix' => ['<div class="px-4!">', ['px-4!']],
            'stacked variants' => ['<div class="dark:hover:text-white">', ['dark:hover:text-white']],
            'fraction utility' => ['<div class="w-1/2">', ['w-1/2']],
            'opacity modifier on a color' => ['<div class="bg-primary/10">', ['bg-primary/10']],
            'Alpine :class with mixed tokens' => [
                ':class="active ? \'bg-primary/10 text-primary\' : \'hover:text-primary\'"',
                ['bg-primary/10', 'text-primary', 'hover:text-primary'],
            ],
        ];
    }

    /**
     * @param array<int, string> $expected
     */
    #[DataProvider('extractionCases')]
    public function testTokenizeFindsRequiredClasses(string $content, array $expected): void
    {
        $tokens = Scanner::tokenize($content);

        foreach ($expected as $class) {
            self::assertContains(
                $class,
                $tokens,
                sprintf('Expected token "%s" not extracted from: %s', $class, $content),
            );
        }
    }

    public function testTokenizeDedupesRepeatedTokens(): void
    {
        $tokens = Scanner::tokenize('class="flex flex flex gap-2"');

        self::assertSame(1, self::tokenOccurrences('flex', $tokens));
        self::assertContains('gap-2', $tokens);
    }

    public function testScanReturnsScanResultWithStats(): void
    {
        $dir = $this->makeTree([
            'a.twig' => '<div class="flex p-4">',
            'b.md' => '# Title {.text-center}',
        ]);

        $scanner = new Scanner($this->cacheDir);
        $result = $scanner->scan([$dir]);

        self::assertInstanceOf(ScanResult::class, $result);
        self::assertSame(2, $result->filesScanned);
        self::assertSame(2, $result->filesRead);
        self::assertSame(0, $result->cacheHits);
        self::assertContains('flex', $result->tokens);
        self::assertContains('p-4', $result->tokens);
        self::assertContains('text-center', $result->tokens);
        self::assertSame($result->tokenCount(), \count($result->tokens));
    }

    public function testWarmScanUsesCacheAndSkipsReads(): void
    {
        $dir = $this->makeTree(['a.twig' => '<div class="flex p-4">']);
        $scanner = new Scanner($this->cacheDir);

        $cold = $scanner->scan([$dir]);
        self::assertSame(1, $cold->filesRead);
        self::assertSame(0, $cold->cacheHits);

        $warm = $scanner->scan([$dir]);
        self::assertSame(0, $warm->filesRead, 'Warm scan must not re-read unchanged files');
        self::assertSame(1, $warm->cacheHits);
        self::assertSame($cold->tokens, $warm->tokens, 'Cache must preserve the token set');
    }

    public function testCacheInvalidatesWhenFileChanges(): void
    {
        $dir = $this->makeTree(['a.twig' => '<div class="flex">']);
        $file = $dir . '/a.twig';
        $scanner = new Scanner($this->cacheDir);

        $scanner->scan([$dir]);

        // Change content and bump mtime so the mtime/size guard fires.
        file_put_contents($file, '<div class="grid gap-8">');
        touch($file, time() + 10);

        $result = $scanner->scan([$dir]);
        self::assertSame(1, $result->filesRead, 'Changed file must be re-read');
        self::assertContains('grid', $result->tokens);
        self::assertContains('gap-8', $result->tokens);
        self::assertNotContains('flex', $result->tokens);
    }

    public function testWarmScanIsAtLeastTenTimesFasterThanCold(): void
    {
        // A generated tree big enough that read+tokenize dominates, so the
        // timing ratio is not fragile on tiny inputs.
        $files = [];
        for ($i = 0; $i < 300; $i++) {
            $files["file_$i.twig"] =
                '<div class="flex items-center gap-4 p-6 md:grid-cols-2 '
                . "bg-blue-$i text-gray-700 hover:bg-blue-900 dark:text-white\">"
                . str_repeat('<span class="mx-1 my-2 rounded-lg">x</span>', 40);
        }
        $dir = $this->makeTree($files);
        $scanner = new Scanner($this->cacheDir);

        $t0 = hrtime(true);
        $cold = $scanner->scan([$dir]);
        $coldNs = hrtime(true) - $t0;

        $t1 = hrtime(true);
        $warm = $scanner->scan([$dir]);
        $warmNs = hrtime(true) - $t1;

        self::assertSame(300, $cold->filesRead);
        self::assertSame(300, $warm->cacheHits);
        self::assertSame(0, $warm->filesRead);
        self::assertGreaterThan(
            0,
            $warmNs,
            'Warm scan should take measurable time',
        );
        self::assertGreaterThan(
            10.0,
            $coldNs / $warmNs,
            sprintf('Warm scan should be >=10x faster (cold %d ns, warm %d ns)', $coldNs, $warmNs),
        );
    }

    public function testScanExcludesVendorAndNodeModules(): void
    {
        $dir = $this->makeTree([
            'app.twig' => '<div class="keep-me">',
            'vendor/lib.twig' => '<div class="vendor-junk">',
            'node_modules/pkg.twig' => '<div class="node-junk">',
        ]);

        $result = (new Scanner($this->cacheDir))->scan([$dir]);

        self::assertContains('keep-me', $result->tokens);
        self::assertNotContains('vendor-junk', $result->tokens);
        self::assertNotContains('node-junk', $result->tokens);
    }

    public function testScanHonoursConfiguredExcludeDir(): void
    {
        $dir = $this->makeTree([
            'src.twig' => '<div class="keep-me">',
            'build/out.twig' => '<div class="build-junk">',
        ]);

        $scanner = new Scanner($this->cacheDir, ['twig', 'md'], [$dir . '/build']);
        $result = $scanner->scan([$dir]);

        self::assertContains('keep-me', $result->tokens);
        self::assertNotContains('build-junk', $result->tokens);
    }

    public function testScanOnlyReadsConfiguredExtensions(): void
    {
        $dir = $this->makeTree([
            'a.twig' => '<div class="twig-class">',
            'b.txt' => '<div class="txt-class">',
        ]);

        $result = (new Scanner($this->cacheDir, ['twig']))->scan([$dir]);

        self::assertContains('twig-class', $result->tokens);
        self::assertNotContains('txt-class', $result->tokens);
    }

    public function testScanAcceptsIndividualFiles(): void
    {
        $dir = $this->makeTree(['classes.md' => '| .bg-blue-100 | x | text-red-500']);
        $result = (new Scanner($this->cacheDir))->scan([$dir . '/classes.md']);

        self::assertSame(1, $result->filesScanned);
        self::assertContains('bg-blue-100', $result->tokens);
        self::assertContains('text-red-500', $result->tokens);
    }

    // --- Functional tests against Typhoon's real theme (read-only) ---------

    public function testScansRealTyphoonTemplatesIncludingMissedClass(): void
    {
        $templates = '/Users/rhuk/Projects/grav/grav-theme-typhoon/templates';
        if (!is_dir($templates)) {
            self::markTestSkipped('Typhoon theme not available at ' . $templates);
        }

        $result = (new Scanner($this->cacheDir))->scan([$templates]);

        self::assertGreaterThan(0, $result->filesScanned);
        // bg-primary/10 lives inside an Alpine :class with nested quotes in
        // templates/partials/scrollspy-navigation.html.twig — the exact class
        // the naive class="..." regex dropped during evaluation.
        self::assertContains(
            'bg-primary/10',
            $result->tokens,
            'The nested-quote opacity class from scrollspy-navigation must be found',
        );
    }

    public function testScansTyphoonAvailableClassesMarkdownTable(): void
    {
        $file = '/Users/rhuk/Projects/grav/grav-theme-typhoon/available-classes.md';
        if (!is_file($file)) {
            self::markTestSkipped('Typhoon available-classes.md not available at ' . $file);
        }

        $result = (new Scanner($this->cacheDir))->scan([$file]);

        self::assertContains('bg-blue-100', $result->tokens);
        self::assertContains('text-red-500', $result->tokens);
    }

    // --- Comparison baseline: TailwindPHP's own extractors -----------------

    public function testOurTokenizerCatchesWhatTheEngineExtractorMisses(): void
    {
        // The engine's class-attr extractor loses everything on nested quotes;
        // ours must not. This documents WHY the scanner exists (PLAN fact 4).
        $content = 'class="{{ [\'flex\',\'gap-2\']|join(\' \') }}"';

        $ours = Scanner::tokenize($content);
        self::assertContains('flex', $ours);
        self::assertContains('gap-2', $ours);

        if (function_exists('TailwindPHP\\extractCandidates')) {
            $engine = \TailwindPHP\extractCandidates($content);
            self::assertNotContains(
                'flex',
                $engine,
                'Baseline: the engine regex is expected to miss nested-quote classes',
            );
        }
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, string> $files Relative path => content.
     */
    private function makeTree(array $files): string
    {
        $root = sys_get_temp_dir() . '/tw4-tree-' . uniqid('', true);
        foreach ($files as $rel => $content) {
            $path = $root . '/' . $rel;
            $parent = \dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            file_put_contents($path, $content);
        }

        $this->cleanupTrees[] = $root;

        return $root;
    }

    private static function tokenOccurrences(string $needle, array $haystack): int
    {
        return \count(array_filter($haystack, static fn ($t): bool => $t === $needle));
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
