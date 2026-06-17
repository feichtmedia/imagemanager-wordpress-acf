# Changelog – FeichtMedia ImageManager for Advanced Custom Fields

## [1.1.0] – 2026-06-17

Addresses feedback from the WordPress.org Plugin Review team (trademark, prefixing, sanitization, translation loading timing).

- Updated: Plugin display name to "FeichtMedia ImageManager for Advanced Custom Fields" (WordPress.org trademark policy on third-party product names). The plugin slug, text domain, and all shared/cross-plugin identifiers (`feichtmedia_imagemanager_*` options, `FM_ImageManager_*` classes) are unchanged.
  - `feichtmedia-imagemanager-acf.php` → `Plugin Name:` header
  - `readme.txt` → plugin title header
  - Admin notice text in `feichtmedia_imagemanager_acf_missing_notice()`
- Updated: `load_plugin_textdomain()` is now called inside an `add_action('init', …, 1)` callback instead of directly during `plugins_loaded` (priority 10). Avoids WordPress's "translation loading triggered too early" `_doing_it_wrong()` notice; priority 1 keeps it ahead of ACF's own `init:5` field-type registration so translated field labels still resolve correctly.
- Updated: `FM_ImageManager_Core::sanitize_domain()` (shared Core component) now validates the CDN domain against real hostname syntax (RFC 1123 labels, or a raw IP) and rejects invalid input (paths, query strings, credentials, ports, malformed labels) instead of saving it. On rejection, the previously stored value is kept and a `settings_errors()` notice is shown on the options page.
- Updated: CDN domain validation error message changed from "The previous value was kept." to "Your change was not saved." — removes technical jargon and makes the outcome immediately clear to end users without context.
- Updated: `.github/workflows/release.yml` — release ZIP renamed from `feichtmedia-imagemanager-acf-{version}.zip` to `feichtmedia-imagemanager-acf.zip` (predictable, version-agnostic asset name); added `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true` on job level to resolve Node.js 20 deprecation warnings from `actions/checkout@v4`, `actions/setup-node@v4`, and `softprops/action-gh-release@v2`.
- Fixed: Renamed `fm_imagemanager_acf_missing_notice()` to `feichtmedia_imagemanager_acf_missing_notice()` — this plugin-specific function did not follow the documented `feichtmedia_imagemanager_{name}()` helper-function naming convention (`WordPress.NamingConventions.PrefixAllGlobals`).
- Fixed: Metadata transient cache key prefix changed from `fm_img_meta_` to `feichtmedia_imagemanager_acf_meta_` in `helpers.php`, and the `uninstall.php` cleanup query updated to match. This also resolves the long-standing prefix mismatch (`fm_img_meta_` vs. `fm_im_meta_`) that caused `uninstall.php` to silently skip deleting these transients.
- Added: `== External Services ==` section to `readme.txt` (WordPress.org requirement). Documents that the plugin connects server-side to the FeichtMedia ImageManager API; lists all transmitted data (API key, project ID, image ID, WordPress site URL via User-Agent, WordPress server IP address); clarifies that no visitor IPs or post content are transmitted; links to Terms of Service and Privacy Policy.

### Core

- Bumped Core component version `1.0.0` → `1.1.0` in `bootstrap.php` (`class-imagemanager-core.php` changed: hardened `sanitize_domain()`, added `settings_errors()` call in `render_options_page()`).

## [1.0.2] – 2026-06-14

- Updated: Excluded the `CHANGELOG.md` from the WordPress.org SVN deployment via `.distignore` so it is not publicly available on the plugin directory. This changelog is only for development.

## [1.0.1] – 2026-06-14

- Updated: `AGENTS.md` – split versioning section into Plugin Version and Core Version; clarifies when and where each is bumped and that Core changes are documented as `### Core` subsections in `CHANGELOG.md`.
- Updated: `readme.txt` – fully translated to English (WordPress.org Plugin Directory requirement since July 2025); reduced tags from 7 to 5 (WordPress.org limit); added note to the Changelog section that only plugin-level changes are listed there.
- Updated: `includes/shared/imagemanager-core/bootstrap.php` – added comment to the Core version constant clarifying its independence from the plugin version.
- Updated: `.github/workflows/release.yml` – release ZIP now uses `--exclude-from=.distignore` instead of hardcoded rsync excludes; GitHub release body is now automatically extracted from the matching `## [X.Y.Z]` section in `CHANGELOG.md` and set via `body_path`; added `make_latest: true`.
- Updated: `README.md` – Release section rewritten: pre-release version checklist, single `git tag` + `git push` command to trigger the pipeline, step-by-step description of the automated workflow.
- Fixed: Removed Plugin URI from the plugin header as it was identical to the Author URI (WordPress.org plugin directory requirement).
- Fixed: `includes/class-acf-field-image.php` L131 – wrapped `$img_src` in `esc_url()` (`WordPress.Security.EscapeOutput`).
- Fixed: `includes/class-acf-field-image.php` L132 – wrapped `$img_alt` in `esc_attr()` (`WordPress.Security.EscapeOutput`).
- Fixed: `uninstall.php` – renamed local variable `$consumers` to `$fm_imagemanager_consumers` (`WordPress.NamingConventions.PrefixAllGlobals`).
- Fixed: `.distignore` – added `.distignore` itself to the exclusion list so the file is not deployed to WordPress.org SVN.
- Fixed: `.github/workflows/release.yml` – corrected `10up/action-wordpress-plugin-deploy` reference from `@v2` to `@2.3.0`; the action does not use `v`-prefixed tags.

