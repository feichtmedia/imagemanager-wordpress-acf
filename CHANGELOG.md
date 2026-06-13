# Changelog – FeichtMedia ImageManager ACF

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