<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\ThemeConfig;
use PHPUnit\Framework\TestCase;

final class ThemeConfigTest extends TestCase
{
    private const THEME_DIR = '/tmp/site/user/themes/typhoon';

    public function testDefaultsMakeAnUncontractedThemeWork(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, null);

        self::assertSame('typhoon', $config->themeName);
        self::assertSame(self::THEME_DIR, $config->themeDir);
        self::assertSame('css/site.css', $config->input);
        self::assertSame('build/css/site.css', $config->output);
        self::assertNull($config->sources);
        self::assertSame([], $config->safelistFiles);
    }

    public function testFromArrayReadsTheFullContract(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR . '/', [
            'input' => 'css/main.css',
            'output' => 'dist/main.css',
            'sources' => ['self://templates', 'user://pages'],
            'safelist_files' => ['available-classes.md'],
        ]);

        self::assertSame(self::THEME_DIR, $config->themeDir, 'Trailing slash on the theme dir is trimmed');
        self::assertSame('css/main.css', $config->input);
        self::assertSame('dist/main.css', $config->output);
        self::assertSame(['self://templates', 'user://pages'], $config->sources);
        self::assertSame(['available-classes.md'], $config->safelistFiles);
    }

    public function testPathsStayThemeRelative(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, [
            'input' => '/css/site.css',
            'output' => '  /build/css/site.css ',
        ]);

        self::assertSame('css/site.css', $config->input);
        self::assertSame('build/css/site.css', $config->output);
    }

    public function testAbsolutePathHelpers(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, null);

        self::assertSame(self::THEME_DIR . '/css/site.css', $config->inputPath());
        self::assertSame(self::THEME_DIR . '/build/css/site.css', $config->outputPath());
        self::assertSame(self::THEME_DIR . '/build', $config->outputRootDir());
    }

    public function testOutputRootDirUsesFirstSegmentOfCustomOutput(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, ['output' => 'dist/css/site.css']);

        self::assertSame(self::THEME_DIR . '/dist', $config->outputRootDir());
    }

    public function testMalformedValuesFallBackToDefaults(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, [
            'input' => ['not', 'a', 'string'],
            'output' => '',
            'sources' => 'not-a-list',
            'safelist_files' => 42,
        ]);

        self::assertSame('css/site.css', $config->input);
        self::assertSame('build/css/site.css', $config->output);
        self::assertNull($config->sources);
        self::assertSame([], $config->safelistFiles);
    }

    public function testEmptyAndNonStringSourceEntriesAreDropped(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, [
            'sources' => ['self://templates', '', '   ', 7, null, ' user://pages '],
        ]);

        self::assertSame(['self://templates', 'user://pages'], $config->sources);
    }

    public function testAllEmptySourcesListMeansDefaults(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, ['sources' => []]);

        self::assertNull($config->sources, 'An empty sources list falls back to the default source set');
    }

    public function testNonArrayBlockIsTreatedAsNoContract(): void
    {
        $config = ThemeConfig::fromArray('typhoon', self::THEME_DIR, 'enabled');

        self::assertSame('css/site.css', $config->input);
        self::assertSame('build/css/site.css', $config->output);
    }
}
