# v1.0.4
## 07/17/2026

1. [](#bugfix)
    * Fixed shortcodes with a colon (such as `[figure caption="Source: Archives"]`) breaking a page's compiled CSS, which knocked out dark mode and responsive styles.

# v1.0.3
## 07/08/2026

1. [](#bugfix)
    * Updated the vendored Tailwind engine to [`trilbymedia/tailwindphp` 1.4.3](https://github.com/trilbymedia/tailwindphp), which fixes the minifier collapsing the descendant combinator before a pseudo-class. Minified selectors like `.prose :where(h1)` were being rewritten to `.prose:where(h1)`, so every `@tailwindcss/typography` rule (headings, paragraphs, lists, code) matched nothing and prose content rendered unstyled. Typography now compiles correctly.
1. [](#improved)
    * The engine is now referenced by tagged release (`^1.4.3`) instead of a pinned branch commit, so `composer update` picks up future fork fixes without editing the constraint.

# v1.0.2
## 07/08/2026

1. [](#bugfix)
    * Ship the plugin self-contained. `vendor/` (production dependencies, including the vendored Tailwind engine) and `composer.lock` are now committed and bundled in the release package. GPM and zip installs have no `composer install` step, so 1.0.0 and 1.0.1 shipped without the engine and could not compile — installing 1.0.2 works out of the box.

# v1.0.1
## 07/08/2026

1. [](#improved)
    * Updated the vendored Tailwind engine to the renamed [`trilbymedia/tailwindphp`](https://github.com/trilbymedia/tailwindphp) fork (branch `trilby`), now carrying more fixes than the original two: CLI source resolution (relative `@import`, `@source` globs, yaml/md scanning), `theme()` resolution inside arbitrary values, `--spacing(0|1)` shortcuts, a `currentcolor` color-mix polyfill, mask angle-unit normalization, and atomic engine-cache writes. Two fixes are submitted upstream (PRs [#4](https://github.com/inline0/tailwindphp/pull/4)/[#5](https://github.com/inline0/tailwindphp/pull/5)); the rest are maintained in the fork.
    * The engine metadata reader now recognizes both the fork and the upstream package name, so the manifest and CLI report the correct engine identity after the rename.

# v1.0.0
## 07/07/2026

1. [](#new)
   * Initial release: compile Tailwind CSS 4.x for Grav themes directly from PHP,
     with no Node.js, no npm install and no build step. Compilation is on demand
     (admin button, CLI, or config save) and never runs on a front-end request.
   * Compiler wrapper around the vendored `tailwindphp/tailwindphp` engine,
     consumed from the trilbymedia fork (branch `trilby`: v1.4.2 plus two engine
     fixes submitted upstream as PRs #4 and #5) and pinned to an exact commit.
   * Scanner: an Oxide-style tokenizer that extracts class candidates from Twig,
     Markdown, YAML, PHP and HTML, with a per-file `mtime`+`size` cache under
     `cache://tailwind4/scan` so unchanged files are free on a rebuild.
   * Source resolver that reproduces a theme's full scan set (theme dir, pages,
     config, and every enabled plugin's templates) from the theme contract.
   * Build service with atomic output writes and a persisted build manifest
     (`user-data://tailwind4/<theme>.json`: timings, file/candidate/cache counts,
     output size, input hash, engine version, success or error).
   * CLI command `bin/plugin tailwind4 compile [theme]` with `--watch` (poll and
     recompile on change) and `--diff` (compare selectors against the official
     Node CLI build via the bundled parity harness).
   * Admin Next integration: a "Compile CSS" menubar button with a success or
     error toast, and a report page showing the last build's stats. Backed by
     `POST /api/v1/tailwind4/compile` and `GET /api/v1/tailwind4/status`, gated to
     theme administrators and super admins.
   * Theme contract read from the theme's own yaml (`tailwind4:` block with
     `input`, `output`, `sources`, `safelist_files`), with defaults so a theme
     following the standard layout compiles with no contract at all.
   * Optional `auto_compile_on_save` config to recompile when the active theme's
     configuration is saved (off by default).
1. [](#improved)
   * Hardened the build pipeline: concurrent builds serialize on a per-theme
     `flock` (a double-clicked Compile button can no longer interleave writes),
     and a failed compile leaves the previous output byte-identical, cleans up
     its temp file, and keeps the last successful build in the manifest so the
     admin report never loses the output path or stats.
   * Verified the test suite under PHP 8.2 and 8.4 and added a large-site
     performance guard (500-page tree: cold compile well under 2s, warm under
     500ms, compile peak under 64MB).
1. [](#bugfix)
   * Fixed (in the engine fork, upstream PR #4) a bug where a rule whose
     `@apply` expands to more than one declaration silently dropped its nested
     child rules. This restored Typhoon's breadcrumb, form-label and nav-indent
     styling and brought the parity diff to zero missing selectors.
   * Fixed (in the engine fork, upstream PR #5) the missing `container` utility;
     the plugin's `container_fix` injection is now an off-by-default fallback for
     stock engines.
