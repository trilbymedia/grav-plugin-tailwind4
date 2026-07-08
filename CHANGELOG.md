# v0.1.0
## 07/07/2026

1. [](#new)
   * Initial scaffold of the Tailwind4 plugin.
1. [](#bugfix)
   * Patched the vendored TailwindPHP engine (via `cweagans/composer-patches`,
     `patches/tailwindphp-nested-apply.patch`) to fix a bug where a rule whose
     `@apply` expands to more than one declaration silently dropped its nested
     child rules. This restored Typhoon's breadcrumb, form-label and nav-indent
     styling and brought the parity diff to zero missing selectors.
