# AGENTS.md – FeichtMedia ImageManager ACF

This file gives AI coding assistants like Claude Code the context they need to work on this plugin. Read it fully before writing any code.

The complete specification lives in **`CONCEPT.md`** (or the equivalent concept document in this project root). When in doubt, the concept is the source of truth. Ask before deviating from it.

---

## Project overview

A WordPress plugin that registers a custom ACF field type (`imagemanager_image`) for the FeichtMedia ImageManager DAM. Editors pick images through a native WP-admin file browser (no iFrame); the plugin proxies all API requests server-side so the API key never reaches the browser.

- **Plugin slug / text domain:** `feichtmedia-imagemanager-acf`
- **Main file:** `feichtmedia-imagemanager-acf.php`
- **Requires:** PHP 8.2+, WordPress 7.0+, ACF (free or PRO)
- **Optional:** WPGraphQL + WPGraphQL for ACF

---

## Directory structure

```
feichtmedia-imagemanager-acf/
├── feichtmedia-imagemanager-acf.php          ← bootstrap, constants, activation hook
├── uninstall.php                             ← reference-counted cleanup (never on deactivation)
├── CHANGELOG.md
├── readme.txt                                ← WordPress.org format
├── README.md                                 ← developer notes, internal documentation
├── LICENSE                                   ← GPL-2.0-or-later
├── package.json                              ← plugin metadata only (no build pipeline)
├── .distignore                               ← WordPress.org deployment exclusions
├── .github/
│   └── workflows/
│       └── release.yml                       ← PHP syntax check, version consistency check, language compilation, GitHub release ZIP, WP.org SVN deploy
├── includes/
│   ├── shared/
│   │   └── imagemanager-core/               ← IDENTICAL copy in every FM ImageManager plugin
│   │       ├── bootstrap.php                ← version-negotiated boot (highest version wins)
│   │       └── class-imagemanager-core.php  ← shared options, options page, consumer registry
│   ├── class-settings.php                   ← plugin-specific settings section on shared page
│   ├── class-acf-field-image.php            ← ACF field type "imagemanager_image"
│   ├── class-graphql.php                    ← WPGraphQL resolvers (String + ImageManagerImage)
│   ├── class-rest-proxy.php                 ← WP REST proxy to ImageManager API
│   └── helpers.php                          ← stateless: URL builder, value parser, mapper, metadata fetch
├── assets/
│   ├── js/acf-imagemanager-field.js         ← file browser modal, field UI, REST calls
│   └── css/acf-imagemanager-field.css       ← field + modal styling (WP 7 admin)
└── languages/                               ← .pot + .po/.mo per locale (no .json, no JS i18n pipeline)
```

---

## Bootstrap order (mandatory — do not change)

```
plugins_loaded priority 5  → imagemanager-core boots (highest bundled version wins)
plugins_loaded priority 10 → this plugin initialises:
    1. ACF present? No → admin notice fm_imagemanager_acf_missing_notice(), return early.
    2. load_plugin_textdomain()
    3. require helpers.php
    4. require class-acf-field-image.php → register on acf/include_field_types
    5. require class-settings.php → (new FM_ImageManager_Settings())->register()
    6. if api_key option set → require class-rest-proxy.php → FM_ImageManager_REST_Proxy::register()
    7. if register_graphql_acf_field_type() exists → require class-graphql.php → FM_ImageManager_GraphQL::register()
```

**Never** load classes outside this flow. **Never** run cleanup on deactivation — only in `uninstall.php`.

---

## Constants (defined in main plugin file)

| Constant                        | Value                                               | Configurable?   |
| ------------------------------- | --------------------------------------------------- | --------------- |
| `FM_IMAGEMANAGER_ACF_VERSION`   | `'1.0.1'`                                           | bump on release |
| `FM_IMAGEMANAGER_ACF_PATH`      | `plugin_dir_path(__FILE__)`                         | no              |
| `FM_IMAGEMANAGER_ACF_URL`       | `plugin_dir_url(__FILE__)`                          | no              |
| `FM_IMAGEMANAGER_API_URL`       | `'https://imagemanager.feicht-media.de/api/v2'`     | no              |
| `FM_IMAGEMANAGER_DASHBOARD_URL` | `'https://imagemanager.feicht-media.de'`            | no              |

---

## Shared options (owned by imagemanager-core, not this plugin)

