<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\SourceResolver;
use PHPUnit\Framework\TestCase;

final class SourceResolverTest extends TestCase
{
    private string $root = '';
    private string $themeDir = '';
    private string $userDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tw4-src-' . uniqid('', true);
        $this->themeDir = $this->root . '/user/themes/typhoon';
        $this->userDir = $this->root . '/user';

        // Minimal Grav-style directory tree.
        $this->mkdirs([
            $this->themeDir . '/templates/partials',
            $this->themeDir . '/build/css',
            $this->userDir . '/pages',
            $this->userDir . '/config',
            $this->userDir . '/plugins/one/templates',
            $this->userDir . '/plugins/two/templates',
            $this->userDir . '/plugins/three', // no templates dir
        ]);
        file_put_contents($this->themeDir . '/available-classes.md', '| .bg-blue-100 |');
        file_put_contents($this->themeDir . '/README.md', '# readme');
        file_put_contents($this->themeDir . '/templates/base.html.twig', '<html>');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            self::rmrf($this->root);
        }
    }

    public function testDefaultSourcesMirrorResolveSourcesLogic(): void
    {
        $resolver = new SourceResolver(
            $this->themeDir,
            $this->userDir,
            [
                $this->userDir . '/plugins/one/templates',
                $this->userDir . '/plugins/two/templates',
            ],
        );

        $resolved = $resolver->resolve();

        self::assertContains($this->themeDir . '/templates', $resolved);
        self::assertContains($this->userDir . '/pages', $resolved);
        self::assertContains($this->userDir . '/config', $resolved);
        self::assertContains($this->userDir . '/plugins/one/templates', $resolved);
        self::assertContains($this->userDir . '/plugins/two/templates', $resolved);
        // Theme-root markdown (safelist docs) is picked up by default.
        self::assertContains($this->themeDir . '/available-classes.md', $resolved);
        self::assertContains($this->themeDir . '/README.md', $resolved);
        // The theme's build output dir is never a default source.
        self::assertNotContains($this->themeDir . '/build', $resolved);
        self::assertNotContains($this->themeDir . '/build/css', $resolved);
    }

    public function testPluginTemplateDirsCanBeSuppliedAsCallable(): void
    {
        $called = 0;
        $resolver = new SourceResolver(
            $this->themeDir,
            $this->userDir,
            function () use (&$called): array {
                ++$called;

                return [
                    $this->userDir . '/plugins/one/templates',
                    $this->userDir . '/plugins/three/templates', // does not exist
                ];
            },
        );

        $resolved = $resolver->resolve();

        self::assertSame(1, $called, 'The plugin-templates callable should be invoked lazily');
        self::assertContains($this->userDir . '/plugins/one/templates', $resolved);
        // Non-existent dirs are filtered out.
        self::assertNotContains($this->userDir . '/plugins/three/templates', $resolved);
    }

    public function testExplicitSourcesWithMagicTokens(): void
    {
        $resolver = new SourceResolver(
            $this->themeDir,
            $this->userDir,
            [$this->userDir . '/plugins/two/templates'],
        );

        $resolved = $resolver->resolve([
            'self://templates',
            'user://pages',
            'user://config',
            'plugin-templates',
        ]);

        self::assertContains($this->themeDir . '/templates', $resolved);
        self::assertContains($this->userDir . '/pages', $resolved);
        self::assertContains($this->userDir . '/config', $resolved);
        self::assertContains($this->userDir . '/plugins/two/templates', $resolved);
        // With explicit sources, theme-root markdown is NOT auto-added.
        self::assertNotContains($this->themeDir . '/available-classes.md', $resolved);
    }

    public function testExplicitRelativeAndPrefixedPaths(): void
    {
        $resolver = new SourceResolver($this->themeDir, $this->userDir, []);

        $resolved = $resolver->resolve([
            'templates/partials',           // relative to theme dir
            'self://templates',
            'user://config',
        ]);

        self::assertContains($this->themeDir . '/templates/partials', $resolved);
        self::assertContains($this->themeDir . '/templates', $resolved);
        self::assertContains($this->userDir . '/config', $resolved);
    }

    public function testSafelistFilesResolveRelativeToTheme(): void
    {
        $resolver = new SourceResolver($this->themeDir, $this->userDir, []);

        $resolved = $resolver->resolve(['self://templates'], ['available-classes.md']);

        self::assertContains($this->themeDir . '/available-classes.md', $resolved);
    }

    public function testNonExistentSafelistFileIsSkipped(): void
    {
        $resolver = new SourceResolver($this->themeDir, $this->userDir, []);

        $resolved = $resolver->resolve(['self://templates'], ['does-not-exist.md']);

        self::assertNotContains($this->themeDir . '/does-not-exist.md', $resolved);
    }

    public function testResolveDedupesRepeatedSources(): void
    {
        $resolver = new SourceResolver($this->themeDir, $this->userDir, []);

        $resolved = $resolver->resolve(['self://templates', 'templates', 'self://templates']);

        $templatesCount = \count(array_filter(
            $resolved,
            fn (string $p): bool => $p === $this->themeDir . '/templates',
        ));
        self::assertSame(1, $templatesCount);
    }

    public function testResolvesRealTyphoonThemeContract(): void
    {
        $themeDir = '/Users/rhuk/Projects/grav/grav-theme-typhoon';
        if (!is_dir($themeDir . '/templates')) {
            self::markTestSkipped('Typhoon theme not available at ' . $themeDir);
        }

        $resolver = new SourceResolver($themeDir, dirname($themeDir), []);
        $resolved = $resolver->resolve();

        self::assertContains($themeDir . '/templates', $resolved);
        self::assertContains($themeDir . '/available-classes.md', $resolved);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<int, string> $dirs
     */
    private function mkdirs(array $dirs): void
    {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
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
