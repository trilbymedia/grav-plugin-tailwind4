<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

/**
 * Immutable result of a single Tailwind compile run.
 *
 * Carries the produced CSS plus lightweight metrics the BuildService and admin
 * report display back to the user.
 */
final class CompileResult
{
    public function __construct(
        /** The compiled (and optionally minified) CSS. */
        public readonly string $css,
        /** Wall-clock duration of the compile in milliseconds. */
        public readonly float $durationMs,
        /** Number of candidate class names fed to the engine. */
        public readonly int $candidateCount,
        /** Peak PHP memory used during the compile, in bytes. */
        public readonly int $peakMemoryBytes,
    ) {
    }
}