| Option key                            | Description                                             |
| ------------------------------------- | ------------------------------------------------------- |
| `feichtmedia_imagemanager_api_key`    | Read-only API token for the ImageManager API            |
| `feichtmedia_imagemanager_project_id` | Usergroup / project ID                                  |
| `feichtmedia_imagemanager_domain`     | CDN domain (no protocol), e.g. `cdn.example.com`        |
| `feichtmedia_imagemanager_consumers`  | Reference-counting registry (array of plugin basenames) |

All are registered, rendered, and sanitised by `FM_ImageManager_Core`. This plugin only reads them.

---

## Plugin-specific options (owned by this plugin)

| Option key                                   | Description                                                        |
| -------------------------------------------- | ------------------------------------------------------------------ |
| `feichtmedia_imagemanager_acf_cache_enabled` | Whether Transient caching is active for metadata (default: `1`)    |
| `feichtmedia_imagemanager_acf_cache_ttl`     | Metadata cache lifetime in seconds (default: `3600`, `0` = no expiry) |

Both are registered and rendered by `FM_ImageManager_Settings` (the "ACF Field" section on the shared options page) and deleted in `uninstall.php`.

---

## Naming conventions

| Type                 | Convention                            | Example                                  |
| -------------------- | ------------------------------------- | ---------------------------------------- |
| PHP class files      | `class-{name}.php`                    | `class-rest-proxy.php`                   |
| PHP class names      | `FM_ImageManager_{Name}` (PascalCase) | `FM_ImageManager_REST_Proxy`             |
| PHP helper functions | `feichtmedia_imagemanager_{name}()`   | `feichtmedia_imagemanager_parse_value()` |
| WP options           | `feichtmedia_imagemanager_{name}`     | `feichtmedia_imagemanager_api_key`       |
| JS / CSS files       | `kebab-case`                          | `acf-imagemanager-field.js`              |
| JS script handles    | `fm-imagemanager-{name}`              | `fm-imagemanager-field`                  |
| CSS class prefix     | `fm-imagemanager-`                    | `.fm-imagemanager-preview`               |
| ACF field type key   | `imagemanager_{type}`                 | `imagemanager_image`                     |

---

## Documentation

- **Language**: All comments and documentation in the `CHANGELOG.md` and `README.md` files must be in English. No exceptions.
- **PHPDoc**: On every function (description, `@param`, `@return`).
- **JSDoc**: On every function except trivial callbacks.
- **Inline comments**: Explain _why_, not _what_. Used for workarounds, non-obvious logic, a11y decisions.

### PHPDoc example

```php
/**
 * Parse a stored field value into its components.
 *
 * Handles both the current format (image ID only) and the legacy format
 * (relative URL: /{groupId}/{imageId} or with filter segments prepended).
 *
 * @param string $value Raw value from post_meta.
 * @return array{format: string, groupId: string, imageId: string}
 */
function feichtmedia_imagemanager_parse_value( string $value ): array { … }
```

### JSDoc example

```javascript
/**
 * Open the file browser modal for a specific ACF field instance.
 * Sets the activeFieldKey so the confirmed selection goes to the right field.
 *
 * @param {string} fieldKey - ACF field key of the triggering field instance.
 * @returns {void}
 */
function openBrowser( fieldKey ) { … }
```

---

## Documentation conventions

### Changelog

All notable changes are tracked in `CHANGELOG.md`. Format:

```markdown
## [x.y.z] – YYYY-MM-DD

- Added: …
- Updated: …
- Fixed: …
- Removed: …
```

Add an entry for every feature, fix, or notable refactor. Group related changes under one version header. The date is the push/release date. If no Git is used, also list all changed files and directories under the version header.

Entries always start with the action verb (Added, Fixed, Updated, Removed, …). Avoid passive voice or vague descriptions. Order within a version:

1. Added
2. Changed / Updated / Moved
3. Fixed
4. Removed

### Versioning

This project has **two independent version numbers**:

**Plugin version** (`MAJOR.MINOR.PATCH`) — tracks the plugin itself. On every release, update all four locations simultaneously:

1. `feichtmedia-imagemanager-acf.php` → `Version:` header
2. `feichtmedia-imagemanager-acf.php` → `FM_IMAGEMANAGER_ACF_VERSION` constant
3. `readme.txt` → `Stable tag:`
4. `CHANGELOG.md` → new version header + entries

