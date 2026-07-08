# Tailwind4 Plugin

The **Tailwind4** plugin compiles [Tailwind CSS](https://tailwindcss.com/) 4.x for
Grav themes directly from PHP. There is no Node.js, no `npm install`, and no build
step. Compilation is triggered on demand from an admin button, the CLI, or a config
save, and never runs on a front-end page request.

It is built on [`tailwindphp/tailwindphp`](https://github.com/inline0/tailwindphp),
a zero-dependency PHP port of the Tailwind engine, consumed through the
[trilbymedia fork](https://github.com/trilbymedia/tailwindphp) (branch `trilby`:
the v1.4.2 release plus two fixes that have been submitted upstream). The engine is
pinned to an exact tested commit and shipped vendored, so Tailwind upgrades happen
deliberately per plugin release rather than on every `composer update`.

## Why

Tailwind-based Grav themes (such as Typhoon) normally need a Node toolchain to build
their CSS: install `node_modules`, run the Tailwind CLI, commit the output. That is
fine on a developer's machine but awkward on a plain PHP host, in CI without Node, or
for a site editor who just wants to add a utility class and see it work.

This plugin removes that requirement. It scans your theme, pages, config and plugin
templates for Tailwind class names, compiles the CSS with the PHP engine, and writes
the result to the same path the npm build would have used. Templates need no changes.
The npm workflow keeps working alongside it during the beta, so you can switch back
and forth and compare the two builds.

Compilation is fast enough to run interactively. A full Typhoon build is roughly 90ms
in the engine and 130 to 170ms end to end when warm (289 files scanned, about 12,600
candidates, roughly 75KB of minified CSS). It is never run per request: the compiled
CSS is a static file your theme links to as usual.

## Requirements

* Grav 2.0
* PHP 8.2 or higher
* A Tailwind 4 based theme (one with a `css/site.css` entry point that imports
  `tailwindcss`)

## Installation

### GPM (once published)

```
bin/gpm install tailwind4
```

### Manual / development install

Clone or copy the plugin into `user/plugins/tailwind4`, then install its
dependencies (this vendors the pinned Tailwind engine from the trilbymedia
fork):

```
cd user/plugins/tailwind4
composer install --no-dev
```

Grav plugins ship their `vendor/` directory, so a GPM release includes the engine
already. The `composer install` step is only needed for a manual or from-source
install.

## Usage

### Admin

With the plugin enabled, the admin (Admin Next) gains:

* A **Compile CSS** button in the header toolbar. Click it to compile the active
  theme. A toast reports the result, including the compile duration and the output
  size, or the error if the build failed.
* A **Tailwind 4** report page (in the sidebar) showing the last build: when it ran,
  how long it took, files scanned, cache hits, candidates found, output size, and the
  engine and Tailwind versions.

Plugin settings (enable/disable, minify, auto-compile on save, scanned file
extensions) live on the plugin's configuration page.

If **Auto-compile on Save** is enabled, saving the active theme's configuration
triggers a recompile automatically. It is off by default; the manual button and the
CLI cover the common cases.

### CLI

```
bin/plugin tailwind4 compile [theme] [--watch] [--diff]
```

* `compile [theme]` compiles the given theme, or the active theme when no name is
  given, and prints a table of build stats.

  ```
  bin/plugin tailwind4 compile
  bin/plugin tailwind4 compile typhoon
  ```

* `--watch` (`-w`) polls the sources every 500ms through the scanner's per-file
  cache and recompiles when anything changes. Useful while editing content or
  templates. For heavy theme development the theme's own `npm run watch` remains a
  fine choice.

  ```
  bin/plugin tailwind4 compile typhoon --watch
  ```

* `--diff` (`-d`) compiles, then (when the theme has a `node_modules` directory) also
  runs the official Node Tailwind CLI and reports the class-selector differences
  between the two builds. This is the confidence tool for the beta period.

  ```
  bin/plugin tailwind4 compile typhoon --diff
  ```

### API

Two endpoints back the admin UI and are available to scripts. Both are under the
Grav API plugin's `/api/v1` prefix and require an authenticated theme administrator
or super admin (an `X-API-Key` header or an active admin session):

* `POST /api/v1/tailwind4/compile` compiles a theme (JSON body `{"theme": "..."}`,
  optional, defaults to the active theme) and returns the build manifest.
* `GET /api/v1/tailwind4/status` returns the last persisted manifest, or a clear
  empty state when nothing has been compiled yet.

```
curl -k -X POST https://your-site.test/api/v1/tailwind4/compile \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"theme": "typhoon"}'
```

Both endpoints always return a 200 with a manifest: a failed build comes back as
`success: false` with the error in the manifest, not as an HTTP error. Callers
without theme access get a 403.

## Theme contract

A theme opts in by declaring a `tailwind4:` block in its own yaml (for example
`typhoon.yaml`). Every key is optional; the defaults below are chosen so a theme that
follows the standard layout compiles with no contract at all.

```yaml
tailwind4:
  input: css/site.css              # Input CSS, relative to the theme dir.
                                   # Default: css/site.css
  output: build/css/site.css       # Compiled output, relative to the theme dir.
                                   # Default: build/css/site.css (the path the npm build uses)
  sources:                         # Directories and files scanned for class candidates.
                                   # Omit to use the default set (shown here explicitly).
    - self://                      # The whole theme dir (templates, blueprints, yaml, PHP)
    - user://pages                 # Page content and frontmatter
    - user://config                # Site and plugin config
    - plugin-templates             # Every enabled plugin's templates/ dir
  safelist_files:                  # Extra files always scanned, relative to the theme dir.
                                   # Default: none
    - available-classes.md         # A documented class safelist, for example
```

Notes on the semantics, matching the plugin's `ThemeConfig`:

* `input` and `output` are resolved relative to the theme directory. Leading slashes
  are stripped, so they always stay theme-relative.
* `sources` is optional. When it is absent (or empty), the default source set above
  is used. When present, it replaces the default set entirely, so list every source
  you need.
* Source entries understand `self://` (theme dir), `user://pages`, `user://config`,
  and the magic token `plugin-templates` (every enabled plugin's `templates/` dir).
  Any other entry is treated as a path relative to the theme dir.
* `safelist_files` are extra files scanned in addition to `sources`, relative to the
  theme dir. There are none by default.

The default output goes to the theme's existing `build/css/` path, so the compiled
CSS is a drop-in replacement for the npm workflow and templates need no changes.

## How scanning works

Tailwind only emits CSS for class names it actually finds in your content. The
official CLI does this with a fast tokenizer called Oxide; this plugin implements the
same idea in PHP.

The scanner deliberately over-extracts. It pulls every run of characters that could
plausibly be a Tailwind candidate out of each file, including class names buried in
Twig expressions (`class="{{ ['flex','gap-2']|join(' ') }}"`), Markdown attribute
lists (`{.text-center}`), YAML values, and `{% set %}` assignments. Anything that is
not a real utility is silently discarded by the compiler, so over-extraction costs
only a few milliseconds and never produces wrong output. Under-extraction, missing a
class that is really used, is the only real bug, so the scanner errs toward finding
too much.

Each file's tokens are cached as JSON under `cache://tailwind4/scan/` keyed on the
file's path, modification time and size. A rebuild only re-reads files that actually
changed, which is why a warm compile is much faster than a cold one.

### If a class is missing from the output

If a utility you use never reaches the output CSS, the class name was not found by
the scanner. That usually means it is generated somewhere the scanner does not look,
for example composed at runtime from fragments, or produced by a plugin that is not
enabled. Two fixes, both the same as real Tailwind:

* Add the file that contains the class to `safelist_files` in the theme contract, or
  add its directory to `sources`.
* Add `@source inline("...")` to your input CSS to force specific classes (brace
  expansion such as `bg-{red,blue}-500` and `@source not inline(...)` blacklisting
  both work). This is the right choice for classes that are never written out in full
  anywhere, for example dynamically assembled color utilities.

## npm parity notes

The plugin is built to match the official Node build selector for selector. On
Typhoon the current build has zero missing selectors compared to the Node CLI.

Use `bin/plugin tailwind4 compile <theme> --diff` to check parity on your own theme
(it needs the theme's `node_modules` present for the reference build). The report
lists two sets:

* **Missing**: selectors in the Node build that the plugin build lacks. This should
  be empty; if it is not, the scanner missed a class (see the section above) or you
  have hit an engine gap worth reporting.
* **Extra**: selectors in the plugin build that the Node build lacks. These are
  harmless. The scanner over-extracts by design, and the compiler occasionally
  accepts a token the official Oxide tokenizer would reject (for example a stray
  arbitrary-property token from a PHP docblock). Extra selectors do not affect
  correctness and add only a few bytes.

The command exits 0 when nothing is missing (extras are tolerated) and 1 when the
Node build contains selectors the plugin build does not.

## Known limitations

* **GPM theme updates overwrite the compiled output.** The default output path lives
  inside the theme directory (`build/css/site.css`), so updating the theme through
  GPM replaces it with whatever the theme shipped. Recompile after a theme update
  (the Compile CSS button or the CLI) to regenerate it. An automatic post-update
  recompile hook is a later enhancement, not part of this release.

* **The engine is pinned and forked on purpose.** `tailwindphp/tailwindphp` is
  consumed from the [trilbymedia fork](https://github.com/trilbymedia/tailwindphp)
  at an exact commit on its `trilby` branch (v1.4.2 plus fixes), and shipped
  vendored. This keeps Tailwind's behavior stable across `composer update` and makes
  every Tailwind upgrade a deliberate, tested plugin release.

* **Engine fixes carried by the fork.** The `trilby` branch fixes two stock-engine
  bugs, both submitted upstream as individual PRs
  ([#4](https://github.com/inline0/tailwindphp/pull/4),
  [#5](https://github.com/inline0/tailwindphp/pull/5)): a rule whose `@apply`
  expanded to more than one declaration silently dropped its nested child rules
  (this would break Typhoon's breadcrumb, form-label and nav-indent styling), and
  the `container` utility was missing entirely. The plugin's `container_fix` option
  remains available as a fallback for stock engines and defaults to off.

* **Dynamic classes need safelisting, exactly as in real Tailwind.** A class name
  that is never written out in full anywhere the scanner looks will not be generated.
  Use `safelist_files`, an added `sources` entry, or `@source inline(...)`, the same
  as you would with the official Tailwind CLI.

## License

MIT. Copyright (c) 2026 Andy Miller / Trilby Media, LLC. See [LICENSE](LICENSE).
