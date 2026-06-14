=== FeichtMedia ImageManager ACF ===
Contributors: feichtmedia
Tags: acf, advanced custom fields, imagemanager, dam, digital asset management
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPL-2.0-or-later

Integrates the FeichtMedia ImageManager DAM into Advanced Custom Fields (ACF) as a native field type.

== Description ==

This plugin adds a native field type for the **FeichtMedia ImageManager** – your Digital Asset Management system – to Advanced Custom Fields (ACF). Editors pick images through an integrated file browser directly in the WP admin, without an iFrame and without any external redirects.

**Requirements:**

* WordPress 7.0 or later
* PHP 8.2 or later
* Advanced Custom Fields (free or PRO)

**Key features:**

* Native WP admin file browser – no iFrame, no cross-origin issues
* Server-side API proxy – the API key never leaves the server; API requests are only possible through the WordPress editor
* Three return formats: relative URL, absolute URL, metadata object
* WPGraphQL integration (`String` type and custom `ImageManagerImage` type)
* Backward compatible with stored relative URLs from plain text fields
* Metadata cache via WordPress Transients (configurable TTL, default: 1 hour)
* Multilingual: en_US (source), en_GB, de_DE, de_DE_formal, de_AT, de_CH

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin under **Plugins** in the WordPress admin.
3. Go to **Settings → FeichtMedia ImageManager** and enter your API key, project ID, and CDN domain.
4. Add a field of type **ImageManager > Image** to any ACF field group.

After setup, a new category "FeichtMedia ImageManager" with the field type **ImageManager Image** will appear in the ACF field editor.

== Frequently Asked Questions ==

= Which plugins are required? =

**Advanced Custom Fields** (free or PRO) is required. The plugin displays an admin notice if ACF is not installed or active.

= Is WPGraphQL required? =

No. The WPGraphQL integration is optional and activates automatically if WPGraphQL and WPGraphQL for ACF (v2.x) are installed and active. Without these plugins, the field type works fully.

= How do I set up the plugin? =

After activation, go to **Settings → FeichtMedia ImageManager**. Enter three values:

1. **API Key** – Your personal access key for the ImageManager. It is stored encrypted in the WordPress database and is never transmitted to the browser.
2. **Project ID** – The ID of your ImageManager project (also called usergroup ID), e.g. `wordpress`. It is part of every CDN URL.
3. **CDN Domain** – Your CDN domain without protocol and without a trailing slash, e.g. `cdn.example.com`. The plugin adds `https://` automatically.

= What do I enter as the CDN domain? =

Enter the domain **without** protocol and **without** a trailing slash, e.g. `cdn.example.com`. The plugin adds `https://` automatically when saving. If `https://` is accidentally prepended, it will be removed on save.

= Which API key permissions are required? =

The plugin accesses the ImageManager read-only. For the file browser and metadata queries, the API key needs at least:

* `image:list` – display the image list in the file browser
* `image:read` – fetch metadata for a single image (for the "metadata" return format)
* `category:list` – load category folders in the file browser
* `category:read` – load a single category (for breadcrumb navigation)

The `*:read` permission (read access to all resources) covers all of the above with a single entry and is the recommended choice if your ImageManager account supports wildcard permissions.

API keys that only grant write permissions (`image:create`, `image:update`, etc.) will result in 403 errors in the file browser and must not be used.

= Is the API key transmitted to the browser? =

No. All requests to the ImageManager are routed through a server-side WP REST API proxy. The API key is read exclusively on the server from the WordPress database and is never visible in the browser or in browser network traffic.

= What is stored in the database? =

Only the **image ID** (the `newFilename` field, e.g. `20260612-085316-my-photo.jpg`) is stored as `post_meta` per image. No URLs, no metadata, no CDN domain. The full URL is assembled at runtime from the stored image ID, the project ID, and the CDN domain.

= Which return formats are available and when should I use which? =

The return format is set per ACF field in the field settings:

* **Relative URL** (default) – Returns a path, e.g. `/wordpress/20260612-085316-my-photo.jpg`. No API call, fastest option. Suitable when your theme assembles the full URL itself or passes the path to a CDN helper.
* **Absolute URL** – Returns the full CDN URL, e.g. `https://cdn.example.com/wordpress/…`. Also no API call. Suitable when you need a ready-to-use URL in templates.
* **Metadata** – Returns an object with `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, and `filesize`. Requires one API call per image per cache interval (default: 1 hour, configurable). Suitable when your template needs alt text, copyright information, or image dimensions.

= What is the project ID? =

The project ID (also called usergroup ID) is the unique identifier of your ImageManager project, e.g. `wordpress`. It is part of every CDN URL and is needed to construct the full image URL from a stored image ID. You can find the project ID in your ImageManager dashboard.

= How does the file browser work in the editor? =

Click **Add image** (or **Change image** if one is already set) in the ACF field. A modal file browser opens, showing the categories and images from your ImageManager project:

* Category tiles (folders) are shown at the top; below them are the images of the current category.
* At the root level you see both subcategories and uncategorised images.
* The search field lets you search project-wide for images.
* Clicking an image selects it; clicking again deselects it.
* Click **Select** to apply the chosen image to the field.
* The info icon on an image tile opens the image directly in the ImageManager dashboard.

= I updated metadata in the ImageManager but the changes do not appear in WordPress. Why? =

The **Metadata** return format stores each image's data as a WordPress Transient. This is intentional: it reduces load on the ImageManager API and prevents pages with many images from exceeding the API request limit.

The cache TTL is **3,600 seconds (1 hour)** by default and can be adjusted or fully disabled under **Settings → FeichtMedia ImageManager** in the **ACF Field** section.

Changes to title, alt text, copyright, or dimensions in the ImageManager will not appear in WordPress until the Transient expires or the cache is cleared manually.

To clear the cache for a specific image immediately:

* **WP-CLI:** `wp transient delete fm_img_meta_$(php -r "echo md5('YOUR_IMAGE_ID');")`
* **Code / mu-plugin:** `delete_transient( 'fm_img_meta_' . md5( 'YOUR_IMAGE_ID' ) );`
* **Caching plugin:** Use the "Delete all transients" or "Flush object cache" feature of your caching plugin.

The **Relative URL** and **Absolute URL** formats are never cached – they are calculated purely at runtime from the stored image ID, project ID, and CDN domain, without any API calls.

= Can I adjust the cache TTL or disable the cache? =

Yes. Under **Settings → FeichtMedia ImageManager** in the **ACF Field** section you can:

* Set the **cache TTL** in seconds (default: 3,600). A value of `0` means the cache never expires.
* **Fully disable the cache** by unchecking the corresponding option. In this case, one API request is made per image per page load.

= What happens when multiple editors work simultaneously? =

The plugin is read-only and only writes the image ID as `post_meta`. Conflicts can only arise from the standard WordPress save behaviour when multiple editors edit the same post simultaneously, not from the plugin itself.

= Is WPGraphQL supported? =

Yes. If **WPGraphQL** and **WPGraphQL for ACF** (v2.x) are installed and active, the plugin automatically registers a GraphQL field type handler:

* Return formats `relative_url` and `absolute_url` are returned as `String`.
* The `metadata` return format returns the custom object type `ImageManagerImage` with the fields: `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, `filesize`.

= What happens to stored data when the plugin is deactivated? =

Nothing. Deactivation does not delete any data. Stored image IDs remain as `post_meta` and are immediately available again after reactivation.

= What happens when the plugin is uninstalled? =

On uninstall, the plugin removes its own settings (`feichtmedia_imagemanager_acf_cache_enabled`, `feichtmedia_imagemanager_acf_cache_ttl`). The **shared settings** (API key, project ID, CDN domain) are only deleted if no other FeichtMedia ImageManager plugin is still active. If other such plugins are installed, the shared settings are kept. `post_meta` (stored image IDs) is **never** deleted.

= How do I use the field in my theme or plugin? =

Retrieve the ACF field as usual with `get_field()` or `the_field()`. The returned value depends on the configured return format:

**Relative URL:**
`$path = get_field('my_imagemanager_field'); // e.g. /wordpress/20260612-085316-my-photo.jpg`

**Absolute URL:**
`$url = get_field('my_imagemanager_field'); // e.g. https://cdn.example.com/wordpress/…`

**Metadata:**
`$image = get_field('my_imagemanager_field');`
`// $image['alt'], $image['title'], $image['absoluteUrl'], $image['width'], …`

= Are images uploaded to or stored in WordPress? =

No. The plugin does not upload images to WordPress and does not store any image files. It stores only the image ID (a short text string) as `post_meta`. Images continue to be served via the FeichtMedia ImageManager and your CDN.

= Can the plugin upload, rename, or delete images? =

No. The plugin has read-only access to the ImageManager. It neither uploads, edits, nor deletes images.

== Changelog ==

Only plugin-level changes are listed here. Changes to the internal Shared Core Component (`includes/shared/imagemanager-core/`) are documented in `CHANGELOG.md` under a separate `Core` sub-section of the relevant version entry.

= 1.0.2 – 2026-06-14 =
* Removed internal changelog file from plugin release.

= 1.0.1 – 2026-06-14 =
* Prepared plugin for release on WordPress.org: fixed output escaping, translated readme to English, reduced tags to five, and minor code standard fixes.

= 1.0.0 – 2026-06-13 =
* Initial release.

== Upgrade Notice ==

= 1.0.1 =
No database changes. Safe to update.

= 1.0.0 =
Initial release.
