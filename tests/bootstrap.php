<?php

declare(strict_types=1);

// Plugin classes + the vendored TailwindPHP engine. Tests run against the plugin
// classes directly and do not require a booted Grav instance; anything needing
// Grav services must construct/mock them explicitly.
require_once dirname(__DIR__) . '/vendor/autoload.php';