**Core component version** — tracks `includes/shared/imagemanager-core/` only. Stored in `bootstrap.php` (`$GLOBALS['fm_imagemanager_core_candidates'][]`). Bump this **only** when `class-imagemanager-core.php` itself changes, and keep it in sync across **all** FeichtMedia ImageManager plugins (the highest bundled version wins at runtime). Core version changes are logged in `CHANGELOG.md` under a separate `### Core` sub-section within the relevant plugin version entry — they are **not** tracked in `readme.txt`.

### Notes on changes

After each change, the `CHANGELOG.md` file must be updated with a new entry describing the change. If no changelog file exisits, create one based on the described structure above.

Also, the `AGENTS.md` file must be reviewed and updated if necessary to reflect the change and ensure that AI coding agents have the most up-to-date information about the codebase. This is crucial for maintaining the productivity of AI coding agents and ensuring they can effectively assist with development tasks.

---

## Code standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) for PHP, JS, and CSS.
- OOP: one class per file, single responsibility. Register all hooks inside the class's `register()` or `init()` method, not at file scope (except the activation hook in the main file).
- No `var_dump`, `print_r`, or `error_log` left in committed code.
- Escape all output: `esc_html__()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` as appropriate. Never echo unescaped user or API data.
- Sanitise all input: `sanitize_text_field()`, `absint()`, etc.
- Use `wp_remote_get()` (never `curl` directly). Always set a `timeout`.
- `class_exists()` / `function_exists()` guards before any optional-dependency code.

---

## i18n rules

- Source language: **en_US** (all msgids written in US English).
- PHP strings: always `__()`, `esc_html__()`, `_e()`, `_n()`, `_x()` with textdomain `feichtmedia-imagemanager-acf` as a **string literal** (never a variable).
- **No JS i18n pipeline.** All UI strings translated in PHP and passed to JS via `wp_localize_script` as `window.fmImageManager.strings`. The JS reads from that object — never calls `wp.i18n.__()`.
- `wp_set_script_translations()` is **not used**.
- `.po`/`.mo` files are the single source of truth for all translations (PHP and JS alike).

Supported locales: `en_GB`, `de_DE`, `de_DE_formal`, `de_AT` (formal/Sie), `de_CH` (formal/Sie, no ß).
Fallback for any other locale: en_US (automatic via gettext — no code needed).

---

## ACF field type: `imagemanager_image`

- Class: `FM_ImageManager_ACF_Field_Image` in `includes/class-acf-field-image.php`
- Extends `acf_field`. Registered via `acf_register_field_type()` on the `acf/include_field_types` action.
- Field settings: `return_format` (relative_url | absolute_url | metadata), `allow_null`.
- `format_value()` handles all three return formats and backward-compat parsing.
- `update_value()` strips any path prefix before saving — only the bare image ID is ever written to `post_meta`.
- ACF Conditional Logic: registers `imagemanagerHasValue` (`!=empty`) and `imagemanagerHasNoValue` (`==empty`) condition types in JS. Only "has a value / has no value" operators are supported (the stored value is an opaque image ID, not a user-comparable string).
- Assets enqueued only on ACF admin screens via `input_admin_enqueue_scripts()`. UI strings are translated in PHP and passed once via `wp_localize_script` as `window.fmImageManager.strings`.

**Stored value:** the image ID (`newFilename`) only — never the full URL.

**Backward compatibility:** values containing `/` are legacy relative URLs. The regex extracts the last two path segments as `groupId` / `imageId` (handles filter-prefix variants too). No data migration needed.

---

## WP REST proxy (`/wp-json/feichtmedia/imagemanager/v2/…`)

- Namespace: `feichtmedia/imagemanager/v2`
- All routes: `GET` only, `permission_callback` → `current_user_can('edit_posts')`.
- Only whitelisted query params forwarded upstream (see class for the lists).
- API key injected server-side from `wp_options` — **never sent to the browser**.
- Timeout: 15 s.

Routes:

| WP REST route              | Upstream                          |
| -------------------------- | --------------------------------- |
| `/images`                  | `/api/v2/images`                  |
| `/images/{imageId}`        | `/api/v2/images/{imageId}`        |
| `/categories`              | `/api/v2/categories`              |
| `/categories/{categoryId}` | `/api/v2/categories/{categoryId}` |

