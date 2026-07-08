<?php

declare(strict_types=1);

namespace Grav\Plugin\Tailwind4\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Tailwind4\BuildManifest;
use Grav\Plugin\Tailwind4\BuildService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * REST endpoints backing the admin-next Tailwind 4 UI.
 *
 *   POST /tailwind4/compile   — compile a theme (body: {theme?}) and return the manifest
 *   GET  /tailwind4/status    — the last persisted manifest, or a clear empty state
 *
 * Access is limited to theme administrators or super admins. Compilation runs
 * synchronously (it is sub-second) through {@see BuildService}, which never
 * throws — a failed build comes back as a manifest with `success: false`.
 *
 * The TailwindPHP engine is only loaded from inside BuildService::build(), so a
 * plain status request never pulls the engine into memory.
 */
final class Tailwind4ApiController extends AbstractApiController
{
    /**
     * POST /tailwind4/compile
     */
    public function compile(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireThemeAccess($request);

        $body = $this->getRequestBody($request);
        $theme = $this->resolveTheme($body['theme'] ?? null);

        $manifest = BuildService::fromGrav($theme)->build();
        $toast = StatusPayload::toast($manifest, $this->translator());

        return ApiResponse::create(StatusPayload::compileResult($manifest, $toast));
    }

    /**
     * GET /tailwind4/status
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireThemeAccess($request);

        $theme = $this->resolveTheme($request->getQueryParams()['theme'] ?? null);

        // Resolve the manifest path without compiling — this does not load the
        // Tailwind engine.
        $manifestPath = BuildService::fromGrav($theme)->manifestPath();
        $manifest = $manifestPath !== null ? BuildManifest::load($manifestPath) : null;

        return ApiResponse::create(StatusPayload::status($manifest, $theme));
    }

    /**
     * Gate access to theme administrators or super admins.
     *
     * The base controller's own super-admin short-circuit checks the API super
     * flag (`access.api.super`); here we additionally honor the classic
     * `admin.super` and the `admin.themes` grant so a theme administrator can
     * trigger a compile, matching the plugin's admin surface.
     */
    private function requireThemeAccess(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);

        if (
            $this->isSuperAdmin($user)
            || (bool) $user->get('access.admin.super')
            || (bool) $user->get('access.admin.themes')
        ) {
            return;
        }

        throw new ForbiddenException('Theme administrator access is required to compile Tailwind CSS.');
    }

    /**
     * Validate an optional theme slug from the request, defaulting to the
     * active theme.
     */
    private function resolveTheme(mixed $theme): string
    {
        $theme = is_string($theme) ? trim($theme) : '';

        if ($theme !== '') {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $theme)) {
                throw new ValidationException(
                    'Invalid theme name',
                    [['field' => 'theme', 'message' => 'Theme name contains invalid characters']],
                );
            }

            return $theme;
        }

        return (string) $this->grav['config']->get('system.pages.theme');
    }

    /**
     * A translator callable for {@see StatusPayload}: resolves a lang key with
     * sprintf-style arguments through Grav's language service.
     *
     * @return callable(string, mixed...): string
     */
    private function translator(): callable
    {
        $language = $this->grav['language'];

        return static fn (string $key, mixed ...$args): string
            => (string) $language->translate(array_merge([$key], $args));
    }
}
