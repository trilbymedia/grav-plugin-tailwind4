<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

use RuntimeException;
use Throwable;

/**
 * Thrown when the TailwindPHP engine fails to compile, or when the compile
 * inputs are unusable (e.g. a missing input CSS file).
 *
 * The message always records the input CSS path and the candidate count so a
 * failure can be diagnosed from the manifest/log without re-running.
 */
final class CompileException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $inputPath = '',
        public readonly int $candidateCount = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build an exception describing a failed engine run.
     */
    public static function engineFailed(string $inputPath, int $candidateCount, Throwable $previous): self
    {
        $message = sprintf(
            'Tailwind compile failed for input "%s" with %d candidate(s): %s',
            $inputPath,
            $candidateCount,
            $previous->getMessage(),
        );

        return new self($message, $inputPath, $candidateCount, $previous);
    }

    /**
     * Build an exception for an input CSS file that cannot be read.
     */
    public static function inputUnreadable(string $inputPath, int $candidateCount): self
    {
        $message = sprintf(
            'Tailwind input CSS "%s" is missing or unreadable (%d candidate(s) requested)',
            $inputPath,
            $candidateCount,
        );

        return new self($message, $inputPath, $candidateCount);
    }
}