Query params for each route are defined in `FM_ImageManager_REST_Proxy::PARAM_WHITELIST`. Consult the ImageManager API docs during development to confirm exact param names.

---

## File browser (modal)

**One modal per page** — created lazily on first open, then reused. Never rendered per field instance. Implemented as a native `<dialog>` element (`showModal()`) which provides a built-in focus trap.

Behaviour:

- Opens on "Add image" / "Change image" button click; sets `activeFieldKey` and saves `triggerEl` (the clicked button) so focus is restored on close. If the field already has a value, the currently stored image is pre-selected (`preSelectedImageId`) when the browser opens.
- Modal receives focus on open (search input). Tab is trapped within the dialog. Escape closes and returns focus to `triggerEl` (WCAG 2.1.2 / 2.4.3).
- Top section: category tiles (folder cards). Bottom section: image grid for the current category.
- Root level shows sub-categories and **uncategorised images**.
- Clicking a category navigates into it; breadcrumbs update accordingly.
- Search is project-wide (API `search` param), not scoped to current category.
- Pagination via `offset` / `limit`.
- **Single-select toggle:** click selects (border + checkmark overlay); click again deselects.
- "Select" button (bottom-right, disabled until a selection is made) commits the choice, writes the image ID into the field input, triggers preview render, closes the modal.
- Each image tile shows an info icon on hover/focus → opens `FM_IMAGEMANAGER_DASHBOARD_URL + '/overview/edit?id=' + imageId` in a new tab (`target="_blank" rel="noopener"`).

**Image tile structure** (WCAG 4.1.2 — no nested interactive elements):

```html
<div class="fm-imagemanager-img-tile" data-image-id="…">
  <button class="fm-imagemanager-img-select" aria-pressed="true|false" aria-label="…">
    <div class="fm-imagemanager-img-thumb"><img alt="" …><div aria-hidden="true">…</div></div>
    <div class="fm-imagemanager-img-meta">…</div>
  </button>
  <a class="fm-imagemanager-img-info" href="…" target="_blank" rel="noopener" aria-label="… (opens in new window)"><!-- dashicon --></a>
</div>
```

- The tile `<div>` is a layout container only — **not** interactive itself.
- `.fm-imagemanager-img-select` is the toggle button; `aria-pressed` reflects selection state.
- `.fm-imagemanager-img-info` is a sibling `<a>`, positioned absolute over the thumbnail via CSS (`bottom: 52px; right: 6px` relative to the tile). It must never be nested inside the button.
- The tile's `border` changes colour on `:focus-within` — this is the WCAG 2.4.11 focus indicator for the whole tile.

**Live region:** `#fm-imagemanager-live` (`.fm-imagemanager-sr-only`, `aria-live="polite"`) is always in the DOM inside `.fm-imagemanager-modal`. `setLoading(true)` writes the loading string to it; `setLoading(false)` clears it. The visual spinner has `aria-hidden="true"` and no `aria-live`. Do not add `aria-live` to the visual spinner.

Image tile content: thumbnail (CDN), name, date, format, dimensions — matching the ImageManager dashboard layout.

**No API calls on field render.** The preview `<img>` loads directly from the CDN: `https://{domain}/{projectId}/{imageId}`. The `alt` attribute is set to the image ID on PHP render; JS updates it to the real title after a new selection (`updateField()`).

---

## GraphQL (WPGraphQL)

Optional — only loaded if `register_graphql_acf_field_type()` exists (i.e. **WPGraphQL for ACF v2.x** is active).

