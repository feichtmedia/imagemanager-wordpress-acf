# Changelog – FeichtMedia ImageManager for Advanced Custom Fields

## [1.2.0] – 2026-06-24

- Added: Plugin icons to `./assets/` for displaying in the WordPress.org plugin directory and in the WP admin plugin list. Icons are 128x128 PNG (normal use), 256x256 PNG (high-DPI), and a responsive SVG.
- Updated: Moved the "Allow Null" field setting from the General tab to the Validation tab, next to ACF's built-in "Required" setting, since both govern whether the field may be left empty. The setting is now rendered via a new `render_field_validation_settings()` method on `FM_ImageManager_ACF_Field_Image` (ACF 6+ renders the "Required" toggle into the same tab automatically) and removed from `render_field_settings()` (General tab). A clarifying `instructions` line was added stating that the option is ignored when the field is required.
- Updated: Adjusted the runtime interplay of "Required" and "Allow Null" so they can no longer contradict each other. "Required" now always takes precedence: the "Remove" button in `render_field()` is only rendered when the field is **not** required **and** "Allow Null" is enabled (the condition changed from `! empty($field['allow_null'])` to `empty($field['required']) && ! empty($field['allow_null'])`). A required field therefore never exposes a way to clear its value, while a non-required field can still be locked against removal by disabling "Allow Null".
- Updated: `AGENTS.md` file with additional context to new directories in the plugin.
- Updated: Opening the modal for a pre-selected image now avoids the second `/categories/{id}` request whenever possible. `resolveImageCategory` inspects the category embedded in the `/images/{id}?includeCategory=true` response: if it already carries a `path` array, or if it has no `parentCategory` (top-level), the breadcrumb path is built directly from that data. The `/categories/{id}?includePath=true` round-trip now only fires when the category has a parent but no embedded path. Together with the boolean-param fix this collapses the previous per-ancestor request storm down to one or two requests. Shared map/push logic extracted into a `applyCategoryPath` helper.
- Updated styles for the category grid in the modal:
  - Set the `fw-imagemanager-modal-body` class to `container-type: inline-size` so the grid can respond to the modal's width instead of the viewport width.
  - Set the category tiles to a fixed 4 columns grid (with responsive layout).
  - Updated the category name with a `text-overflow: ellipsis` so long names do not wrap and break the grid layout as well as `text-align: left` to align the text on the left (as all other text in the modal).
- Fixed: The skeleton loaders were never shown while the file browser modal loaded its data. The shimmer placeholder styles had shipped in CSS since v1.1.0, but no JavaScript ever rendered them — `openModal()` only emptied the grids and showed the overlay spinner. A new `renderSkeletons(showCats)` helper now fills `.fm-imagemanager-cats-grid` and `.fm-imagemanager-imgs-grid` with placeholder tiles whenever a full page is loading: on modal open (including during the `resolveImageCategory()` round-trip for a pre-selected image) and at the start of every `loadPage()` (category navigation, breadcrumb jumps, search). The category skeletons are inserted as direct children of `.fm-imagemanager-cats-grid`, so they inherit the exact same responsive 4/3/2/1-column layout as the real category tiles (their `border-radius` was bumped from `2px` to `4px` to match `.fm-imagemanager-cat-tile`). While skeletons are visible a new `skeletonsActive` flag keeps `setLoading()` from drawing the overlay spinner and the grid dim — the spinner is now reserved for the "Load more" append, which keeps existing tiles in place. Category skeletons are skipped during search (where the category section stays hidden), and on load failure the skeletons are cleared via a new `clearGrids()` helper before the error message is shown.
- Fixed: Opening the modal for a pre-selected image whose stored value was a legacy relative URL (e.g. `/rsm/<file>.jpg`) produced a 404. The raw value was passed straight into the `/images/{id}` REST route, where `encodeURIComponent` turned the slashes into `%2F…`, which the `[\w.\-]+` route pattern does not match. `acf-imagemanager-field.js` now normalises the field value to a bare image ID (last path segment) via a new `normalizeImageId` helper before using it — mirroring the server-side `feichtmedia_imagemanager_parse_value()` parser. This also fixes the grid highlight, which previously never matched a legacy value against the API's bare image IDs.
- Fixed: Boolean query parameters (`includeCategory`, `includePath`) sent to the ImageManager API were serialised as `1` instead of `true`. The API expects real boolean values (`true`/`false`) for `include*` flags, so the embedded category/path data was not returned and breadcrumb resolution silently fell back to recursive parent traversal. The active flags in `acf-imagemanager-field.js` now send `true`. Integer filters that legitimately use `0` (`category=0` for uncategorised images, `parentCategory=0` for top-level categories) are unaffected.
- Fixed: File browser button in ACF block fields edited via the Expanded Editor (v3 blocks only) had no effect (no modal appeared). The root cause was that ACF's `ready_field` JavaScript lifecycle event does not fire reliably when block fields are rendered in the block editor context (e.g. iFrame-based block editor in WordPress 7, or ACF's React-based Expanded Editor panel). Switched from per-field event binding in `initField` to global event delegation on `document`, which catches button clicks regardless of how or when the field is initialised.
- Fixed: Selecting or changing an image in any ACF Repeater row other than the first always updated the first row instead of the clicked one. The root cause was that all repeater rows share the same ACF field definition key (`data-field-key`), so `document.querySelector` always matched the first occurrence. The modal now stores a direct DOM reference (`activeFieldEl`) to the exact row element that opened it; all read/write operations use this reference instead of querying by key.
- Fixed: GraphQL resolver returned `null` for `imagemanager_image` fields inside ACF Repeaters. When the field is a Repeater sub-field, WPGraphQL for ACF v2.x passes the already-formatted row data as `$root` (no `databaseId`); the previous resolver called `get_field()` with a `null` source ID, which fell back to the global post context and found no value. The resolver now reads the pre-formatted value directly from `$root` when `source_id` is `null` and the field name exists as a key in the row array.

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
- Added: `assets/js/acf-imagemanager-field.js` — single modal DOM node (lazy init), `wp.apiFetch` REST calls
- Added: `assets/css/acf-imagemanager-field.css` — field UI and modal styled for WP 7 admin
- Added: `README.md` with developer notes and internal documentation
- Added: WCAG 2.2 accessibility
