# FeichtMedia ImageManager ACF – Developer Notes

Internal reference for developers. For the full specification and AI-agent context see [AGENTS.md](AGENTS.md).

---

## Setup

1. Install and activate ACF (free or PRO).
2. Go to **Settings → FeichtMedia ImageManager** and enter the API key and project settings.
3. The REST proxy and file-browser modal activate automatically once an API key is saved.

Optional integrations activate automatically when present:
- **WPGraphQL + WPGraphQL for ACF v2.x** – the GraphQL field type registers itself when `register_graphql_acf_field_type()` is available.

---

## File overview

| File / Directory | Purpose |
|---|---|
| `feichtmedia-imagemanager-acf.php` | Bootstrap, constants, activation hook |
| `uninstall.php` | Reference-counted cleanup |
| `includes/shared/imagemanager-core/bootstrap.php` | Version-negotiated boot (highest bundled version wins) |
| `includes/shared/imagemanager-core/class-imagemanager-core.php` | Shared options page, option registration, consumer registry |
| `includes/class-settings.php` | Plugin-specific settings section on shared options page |
| `includes/class-rest-proxy.php` | WP REST proxy to ImageManager API |
| `includes/class-acf-field-image.php` | ACF field type `imagemanager_image` |
| `includes/class-graphql.php` | WPGraphQL for ACF v2.x integration (optional) |
| `includes/helpers.php` | Value parser, URL builder, API mapper, metadata fetch (Transient-cached) |
| `assets/js/acf-imagemanager-field.js` | File-browser modal and field UI |
| `assets/css/acf-imagemanager-field.css` | Field and modal styles (WP 7 admin) |
| `languages/` | `.pot` + `.po`/`.mo` per locale (`de_DE`, `de_DE_formal`, `de_AT`, `de_CH`, `en_GB`) |
| `package.json` | npm script: `compile-languages` → `wp i18n make-mo` |
| `.distignore` | Excludes for WordPress.org SVN deployment |
| `.github/workflows/release.yml` | Automated release pipeline |

---

## Constants

| Constant | Value |
|---|---|
| `FM_IMAGEMANAGER_ACF_VERSION` | `'1.0.0'` (bump on every release) |
| `FM_IMAGEMANAGER_ACF_PATH` | `plugin_dir_path(__FILE__)` |
| `FM_IMAGEMANAGER_ACF_URL` | `plugin_dir_url(__FILE__)` |
| `FM_IMAGEMANAGER_API_URL` | `'https://imagemanager.feicht-media.de/api/v2'` |
| `FM_IMAGEMANAGER_DASHBOARD_URL` | `'https://imagemanager.feicht-media.de'` |

---

## Bootstrap order

```
plugins_loaded priority 5  → imagemanager-core boots (highest bundled version wins)
plugins_loaded priority 10 → this plugin initialises:
    1. ACF present? No → show admin notice, return early.
    2. load_plugin_textdomain()
    3. require helpers.php + class-acf-field-image.php → register on acf/include_field_types
    4. FM_ImageManager_Settings::register() (always)
    5. api_key option set? → FM_ImageManager_REST_Proxy::register()
    6. register_graphql_acf_field_type() exists? → FM_ImageManager_GraphQL::register()
```

---

## REST proxy

Namespace: `feichtmedia/imagemanager/v2`  
All routes are GET-only and require `edit_posts` capability.

| WP REST route | Upstream |
|---|---|
| `/images` | `/api/v2/images` |
| `/images/{imageId}` | `/api/v2/images/{imageId}` |
| `/categories` | `/api/v2/categories` |
| `/categories/{categoryId}` | `/api/v2/categories/{categoryId}` |

The API key is injected server-side from `wp_options` and never sent to the browser.  
Only whitelisted query params are forwarded upstream (see `FM_ImageManager_REST_Proxy::PARAM_WHITELIST`). Timeout: 15 s.

---

## ACF field type

- **Type key:** `imagemanager_image`
- **Class:** `FM_ImageManager_ACF_Field_Image` (`includes/class-acf-field-image.php`)
- **Field settings:** `return_format` (`relative_url` | `absolute_url` | `metadata`), `required`, `allow_null`
- **Stored value:** image ID (`newFilename`) only — never a full URL
- **Backward compat:** values containing `/` are legacy relative URLs; the regex extracts the last two path segments as `groupId`/`imageId`, handling filter-prefix variants too

---

## WPGraphQL integration

Requires **WPGraphQL for ACF v2.x** (`wpgraphql-acf`). The v0.x legacy API is not used.

| return_format | GraphQL type |
|---|---|
| `relative_url` | `String` |
| `absolute_url` | `String` |
| `metadata` | `ImageManagerImage` (custom object type) |

`ImageManagerImage` fields: `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, `filesize`.

---

## i18n

- Source language: **en_US** (all msgids in US English).
- PHP strings use `__()`, `esc_html__()`, `_e()` etc. with text domain `feichtmedia-imagemanager-acf`.
- **No JS i18n pipeline.** All UI strings are translated in PHP and passed to JS via `wp_localize_script` as `window.fmImageManager.strings`. `wp_set_script_translations()` is not used.
- Supported locales: `en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`. Any other locale falls back to en_US automatically.

---

## Release

Releases are fully automated via `.github/workflows/release.yml`. Push a tag `v1.2.3` to trigger:

1. PHP syntax check (`php -l` on all PHP files)
2. Version consistency check — aborts if the tag does not match all three script-verified locations: `Version:` plugin header, `FM_IMAGEMANAGER_ACF_VERSION` constant, `Stable tag:` in `readme.txt`. (Keep `CHANGELOG.md` in sync manually as a fourth location by convention.)
3. Language file compilation (`npm run compile-languages` → `wp i18n make-mo`)
4. GitHub release ZIP (dev-only files excluded via rsync: `.git`, `.github`, `.claude`, `node_modules`, `package.json`, `AGENTS.md`, `CLAUDE.md`)
5. GitHub release asset upload
6. WordPress.org SVN deployment via `10up/action-wordpress-plugin-deploy` (excludes per `.distignore`)

Steps 5 and 6 are skipped on `workflow_dispatch` runs; they only fire on tag pushes.

**Required GitHub Secrets** (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `SVN_USERNAME` | Your wordpress.org username |
| `SVN_PASSWORD` | Your wordpress.org password (or application password) |

---

## Known issues

### HTTP/2 not usable via `wp_remote_get()`

**Symptom:** Setting `CURLOPT_HTTP_VERSION` to `CURL_HTTP_VERSION_2_0` via the `http_api_curl` action hook causes `wp_remote_get()` to return a `WP_Error` with the message _"Response could not be parsed"_.

**Root cause:** WordPress 7.0 routes all HTTP requests through `WpOrg\Requests\Requests::request()`. The Requests library's cURL transport explicitly locks the connection to HTTP/1.1 and then parses the raw response headers with a regex that only matches `HTTP/1.x` status lines (`#^HTTP/(1\.\d)[ \t]+(\d+)#i`, see `wp-includes/Requests/src/Requests.php:753`). When the hook overrides the version to HTTP/2, the upstream server responds with an `HTTP/2 200` status line, which the regex does not match → exception.

**Status:** Not fixable within the plugin without patching WordPress core. HTTP/1.1 is used for all upstream requests.

### Using local date format and no specific German format

This ensures in JavaScript that the date value in an image's meta data is always formatted to the user's locale. We do not use a specific German date format, because the plugin can be used in various German-speaking countries with different date formats.
