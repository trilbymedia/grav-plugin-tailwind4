<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Api;

use Grav\Plugin\Tailwind4\BuildManifest;

/**
 * Pure response builders for the Tailwind4 API endpoints and admin-next UI.
 *
 * Deliberately free of any Grav coupling so it is unit testable on its own:
 * the controller and the menubar action hand it a {@see BuildManifest} (or
 * null) plus a translator callback, and it returns the JSON-ready arrays the
 * admin-next page and toasts consume.
 */
final class StatusPayload
{
    /**
     * Body for `GET /tailwind4/status`.
     *
     * When no build has run yet the manifest is null and the payload carries a
     * clear empty state the report view can render without guessing.
     *
     * @return array<string, mixed>
     */
    public static function status(?BuildManifest $manifest, string $theme): array
    {
        if ($manifest === null) {
            return [
                'theme' => $theme,
                'compiled' => false,
                'manifest' => null,
            ];
        }

        return [
            'theme' => $manifest->theme,
            'compiled' => true,
            'manifest' => $manifest->toArray(),
        ];
    }

    /**
     * Body for `POST /tailwind4/compile`. Always a 200 payload: BuildService
     * never throws, so a failed build is reported as `success: false` with the
     * error carried in the manifest and an error toast hint.
     *
     * @param array<string, mixed> $toast Pre-built toast hint (see {@see toast()}).
     *
     * @return array<string, mixed>
     */
    public static function compileResult(BuildManifest $manifest, array $toast): array
    {
        return [
            'theme' => $manifest->theme,
            'compiled' => true,
            'success' => $manifest->success,
            'manifest' => $manifest->toArray(),
            'toast' => $toast,
            'message' => $toast['message'] ?? '',
        ];
    }

    /**
     * Build a toast hint for a completed build. `$translate` resolves a lang
     * key with sprintf-style arguments, e.g.
     * `fn(string $key, ...$args) => $language->translate([$key, ...$args])`.
     *
     * @param callable(string, mixed...): string $translate
     *
     * @return array<string, mixed>
     */
    public static function toast(BuildManifest $manifest, callable $translate): array
    {
        if ($manifest->success) {
            return [
                'status' => 'success',
                'type' => 'success',
                'message' => $translate(
                    'PLUGIN_TAILWIND4.COMPILE_SUCCESS',
                    $manifest->theme,
                    self::humanDuration($manifest->durationMs),
                    self::humanBytes($manifest->outputSize),
                ),
            ];
        }

        return [
            'status' => 'error',
            'type' => 'error',
            'message' => $translate(
                'PLUGIN_TAILWIND4.COMPILE_FAILED',
                $manifest->error ?? $translate('PLUGIN_TAILWIND4.UNKNOWN_ERROR'),
            ),
            'duration' => 0,
            'dismissible' => true,
        ];
    }

    /**
     * Human-readable byte size, e.g. 1536 -> "1.5 KB". Language-neutral.
     */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($value, 1), '0'), '.') . ' ' . $units[$unit];
    }

    /**
     * Human-readable duration, e.g. 45.2 -> "45 ms", 1250.0 -> "1.25 s".
     * Language-neutral.
     */
    public static function humanDuration(float $ms): string
    {
        if ($ms < 1000) {
            return round($ms) . ' ms';
        }

        return rtrim(rtrim(number_format($ms / 1000, 2), '0'), '.') . ' s';
    }
}