## [1.0.0] – 2026-06-13

Initial release of the FeichtMedia ImageManager ACF field type.

- Added: `.github/workflows/release.yml` – GitHub Actions release workflow: PHP syntax check, version consistency check across all four version locations, language file compilation via WP-CLI, GitHub release ZIP, and WordPress.org SVN deployment via `10up/action-wordpress-plugin-deploy`
- Added: `.distignore` for WordPress.org deployment (excludes `.git`, `.github`, `.claude`, `node_modules`, `package.json`, `AGENTS.md`, `CLAUDE.md`, `README.md`)
- Added: `CONECPT.md` with the complete technical specification for this plugin (the source of truth for all development). Some sections and specs may be out of date with the current implementation; always refer to this document for the intended design and behaviour.
- Added: `LICENSE` file with GPL-2.0-or-later License text from GitHub template
- Added: `AGENTS.md` and `CLAUDE.md` with AI agent context and instructions for using Claude to maintain this plugin
- Added: `imagemanager_image` ACF custom field type with native file-browser modal (no iFrame)
- Added: Shared `FM_ImageManager_Core` component with version-negotiated boot (`includes/shared/imagemanager-core/`)
- Added: Global settings page under Settings → FeichtMedia ImageManager (shared across all FM ImageManager plugins)
- Added: Consumer-registry reference-counting for safe shared-option cleanup on uninstall
- Added: WP REST API proxy (`includes/class-rest-proxy.php`) for `/images`, `/images/{id}`, `/categories`, `/categories/{id}` — API key stays server-side
- Added: `includes/helpers.php` with value parser (current + legacy formats), URL builder, API-to-canonical mapper, and Transient-cached metadata fetch
- Added: Return format `relative_url` (default, zero HTTP)
- Added: Return format `absolute_url` (zero HTTP)
- Added: Return format `metadata` (API call via helper + Transient cache, TTL 1 h)
- Added: Thumbnail preview via CDN (`<img>` with `onerror` fallback)
- Added: Single-select file browser modal: category tiles + breadcrumbs, image grid, project-wide search, pagination (Load more), info link per image to ImageManager dashboard
- Added: Backward compatibility for legacy relative URL values (including filter-prefix variants); last two path segments extracted as groupId/imageId
- Added: `update_value()` normalisation — always stores bare image ID, never a URL
- Added: WPGraphQL integration (`includes/class-graphql.php`): `String` for URL formats, `ImageManagerImage` object type for metadata format
- Added: `uninstall.php` with reference-counted cleanup (`fm_img_meta_*` transients always removed; shared options only removed when last consumer uninstalls)
- Added: Metadata cache settings in the ACF Field section of the shared options page — "Metadata Cache" checkbox to enable/disable transient caching and "Cache TTL" number field for the cache duration in seconds (default: 3600)
- Added: `feichtmedia_imagemanager_acf_cache_enabled` and `feichtmedia_imagemanager_acf_cache_ttl` options; both removed on uninstall; TTL replaces the previously hardcoded `HOUR_IN_SECONDS` in `feichtmedia_imagemanager_get_metadata()`
- Added: ACF-missing admin notice
- Added: Incomplete-settings admin notice with link to settings page
- Added: Field-level configuration-incomplete notice
- Added: Internationalisation: `.pot` template + `en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH` `.po` files; all JS strings translated in PHP and passed via `wp_localize_script`
- Added: `assets/js/acf-imagemanager-field.js` — single modal DOM node (lazy init), one `activeFieldKey` state variable, `wp.apiFetch` REST calls
- Added: `assets/css/acf-imagemanager-field.css` — field UI and modal styled for WP 7 admin
- Added: `README.md` with developer notes and internal documentation
- Added: WCAG 2.2 accessibility
