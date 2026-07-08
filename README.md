# Tailwind4 Plugin

The **Tailwind4** plugin compiles [Tailwind CSS](https://tailwindcss.com/) 4.x for
Grav themes (such as Typhoon and Helios) directly from PHP. There is no Node.js, no
`npm install`, and no build step. Compilation is triggered on demand from an admin
action, the CLI, or a config save, and never runs on a front-end page request.

It is built on [`tailwindphp/tailwindphp`](https://github.com/dnnsjsk/tailwindphp),
a zero-dependency PHP port of the Tailwind engine, pinned to an exact tested version
and shipped vendored so Tailwind upgrades happen deliberately per plugin release.

## Status

Early development. This is the WP0 scaffold; the compiler, scanner, build service,
CLI, and admin UI arrive in later work packages. See `PLAN.md` for the full plan.

## Requirements

* Grav 2.0
* PHP 8.2 or higher

## Installation

Install into `user/plugins/tailwind4`, then run:

```
composer install --no-dev
```

## License

MIT. Copyright (c) 2026 Andy Miller / Trilby Media, LLC. See [LICENSE](LICENSE).
