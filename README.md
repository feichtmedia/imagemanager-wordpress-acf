# FeichtMedia ImageManager for Advanced Custom Fields – Developer Notes

Internal reference for developers. For the full specification and AI-agent context see [AGENTS.md](AGENTS.md).

---

## Setup

1. Install and activate ACF (free or PRO).
2. Go to **Settings → FeichtMedia ImageManager** and enter the API key and project settings. On multisite, the same settings can be entered for all sites under **Network Admin → Settings → FeichtMedia ImageManager** (see [Multisite](#multisite)).
3. The REST proxy and file-browser modal activate automatically once an API key is saved (site or network).

Optional integrations activate automatically when present:

- **WPGraphQL + WPGraphQL for ACF v2.x** – the GraphQL field type registers itself when `register_graphql_acf_field_type()` is available.

---

## File overview

| File / Directory                                                | Purpose                                                                              |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `feichtmedia-imagemanager-acf.php`                              | Bootstrap, constants, activation hook                                                |
| `uninstall.php`                                                 | Reference-counted cleanup (per site on multisite)                                    |
| `includes/shared/imagemanager-core/bootstrap.php`               | Version-negotiated boot (highest bundled version wins), consumer sync                |
| `includes/shared/imagemanager-core/class-imagemanager-core.php` | Shared + network options pages, option registration, setting scope resolution        |
| `includes/class-settings.php`                                   | Plugin-specific settings section (cache options, "Clear metadata cache" button)      |
| `includes/class-rest-proxy.php`                                 | WP REST proxy to ImageManager API                                                    |
| `includes/class-acf-field-image.php`                            | ACF field type `imagemanager_image`                                                  |
| `includes/class-graphql.php`                                    | WPGraphQL for ACF v2.x integration (optional)                                        |
| `includes/helpers.php`                                          | Value parser, URL builder, API mapper, metadata fetch + cache (key, TTL, flush)      |
| `assets/js/acf-imagemanager-field.js`                           | File-browser modal and field UI                                                      |
| `assets/css/acf-imagemanager-field.css`                         | Field and modal styles (WP 7 admin)                                                  |
| `languages/`                                                    | `.pot` + `.po`/`.mo` per locale (`de_DE`, `de_DE_formal`, `de_AT`, `de_CH`, `en_GB`) |
| `package.json`                                                  | npm script: `compile-languages` → `wp i18n make-mo`                                  |
| `.distignore`                                                   | Excludes for WordPress.org SVN deployment                                            |
| `.github/workflows/release.yml`                                 | Automated release pipeline                                                           |

---

## Constants

| Constant                        | Value                                           |
| ------------------------------- | ----------------------------------------------- |
| `FM_IMAGEMANAGER_ACF_VERSION`   | `'1.3.0'` (bump on every release)               |
| `FM_IMAGEMANAGER_ACF_PATH`      | `plugin_dir_path(__FILE__)`                     |
| `FM_IMAGEMANAGER_ACF_URL`       | `plugin_dir_url(__FILE__)`                      |
| `FM_IMAGEMANAGER_API_URL`       | `'https://imagemanager.feicht-media.de/api/v2'` |
| `FM_IMAGEMANAGER_DASHBOARD_URL` | `'https://imagemanager.feicht-media.de'`        |

---

## Bootstrap order

```
plugins_loaded priority 5  → imagemanager-core boots (highest bundled version wins)
plugins_loaded priority 10 → this plugin initialises:
    0. Add plugin_basename() to $GLOBALS['fm_imagemanager_consumer_candidates'] (before the ACF check)
    1. Register add_action('init', …, 1) closure that calls load_plugin_textdomain() (deferred, before the ACF check)
    2. ACF present? No → show admin notice, return early.
    3. require helpers.php + class-acf-field-image.php → register on acf/include_field_types
    4. FM_ImageManager_Settings::register() (always)
    5. FM_ImageManager_Core::get_setting('…_api_key') set? → FM_ImageManager_REST_Proxy::register()
    6. register_graphql_acf_field_type() exists? → FM_ImageManager_GraphQL::register()
plugins_loaded priority 20 → Core: write lock on all managed options
                           → fm_imagemanager_sync_consumers(): multisite only, registers all candidates in this site's registry
```

---

## REST proxy

Namespace: `feichtmedia/imagemanager/v2`  
All routes are GET-only and require `edit_posts` capability.

| WP REST route              | Upstream                          |
| -------------------------- | --------------------------------- |
| `/images`                  | `/api/v2/images`                  |
| `/images/{imageId}`        | `/api/v2/images/{imageId}`        |
| `/categories`              | `/api/v2/categories`              |
| `/categories/{categoryId}` | `/api/v2/categories/{categoryId}` |

The API key is injected server-side (resolved via `FM_ImageManager_Core::get_setting()`) and never sent to the browser.  
Only whitelisted query params are forwarded upstream (see `FM_ImageManager_REST_Proxy::PARAM_WHITELIST`). Timeout: 15 s.

---

## ACF field type

- **Type key:** `imagemanager_image`
- **Class:** `FM_ImageManager_ACF_Field_Image` (`includes/class-acf-field-image.php`)
- **Field settings:** `return_format` (`relative_url` | `absolute_url` | `metadata`), `required` (built-in), `allow_null` (rendered on the **Validation** tab via `render_field_validation_settings()`)
- **Stored value:** image ID (`newFilename`) only — never a full URL
- **Backward compat:** values containing `/` are legacy relative URLs; the regex extracts the last two path segments as `groupId`/`imageId`, handling filter-prefix variants too

### Why keep `allow_null` next to `required`?

At first glance `allow_null` looks redundant with `required` — a required field cannot be empty, so "allow empty" seems to just be the inverse of "required". They are **not** the inverse, though, and we deliberately keep both:

- `required` governs whether a value **must** be set to save the post.
- `allow_null` governs whether an **already-selected** image may be **removed** again (it controls the "Remove" button).

The case that needs both is a **non-required field whose value, once chosen, must not be cleared again** (for whatever editorial reason). That is `required = false` + `allow_null = false` — impossible to express with `required` alone.

To prevent the two from contradicting each other, **`required` always wins**: a required field never shows the "Remove" button regardless of `allow_null` (see the `empty($field['required']) && ! empty($field['allow_null'])` guard in `render_field()`). `allow_null` therefore only has an effect on non-required fields.

---

## Metadata cache

Only the `metadata` return format is cached (Transients). All cache functions live in `includes/helpers.php`:

- **Key:** `feichtmedia_imagemanager_acf_meta_{md5(salt . imageId)}`. The salt is a per-site option (`feichtmedia_imagemanager_acf_cache_salt`, not a setting) and is rotated on every flush, which invalidates entries in every cache backend — including a persistent object cache (Redis/Memcached) that a `$wpdb` query cannot reach.
- **TTL:** `feichtmedia_imagemanager_acf_cache_ttl` (default `3600`), capped at 30 days. `0` maps to the cap — never pass `0` to `set_transient()` (autoloaded, never purged) and never exceed 30 days (Memcached reads it as a Unix timestamp).
- **Flush:** `feichtmedia_imagemanager_flush_metadata_cache()` rotates the salt and deletes the transient rows. It runs automatically when project ID, domain, or the cache options change, and via the "Clear metadata cache" button (site page: current site; network page: all sites).

---

## Multisite

- **Never call `get_option()` on a settings value** — always use `FM_ImageManager_Core::get_setting()`. On multisite, network values (site options under the same names) act as a fallback for sites without their own value; when "Enforce configuration network-wide" is on, the network values always win.
- **Network settings page:** Network Admin → Settings → FeichtMedia ImageManager. Renders all sections of the site settings page plus the enforce switch.
- **Managed options:** Core's `managed_options()` (filter `fm_imagemanager_managed_options`) — this plugin adds its cache options there, so they are network-scoped, write-locked while enforced, and read-only on site pages.
- **Uninstall** runs the cleanup on every site via `switch_to_blog()`; shared network options are only deleted once no site has a consumer left.

---

## WPGraphQL integration

Requires **WPGraphQL for ACF v2.x** (`wpgraphql-acf`). The v0.x legacy API is not used.

| return_format  | GraphQL type                             |
| -------------- | ---------------------------------------- |
| `relative_url` | `String`                                 |
| `absolute_url` | `String`                                 |
| `metadata`     | `ImageManagerImage` (custom object type) |

`ImageManagerImage` fields: `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, `filesize`.

---

## i18n

- Source language: **en_US** (all msgids in US English).
- PHP strings use `__()`, `esc_html__()`, `_e()` etc. with text domain `feichtmedia-imagemanager-acf`.
- **No JS i18n pipeline.** All UI strings are translated in PHP and passed to JS via `wp_localize_script` as `window.fmImageManager.strings`. `wp_set_script_translations()` is not used.
- Supported locales: `en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`. Any other locale falls back to en_US automatically.
- `load_plugin_textdomain()` runs on `init` (priority 1), not on `plugins_loaded` — avoids WordPress's "translation loading triggered too early" notice while still loading before ACF registers field types (`init:5`).

---

## Release

Releases are fully automated via `.github/workflows/release.yml`. No manual steps are needed on GitHub after the tag is pushed.

### Pre-release checklist

Update all four version locations to the new version number before tagging:

| Location                           | Field                                                    |
| ---------------------------------- | -------------------------------------------------------- |
| `feichtmedia-imagemanager-acf.php` | `Version:` plugin header                                 |
| `feichtmedia-imagemanager-acf.php` | `FM_IMAGEMANAGER_ACF_VERSION` constant                   |
| `readme.txt`                       | `Stable tag:`                                            |
| `CHANGELOG.md`                     | Add `## [X.Y.Z] – YYYY-MM-DD` section with release notes |

The workflow verifies the first three automatically and aborts if they don't match the tag. The `CHANGELOG.md` section is used as the GitHub release description.

### Trigger a release

```bash
git tag v1.2.3
git push origin v1.2.3
```

### What the workflow does

1. PHP syntax check (`php -l` on all PHP files)
2. Version consistency check across plugin header, constant, and `readme.txt`
3. Language file compilation (`npm run compile-languages` → WP-CLI `make-mo`)
4. Release ZIP — files excluded per `.distignore`
5. GitHub release — body auto-populated from the matching `## [X.Y.Z]` section in `CHANGELOG.md`
6. WordPress.org SVN deployment via `10up/action-wordpress-plugin-deploy`

Steps 5 and 6 only fire on tag pushes; `workflow_dispatch` skips them.

**Required GitHub Secrets** (Settings → Secrets and variables → Actions):

| Secret         | Value                                                 |
| -------------- | ----------------------------------------------------- |
| `SVN_USERNAME` | Your wordpress.org username                           |
| `SVN_PASSWORD` | Your wordpress.org password (or application password) |

---

## Known issues

### HTTP/2 not usable via `wp_remote_get()`

**Symptom:** Setting `CURLOPT_HTTP_VERSION` to `CURL_HTTP_VERSION_2_0` via the `http_api_curl` action hook causes `wp_remote_get()` to return a `WP_Error` with the message _"Response could not be parsed"_.

**Root cause:** WordPress 7.0 routes all HTTP requests through `WpOrg\Requests\Requests::request()`. The Requests library's cURL transport explicitly locks the connection to HTTP/1.1 and then parses the raw response headers with a regex that only matches `HTTP/1.x` status lines (`#^HTTP/(1\.\d)[ \t]+(\d+)#i`, see `wp-includes/Requests/src/Requests.php:753`). When the hook overrides the version to HTTP/2, the upstream server responds with an `HTTP/2 200` status line, which the regex does not match → exception.

**Status:** Not fixable within the plugin without patching WordPress core. HTTP/1.1 is used for all upstream requests.

### Using local date format and no specific German format

This ensures in JavaScript that the date value in an image's meta data is always formatted to the user's locale. We do not use a specific German date format, because the plugin can be used in various German-speaking countries with different date formats.
