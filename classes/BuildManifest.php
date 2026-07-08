<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4;

/**
 * Immutable record of one build run, persisted as JSON to
 * `user-data://tailwind4/<theme>.json` so the admin report and CLI can show
 * what the last compile did (or why it failed) without re-running anything.
 */
final class BuildManifest
{
    public function __construct(
        /** Theme slug the build ran for. */
        public readonly string $theme,
        /** Whether the build completed and the output file was written. */
        public readonly bool $success,
        /** Failure message when $success is false, null otherwise. */
        public readonly ?string $error,
        /** Unix timestamp of the build. */
        public readonly int $timestamp,
        /** Total wall-clock duration (resolve + scan + compile + write) in ms. */
        public readonly float $durationMs,
        /** Engine compile portion of the duration in ms. */
        public readonly float $compileMs,
        /** Source files considered by the scanner. */
        public readonly int $filesScanned,
        /** Files served from the scanner's per-file cache. */
        public readonly int $cacheHits,
        /** Files actually read and tokenized. */
        public readonly int $filesRead,
        /** Candidate class names handed to the engine. */
        public readonly int $candidateCount,
        /** Absolute path of the written output file. */
        public readonly string $outputPath,
        /** Size of the written output in bytes (0 on failure). */
        public readonly int $outputSize,
        /** sha256 of the input CSS file ('' on failure before hashing). */
        public readonly string $inputHash,
        /** TailwindPHP engine version, e.g. "v1.4.2". */
        public readonly string $engineVersion,
        /** Peak PHP memory during the compile, in bytes. */
        public readonly int $peakMemoryBytes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'theme' => $this->theme,
            'success' => $this->success,
            'error' => $this->error,
            'timestamp' => $this->timestamp,
            'duration_ms' => round($this->durationMs, 2),
            'compile_ms' => round($this->compileMs, 2),
            'files_scanned' => $this->filesScanned,
            'cache_hits' => $this->cacheHits,
            'files_read' => $this->filesRead,
            'candidate_count' => $this->candidateCount,
            'output_path' => $this->outputPath,
            'output_size' => $this->outputSize,
            'input_hash' => $this->inputHash,
            'engine_version' => $this->engineVersion,
            'peak_memory_bytes' => $this->peakMemoryBytes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            theme: (string) ($data['theme'] ?? ''),
            success: (bool) ($data['success'] ?? false),
            error: isset($data['error']) && \is_string($data['error']) ? $data['error'] : null,
            timestamp: (int) ($data['timestamp'] ?? 0),
            durationMs: (float) ($data['duration_ms'] ?? 0.0),
            compileMs: (float) ($data['compile_ms'] ?? 0.0),
            filesScanned: (int) ($data['files_scanned'] ?? 0),
            cacheHits: (int) ($data['cache_hits'] ?? 0),
            filesRead: (int) ($data['files_read'] ?? 0),
            candidateCount: (int) ($data['candidate_count'] ?? 0),
            outputPath: (string) ($data['output_path'] ?? ''),
            outputSize: (int) ($data['output_size'] ?? 0),
            inputHash: (string) ($data['input_hash'] ?? ''),
            engineVersion: (string) ($data['engine_version'] ?? ''),
            peakMemoryBytes: (int) ($data['peak_memory_bytes'] ?? 0),
        );
    }

    /**
     * Persist the manifest as pretty JSON. The parent directory is created if
     * needed and the write is atomic (tmp file + rename) so a concurrent reader
     * never sees a half-written manifest.
     */
    public function save(string $file): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            return;
        }

        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") !== false) {
            @rename($tmp, $file);
        }
    }

    /**
     * Load a previously saved manifest, or null when the file is missing or
     * unreadable.
     */
    public static function load(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return \is_array($data) ? self::fromArray($data) : null;
    }
}
