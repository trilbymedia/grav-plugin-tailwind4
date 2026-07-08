<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;

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
     * on front-end requests. Later work packages attach the admin and CLI
     * handlers here behind this guard. The Tailwind engine is never loaded from
     * this method.
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdmin()) {
            return;
        }

        // Admin and CLI event handlers are added in later work packages.
    }
}
