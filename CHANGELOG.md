# v0.1.0
## 07/07/2026

1. [](#new)
   * Initial scaffold of the Tailwind4 plugin.
1. [](#improved)
   * Hardened the build pipeline: concurrent builds now serialize on a per-theme
     `flock` (a double-clicked Compile button can no longer interleave writes),
     and a failed compile leaves the previous output byte-identical, cleans up
     its temp file, and keeps the last successful build in the manifest so the
     admin report never loses the output path or stats.
   * Verified the test suite under PHP 8.2 and added a large-site performance
     guard (500-page tree: cold compile well under 2s, warm under 500ms,
     compile peak under 64MB).
1. [](#bugfix)
   * Patched the vendored TailwindPHP engine (via `cweagans/composer-patches`,
     `patches/tailwindphp-nested-apply.patch`) to fix a bug where a rule whose
     `@apply` expands to more than one declaration silently dropped its nested
     child rules. This restored Typhoon's breadcrumb, form-label and nav-indent
     styling and brought the parity diff to zero missing selectors.
