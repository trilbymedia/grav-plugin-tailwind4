<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\BuildService;
use Grav\Plugin\Tailwind4\Compiler;
use Grav\Plugin\Tailwind4\Scanner;
use Grav\Plugin\Tailwind4\SourceResolver;
use Grav\Plugin\Tailwind4\ThemeConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Performance guard against a realistically large Grav site: a synthetic pages
 * tree of 500 markdown files carrying class-bearing frontmatter and content.
 *
 * Budgets (M-series dev machine, generous enough for CI):
 *   - cold compile (empty scan cache)  < 2000 ms
 *   - warm compile (full cache hit)    <  500 ms
 *   - compile peak memory              <   64 MB
 *
 * Tagged `slow` so it can be excluded (`--exclude-group slow`), but it stays in
 * the default run because the whole thing finishes in well under a second.
 */
#[Group('slow')]
final class LargeSiteTest extends TestCase
{
    private const PAGE_COUNT = 500;

    private string $root = '';
    private string $themeDir = '';
    private string $pagesDir = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tw4-large-' . uniqid('', true);
        $this->themeDir = $this->root . '/user/themes/fixture';
        $this->pagesDir = $this->root . '/user/pages';
        $this->cacheDir = $this->root . '/cache/tailwind4/scan';

        // Minimal but real theme input: an @import "tailwindcss" entry point.
        mkdir($this->themeDir . '/css', 0775, true);
        file_put_contents($this->themeDir . '/css/site.css', "@import \"tailwindcss\";\n");

        $this->generatePages(self::PAGE_COUNT);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            self::rmrf($this->root);
        }
    }

    public function testLargePagesTreeCompilesWithinBudget(): void
    {
        // Sanity: we really did generate the tree.
        $md = glob($this->pagesDir . '/*/*.md') ?: [];
        self::assertCount(self::PAGE_COUNT, $md);

        // Cold: empty scan cache, every file read + tokenized.
        $coldStart = microtime(true);
        $cold = $this->makeService()->build();
        $coldMs = (microtime(true) - $coldStart) * 1000.0;

        self::assertTrue($cold->success, 'Cold build should succeed: ' . ($cold->error ?? ''));
        self::assertSame(self::PAGE_COUNT, $cold->filesRead, 'Cold build reads every page');
        self::assertSame(0, $cold->cacheHits);
        self::assertGreaterThan(0, $cold->candidateCount);

        // Warm: same tree, scan cache fully populated, nothing re-read.
        $warmStart = microtime(true);
        $warm = $this->makeService()->build();
        $warmMs = (microtime(true) - $warmStart) * 1000.0;

        self::assertTrue($warm->success);
        self::assertSame(0, $warm->filesRead, 'Warm build must serve every page from cache');
        self::assertSame($cold->filesScanned, $warm->cacheHits);
        self::assertSame($cold->candidateCount, $warm->candidateCount);

        // Budgets.
        self::assertLessThan(
            2000.0,
            $coldMs,
            sprintf('Cold compile took %.0f ms (budget 2000 ms)', $coldMs),
        );
        self::assertLessThan(
            500.0,
            $warmMs,
            sprintf('Warm compile took %.0f ms (budget 500 ms)', $warmMs),
        );
        self::assertLessThan(
            64 * 1024 * 1024,
            $cold->peakMemoryBytes,
            sprintf('Compile peak was %.1f MB (budget 64 MB)', $cold->peakMemoryBytes / 1048576),
        );
    }

    // --- helpers -----------------------------------------------------------

    private function makeService(): BuildService
    {
        $themeConfig = ThemeConfig::fromArray('fixture', $this->themeDir, [
            'sources' => [$this->pagesDir],
        ]);

        return new BuildService(
            themeConfig: $themeConfig,
            resolver: new SourceResolver($this->themeDir, $this->root . '/user', []),
            scanner: new Scanner($this->cacheDir),
            compiler: new Compiler(),
            manifestDir: '',
            minify: true,
            lockDir: $this->cacheDir,
        );
    }

    /**
     * Generate a realistic pages tree: PAGE_COUNT markdown files spread across
     * folders, each with class-bearing YAML frontmatter and body content
     * (class attributes, Markdown attribute lists, Twig-ish class strings).
     */
    private function generatePages(int $count): void
    {
        // A pool of realistic Tailwind utilities so candidate counts are
        // meaningful without exploding (utilities repeat across pages, exactly
        // like a real site).
        $bg = ['bg-white', 'bg-gray-50', 'bg-primary/10', 'bg-slate-900', 'dark:bg-slate-800'];
        $text = ['text-gray-700', 'text-lg', 'text-center', 'dark:text-white', 'font-semibold'];
        $layout = ['flex', 'grid', 'gap-4', 'p-6', 'px-4', 'py-12', 'mx-auto', 'container', 'w-1/2'];
        $fancy = ['hover:underline', 'md:flex', 'xl:container', 'max-w-[42rem]', 'rounded-lg', 'shadow-md'];

        for ($i = 0; $i < $count; $i++) {
            $folder = $this->pagesDir . '/' . sprintf('%02d.section', intdiv($i, 25));
            if (!is_dir($folder)) {
                mkdir($folder, 0775, true);
            }

            $a = $bg[$i % count($bg)];
            $b = $text[$i % count($text)];
            $c = $layout[$i % count($layout)];
            $d = $fancy[$i % count($fancy)];
            $e = $layout[($i * 3) % count($layout)];

            $md = <<<MD
                ---
                title: Page {$i}
                body_classes: '{$c} {$e} {$a}'
                hero:
                    css_class: '{$b} {$d}'
                ---

                # Heading {$i}

                A paragraph with a [styled link](/x){.{$b} .{$d}} and a
                <span class="{$a} {$c}">badge</span> inline.

                <div class="{$c} {$e} {$d}">
                  <p class="{$b}">Body copy for page {$i}.</p>
                </div>

                {% set extra = '{$a} hover:{$c}' %}

                MD;

            file_put_contents($folder . '/page-' . $i . '.md', $md);
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
