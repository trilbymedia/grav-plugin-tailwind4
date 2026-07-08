<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * Guards the nested-@apply fix carried by the trilbymedia engine fork
 * (branch `trilby`, consumed via composer as `dev-trilby`; submitted upstream
 * as inline0/tailwindphp PR #4).
 *
 * Stock tailwindphp v1.4.2 drops every nested child rule of a rule whose
 * `@apply` expands to more than one declaration. If a future engine bump
 * points composer back at a stock release without the fix, these assertions
 * fail loudly instead of silently regressing Typhoon's breadcrumb, form-label
 * and nav-indent styling.
 */
final class EnginePatchTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/engine-patch/input.css';

    /**
     * A multi-declaration parent @apply must not swallow its nested child rule.
     * This is the exact reproduction the patch addresses.
     */
    public function testNestedApplyUnderMultiDeclarationParentIsNotDropped(): void
    {
        $css = (new Compiler())->compile(self::FIXTURE, ['flex'], minify: false)->css;

        // The nested child of the multi-declaration parent must be present and
        // must carry the display:block declaration its @apply expands to.
        $this->assertMatchesRegularExpression(
            '/#bug\s+\.child\s*\{[^}]*display:\s*block/s',
            $css,
            'Nested .child rule was dropped — the engine fix is missing (stock tailwindphp nested-@apply bug).',
        );
    }

    /**
     * The single-declaration parent case has always worked; keep it green so a
     * future fix can never trade one regression for another.
     */
    public function testNestedApplyUnderSingleDeclarationParentStillWorks(): void
    {
        $css = (new Compiler())->compile(self::FIXTURE, ['flex'], minify: false)->css;

        $this->assertMatchesRegularExpression(
            '/#ok\s+\.kid\s*\{[^}]*display:\s*block/s',
            $css,
        );
    }
}
