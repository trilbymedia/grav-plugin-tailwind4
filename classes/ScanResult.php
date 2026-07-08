<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

/**
 * Immutable result of a {@see Scanner::scan()} run: the deduped candidate
 * tokens plus the stats that let callers reason about cache effectiveness.
 */
final class ScanResult
{
    /**
     * @param array<int, string> $tokens      Deduped, sorted candidate tokens.
     * @param int                $filesScanned Total source files considered.
     * @param int                $cacheHits    Files served from the per-file cache.
     * @param int                $filesRead    Files actually read + tokenized.
     */
    public function __construct(
        public readonly array $tokens,
        public readonly int $filesScanned,
        public readonly int $cacheHits,
        public readonly int $filesRead,
    ) {
    }

    /**
     * Number of distinct candidate tokens found.
     */
    public function tokenCount(): int
    {
        return \count($this->tokens);
    }

    /**
     * @return array<string, int|array<int, string>>
     */
    public function stats(): array
    {
        return [
            'files_scanned' => $this->filesScanned,
            'cache_hits' => $this->cacheHits,
            'files_read' => $this->filesRead,
            'token_count' => $this->tokenCount(),
        ];
    }
}