**Target plugin:** [`wpgraphql-acf`](https://wordpress.org/plugins/wpgraphql-acf/) v2.x — **never** the legacy `wp-graphql-acf` v0.x.
The v0.x API (filter `wpgraphql_acf_register_graphql_field`, filter `wpgraphql_acf_supported_fields`) is **not used** anywhere in this plugin. Do not introduce it.

### v2.x registration API

Register a custom ACF field type handler via `register_graphql_acf_field_type()` (defined in `wpgraphql-acf/access-functions.php`):

```php
register_graphql_acf_field_type(
    'imagemanager_image',  // ACF field type key
    [
        // Return type — callable receives (FieldConfig $fc, AcfGraphQLFieldType $ft)
        'graphql_type' => function (\WPGraphQL\Acf\FieldConfig $field_config): string { … },

        // Resolver — receives ($root, $_args, $_context, $_info, $_field_type, FieldConfig $fc)
        'resolve'      => function ($root, …, \WPGraphQL\Acf\FieldConfig $field_config) { … },
    ]
);
```

- `register_graphql_acf_field_type()` internally hooks into the `wpgraphql/acf/register_field_types` action (fired by `FieldTypeRegistry::__construct()` at `plugins_loaded` priority 50).
- Call it from within `plugins_loaded` priority 10 — the hook is queued and fires later. No timing issue.
- The ACF field config is accessed via `$field_config->get_acf_field()`.

### Return types

| return_format  | GraphQL type                             |
| -------------- | ---------------------------------------- |
| `relative_url` | `String`                                 |
| `absolute_url` | `String`                                 |
| `metadata`     | `ImageManagerImage` (custom object type) |

`ImageManagerImage` fields: `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, `filesize`.

The resolver always calls `get_field()` which runs through `format_value()` — the GraphQL layer adds no extra API calls.

---

## Performance rules (strictly enforced)

1. **One modal DOM node** — guarded against double-init. Never instantiated per field.
2. **Assets enqueued once** — one script handle, one style handle. `wp_localize_script` outputs the config object once.
3. **Zero HTTP calls on field render** — neither in the editor nor on the frontend for URL formats.
4. **Metadata format** — always go through `feichtmedia_imagemanager_get_metadata()` which uses a Transient (key: `fm_img_meta_{md5(imageId)}`, TTL: `feichtmedia_imagemanager_acf_cache_ttl` option, default 3600 s; caching can be disabled via `feichtmedia_imagemanager_acf_cache_enabled`). Never call the API directly inside `format_value()`.
5. **Default return format is `relative_url`** — zero HTTP, pure string construction.

---

## Uninstall / lifecycle

- **Deactivation:** do nothing. No data deleted, no registry changes.
- **Uninstall (`uninstall.php`):**
  1. Remove this plugin's basename from `feichtmedia_imagemanager_consumers`.
  2. If the registry is now empty → delete all shared options (`feichtmedia_imagemanager_api_key`, `feichtmedia_imagemanager_project_id`, `feichtmedia_imagemanager_domain`, `feichtmedia_imagemanager_consumers`).
  3. If other consumers remain → only update the registry, keep shared options.
  4. Always: delete plugin-specific options (`feichtmedia_imagemanager_acf_cache_enabled`, `feichtmedia_imagemanager_acf_cache_ttl`) and metadata transients via a direct `$wpdb` query matching `_transient_fm_im_meta_%`.
  5. Never delete `post_meta`.

> **Note:** The transient cache key set in `helpers.php` is `fm_img_meta_{md5(imageId)}` (prefix `fm_img_meta_`), but `uninstall.php` deletes rows matching `fm_im_meta_%` (prefix `fm_im_meta_`). These do not match — `uninstall.php` currently does not clean up the metadata transients. Fix either the cache key in `helpers.php` or the LIKE pattern in `uninstall.php` before bumping to v1.1.0.

---

## Development sequence (recommended for future features)

All components below are implemented in v1.0.0. When adding new features, follow this order and confirm each layer before moving to the next:

1. `includes/shared/imagemanager-core/` — Core component (options, options page, consumer registry, versioned boot)
2. `feichtmedia-imagemanager-acf.php` + `uninstall.php` — main file bootstrap + cleanup
3. `includes/class-rest-proxy.php` — proxy routes
4. `includes/helpers.php` — value parser, URL builder, mapper, metadata fetch
5. `includes/class-acf-field-image.php` — field type class
6. `assets/js/acf-imagemanager-field.js` + `assets/css/acf-imagemanager-field.css` — file browser UI
7. `includes/class-graphql.php` — WPGraphQL integration
8. `languages/` — `.pot` + `.po`/`.mo` files

---

## What this plugin does NOT do

- Upload, edit, or delete images (read-only access to the ImageManager).
- Store image metadata in WordPress — only the image ID in `post_meta`.
- Filter by dimensions or file type in the browser (API does not support it yet; field settings for this are planned but not implemented).
- Register a separate "Core" plugin — the shared component is bundled here.
- Use `@wordpress/i18n`, `wp_set_script_translations`, or `.json` translation files.
