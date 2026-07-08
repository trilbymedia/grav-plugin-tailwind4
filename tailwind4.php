<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\Tailwind4\Api\StatusPayload;
use Grav\Plugin\Tailwind4\BuildManifest;
use Grav\Plugin\Tailwind4\BuildService;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class Tailwind4Plugin
 *
 * Compiles Tailwind CSS 4.x for Grav themes directly from PHP. Compilation is
 * triggered on demand (admin action, CLI, or config save) and never runs on a
 * front-end page request. The Tailwind engine is loaded lazily inside the
 * compile code path, never here in onPluginsInitialized.
 *
 * @package Grav\Plugin
 */
class Tailwind4Plugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Load the class autoloader early, but after plugins are initialized.
                ['autoload', 100001],
                ['onPluginsInitialized', 0],
            ],
            // Admin Next (API plugin) integration. These only fire during an API
            // request dispatch, so subscribing them unconditionally is free on the
            // front end and, per the API integration contract, must NOT be gated on
            // isAdmin() (the admin proxy is not registered yet at this point).
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiMenubarItems' => ['onApiMenubarItems', 0],
            'onApiMenubarAction' => ['onApiMenubarAction', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            // Auto-compile when the active theme's config is saved. Fires only
            // during admin/API save operations; gated on config inside the handler.
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
        ];
    }

    /**
     * Register an autoloader for the plugin's own classes only.
     *
     * Deliberately NOT the Composer autoloader: vendor/autoload.php triggers the
     * TailwindPHP engine's `files` autoload (~68 files, ~5 MB without opcache) on
     * every request. Compiler::loadEngine() requires vendor/autoload.php on
     * demand, so the engine costs nothing until a compile actually runs.
     */
    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\Tailwind4\\';
            if (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    /**
     * Initialize the plugin.
     *
     * Compilation is an admin/CLI-only concern, so there is nothing to wire up
     * on front-end requests. The admin-next handlers live in getSubscribedEvents
     * (they only fire during API dispatch); the Tailwind engine is never loaded
     * from this method.
     */
    public function onPluginsInitialized(): void
    {
        // Intentionally empty: all admin work is handled by the onApi*/onAdmin*
        // subscribers, and none of them load the engine until a compile runs.
    }

    /**
     * Register the REST routes backing the admin-next UI.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\Tailwind4\Api\Tailwind4ApiController::class;

        $routes->post('/tailwind4/compile', [$controller, 'compile']);
        $routes->get('/tailwind4/status', [$controller, 'status']);
    }

    /**
     * Add a sidebar entry that opens the Tailwind 4 report page.
     */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => 'tailwind4',
            'plugin' => 'tailwind4',
            'label' => $this->translate('PLUGIN_TAILWIND4.MENU_TITLE'),
            'icon' => 'fa-paint-brush',
            'route' => '/plugin/tailwind4',
            'priority' => 5,
            'authorize' => ['admin.themes', 'admin.super'],
        ];
        $event['items'] = $items;
    }

    /**
     * Add a "Compile CSS" button to the admin-next header toolbar.
     */
    public function onApiMenubarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => 'tailwind4-compile',
            'plugin' => 'tailwind4',
            'label' => $this->translate('PLUGIN_TAILWIND4.COMPILE_CSS'),
            'icon' => 'fa-paint-brush',
            'action' => 'compile',
            'placement' => 'start',
            'priority' => 5,
            'authorize' => ['admin.themes', 'admin.super'],
        ];
        $event['items'] = $items;
    }

    /**
     * Handle the toolbar "Compile CSS" action: compile the active theme and
     * report the result as a toast.
     */
    public function onApiMenubarAction(Event $event): void
    {
        if (($event['plugin'] ?? null) !== 'tailwind4') {
            return;
        }

        if (($event['action'] ?? null) !== 'compile') {
            return;
        }

        $manifest = BuildService::fromGrav(null)->build();
        $event['result'] = StatusPayload::toast($manifest, $this->translator());
    }

    /**
     * Register the component-mode plugin page (admin-next/pages/tailwind4.js).
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== 'tailwind4') {
            return;
        }

        $event['definition'] = [
            'id' => 'tailwind4',
            'plugin' => 'tailwind4',
            'title' => $this->translate('PLUGIN_TAILWIND4.MENU_TITLE'),
            'icon' => 'fa-paint-brush',
            'page_type' => 'component',
        ];
    }

    /**
     * Recompile the active theme when its configuration is saved and
     * auto-compile is enabled. Runs for both classic admin and admin-next/API
     * saves (both fire onAdminAfterSave); the engine loads only if this actually
     * triggers a compile.
     */
    public function onAdminAfterSave(Event $event): void
    {
        if (!$this->config->get('plugins.tailwind4.auto_compile_on_save')) {
            return;
        }

        if (!$this->isActiveThemeConfigSave($event['object'] ?? null)) {
            return;
        }

        BuildService::fromGrav(null)->build();
    }

    /**
     * Whether the saved object is the active theme's configuration file.
     *
     * Detected from the object's backing config file path, which both the
     * classic admin (Theme config Data) and the API plugin's ConfigController
     * set to `<config>/themes/<theme>.yaml`.
     */
    private function isActiveThemeConfigSave(mixed $object): bool
    {
        if (!is_object($object) || !method_exists($object, 'file')) {
            return false;
        }

        $file = $object->file();
        if (!is_object($file) || !method_exists($file, 'filename')) {
            return false;
        }

        $path = str_replace('\\', '/', (string) $file->filename());
        if ($path === '') {
            return false;
        }

        $theme = (string) $this->config->get('system.pages.theme');
        if ($theme === '') {
            return false;
        }

        return (bool) preg_match('#/themes/' . preg_quote($theme, '#') . '\.yaml$#', $path);
    }

    /**
     * Resolve a lang key to the active admin language.
     */
    private function translate(string $key): string
    {
        return (string) $this->grav['language']->translate([$key]);
    }

    /**
     * A translator callable for {@see StatusPayload} (key + sprintf-style args).
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
