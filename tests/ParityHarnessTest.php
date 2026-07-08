<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\ParityHarness;
use PHPUnit\Framework\TestCase;

final class ParityHarnessTest extends TestCase
{
    public function testExtractsPlainClassSelectors(): void
    {
        $css = '.flex{display:flex}.p-4{padding:1rem}';

        self::assertSame(['flex', 'p-4'], ParityHarness::classSet($css));
    }

    public function testDecodesEscapedVariantAndFractionSelectors(): void
    {
        $css = '.hover\:bg-blue-700:hover{color:red}.w-1\/2{width:50%}.p-\[3\.5rem\]{padding:3.5rem}';

        self::assertSame(
            ['hover:bg-blue-700', 'p-[3.5rem]', 'w-1/2'],
            ParityHarness::classSet($css),
        );
    }

    public function testDecodesHexEscapesForDigitLeadingClasses(): void
    {
        // LightningCSS writes `.\32 xl\:flex`, other minifiers `.\32xl\:flex`
        // is not valid (32xl would be one hex escape), so only the spaced form
        // plus the two-digit form need decoding.
        $css = '.\32 xl\:flex{display:flex}';

        self::assertSame(['2xl:flex'], ParityHarness::classSet($css));
    }

    public function testIgnoresNumericLiteralsAndStrings(): void
    {
        $css = '.real{margin:.5rem;content:".fake-class"}a[href=".also-fake"]{top:.25em}';

        self::assertSame(['real'], ParityHarness::classSet($css));
    }

    public function testIgnoresUnquotedUrlTokens(): void
    {
        $css = '@font-face{font-family:Inter;src:url(../fonts/Inter-roman.var.woff2?v=3.19) format("woff2")}.kept{display:block}';

        self::assertSame(['kept'], ParityHarness::classSet($css));
    }

    public function testIgnoresComments(): void
    {
        $css = "/* .commented-out{} */\n.kept{display:block}";

        self::assertSame(['kept'], ParityHarness::classSet($css));
    }

    public function testHandlesNodeStyleNestedOutput(): void
    {
        // Unminified Node CLI output nests variant conditions with &:where().
        $css = ".dark\\:flex {\n  &:where(.dark, .dark *) {\n    display: flex;\n  }\n}";

        self::assertSame(['dark', 'dark:flex'], ParityHarness::classSet($css));
    }

    public function testFlattenedAndNestedFormsYieldTheSameSet(): void
    {
        $nested = ".dark\\:flex { &:where(.dark, .dark *) { display: flex; } }";
        $flat = '.dark\:flex:where(.dark,.dark *){display:flex}';

        self::assertSame(
            ParityHarness::classSet($nested),
            ParityHarness::classSet($flat),
        );
    }

    public function testClassesInsideAtRulesAreFound(): void
    {
        $css = '@media (width>=80rem){.xl\:container{width:100%}}@supports (display:grid){.grid{display:grid}}';

        self::assertSame(['grid', 'xl:container'], ParityHarness::classSet($css));
    }

    public function testDiffReportsMissingAndExtra(): void
    {
        $reference = '.flex{display:flex}.container{width:100%}.p-4{padding:1rem}';
        $actual = '.flex{display:flex}.p-4{padding:1rem}.hidden{display:none}';

        $diff = ParityHarness::diff($reference, $actual);

        self::assertSame(['container'], $diff['missing']);
        self::assertSame(['hidden'], $diff['extra']);
    }

    public function testDiffOfIdenticalSetsIsEmpty(): void
    {
        $a = '.flex{display:flex}.p-4{padding:1rem}';
        $b = ".p-4 {\n  padding: 1rem;\n}\n.flex {\n  display: flex;\n}";

        $diff = ParityHarness::diff($a, $b);

        self::assertSame([], $diff['missing']);
        self::assertSame([], $diff['extra']);
    }

    public function testNodeAvailableChecksForNodeModules(): void
    {
        $dir = sys_get_temp_dir() . '/tw4-parity-' . uniqid('', true);
        mkdir($dir . '/node_modules', 0775, true);

        try {
            self::assertTrue(ParityHarness::nodeAvailable($dir));
            self::assertFalse(ParityHarness::nodeAvailable($dir . '/nope'));
        } finally {
            @rmdir($dir . '/node_modules');
            @rmdir($dir);
        }
    }
}
