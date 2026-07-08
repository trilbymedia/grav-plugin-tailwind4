<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Tests;

use Grav\Plugin\Tailwind4\Compiler;
use Grav\Plugin\Tailwind4\CompileException;
use Grav\Plugin\Tailwind4\CompileResult;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/compiler';

    /** Acceptance (a): candidate list compiles against a minimal input. */
    public function testCompilesCandidateListAgainstMinimalInput(): void
    {
        $result = (new Compiler())->compile(
            self::FIXTURES . '/basic/input.css',
            ['flex', 'p-4'],
            minify: false,
        );

        $this->assertInstanceOf(CompileResult::class, $result);
        $this->assertStringContainsString('.flex', $result->css);
        $this->assertStringContainsString('.p-4', $result->css);
        $this->assertSame(2, $result->candidateCount);
        $this->assertGreaterThan(0.0, $result->durationMs);
        $this->assertGreaterThan(0, $result->peakMemoryBytes);
    }

    /** Acceptance (b): nested relative @import chains resolve. */
    public function testNestedRelativeImportsResolve(): void
    {
        $result = (new Compiler())->compile(
            self::FIXTURES . '/nested/input.css',
            ['flex'],
            minify: false,
        );

        // The rule authored in ./partial.css must appear in the output.
        $this->assertStringContainsString('nested-partial-marker', $result->css);
        $this->assertStringContainsString('rebeccapurple', $result->css);
    }

    /**
     * Acceptance (c), updated for the trilbymedia engine fork: the container
     * utility is implemented natively, so it must compile with the injection
     * OFF (the default). Guards against regressing to a stock engine that
     * lacks it.
     */
    public function testContainerCompilesNativelyWithoutInjection(): void
    {
        $result = (new Compiler(containerFix: false))->compile(
            self::FIXTURES . '/basic/input.css',
            ['container', 'xl:container'],
            minify: false,
        );

        $this->assertMatchesRegularExpression('/\.container\b/', $result->css);
        $this->assertStringContainsString('max-width: 80rem', $result->css);
    }

    /**
     * The injection fallback (for a stock engine without the container fix)
     * must still produce a working container rule rather than breaking the
     * native one.
     */
    public function testContainerFixFallbackStillCompiles(): void
    {
        $result = (new Compiler(containerFix: true))->compile(
            self::FIXTURES . '/basic/input.css',
            ['container', 'xl:container'],
            minify: false,
        );

        $this->assertMatchesRegularExpression('/\.container\b/', $result->css);
        $this->assertStringContainsString('max-width: 80rem', $result->css);
    }

    /** Acceptance (d): @source inline() classes appear without being candidates. */
    public function testSourceInlineProducesClassesNotInCandidateList(): void
    {
        $result = (new Compiler())->compile(
            self::FIXTURES . '/inline/input.css',
            ['flex'], // deliberately omits bg-red-500 / bg-blue-500
            minify: false,
        );

        $this->assertStringContainsString('bg-red-500', $result->css);
        $this->assertStringContainsString('bg-blue-500', $result->css);
    }

    public function testMinifyProducesSmallerOutput(): void
    {
        $compiler = new Compiler();
        $input = self::FIXTURES . '/basic/input.css';

        $raw = $compiler->compile($input, ['flex', 'p-4'], minify: false);
        $min = $compiler->compile($input, ['flex', 'p-4'], minify: true);

        $this->assertLessThan(strlen($raw->css), strlen($min->css));
        $this->assertStringContainsString('.flex', $min->css);
    }

    public function testMissingInputThrowsCompileException(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('missing or unreadable');

        (new Compiler())->compile(self::FIXTURES . '/does-not-exist.css', ['flex']);
    }

    public function testCompileExceptionCarriesPathAndCandidateCount(): void
    {
        try {
            (new Compiler())->compile(self::FIXTURES . '/does-not-exist.css', ['flex', 'p-4', 'block']);
            $this->fail('Expected CompileException');
        } catch (CompileException $e) {
            $this->assertSame(self::FIXTURES . '/does-not-exist.css', $e->inputPath);
            $this->assertSame(3, $e->candidateCount);
            $this->assertStringContainsString('does-not-exist.css', $e->getMessage());
            $this->assertStringContainsString('3 candidate', $e->getMessage());
        }
    }
}
