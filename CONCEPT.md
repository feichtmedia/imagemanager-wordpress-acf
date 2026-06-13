# FeichtMedia ImageManager ACF – Plugin-Konzept & Entwicklungsbriefing

> **Version:** 2.0.0
> **Datum:** 2026-06-12
> **Autor:** FeichtMedia (Digitalagentur)
> **Status:** Bereit für Entwicklung

> **Änderungen gegenüber 1.0.0 (Kurzfassung):**
> - Phaseneinteilung (MVP / Phase 2) vollständig entfernt – das Plugin wird in vollem Funktionsumfang gebaut.
> - Statische, berechtigungsbasierte API-Keys sind in der ImageManager-API **implementiert** → der frühere Vorbehalt „Token existiert noch nicht" entfällt.
> - Der iFrame-Picker (`?mode=picker` + `postMessage`) wird durch einen **nativen File-Browser** im WordPress-Admin ersetzt, der über den WP-REST-Proxy auf die ImageManager-API zugreift. Keine Änderungen am ImageManager-Dashboard mehr nötig.
> - Rückgabeformat **Metadaten-Objekt** ist regulärer Bestandteil (nicht mehr deaktiviert).
> - Kompatibilität & Admin-Styles zielen direkt auf **WordPress 7.0+**.
> - Plugin umbenannt von „FeichtMedia ImageManager Explorer for ACF" zu **„FeichtMedia ImageManager ACF"**; plugin-spezifische Slugs/Konstanten/Repo entsprechend angepasst (plugin-übergreifende Bezeichner bleiben unverändert).
> - **Architekturentscheidung:** Statt eines Universalfelds mit Datentyp-Option werden **mehrere, suffixierte Feldtypen** registriert (`imagemanager_image`, künftig `imagemanager_category`, …) – jeder Datensatz-Typ als eigener, isolierter Feldtyp, gemeinsame Infrastruktur (Settings, Proxy, Auth, Modal-Rahmen) geteilt. **V1 enthält ausschließlich `imagemanager_image`** (Bild-Auswahl).
> - **Mehrsprachigkeit von Anfang an** (Kap. 15): Quellsprache en_US, mitgelieferte Locales `en_GB`, `de_DE` (du), `de_DE_formal`, `de_AT`, `de_CH`; automatischer Fallback auf en_US für alle übrigen Sprachen. PHP- **und** JS-Strings übersetzbar.
> - **Geteilte Initialisierung & sauberer Lebenszyklus** (Kap. 16): Options + Options-Page liegen in einer wiederverwendbaren „ImageManager Core"-Komponente (versionsverhandelter Boot). Cleanup per Consumer-Registry/Reference-Counting – geteilte Options werden erst gelöscht, wenn das **letzte** nutzende Plugin deinstalliert wird; Deaktivierung löscht nie Daten.
> - **File-Browser-UX spezifiziert** (Kap. 9): Kategorien-Kacheln oben, Bilder darunter, Hauptebene = unkategorisiert, Breadcrumbs, projektweite Suche, Single-Select-Toggle + „Auswählen"-Button, Info-Icon je Bild auf die ImageManager-Detailseite.
> - **Backend-Performance bei vielen Feld-Instanzen** (Kap. 17): ein Modal, Assets einmal, **keine** API-Calls beim Rendern; Metadaten-Format mit Transient-Cache und (optionalem) Batching.
> - Feld-Settings **Min/Max-Größe & Dateityp** zurückgestellt (API kann noch nicht filtern) – siehe Kap. 5/14.

---

## Inhaltsverzeichnis

1. Übersicht
2. Konventionen & Code-Standards
3. Plugin-Architektur & Dateistruktur
4. Globale Einstellungsseite
5. ACF Custom Field Typ
6. Gespeicherter Wert & Abwärtskompatibilität
7. Rückgabeformate
8. WP REST API Proxy (Sicherheitsschicht)
9. Nativer File-Browser (Picker)
10. WPGraphQL-Integration
11. Robustheit & Fehlerbehandlung
12. Deinstallationsverhalten
13. Image Manager API-Referenz
14. Umfang & Architekturüberblick
15. Mehrsprachigkeit (i18n & l10n)
16. Geteilte Initialisierung & Lebenszyklus (ImageManager Core)
17. Backend-Performance & Skalierung (viele Feld-Instanzen)

---

## 1. Übersicht

### Was ist das?

Ein WordPress-Plugin, das einen benutzerdefinierten ACF (Advanced Custom Fields) Feldtyp für den FeichtMedia „Image Manager" registriert – ein internes Digital Asset Management (DAM)-System. Das Plugin ersetzt den bisherigen Workflow, bei dem Benutzer relative Bild-URLs manuell in einfache ACF-Textfelder kopierten.

### Problem

- Benutzer mussten relative Bild-URLs (z. B. `/99999/20260101-080000-image.jpg`) manuell aus dem Image Manager in ACF-Textfelder kopieren.
- Keine visuelle Vorschau, keine Validierung, fehleranfällig.

### Lösung

Ein benutzerdefinierter ACF-Feldtyp, der Folgendes bietet:

- Einen **nativen Datei-Browser** im WordPress-Admin (kein iFrame), der Bilder und Kategorien serverseitig über einen WP-REST-Proxy aus der Image-Manager-API lädt
- Vorschaubilder im Editor
- Konfigurierbare Rückgabeformate (relative URL, absolute URL, Metadaten-Objekt)
- Vollständige WPGraphQL-Integration für Headless-WordPress-Setups
- Abwärtskompatibilität mit bestehenden Textfeldwerten

### Wichtige Fakten

| Aspekt | Detail |
| --- | --- |
| Plugin-Slug | `feichtmedia-imagemanager-acf` |
| Textdomain | `feichtmedia-imagemanager-acf` |
| Minimale PHP-Version | 8.2+ |
| Minimale WP-Version | 7.0+ |
| Abhängigkeiten | ACF (frei oder PRO), optional WPGraphQL + WPGraphQL für ACF |
| Lizenz | Proprietär (interne Nutzung) |
| Repository | `feichtmedia/imagemanager-acf` |

---

## 2. Konventionen & Code-Standards

### Allgemein

- Der gesamte Code folgt den WordPress-Codierungsstandards (PHP, JS, CSS).
- Das Plugin verwendet eine **objektorientierte Klassenstruktur** mit logischer Trennung über mehrere Dateien.
- Jede Klasse hat eine einzige Verantwortung und befindet sich im Verzeichnis `includes/`.
- Alle Klassen verwenden den Namespace `FeichtMedia\ImageManagerACF` (oder ein `FM_ImageManager_`-Präfix, falls keine Namespaces für WP-Kompatibilität verwendet werden).
- Alle Hooks (`add_action`, `add_filter`) werden in der Haupt-Plugin-Datei oder innerhalb der `init()`/`register()`-Methode jeder Klasse registriert.
- Inline-Kommentare auf Englisch. DocBlocks für alle öffentlichen Methoden.
- Alle benutzerorientierten PHP-Zeichenketten sind über `__()` / `esc_html__()` etc. mit der Textdomain `feichtmedia-imagemanager-acf` übersetzbar. **Quellsprache ist durchgängig en_US** (msgid). UI-Texte des JS-Browsers werden **ebenfalls in PHP** übersetzt und per `wp_localize_script` übergeben – es gibt keine separate JS-Übersetzungspipeline. Details: siehe Kap. 15 (Mehrsprachigkeit).

### Namenskonventionen

| Typ | Konvention | Beispiel |
| --- | --- | --- |
| PHP-Klassen | `class-{name}.php`, PascalCase-Klassenname | `class-settings.php` → `FM_ImageManager_Settings` |
| PHP-Funktionen (Hilfsfunktionen) | `snake_case` mit Präfix | `feichtmedia_imagemanager_get_relative_url()` |
| WordPress-Optionen | `feichtmedia_imagemanager_{name}` | `feichtmedia_imagemanager_api_key` |
| JavaScript-Dateien | `kebab-case` | `acf-imagemanager-field.js` |
| CSS-Dateien | `kebab-case` | `acf-imagemanager-field.css` |
| ACF-Feldtyp-Schlüssel | `imagemanager_image` (ein Schlüssel je Datensatz-Typ, suffixiert) | Registriert über `acf_register_field_type()` |
| CSS-Klassenpräfix | `fm-imagemanager-` | `.fm-imagemanager-preview` |
| JS-Handle-Präfix | `fm-imagemanager-` | `fm-imagemanager-field` |

### Versionierung & Changelog

- Semantische Versionierung (`MAJOR.MINOR.PATCH`).
- Versionsnummer muss gleichzeitig in allen relevanten Dateien aktualisiert werden:
  - `feichtmedia-imagemanager-acf.php` → Plugin-Header `Version:`
  - `feichtmedia-imagemanager-acf.php` → `FM_IMAGEMANAGER_ACF_VERSION`-Konstante
  - `readme.txt` → `Stable tag:`
  - `CHANGELOG.md` → Neuer Eintrag
- Changelog-Einträge sind auf Englisch und folgen dieser Struktur:

```markdown
## [1.0.0] – 2026-XX-XX

**Initial release of the FeichtMedia ImageManager ACF field type.**

- Added: `imagemanager` ACF custom field type with native file browser
- Added: Global settings page under Settings → FeichtMedia ImageManager
- Added: WP REST API proxy for the Image Manager API (server-side, read-only)
- Added: Return formats relative URL, absolute URL, and metadata object
- Added: WPGraphQL integration (String + `ImageManagerImage` object type)
- Added: Backward compatibility for legacy relative URL values
```

- Changelog-Schlüsselwörter in der Reihenfolge: `Hinzugefügt`, `Aktualisiert`, `Umbenannt`, `Verschoben`, `Behoben`, `Entfernt`.
- Komponenten-/Dateinamen in Backticks.

### README

- Das Plugin verwendet ein `readme.txt` im WordPress.org-Format (nicht `README.md`), gemäß den WordPress-Plugin-Konventionen.

---

## 3. Plugin-Architektur & Dateistruktur

```plain text
feichtmedia-imagemanager-acf/
├── feichtmedia-imagemanager-acf.php    ← Main plugin file (bootstrap, constants, dependency checks)
├── includes/
│   ├── shared/
│   │   └── imagemanager-core/                ← Reusable, identical across all FM ImageManager plugins (ch. 16)
│   │       ├── bootstrap.php                 ← Registers this copy's version; boots highest version once
│   │       └── class-imagemanager-core.php   ← Shared options, options page, consumer registry, lifecycle
│   ├── class-settings.php                    ← Plugin-specific settings section(s) added to the shared page
│   ├── class-acf-field-image.php             ← ACF field type "imagemanager_image" (extends acf_field)
│   │                                            (future: class-acf-field-base.php, class-acf-field-category.php)
│   ├── class-graphql.php                     ← WPGraphQL integration & resolver
│   ├── class-rest-proxy.php                  ← WP REST API proxy for Image Manager API
│   └── helpers.php                           ← URL builder, ID extraction, regex helpers, mapper, metadata fetch
├── assets/
│   ├── js/
│   │   └── acf-imagemanager-field.js         ← File browser modal, REST calls, field UI
│   └── css/
│       └── acf-imagemanager-field.css        ← Field & browser styling (WP 7 admin)
├── languages/                                ← i18n: .pot + .po/.mo per locale (see ch. 15)
├── uninstall.php                             ← Cleanup on plugin deletion
├── CHANGELOG.md                              ← Version history
└── readme.txt                                ← WordPress plugin readme
```

### Class Overview

| Class | File | Responsibility |
| --- | --- | --- |
| `FM_ImageManager_Core` | `shared/imagemanager-core/class-imagemanager-core.php` | **Geteilte Komponente** (identisch in jedem FM-ImageManager-Plugin). Registriert die geteilten Options + Options-Page, die Consumer-Registry und den Lebenszyklus. Bootet versionsverhandelt genau einmal (siehe Kap. 16). |
| (bootstrap) | `shared/imagemanager-core/bootstrap.php` | Registriert die gebündelte Core-Version als Kandidat und bootet nach `plugins_loaded` die höchste Version. Mit `function_exists()`-Guard gegen Mehrfach-Deklaration. |
| `FM_ImageManager_Settings` | `class-settings.php` | Fügt die **plugin-spezifischen** Settings-Abschnitte/-Felder zur geteilten Options-Page hinzu (dieses Plugin hat in V1 keine eigenen Options; Abschnitt dient als Erweiterungspunkt). |
| `FM_ImageManager_ACF_Field_Image` | `class-acf-field-image.php` | Extends `acf_field` (field type `imagemanager_image`). Defines field settings, renders the field UI, handles `format_value()` for return formats, enqueues JS/CSS. In V1 the only field type; when a second type is added, shared logic is extracted into an abstract base / trait. |
| `FM_ImageManager_GraphQL` | `class-graphql.php` | Registers the field type with WPGraphQL. Provides resolvers for String (URL) and Object (metadata) return types. |
| `FM_ImageManager_REST_Proxy` | `class-rest-proxy.php` | Registers WP REST API proxy routes (`/images`, `/images/:id`, `/categories`, `/categories/:id`). Forwards requests server-side to the Image Manager API with the API key. |
| (procedural) | `helpers.php` | Stateless utility functions for URL building, value parsing, settings validation, and cached metadata fetching. |

### Main Plugin File (`feichtmedia-imagemanager-acf.php`)

Responsibilities:

1. Plugin header comment (name, version, author, text domain, etc.)
2. Define constants:
   - `FM_IMAGEMANAGER_ACF_VERSION` – Plugin version
   - `FM_IMAGEMANAGER_ACF_PATH` – Plugin directory path
   - `FM_IMAGEMANAGER_ACF_URL` – Plugin directory URL
   - `FM_IMAGEMANAGER_API_URL` – Image Manager API base URL (constant, not configurable)
   - `FM_IMAGEMANAGER_DASHBOARD_URL` – Image Manager dashboard base URL (for per-image detail links)
3. Check dependencies (ACF active?)
4. Include class files
5. Initialize classes on appropriate hooks

```php
/**
 * Plugin Name: FeichtMedia ImageManager ACF
 * Description: ACF custom field type for the FeichtMedia Image Manager DAM.
 * Version:     1.0.0
 * Author:      FeichtMedia
 * Author URI:  https://www.feicht-media.de/
 * Text Domain: feichtmedia-imagemanager-acf
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.2
 */

if (!defined('ABSPATH')) exit;

define('FM_IMAGEMANAGER_ACF_VERSION', '1.0.0');
define('FM_IMAGEMANAGER_ACF_PATH', plugin_dir_path(__FILE__));
define('FM_IMAGEMANAGER_ACF_URL', plugin_dir_url(__FILE__));
define('FM_IMAGEMANAGER_API_URL', 'https://api.imagemanager.feichtmedia.com/api/v2');
define('FM_IMAGEMANAGER_DASHBOARD_URL', 'https://imagemanager.feicht-media.de'); // for image detail links
```

> **Hinweis:** Eine separate `FM_IMAGEMANAGER_PICKER_URL` (Dashboard-URL für den iFrame) wird nicht mehr benötigt, da der Picker nativ im WP-Admin läuft und ausschließlich über den REST-Proxy mit der API kommuniziert.

### Bootstrap-Grundgerüst (verbindlicher Ablauf)

Damit Reihenfolge und Lebenszyklus eindeutig sind, folgt die Hauptdatei genau diesem Muster (Skelett – Implementierungsdetails in den jeweiligen Kapiteln):

```php
// … nach Header + Konstanten (siehe oben) …

const FM_IMAGEMANAGER_ACF_BASENAME = __FILE__;

// 1) Geteilte Core-Komponente laden (registriert Version, bootet die höchste einmal). Kap. 16.
require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/shared/imagemanager-core/bootstrap.php';

// 2) Consumer-Registry: bei Aktivierung eintragen, bei Deinstallation via uninstall.php austragen. Kap. 12/16.
register_activation_hook(__FILE__, function () {
    fm_imagemanager_register_consumer(plugin_basename(FM_IMAGEMANAGER_ACF_BASENAME));
});

// 3) Eigentliche Plugin-Initialisierung – erst wenn alle Plugins geladen sind.
add_action('plugins_loaded', function () {

    // 3a) Harte Abhängigkeit: ACF. Ohne ACF nichts registrieren, nur Admin-Hinweis (Kap. 11).
    if (!class_exists('ACF')) {
        add_action('admin_notices', 'fm_imagemanager_acf_missing_notice');
        return;
    }

    // 3b) Übersetzungen (defensiv; Kap. 15).
    load_plugin_textdomain('feichtmedia-imagemanager-acf', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // 3c) Helpers + Feldtyp registrieren (ein konkreter Feldtyp in V1).
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/helpers.php';
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-acf-field-image.php';
    add_action('acf/include_field_types', function () {
        acf_register_field_type('FM_ImageManager_ACF_Field_Image');
    });

    // 3d) Plugin-spezifische Settings-Sektion an die (Core-)Options-Page hängen. Kap. 4.
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-settings.php';
    (new FM_ImageManager_Settings())->register();

    // 3e) REST-Proxy nur, wenn ein API-Key konfiguriert ist. Kap. 8.
    if (get_option('feichtmedia_imagemanager_api_key')) {
        require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-rest-proxy.php';
        (new FM_ImageManager_REST_Proxy())->register();
    }

    // 3f) WPGraphQL-Integration nur, wenn WPGraphQL aktiv ist. Kap. 10/11.
    if (function_exists('register_graphql_field')) {
        require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-graphql.php';
        (new FM_ImageManager_GraphQL())->register();
    }
});
```

Reihenfolge-Garantien:

- Die Core-Komponente bootet auf `plugins_loaded` **Priorität 5**, die Plugin-Init (oben) auf Standard-Priorität (10) – die Options-Page/Optionen stehen also bereit, bevor das Plugin sie nutzt.
- Der Feldtyp wird über `acf/include_field_types` registriert (ACF ist dann garantiert geladen).
- Auf **Deaktivierung** wird nichts gelöscht; Cleanup ausschließlich in `uninstall.php` (Kap. 12).

---

## 4. Globale Einstellungsseite

### Konzept

Eine **gemeinsame Einstellungsseite** für alle aktuellen und zukünftigen FeichtMedia ImageManager-Plugins. Die Seite und die geteilten Optionen werden von der wiederverwendbaren **ImageManager-Core-Komponente** erstellt und besessen (versionsverhandelter Boot, genau einmal – siehe Kap. 16). Jedes Plugin fügt der bestehenden Seite nur seine **eigenen** Einstellungsabschnitte hinzu.

### Berechtigungen

Nur Administratoren (Benutzer, die Einstellungsseiten bearbeiten können) haben Zugriff auf diese Seite. Dies sind Benutzer mit der `manage_options`-Fähigkeit.

### Location

**WordPress Admin → Settings → FeichtMedia ImageManager**

### Page Slug

`feichtmedia-imagemanager`

### Options (gespeichert in `wp_options`)

| Option Key | Type | Description | Example Value | Required |
| --- | --- | --- | --- | --- |
| `feichtmedia_imagemanager_api_key` | `string` | API-Key für die Image Manager API (Bild-/Kategorie-Browser, Metadatenabfrage) | `sk_live_abc123...` | Ja |
| `feichtmedia_imagemanager_project_id` | `string` | Project ID / Usergroup ID | `99999` | Ja |
| `feichtmedia_imagemanager_domain` | `string` | CDN-Domain für Bildversand und Vorschaubilder (ohne Protokoll) | `cdn.example.com` | Ja |

> Der API-Key ist nun ein **Pflichtfeld**, da der native File-Browser und die Metadaten-Rückgabe ohne ihn nicht funktionieren.

### Option Prefix Rationale

Der Präfix `feichtmedia_imagemanager_` ist absichtlich verständlich für:

- **Uniqueness:** Verhindert Kollisionen mit anderen Plugins in der `wp_options`-Tabelle (kürzere Präfixe wie `fm_` könnten mit „Form Maker", „File Manager", etc. konfliktieren)
- **Self-documenting:** Jeder, der die Datenbank inspiziert, weiß sofort, welches Plugin die Option besitzt.
- **Cross-plugin access:** Andere FeichtMedia ImageManager-Plugins können den gleichen API-Schlüssel lesen:

```php
$apiKey = get_option('feichtmedia_imagemanager_api_key');
```

### API-URL als Konstante (nicht als Option)

Die API-URL ist **nicht** als Option gespeichert, sondern als PHP-Konstante in der Haupt-Plugin-Datei. Rationale:

- Diese URL zeigt auf FeichtMedia's eigene Infrastruktur und ändert selten.
- Verhindert versehentliche Misconfiguration durch Endnutzer.
- Wenn sie je ändern muss, ist es ein Plugin-Update, nicht eine Einstellungsänderung.

### Settings Validation

- Die Einstellungsseite muss validieren, dass `api_key`, `project_id` und `domain` nicht leer sind.
- `domain` darf nicht das Protokoll (`https://`) enthalten. Wenn mit Protokoll eingegeben, wird es automatisch abgeschnitten.
- Wenn die Einstellungen unvollständig sind, wird eine **admin-Nachricht** angezeigt: *„FeichtMedia ImageManager: Bitte vervollständigen Sie die Plugin-Einstellungen unter Einstellungen → FeichtMedia ImageManager."*

### Besitz der Options-Page (geteilte Core-Komponente)

Die Options-Page und die geteilten Optionen (`api_key`, `project_id`, `domain`) werden **nicht** von diesem Plugin direkt registriert, sondern von der wiederverwendbaren **ImageManager-Core-Komponente** (siehe Kap. 16). Dadurch:

- existiert die Page genau **einmal**, unabhängig davon, wie viele FeichtMedia-ImageManager-Plugins aktiv sind,
- verschwindet sie **nicht**, wenn ausgerechnet dieses Plugin gelöscht wird,
- werden die Options sauber per Reference-Counting aufgeräumt (Cleanup erst, wenn das letzte nutzende Plugin geht).

Dieses Plugin (und jedes künftige) fügt der bestehenden Page nur noch **seine eigenen Abschnitte** hinzu:

```php
// Plugin-specific section on the shared page (page is owned by the Core component):
add_settings_section(
    'feichtmedia_imagemanager_acf',
    __('ACF Field', 'feichtmedia-imagemanager-acf'),
    null,
    'feichtmedia-imagemanager'  // shared page slug, owned by ImageManager Core
);
```

> In V1 hat das ACF-Plugin keine eigenen Optionen; der Abschnitt dient als Erweiterungspunkt. Die geteilten Felder (API-Key, Project ID, Domain) gehören der Core-Komponente.

---

## 5. ACF Custom Field Typ

### Feldtyp-Schlüssel

`imagemanager_image`

Jeder Datensatz-Typ ist ein **eigener, suffixierter Feldtyp**. V1 registriert genau einen: `imagemanager_image` (Bild-Auswahl). In der ACF-Feldtyp-Liste erscheint er als „FeichtMedia ImageManager: Bild".

> Künftige Datensatz-Typen werden als weitere Feldtypen ergänzt (`imagemanager_category`, …) und teilen sich die Infrastruktur – Details unter „Architektur: Ein Feldtyp pro Datensatz-Typ".

### Registrierung

Das Feldtyp ist über `acf_register_field_type()` mit einer Klasse registriert, die `acf_field` erweitert.

### Feld-Einstellungen (sichtbar im ACF-Field Group Editor)

| Setting | ACF Key | Type | Options | Default | Description |
| --- | --- | --- | --- | --- | --- |
| Return Format | `return_format` | `select` | `relative_url`, `absolute_url`, `metadata` | `relative_url` | Bestimmt, was `get_field()` und GraphQL zurückgeben. |
| Required | `required` | `boolean` | — | `false` | Standard-ACF-erforderliches Flag. |
| Allow Null | `allow_null` | `boolean` | — | `true` | Wenn `true`, ist der „Entfernen"-Button sichtbar und das Feld kann gelöscht werden. |

> Das Rückgabeformat `metadata` ist regulär verfügbar.
>
> **Zukunft (nicht in V1):** Feld-Settings für **Min/Max-Breite/Höhe** und **erlaubte Dateitypen** (`min_width`, `min_height`, `max_width`, `max_height`, `allowed_types`) sind vorgesehen, aber zurückgestellt – die **ImageManager-API kann (noch) nicht** nach Dimensionen oder Dateityp filtern. Sobald die API das unterstützt, werden diese Settings als serverseitige Filter im File-Browser ergänzt (siehe Kap. 14).

### Architektur: Ein Feldtyp pro Datensatz-Typ

**Gewählter Ansatz:** Pro Datensatz-Typ ein eigener, isolierter ACF-Feldtyp (suffixiert). Kein Universalfeld mit Datentyp-Umschalter (`record_type` entfällt vollständig). Begründung: Gespeicherter Wert (String `newFilename` vs. Integer-Kategorie-`id`), sinnvolle Rückgabeformate (URLs ergeben für Kategorien keinen Sinn), Picker-UI (Grid vs. Baum) und GraphQL-Typ unterscheiden sich je Datensatz stark. Getrennte Feldtypen halten jede Logik kohärent (eine Klasse pro Typ), vermeiden `if`/`switch`-Verzweigungen an genau den komplexen Stellen und machen das Dropdown für Redakteure eindeutig.

**V1-Umfang:** Genau **ein** Feldtyp, `imagemanager_image`, umgesetzt in einer konkreten Klasse `FM_ImageManager_ACF_Field_Image`. Es wird **keine abstrakte Basisklasse auf Vorrat** gebaut – das wäre bei einem einzigen Feldtyp Over-Engineering.

**Geteilte Infrastruktur (schon jetzt entkoppelt):** Settings-Seite, WP-REST-Proxy, Auth, Helper/Mapper und der Enqueue-/Modal-Rahmen liegen in eigenen, typ-unabhängigen Einheiten. Ein neuer Feldtyp braucht daher nur: eine neue Feldtyp-Klasse + Mapper-Funktion + GraphQL-Objekttyp – die restliche Infrastruktur wird wiederverwendet.

**Künftige Erweiterung (z. B. Kategorie-Auswahl):** Neuer Feldtyp `imagemanager_category` im selben Plugin. In dem Moment, in dem der zweite Typ dazukommt, wird die gemeinsame Logik der Feldtyp-Klassen in eine abstrakte Basis bzw. ein Trait (`FM_ImageManager_ACF_Field_Base`) extrahiert – ein günstiger Refactor, weil die Infrastruktur bereits getrennt ist.

**GraphQL bleibt einfach:** WPGraphQL baut das Schema **pro Feld-Instanz zur Build-Zeit**. Der Typ steht zum Konfigurationszeitpunkt fest, daher löst jede Instanz zu einem festen, statischen Typ auf:

```plain text
imagemanager_image            (V1)
  return_format relative_url | absolute_url  → String
  return_format metadata                     → ImageManagerImage

imagemanager_category         (künftig)
  return_format id                           → Int
  return_format path | name                  → String
  return_format metadata                     → ImageManagerCategory
```

Eine GraphQL-Union (`ImageManagerImage | ImageManagerCategory`) wäre nur nötig, wenn eine *einzelne* Instanz Bild *oder* Kategorie halten könnte – das ist durch die Trennung in Feldtypen ausgeschlossen und würde Komplexität in jeden Client verlagern.

Skizze des künftigen Kategorie-Objekttyps:

```graphql
type ImageManagerCategory {
  id: Int!
  name: String!     # displayName
  parentId: Int     # parentCategory
  path: String      # "Root / Sub / Leaf" (via queryPath=1)
  usergroup: String
}
```

**Konsistenz Upstream ↔ kanonisch:** Ein gemeinsamer Mapper (`Mapper::image()`, künftig `Mapper::category()`) übersetzt die Rohnamen der ImageManager-API (`newFilename`, `customTitle`, `altText`, `parentCategory`, …) in die kanonische Form (`imageId`, `title`, `alt`, `parentId`, …) und wird von `format_value`/Metadaten-Helper **und** den GraphQL-Resolvern genutzt, damit PHP-Return und GraphQL garantiert identisch sind.

> Offene Designfrage (nicht in diesem Schritt): ob der REST-Proxy die Antworten **1:1** durchreicht oder bereits in die kanonische Form **normalisiert**. Tendenz: Normalisierung, damit unterhalb des Proxy genau ein Vokabular existiert.

### Feld UI im Editor

Die Editor-UI ist **identisch** unabhängig vom gewählten Return Format. Nur die zugrunde liegende Werttransformation ändert sich.

### Leerer Zustand (kein Bild ausgewählt)

```plain text
┌─────────────────────────────────────┐
│  Label (e.g., "Beitragsbild")       │
│                                     │
│     [ Bild hinzufügen ]             │  ← Button, öffnet File-Browser-Modal
│                                     │
└─────────────────────────────────────┘
```

### Bild ausgewählt

```plain text
┌─────────────────────────────────────┐
│  Label                              │
│                                     │
│  ┌───────────────────────────┐      │
│  │                           │      │
│  │     Thumbnail Preview     │      │  ← Geladen via CDN: https://{domain}/{projectId}/{imageId}
│  │     (max-width: 100%)     │      │
│  │                           │      │
│  └───────────────────────────┘      │
│                                     │
│  [ Bild ändern ] [ Entfernen ]      │  ← "Entfernen" nur sichtbar, wenn allow_null = true
│                                     │
└─────────────────────────────────────┘
```

### Thumbnail Error (404 / beschädigtes Bild)

```plain text
┌─────────────────────────────────────┐
│  Label                              │
│                                     │
│  ┌───────────────────────────┐      │
│  │   ⚠ Bild nicht gefunden   │      │  ← onerror handler on <img> zeigt Platzhalter
│  └───────────────────────────┘      │
│                                     │
│  [ Bild ändern ] [ Entfernen ]      │
│                                     │
└─────────────────────────────────────┘
```

### Incomplete Settings

Wenn `feichtmedia_imagemanager_api_key`, `feichtmedia_imagemanager_project_id` oder `feichtmedia_imagemanager_domain` leer sind, zeigt das Feld eine Nachricht anstatt der Picker-UI:

```plain text
┌─────────────────────────────────────┐
│  Label                              │
│                                     │
│  ⚠ Konfiguration nicht vollständig. │
│  Bitte alle Einstellungen unter     │
│  Einstellungen → FeichtMedia        │
│  ImageManager vervollständigen.     │
│                                     │
└─────────────────────────────────────┘
```

### Thumbnail Preview URL

Die Vorschaubilder werden **direkt über die CDN** geladen – keine API-Aufrufe nötig:

```plain text
https://{domain}/{projectId}/{imageId}
```

Beispiel:

```plain text
https://cdn.example.com/99999/20260101-080000-image.jpg
```

### JS & CSS Enqueuing

Assets werden nur auf ACF-Field-Admin-Screens enqueued:

```php
public function input_admin_enqueue_scripts() {
    wp_enqueue_script(
        'fm-imagemanager-field',
        FM_IMAGEMANAGER_ACF_URL . 'assets/js/acf-imagemanager-field.js',
        ['acf-input', 'wp-api-fetch'],
        FM_IMAGEMANAGER_ACF_VERSION,
        true
    );

    wp_localize_script('fm-imagemanager-field', 'fmImageManager', [
        'restNamespace' => 'feichtmedia/imagemanager/v2',
        'projectId'     => get_option('feichtmedia_imagemanager_project_id', ''),
        'domain'        => get_option('feichtmedia_imagemanager_domain', ''),
        'dashboardUrl'  => FM_IMAGEMANAGER_DASHBOARD_URL, // for per-image detail links
        // UI strings translated in PHP, consumed by the JS (no JS i18n pipeline). See ch. 15.
        'strings'       => [
            'addImage'    => __('Add image', 'feichtmedia-imagemanager-acf'),
            'changeImage' => __('Change image', 'feichtmedia-imagemanager-acf'),
            'remove'      => __('Remove', 'feichtmedia-imagemanager-acf'),
            'search'      => __('Search…', 'feichtmedia-imagemanager-acf'),
            'noResults'   => __('No images found.', 'feichtmedia-imagemanager-acf'),
            'loadError'   => __('Could not load images. Please try again.', 'feichtmedia-imagemanager-acf'),
            // Placeholders stay in the (translated) template; filled in JS:
            'showingCount'=> __('Showing %1$d of %2$d', 'feichtmedia-imagemanager-acf'),
        ],
    ]);

    wp_enqueue_style(
        'fm-imagemanager-field',
        FM_IMAGEMANAGER_ACF_URL . 'assets/css/acf-imagemanager-field.css',
        ['acf-input'],
        FM_IMAGEMANAGER_ACF_VERSION
    );
}
```

> Der Browser-Code nutzt `wp-api-fetch` (mit gesetztem WP-Nonce), um die internen Proxy-Routen aufzurufen. Picker-URL und API-Key werden **nicht** mehr ans Frontend übergeben. Alle UI-Texte kommen **vorübersetzt aus PHP** über `fmImageManager.strings` – das JS übersetzt selbst nichts (siehe Kap. 15).

---

## 6. Gespeicherter Wert & Abwärtskompatibilität

### Neuer Gespeicherter Wert

Nur die **Bild-ID** (`newFilename`) wird in `post_meta` gespeichert:

```plain text
post_meta_key:   hero_image          (ACF field name)
post_meta_value: 20260101-080000-image.jpg    (image ID only)
```

### Warum nur die Bild-ID?

| Grund | Detail |
| --- | --- |
| **Unique identifier** | `newFilename` ist die Primary Key in der Image Manager-Datenbank. Doppelte Werte über Usergroups sind unmöglich. |
| **Usergroup is global** | Die Project ID / Usergroup ist einmal in den Plugin-Einstellungen gespeichert, nicht redundant in jedem Feldwert. |
| **Change resilience** | Wenn die Usergroup je ändert, muss nur eine Option aktualisiert werden – nicht hunderte von `post_meta`-Rows. |
| **Clean separation** | Der gespeicherte Wert ist eine pure ID. URL-Bau ist Anwendungslogik, gehandelt von Hilfsfunktionen. |

### Legacy Value Format

Zu Beginn speicherten Benutzer die vollständige relative URL in Textfeldern:

```plain text
/99999/20260101-080000-image.jpg
```

Format: `/{usergroup}/{imageId}`

### Abwärtskompatibilität via Regex

Das Plugin **detektiert automatisch**, ob ein gespeicherter Wert ein Legacy-URL oder eine neue Bild-ID ist:

```php
function feichtmedia_imagemanager_parse_value(string $value): array {
    // Contains "/" → legacy relative URL
    if (str_contains($value, '/')) {
        // Extract parts: /99999/20260101-080000-image.jpg
        if (preg_match('#^/([^/]+)/(.+)$#', $value, $matches)) {
            return [
                'format'  => 'legacy',
                'groupId' => $matches[1],
                'imageId' => $matches[2],
            ];
        }
    }

    // No "/" → new format, just the image ID
    return [
        'format'  => 'current',
        'groupId' => get_option('feichtmedia_imagemanager_project_id'),
        'imageId' => $value,
    ];
}
```

**Wichtig:** In einigen seltenen Fällen wurde neben der relativen URL im Format `/{usergroup}/{imageId}` auch zusätzliche Bild-Filter mit definiert. Dies führte zu Werten im Format `/{filters...}/{usergroup}/{imageId}`. `filters` kann beliebig oft vorkommen und durch `/` getrennt sein, z. B. `/filters:smart_crop()/filter:strip_exif()/filters:blur(5)/99999/20260101-080000-image.jpg`. Der Regex-Fallback behandelt diese Fälle ebenfalls korrekt, indem er immer die **letzten beiden Segmente** als `groupId` und `imageId` extrahiert.

**Warum das reliably funktioniert:** Eine `newFilename` ist immer im Format `YYYYMMDD-HHMMSS-filename.ext` und enthält nie einen `/`. Jeder Wert, der `/` enthält, ist definitiv ein Legacy-URL.

### Keine Migration benötigt

Bestehende Textfelder können ohne Datenmigration auf den neuen `imagemanager`-Feldtyp umgestellt werden. Der Regex-Fallback behandelt Legacy-Werte transparent. Neue Saves werden nur die Bild-ID speichern.

---

## 7. Rückgabeformate

Die UI im Editor ist **immer identisch**. Das Rückgabeformat ist pro Feldinstanz in den ACF-Field-Group-Einstellungen konfiguriert und beeinflusst nur, was `get_field()` / `the_field()` und GraphQL-Resolver zurückgeben.

### Relative URL (`return_format: relative_url`) – Default

**PHP:**

```php
$image = get_field('hero_image');
// → "/99999/20260101-080000-image.jpg"

echo '<img src="https://cdn.example.com' . esc_attr($image) . '" alt="" />';
```

**GraphQL:**

```graphql
{
  post(id: "cG9zdDox") {
    heroImage # → "/99999/20260101-080000-image.jpg"
  }
}
```

**GraphQL Type:** `String`

### Absolute URL (`return_format: absolute_url`)

**PHP:**

```php
$image = get_field('hero_image');
// → "https://cdn.example.com/99999/20260101-080000-image.jpg"

echo '<img src="' . esc_url($image) . '" alt="" />';
```

**GraphQL:**

```graphql
{
  post(id: "cG9zdDox") {
    heroImage # → "https://cdn.example.com/99999/20260101-080000-image.jpg"
  }
}
```

**GraphQL Type:** `String`

### Metadata Object (`return_format: metadata`)

**PHP (array, destructurable like post thumbnails):**

```php
$image = get_field('hero_image');
// → associative array

echo '<img
  src="' . esc_url($image['absolute_url']) . '"
  alt="' . esc_attr($image['alt']) . '"
  width="' . intval($image['width']) . '"
  height="' . intval($image['height']) . '"
/>';

// Or destructured:
['absolute_url' => $url, 'alt' => $alt, 'width' => $w, 'height' => $h] = get_field('hero_image');
```

**Return structure:**

```php
[
    'org_filename'  => 'original-name.jpg',
    'image_id'      => '20260101-080000-image.jpg',
    'relative_url'  => '/99999/20260101-080000-image.jpg',
    'absolute_url'  => 'https://cdn.example.com/99999/20260101-080000-image.jpg',
    'title'         => 'Image title',
    'alt'           => 'Alternative text for the image',
    'copyright'     => 'FeichtMedia',
    'width'         => 1920,
    'height'        => 1080,
    'filetype'      => 'jpg',
    'filesize'      => 245000,
]
```

**GraphQL (subfield selection):**

```graphql
{
  post(id: "cG9zdDox") {
    heroImage {
      absoluteUrl
      alt
      width
      height
      copyright
    }
  }
}
```

**GraphQL Type:** Custom Object Type `ImageManagerImage`

```graphql
type ImageManagerImage {
  imageId: String!
  relativeUrl: String!
  absoluteUrl: String!
  orgFilename: String!
  title: String
  alt: String
  copyright: String
  width: Int!
  height: Int!
  filetype: String!
  filesize: Int!
}
```

### Implementation in `format_value()`

```php
public function format_value($value, $post_id, $field) {
    if (empty($value)) {
        return $value;
    }

    $parsed   = feichtmedia_imagemanager_parse_value($value);
    $imageId  = $parsed['imageId'];
    $groupId  = $parsed['groupId'];
    $domain   = get_option('feichtmedia_imagemanager_domain', '');

    switch ($field['return_format']) {
        case 'absolute_url':
            return 'https://' . $domain . '/' . $groupId . '/' . $imageId;

        case 'metadata':
            // Server-side API call via stored API key, with transient caching.
            return feichtmedia_imagemanager_get_metadata($groupId, $imageId, $domain);

        case 'relative_url':
        default:
            return '/' . $groupId . '/' . $imageId;
    }
}
```

### Metadata-Helper mit Transient-Caching

```php
/**
 * Fetch image metadata from the Image Manager API, cached as a transient.
 * Cache key: fm_im_meta_{imageId}, TTL ~1h.
 */
function feichtmedia_imagemanager_get_metadata(string $groupId, string $imageId, string $domain): array {
    $cacheKey = 'fm_im_meta_' . md5($imageId);
    $cached   = get_transient($cacheKey);
    if ($cached !== false) {
        return $cached;
    }

    $apiKey = get_option('feichtmedia_imagemanager_api_key', '');
    $response = wp_remote_get(
        FM_IMAGEMANAGER_API_URL . '/images/' . urlencode($imageId),
        [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]
    );

    // On error, fall back to minimal data built from known values.
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return [
            'image_id'     => $imageId,
            'relative_url' => '/' . $groupId . '/' . $imageId,
            'absolute_url' => 'https://' . $domain . '/' . $groupId . '/' . $imageId,
        ];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    $meta = [
        'org_filename' => $data['orgFilename']   ?? '',
        'image_id'     => $imageId,
        'relative_url' => '/' . $groupId . '/' . $imageId,
        'absolute_url' => 'https://' . $domain . '/' . $groupId . '/' . $imageId,
        'title'        => $data['customTitle']   ?? '',
        'alt'          => $data['altText']       ?? '',
        'copyright'    => $data['copyrightInfos']?? '',
        'width'        => (int)($data['width']    ?? 0),
        'height'       => (int)($data['height']   ?? 0),
        'filetype'     => $data['filetype']      ?? '',
        'filesize'     => (int)($data['filesize'] ?? 0),
    ];

    set_transient($cacheKey, $meta, HOUR_IN_SECONDS);
    return $meta;
}
```

---

## 8. WP REST API Proxy (Sicherheitsschicht)

### Problem

Der native File-Browser (siehe Kapitel 9) muss Bilder und Kategorien von der Image Manager API abfragen. Senden von API-Aufrufen direkt aus dem Browser (JavaScript) würde **den API-Schlüssel** in der Netzwerk-Tab / Seitenquelle zeigen – jeder könnte ihn kopieren und die Image-Manager-Daten aus externen Anwendungen abfragen.

### Lösung: Server-seitiger Proxy

Alle Image Manager API-Aufrufe werden über einen **WordPress REST API-Proxy** weitergeleitet. Der Browser ruft einen lokalen WP-Endpunkt; PHP auf dem Server fügt den API-Schlüssel hinzu und leitet die Anfrage an die echte Image Manager API weiter. Der API-Schlüssel **verlässt nie den Server**.

```plain text
Browser (JS in WP Admin)
    │
    ▼
WP REST API  /wp-json/feichtmedia/imagemanager/v2/images
    │  ← permission_callback: current_user_can('edit_posts')
    │  ← PHP adds Authorization header with API key from wp_options
    ▼
Image Manager API  https://api.imagemanager.feichtmedia.com/api/v2/images
    │
    ▼
JSON response → forwarded back to browser
```

### Namespace & Route Prefix

```plain text
feichtmedia/imagemanager/v2
```

*Hinweis: Wir starten bereits mit **`v2`**, da im Hintergrund auch die ImageManager API v2 verwendet wird. So ist es eindeutig.*

### Proxy Routes

All routes require `permission_callback` → `current_user_can('edit_posts')`. Query parameters are forwarded **1:1** to the upstream API.

| WP REST Route | Method | Upstream API Endpoint | Forwarded Query Params |
| --- | --- | --- | --- |
| `/images` | `GET` | `/api/v2/images` | `offset`, `limit`, `orderBy`, `order`, `search`, `category`, `filetype`, `startDate`, `endDate` |
| `/images/(?P<imageId>[\w.-]+)` | `GET` | `/api/v2/images/{imageId}` | `queryCategory`, `queryProject` |
| `/categories` | `GET` | `/api/v2/categories` | `parentCategory`, `offset`, `limit`, `orderBy`, `order`, `search`, `queryParent` |
| `/categories/(?P<categoryId>[\d]+)` | `GET` | `/api/v2/categories/{categoryId}` | `queryParent`, `queryChilds`, `queryProject`, `queryPath` |

### Implementation: `class-rest-proxy.php`

```php
<?php
/**
 * FM_ImageManager_REST_Proxy
 *
 * Registers WP REST API proxy routes that forward requests to the
 * Image Manager API. The API key stays server-side and is never
 * exposed to the browser.
 */
class FM_ImageManager_REST_Proxy {

    private const NAMESPACE = 'feichtmedia/imagemanager/v2';

    /**
     * Allowed query params per route (whitelist).
     */
    private const PARAM_WHITELIST = [
        'images'     => ['offset', 'limit', 'orderBy', 'order', 'search', 'category', 'filetype', 'startDate', 'endDate'],
        'image'      => ['queryCategory', 'queryProject'],
        'categories' => ['parentCategory', 'offset', 'limit', 'orderBy', 'order', 'search', 'queryParent'],
        'category'   => ['queryParent', 'queryChilds', 'queryProject', 'queryPath'],
    ];

    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        // GET /images
        register_rest_route(self::NAMESPACE, '/images', [
            'methods'             => 'GET',
            'callback'            => [$this, 'proxy_images'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // GET /images/{imageId}
        register_rest_route(self::NAMESPACE, '/images/(?P<imageId>[\w.-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'proxy_single_image'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // GET /categories
        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => 'GET',
            'callback'            => [$this, 'proxy_categories'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // GET /categories/{categoryId}
        register_rest_route(self::NAMESPACE, '/categories/(?P<categoryId>[\d]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'proxy_single_category'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Only logged-in users who can edit posts may use the proxy.
     */
    public function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    // --- Route callbacks ---------------------------------------------------

    public function proxy_images(WP_REST_Request $request): WP_REST_Response {
        return $this->forward('/images', $request, self::PARAM_WHITELIST['images']);
    }

    public function proxy_single_image(WP_REST_Request $request): WP_REST_Response {
        $imageId = $request->get_param('imageId');
        return $this->forward('/images/' . urlencode($imageId), $request, self::PARAM_WHITELIST['image']);
    }

    public function proxy_categories(WP_REST_Request $request): WP_REST_Response {
        return $this->forward('/categories', $request, self::PARAM_WHITELIST['categories']);
    }

    public function proxy_single_category(WP_REST_Request $request): WP_REST_Response {
        $categoryId = $request->get_param('categoryId');
        return $this->forward('/categories/' . urlencode($categoryId), $request, self::PARAM_WHITELIST['category']);
    }

    // --- Core proxy logic --------------------------------------------------

    /**
     * Forward a request to the Image Manager API.
     *
     * @param string          $path           API path (e.g. '/images')
     * @param WP_REST_Request $request        Incoming WP REST request
     * @param string[]        $allowed_params Whitelisted query parameter names
     */
    private function forward(string $path, WP_REST_Request $request, array $allowed_params): WP_REST_Response {
        $api_key = get_option('feichtmedia_imagemanager_api_key', '');

        if (empty($api_key)) {
            return new WP_REST_Response([
                'error'   => 'missing_api_key',
                'message' => 'Image Manager API key is not configured.',
            ], 500);
        }

        // Build query string from whitelisted params only
        $query = [];
        foreach ($allowed_params as $param) {
            $value = $request->get_param($param);
            if ($value !== null && $value !== '') {
                $query[$param] = $value;
            }
        }

        $url = FM_IMAGEMANAGER_API_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'error'   => 'upstream_error',
                'message' => $response->get_error_message(),
            ], 502);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response($body ?? [], $status);
    }
}
```

### Parameter Whitelisting

Nur explizit whitelisted query parameters werden an die upstream API weitergeleitet. Dies verhindert die Injection von unerwarteten Parametern und begrenzt die Proxy-Oberfläche auf nur Lesen.

### Security Considerations

| Aspekt | Implementation |
| --- | --- |
| **API key location** | Gespeichert in `wp_options`, gelesen server-seitig durch PHP. Nie gesendet an den Browser. |
| **Access control** | `permission_callback` erfordert `edit_posts`-Berechtigung. Anonyme oder Subscriber-Level-Nutzer können den Proxy nicht nutzen. |
| **Parameter whitelist** | Nur bekannte Query-Params werden weitergeleitet. Unbekannte Params werden stumm gelöscht. |
| **Read-only** | Der Proxy registriert nur `GET`-Routen. Keine `POST`, `PUT`, `PATCH`, oder `DELETE` – keine Schreiboperationen möglich. |
| **API key scope** | Der API-Schlüssel in den Einstellungen **muss** ein read-only-Token sein. Auch wenn jemand `edit_posts`-Zugriff hat, kann er nur lesen – nie modifizieren oder löschen. |
| **Timeout** | `wp_remote_get` Timeout gesetzt auf 15 Sekunden. Verhindert langhängende Anfragen, die PHP-Worker blockieren. |

### API-Authentifizierung (implementiert)

Der WP-REST-Proxy nutzt einen **statischen API-Key** für Server-zu-Server-Auth (kein interaktiver Cognito-Login). Dieser Token-Typ ist in der Image Manager API **implementiert** und steht zur Verfügung.

Eigenschaften des Token-Systems:

| Eigenschaft | Detail |
| --- | --- |
| **Token format** | Opaque string, z. B. `sk_live_abc123...` (Präfix `sk_` zur Identifikation) |
| **Scope** | Read-only: `GET /images`, `GET /images/:id`, `GET /categories`, `GET /categories/:id` |
| **Bound to usergroup** | Token ist auf eine bestimmte `groupId` / Project ID gescoped. Kann keine Daten anderer Usergroups lesen. |
| **Revocable** | Token kann über das Image Manager Dashboard rotiert werden, ohne andere Tokens zu beeinflussen. |
| **No expiry** | Statische Tokens laufen nicht ab (anders als Cognito-Sessions). Rotation ist manuell. |
| **Auth header** | `Authorization: Bearer {token}` – gleiches Header-Format wie Cognito-Tokens. |

### Loading in Main Plugin File

```php
// REST Proxy (loaded when API key is configured)
if (get_option('feichtmedia_imagemanager_api_key')) {
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-rest-proxy.php';
    (new FM_ImageManager_REST_Proxy())->register();
}
```

---

## 9. Nativer File-Browser (Picker)

### Konzept

Der Picker ist ein **nativer Datei-Browser im WordPress-Admin**, gestaltet im WP-7-Admin-Look (kein iFrame, kein eingebettetes ImageManager-Dashboard). Er wird als Modal aus dem Feld-Button („Bild hinzufügen" / „Bild ändern") geöffnet und lädt Bilder und Kategorien **ausschließlich über die WP-REST-Proxy-Routen** aus Kapitel 8.

Vorteile gegenüber dem früheren iFrame-Ansatz:

- **Kein Login-Screen im Modal** – Auth läuft serverseitig über den API-Key, der Editor sieht nie eine ImageManager-Anmeldung.
- **Keine Cross-Origin-Komplexität** – kein `postMessage`, kein `targetOrigin`-Handling, keine CSP/X-Frame-Options-Anpassung.
- **Keine Änderungen am ImageManager-Dashboard nötig** – das Dashboard muss keinen `?mode=picker` mehr kennen.
- **Konsistentes Look & Feel** mit dem restlichen WP-Admin.

### Layout (an ImageManager + native WP-Mediathek angelehnt)

Das Modal orientiert sich optisch am nativen WP-Mediathek-Dialog („Bild auswählen", Schließen-X, unten rechts ein **„Auswählen"-Button**) und inhaltlich am ImageManager-Dashboard:

```plain text
┌───────────────────────────────────────────────────────────────┐
│  Bild auswählen                                            [×]  │
│  Start / Projekte / Website Header        [ 🔍 Suche … ]        │  ← Breadcrumbs + projektweite Suche
├───────────────────────────────────────────────────────────────┤
│  Kategorien (Ordner-Kacheln)                                    │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐                         │
│  │ 📁 Assets │ │ 📁 Awards │ │ 📁 Blog   │  …                    │  ← Klick = in Kategorie navigieren
│  └──────────┘ └──────────┘ └──────────┘                         │
│                                                                 │
│  Bilder (Thumbnail-Grid)                                        │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐                            │
│  │ img ✓│ │ img  │ │ img  │ │ img ⓘ│  …                          │  ← ✓ = ausgewählt (Rahmen+Check)
│  │ name │ │ name │ │ name │ │ name │                            │     ⓘ = Info-Icon (on hover/focus)
│  │ date·│ │ date·│ │ date·│ │ date·│                            │
│  │ jpg· │ │ png· │ │ jpg· │ │ jpg· │                            │
│  │ WxH  │ │ WxH  │ │ WxH  │ │ WxH  │                            │
│  └──────┘ └──────┘ └──────┘ └──────┘                            │
│                                          [ Mehr laden ]         │  ← Pagination (offset/limit)
├───────────────────────────────────────────────────────────────┤
│                                            [ Auswählen ]        │  ← disabled bis Auswahl getroffen
└───────────────────────────────────────────────────────────────┘
```

- **Navigation:** Oben die Kategorien als Ordner-Kacheln, darunter die Bilder der **aktuellen** Kategorie. Auf der **Hauptebene** werden die Unterkategorien und die **unkategorisierten** Bilder gezeigt. Klick auf eine Kategorie navigiert hinein (Breadcrumb + Inhalt aktualisieren).
- **Breadcrumbs** (oben) zum Zurücknavigieren; Pfad kommt aus der API.
- **Suche** projektweit über den API-`search`-Parameter (durchsucht über Kategoriegrenzen hinweg).
- **Bildkachel** zeigt Thumbnail (via CDN), Name, Datum, Format und Dimensionen (analog ImageManager-Layout).
- **Auswahl (Single-Select, Toggle):** Klick markiert ein Bild (Rahmen + Checkmark-Overlay), erneuter Klick hebt die Markierung auf. Der **„Auswählen"-Button** unten rechts übernimmt die Markierung final und schließt das Modal. Erst dadurch wird die Bild-ID ins Feld geschrieben.
- **Info-Icon** je Bild (on hover/focus): öffnet die Detailseite im ImageManager in einem neuen Tab –
  `FM_IMAGEMANAGER_DASHBOARD_URL . '/overview/edit?id=' . encodeURIComponent(imageId)`
  (= `https://imagemanager.feicht-media.de/overview/edit?id={imageId}`), `target="_blank" rel="noopener"`.

> Die exakten Query-Parameter/Endpoints (Kategorie-Navigation, Suche, Pagination) werden während der Entwicklung gegen die ImageManager-API-Docs bestätigt.

### Funktionsumfang

- **Kategorie-Navigation** über `/categories` (Unterkategorien + Breadcrumb-Pfad).
- **Bildraster** über `/images` der aktuellen Kategorie (Hauptebene = unkategorisiert); Thumbnails via CDN.
- **Suche** projektweit über `search`.
- **Pagination** via `offset`/`limit` (Infinite Scroll oder „Mehr laden").
- **Auswahl** als Single-Select-Toggle + Bestätigen-Button.
- **Info-Link** je Bild auf die ImageManager-Detailseite.

> **Hinweis:** Filter nach Bilddimensionen oder Dateityp sind **nicht** Teil von V1 (die ImageManager-API kann danach noch nicht filtern) – siehe Feld-Settings in Kap. 5 und Zukunft in Kap. 14.

### Datenfluss bei Auswahl

```plain text
"Bild hinzufügen" (Feld-Button)
    │  setzt activeFieldKey, öffnet das (eine, geteilte) Modal
    ▼
File-Browser lädt /categories & /images  ──►  WP REST Proxy  ──►  ImageManager API
    │
    ▼
Klick auf Bild  → markiert (Rahmen+Check); erneuter Klick → Markierung weg
    │
    ▼
"Auswählen"-Button
    │  → schreibt newFilename in den Input des activeFieldKey
    │  → Metadaten aus der /images-Antwort liegen bereits vor (Caching möglich)
    ▼
Vorschau gerendert, Modal geschlossen
```

> Da der Browser die `/images`-Antwort bereits enthält (inkl. `altText`, `width`, `height`, etc.), liegen die Metadaten zum Auswahlzeitpunkt vor und können direkt für die Vorschau bzw. zum Befüllen des Metadaten-Transients genutzt werden – das spart beim Metadaten-Rückgabeformat später API-Calls.

### Multi-Field Handling (ein Modal für alle Felder)

Eine Seite kann **20+ ImageManager-Felder** haben (ACF-Blöcke, Pagebuilder). Es gibt deshalb **genau ein** Modal/Browser im DOM – nicht eines pro Feld. Eine `activeFieldKey`-Variable (gesetzt beim Klick auf den Button eines bestimmten Feldes) bestimmt, welches Feld das ausgewählte Bild erhält.

- **Ein** Modal/Browser im DOM (lazy beim ersten Öffnen erzeugt, danach wiederverwendet).
- Felder selbst rendern nur einen versteckten Input + eine `<img>`-Vorschau (CDN) – **kein** eigenes Modal, **keine** API-Calls beim Rendern.
- `activeFieldKey` als einfache Zustandsvariable.

> Performance bei vielen Instanzen ist eigenständig in Kap. 17 beschrieben.

### Vereinfachtes JS-Grundgerüst

```javascript
const { strings } = window.fmImageManager;
let activeFieldKey = null;
let selectedImage  = null;
let modalEl        = null; // single, lazily created modal

function openBrowser(fieldKey) {
  activeFieldKey = fieldKey;
  selectedImage  = null;
  if (!modalEl) modalEl = createModalOnce(); // built exactly once, then reused
  showModal();
  loadCategoriesAndImages(); // wp.apiFetch on the proxy routes
}

// Single-select toggle
function onImageClick(image) {
  selectedImage = (selectedImage && selectedImage.newFilename === image.newFilename)
    ? null            // second click → deselect
    : image;          // select
  renderSelectionState(selectedImage);
  setConfirmEnabled(!!selectedImage);
}

// Confirm button commits the selection
function onConfirm() {
  if (selectedImage && activeFieldKey) {
    updateField(activeFieldKey, {
      imageId: selectedImage.newFilename,
      groupId: selectedImage.owner,
      // metadata already present in the API response (optional cache fill):
      alt: selectedImage.altText, width: selectedImage.width, height: selectedImage.height,
    });
  }
  activeFieldKey = null;
  selectedImage  = null;
  hideModal();
}
```

---

## 10. WPGraphQL-Integration

Das Plugin muss in beiden **native WordPress** (PHP-Templates) und **headless WordPress** (WPGraphQL) Umgebungen funktionieren. Der GraphQL-Resolver respektiert das `return_format`-Feldsetting.

### String-Typ (relative / absolute URL)

Für `return_format: relative_url` und `return_format: absolute_url` löst das GraphQL-Feld zu einem `String`.

```php
// class-graphql.php

add_filter('wpgraphql_acf_register_graphql_field', function($field_config, $acf_field) {
    // V1: only the image field type. Future types register their own resolvers.
    if ($acf_field['type'] !== 'imagemanager_image') {
        return $field_config;
    }

    $return_format = $acf_field['return_format'] ?? 'relative_url';

    if ($return_format === 'metadata') {
        // Custom Object Type
        $field_config['type'] = 'ImageManagerImage';
        $field_config['resolve'] = function($root, $args, $context, $info) use ($acf_field) {
            return get_field($acf_field['name'], $root->databaseId);
        };
    } else {
        // String (relative or absolute URL)
        $field_config['type'] = 'String';
        $field_config['resolve'] = function($root, $args, $context, $info) use ($acf_field) {
            return get_field($acf_field['name'], $root->databaseId);
        };
    }

    return $field_config;
}, 10, 2);
```

### Custom Object Type

Der `ImageManagerImage`-Typ muss registriert sein:

```php
register_graphql_object_type('ImageManagerImage', [
    'description' => 'An image from the FeichtMedia Image Manager',
    'fields' => [
        'imageId'     => ['type' => ['non_null' => 'String']],
        'relativeUrl' => ['type' => ['non_null' => 'String']],
        'absoluteUrl' => ['type' => ['non_null' => 'String']],
        'orgFilename' => ['type' => 'String'],
        'alt'         => ['type' => 'String'],
        'title'       => ['type' => 'String'],
        'copyright'   => ['type' => 'String'],
        'width'       => ['type' => 'Int'],
        'height'      => ['type' => 'Int'],
        'filetype'    => ['type' => 'String'],
        'filesize'    => ['type' => 'Int'],
    ],
]);
```

### Usage Examples

**Native PHP (theme template):**

```php
// Relative URL
$url = get_field('hero_image');
echo '<img src="https://cdn.example.com' . esc_attr($url) . '" />';

// Absolute URL
$url = get_field('hero_image');
echo '<img src="' . esc_url($url) . '" />';

// Metadata
$img = get_field('hero_image');
echo '<img src="' . esc_url($img['absolute_url']) . '" alt="' . esc_attr($img['alt']) . '" />';
```

**Headless (Next.js via GraphQL):**

```graphql
query GetPost($id: ID!) {
  post(id: $id) {
    title
    heroImage # → String (relative or absolute URL)
  }
}
```

```graphql
# Metadata
query GetPost($id: ID!) {
  post(id: $id) {
    title
    heroImage {
      absoluteUrl
      alt
      width
      height
    }
  }
}
```

---

## 11. Robustheit & Fehlerbehandlung

### Dependency Check: ACF

Wenn ACF nicht installiert/aktiv ist, muss das Plugin den Feldtyp **nicht** registrieren und eine persistente Admin-Notice anzeigen. Die Notice wird als **benannte Funktion** definiert (konsistent mit dem Bootstrap-Grundgerüst in Kap. 3, das `fm_imagemanager_acf_missing_notice` referenziert):

```php
// helpers.php oder main plugin file
function fm_imagemanager_acf_missing_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('FeichtMedia ImageManager ACF requires Advanced Custom Fields to be installed and active.', 'feichtmedia-imagemanager-acf');
    echo '</p></div>';
}

// In der plugins_loaded-Callback (Kap. 3):
if (!class_exists('ACF')) {
    add_action('admin_notices', 'fm_imagemanager_acf_missing_notice');
    return;
}
```

> Hinweis: ACF registriert sich als Klasse `ACF` (Großbuchstaben). Nicht `acf` oder `Acf` – konsistent auf `class_exists('ACF')` prüfen.

### Settings Validation

Wenn `feichtmedia_imagemanager_api_key`, `feichtmedia_imagemanager_project_id` oder `feichtmedia_imagemanager_domain` leer sind:

- Zeigt eine persistente admin-Nachricht an, die auf die Einstellungsseite verlinkt.
- Der Feldtyp **kann** zu Feldgruppen hinzugefügt werden, aber das Feld **rendert nicht** im Editor. Es zeigt eine inline-Konfigurationswarnung an.

### Thumbnail 404-Handling

```javascript
const img = document.querySelector(".fm-imagemanager-preview img");
img.addEventListener("error", function () {
  this.style.display = "none";
  this.parentElement.querySelector(".fm-imagemanager-error").style.display = "block";
});
```

Es wird kein automatischer Cleanup des gespeicherten Werts durchgeführt. Das Feld zeigt den Fehlerzustand an und der Benutzer kann manuell ändern oder entfernen.

**Wichtig:** Do NOT auto-clear the value on 404 – der Fehler könnte durch eine falsche Domain in den Einstellungen verursacht sein, nicht durch ein gelöschtes Bild.

### Proxy-/API-Fehler im Browser

- `500 missing_api_key` → Hinweis im Modal, der auf die Einstellungsseite verweist.
- `502 upstream_error` / Timeout → benutzerfreundliche Fehlermeldung mit Retry-Option.
- Leere Ergebnislisten → Empty-State im Grid.

### WPGraphQL Dependency

WPGraphQL-Integration ist **optional**. Die `class-graphql.php` wird nur geladen, wenn WPGraphQL aktiv ist:

```php
if (function_exists('register_graphql_field')) {
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-graphql.php';
    new FM_ImageManager_GraphQL();
}
```

---

## 12. Deinstallationsverhalten

> Vollständige Begründung und der Lebenszyklus über mehrere Plugins hinweg: siehe Kap. 16. Hier die konkrete Cleanup-Logik dieses Plugins.

### Deaktivierung

Beim **Deaktivieren** wird **nichts** gelöscht – weder Options noch `post_meta`. Deaktivierung ist temporär; Datenlöschung bei Deaktivierung wäre ein Anti-Pattern.

### Deinstallation (Löschen) – Reference-Counting

Die `uninstall.php` läuft nur beim **Löschen** des Plugins. Geteilte Options gehören mehreren Plugins, dürfen also nur entfernt werden, wenn **kein** weiteres FeichtMedia-ImageManager-Plugin sie mehr nutzt:

```php
// uninstall.php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$self = 'feichtmedia-imagemanager-acf/feichtmedia-imagemanager-acf.php';

// 1) Remove this plugin from the shared consumer registry.
$consumers = (array) get_option('feichtmedia_imagemanager_consumers', []);
$consumers = array_values(array_diff($consumers, [$self]));

if (empty($consumers)) {
    // 2) Last consumer gone → remove shared options cleanly.
    delete_option('feichtmedia_imagemanager_api_key');
    delete_option('feichtmedia_imagemanager_project_id');
    delete_option('feichtmedia_imagemanager_domain');
    delete_option('feichtmedia_imagemanager_consumers');
} else {
    // Other plugins still rely on the shared options → keep them.
    update_option('feichtmedia_imagemanager_consumers', $consumers);
}

// 3) Always remove this plugin's own regenerable caches (fm_im_meta_* transients).
//    (delete via a transient cleanup helper)
```

### Was wird erhalten

- **Geteilte Options**, solange noch ein anderes Plugin in der Consumer-Registry steht.
- Alle `post_meta`-Werte bleiben unverändert. Sie sind gültige Bild-IDs (oder alte relative URLs) und bleiben nutzbar, wenn der Benutzer auf ein einfaches Textfeld umstellt.

So bleibt nichts unnötig in der Datenbank liegen („clean"), ohne anderen Plugins die Konfiguration zu entziehen.

---

## 13. Image Manager API-Referenz

Dieser Abschnitt bietet den wesentlichen API-Kontext für die Entwicklung. Der vollständige API-Code ist im `feichtmedia/imagemanager-api`-Repository (Branch: `master`).

### Stack

- **Runtime:** Node.js / Express.js v5
- **ORM:** Prisma (MySQL / MariaDB)
- **Storage:** AWS S3 + CloudFront CDN
- **Auth:** Amazon Cognito (username/password + optional TOTP MFA) **sowie statische, berechtigungsbasierte API-Keys**

### Authentication

Die API unterstützt zwei Auth-Mechanismen:

1. **Cognito Bearer-Token (interaktiv):** Der `POST /api/v2/auth`-Endpoint akzeptiert `username`, `password`, und optional `code` (MFA) und gibt ein `accessToken` zurück.
2. **Statischer API-Key (Server-zu-Server, implementiert):** Read-only, usergroup-gescoped, rotierbar, ohne Ablauf. Wird vom WP-REST-Proxy genutzt. Gleiches Header-Format: `Authorization: Bearer {token}`.

Alle Endpunkte akzeptieren beide Token-Typen im `Bearer`-Header.

### Data Model

#### `images`-Tabelle

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| `newFilename` | `VARCHAR(255)` **PK** | Unique image identifier, e.g., `20260101-080000-image.jpg` |
| `orgFilename` | `VARCHAR(255)` | Original filename at upload |
| `uploadDate` | `DATETIME` | Upload timestamp |
| `filetype` | `VARCHAR(10)` | File extension (e.g., `jpg`, `png`, `webp`) |
| `filesize` | `INT` | File size in bytes |
| `width` | `INT` | Image width in pixels |
| `height` | `INT` | Image height in pixels |
| `category` | `INT` FK | Category ID (nullable) |
| `owner` | `VARCHAR(50)` FK | Usergroup / Project ID |
| `customTitle` | `VARCHAR(150)` | User-defined title |
| `copyrightInfos` | `TEXT` | Copyright information |
| `altText` | `TEXT` | Alt text for accessibility |
| `privateNote` | `TEXT` | Internal note |
| `uploadedBy` | `VARCHAR(55)` FK | Username |

#### `categories`-Tabelle

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| `id` | `INT` **PK** auto-increment | Category ID |
| `usergroup` | `VARCHAR(50)` FK | Owning usergroup |
| `displayName` | `VARCHAR(255)` | Category display name |
| `parentCategory` | `INT` self-ref FK | Parent category (nullable, max depth: 10) |

#### `usergroups`-Tabelle

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| `groupId` | `VARCHAR(50)` **PK** | Unique group identifier (= Project ID) |
| `groupTitle` | `VARCHAR(255)` | Display name |
| `defaultDomain` | `VARCHAR(150)` FK | Default CDN domain |
| `cloudfrontDistributionId` | `VARCHAR(25)` | CloudFront distribution |

### API Endpoints (v2, unter `/api/v2/`)

| Method | Endpoint | Description | Key Query Params |
| --- | --- | --- | --- |
| `POST` | `/auth` | Authenticate, get access token | Body: `username`, `password`, `code` |
| `GET` | `/images` | List images (paginated) | `offset`, `limit`, `orderBy`, `order`, `search`, `category`, `filetype`, `startDate`, `endDate` |
| `GET` | `/images/:imageId` | Get single image details | `queryCategory=1`, `queryProject=1` |
| `GET` | `/categories` | List categories (paginated) | `parentCategory`, `offset`, `limit`, `orderBy`, `order`, `search`, `queryParent=1` |
| `GET` | `/categories/:categoryId` | Get single category | `queryParent=1`, `queryChilds=1`, `queryProject=1`, `queryPath=1` |

### Image URL Structure

Images are served via CloudFront CDN with a two-level path:

```plain text
https://{domain}/{groupId}/{newFilename}
```

Example:

```plain text
https://cdn.example.com/99999/20260101-080000-image.jpg
```

The same path structure is used in the S3 bucket (`{groupId}/{newFilename}`).

---

## 14. Umfang & Architekturüberblick

### Funktionsumfang (gesamtes Plugin, einphasig)

- [x] ACF-Custom-Feldtyp `imagemanager_image` mit Picker-Button (einziger Feldtyp in V1)
- [x] Nativer File-Browser im WP-Admin (WP-7-Style), lädt Bilder/Kategorien über den WP-REST-Proxy
- [x] WP REST API Proxy (`class-rest-proxy.php`) – 4 read-only Routen, API-Key serverseitig
- [x] Bild-ID als gespeicherter Wert (`newFilename` nur)
- [x] Regex-basierte Abwärtskompatibilität für alte relative URLs (inkl. Filter-Segmente)
- [x] Return format: Relative URL (default)
- [x] Return format: Absolute URL
- [x] Return format: Metadata object (API-Call via Proxy/Helper + Transient-Caching)
- [x] Thumbnail-Vorschau über CDN (direkt `<img>` mit `onerror`-Fallback)
- [x] Globale Einstellungsseite: API-Key, Project ID, Domain (alle Pflicht)
- [x] Settings-Validierung mit admin-Nachrichten
- [x] ACF-Dependency-Check mit admin-Nachrichten
- [x] WPGraphQL-Integration: `String` für URL-Formate, `ImageManagerImage` Object Type für Metadaten
- [x] Allow null / Clear-Button (konfigurierbar pro Feld)
- [x] File-Browser-UX spezifiziert (Kap. 9): Kategorien→Bilder, Breadcrumbs, Suche, Single-Select-Toggle + Bestätigen, Info-Link
- [x] Multi-field support: **ein** Modal, Assets einmal, keine API-Calls beim Rendern (20+ Felder pro Seite) – Kap. 17
- [x] `uninstall.php` mit Reference-Counting (geteilte Options nur löschen, wenn letztes Plugin geht; `post_meta` bleibt; Deaktivierung löscht nie)
- [x] Geteilte „ImageManager Core"-Komponente (versionsverhandelter Boot, Options-Page-Ownership, Consumer-Registry) – Kap. 16
- [x] Mehrsprachigkeit (Kap. 15): Quellsprache en_US + Locales `en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`; PHP- & JS-Strings übersetzbar; automatischer Fallback auf en_US

### Nice-to-have / spätere Iterationen

- [ ] **Feld-Settings Min/Max-Breite/Höhe & erlaubte Dateitypen** – zurückgestellt, bis die ImageManager-API nach Dimensionen/Dateityp filtern kann (Kap. 5)
- [ ] Tastatur-Navigation & Feinschliff des File-Browsers (sehr große Bibliotheken)
- [ ] Bulk-Auswahl / Mehrfachbilder (falls künftig benötigt)
- [ ] **Weitere Datensatz-Typen** als eigene Feldtypen (`imagemanager_category`, …) mit geteilter Infrastruktur; Extraktion einer abstrakten Feld-Basisklasse beim zweiten Typ (siehe Kap. 5)
- [ ] REST-Proxy-Normalisierung in die kanonische Form (statt 1:1 pass-through) – Designentscheidung offen

### Architecture Diagram

```plain text
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Admin                          │
│                                                             │
│  ┌─────────────┐    ┌──────────────────────────────────┐   │
│  │  Settings    │    │  ACF Field: ImageManager          │   │
│  │  Page        │    │                                    │   │
│  │  ─────────   │    │  [Thumbnail] or [Bild hinzufügen] │   │
│  │  • API Key   │    │                                    │   │
│  │  • Project ID│    │  Click → opens File-Browser Modal  │   │
│  │  • Domain    │    └──────────┬─────────────────────────┘   │
│  └─────────────┘               │                             │
│                                ▼                             │
│                    ┌───────────────────────┐                 │
│                    │  Modal: File-Browser   │                 │
│                    │  (native WP 7 admin)   │                 │
│                    │  • Kategorie-Baum      │                 │
│                    │  • Bild-Grid + Suche   │                 │
│                    │  • Filter/Pagination   │                 │
│                    └──────────┬────────────┘                 │
│                               │ wp.apiFetch (Nonce)          │
│                               ▼                              │
│              ┌────────────────────────────────┐             │
│              │  WP REST Proxy                  │             │
│              │  /feichtmedia/imagemanager/v2   │             │
│              │  + Authorization: Bearer {key}  │             │
│              └────────────────┬───────────────┘             │
│                               │                             │
│  post_meta: "20260101-080000-image.jpg" (image ID only)     │
└───────────────────────────────┼─────────────────────────────┘
                                 ▼
                  ┌──────────────────────────────┐
                  │  Image Manager API (v2)       │
                  │  /images, /categories         │
                  └──────────────────────────────┘

            ┌───────────────────────────────┐
            │  get_field() / GraphQL        │
            │  ┌─────────────────────────┐  │
            │  │ return_format?           │  │
            │  │                         │  │
            │  │ relative_url →          │  │
            │  │   "/99999/image.jpg"    │  │
            │  │                         │  │
            │  │ absolute_url →          │  │
            │  │   "https://cdn/99999/…" │  │
            │  │                         │  │
            │  │ metadata →              │  │
            │  │   { url, alt, … }       │  │
            │  │   (API + Transient)     │  │
            │  └─────────────────────────┘  │
            └───────────────────────────────┘
```

---

## 15. Mehrsprachigkeit (i18n & l10n)

Das Plugin wird veröffentlicht und steht stellvertretend für die Agenturarbeit – daher ist vollständige Internationalisierung von Beginn an verpflichtend. Die UI ist überschaubar, der Aufwand also gering, der Qualitätseindruck aber hoch.

### Grundprinzip: Quellsprache = en_US

Alle Strings im Code (msgid) werden in **US-Englisch** geschrieben und mit den Standard-i18n-Funktionen umschlossen. Daraus ergibt sich der gewünschte Fallback automatisch: Findet WordPress für die aktive Sprache keine Übersetzungsdatei dieser Textdomain, liefert gettext den Original-msgid zurück – also en_US. WordPress macht **keine** Sprachketten-Fallbacks (kein „fr_FR → de_DE → en_US"); es gibt nur „Übersetzung vorhanden" oder „msgid". Deshalb ist **kein eigener Fallback-Code nötig**: ein französischer Nutzer sieht en_US, weil keine fr_FR-Übersetzung existiert.

Konsequenz: **en_US braucht keine Übersetzungsdatei** (es ist die Quellsprache). Alle anderen Ziel-Locales erhalten je eine Übersetzungsdatei.

### Ziel-Locales

| Sprache (gewünscht) | WordPress-Locale | Übersetzungsdatei nötig? | Anrede |
| --- | --- | --- | --- |
| Englisch (US) | `en_US` | nein (Quellsprache) | — |
| Englisch (GB) | `en_GB` | ja (nur abweichende Begriffe; ggf. minimal) | — |
| Deutsch (du) | `de_DE` | ja | du |
| Deutsch (formell, Sie) | `de_DE_formal` | ja | Sie |
| Deutsch (Österreich) | `de_AT` | ja | Sie |
| Deutsch (Schweiz) | `de_CH` | ja | Sie, „ß"→„ss" |

Hinweise zu den Locales:

- **`de_DE`** ist in WordPress die **informelle** Variante (du); **`de_DE_formal`** die Sie-Variante. Das deckt „Deutsch (du)" und „Deutsch (formell)" ab.
- **`de_CH`** ist die **formelle (Sie)** Schweiz-Variante und damit das gewählte Locale (`de_CH_informal` wäre die Du-Form und entfällt). Wichtig: **nie „ß", immer „ss"**.
- **`de_AT`** kennt in WordPress **keine** eigene formal/informal-Aufteilung – ein einzelnes Locale. Der Sie-Ton wird im Übersetzungstext selbst umgesetzt. Inhaltlich oft fast identisch zu de_DE_formal (kleine Vokabelunterschiede, z. B. „Jänner").
- **`en_GB`** unterscheidet sich bei einer kleinen UI evtl. nur in wenigen Wörtern; Strings ohne Abweichung fallen automatisch auf en_US (msgid) zurück.

### PHP-Strings

Alle benutzerorientierten PHP-Strings über die Standard-Funktionen mit Textdomain `feichtmedia-imagemanager-acf`: `__()`, `esc_html__()`, `esc_attr__()`, `_e()`, bei Bedarf `_n()` (Plurale) und `_x()` (Kontext). Die Textdomain wird stets als **String-Literal** übergeben (keine Variable, keine Konstante), damit Poedit sie beim Scannen findet.

**Auch die UI-Texte des JS-Browsers werden hier in PHP übersetzt** und per `wp_localize_script` als `fmImageManager.strings` ans Skript übergeben (siehe Kap. 5 und „JavaScript-Strings" unten). Dadurch liegen sämtliche Übersetzungen an einer Stelle – in den `.po`/`.mo`-Dateien.

Just-in-time-Loading: Ab WP 4.6 lädt WordPress Plugin-Übersetzungen automatisch beim ersten Gebrauch der Textdomain; ein expliziter `load_plugin_textdomain()`-Aufruf ist für gebündelte und über .org bereitgestellte Sprachen meist nicht mehr nötig. Wir rufen ihn dennoch defensiv im `init`-Hook auf, um eigene `/languages`-Dateien zuverlässig zu laden:

```php
add_action('init', function () {
    load_plugin_textdomain(
        'feichtmedia-imagemanager-acf',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});
```

### JavaScript-Strings (File-Browser)

Ein großer Teil der UI-Texte (Modal, Buttons, Filter, Fehlermeldungen) liegt im File-Browser-JS. Um **jegliche JS-Übersetzungspipeline zu vermeiden** (keine `.json`-Dateien, kein `wp_set_script_translations`, kein JS-Build), werden diese Texte **in PHP übersetzt** und als fertiges Objekt ans Skript übergeben:

```php
// In input_admin_enqueue_scripts() – siehe Kap. 5
wp_localize_script('fm-imagemanager-field', 'fmImageManager', [
    // …
    'strings' => [
        'addImage'    => __('Add image', 'feichtmedia-imagemanager-acf'),
        'noResults'   => __('No images found.', 'feichtmedia-imagemanager-acf'),
        'showingCount'=> __('Showing %1$d of %2$d', 'feichtmedia-imagemanager-acf'),
        // …
    ],
]);
```

Das JavaScript konsumiert nur vorübersetzte Strings und übersetzt selbst nichts:

```javascript
const { strings } = window.fmImageManager;
button.textContent = strings.addImage;

// Platzhalter werden im JS gefüllt (kein wp.i18n nötig):
const label = strings.showingCount
  .replace('%1$d', shown)
  .replace('%2$d', total);
```

**Folge:** Sämtliche Übersetzungen – PHP wie JS – liegen ausschließlich in den `.po`/`.mo`-Dateien. Poedit erfasst die JS-Texte automatisch mit, weil sie als `__()`-Aufrufe im PHP stehen.

### Plugin-Header

```php
 * Text Domain: feichtmedia-imagemanager-acf
 * Domain Path: /languages
```

### Dateien in `/languages`

```plain text
languages/
├── feichtmedia-imagemanager-acf.pot                 ← Template (alle msgids)
├── feichtmedia-imagemanager-acf-en_GB.po / .mo
├── feichtmedia-imagemanager-acf-de_DE.po / .mo
├── feichtmedia-imagemanager-acf-de_DE_formal.po / .mo
├── feichtmedia-imagemanager-acf-de_AT.po / .mo
└── feichtmedia-imagemanager-acf-de_CH.po / .mo
```

- **.po** – editierbare Übersetzungsquelle (Poedit). **.mo** – kompiliert, von WordPress geladen (Poedit erzeugt sie beim Speichern automatisch).
- Keine `.json` (JS-Strings kommen aus PHP) und keine `.l10n.php` nötig.
- **`.l10n.php`** (WP 6.5+) ist eine reine **Performance-Option** und kann später jederzeit ergänzt werden; ohne sie funktioniert alles vollständig über `.mo`.
- **en_US** taucht hier bewusst nicht auf (Quellsprache).

### Tooling: nur Poedit (kein Composer, kein WP-CLI, kein Build)

Der gesamte Workflow läuft über **Poedit** (GUI-Desktop-App):

1. **`.pot`/`.po` anlegen & pflegen:** Poedit scannt die PHP-Quellen (Schlüsselwörter `__`, `_e`, `esc_html__`, … + Textdomain) und erzeugt/aktualisiert das Template und die je-Locale-`.po`-Dateien. Da auch die JS-Texte als `__()` im PHP stehen (localize-Muster), werden sie automatisch miterfasst.
2. **Übersetzen:** in Poedit pro Locale (`en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`).
3. **`.mo` erzeugen:** passiert in Poedit **automatisch beim Speichern** der `.po`.

Damit entfällt jede Kommandozeile. Wer später doch automatisieren will, kann optional WP-CLI nutzen (`wp i18n make-pot`, `make-mo`, `make-php`) – das ist ein eigenständiges PHAR und braucht **ebenfalls kein Composer**. Für V1 nicht erforderlich.

### Ladereihenfolge & Veröffentlichung

- WordPress lädt Übersetzungen in dieser Reihenfolge: `wp-content/languages/plugins/` (Sprachpakete) **vor** dem gebündelten `/languages/` des Plugins.
- Bei Veröffentlichung über WordPress.org werden kanonische Locales über translate.wordpress.org (GlotPress) als Sprachpakete bereitgestellt – diese können gebündelte Dateien **überschreiben**. Für volle Kontrolle über die formal/informell-Nuancen sowie en_GB bündeln wir die Dateien und behalten im Blick, dass .org-Pakete für kanonische Locales Vorrang haben. Bei Eigen-Distribution (außerhalb .org) entfällt dieser Punkt.

### Konsequenz für die Entwicklung

- **Keine harten Strings** in PHP oder JS – PHP-Strings über i18n-Funktionen, JS-Strings vorübersetzt aus PHP.
- Quellsprache durchgängig **en_US**.
- Die sechs Ziel-Locales (`en_US` als Quelle + `en_GB`, `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`) werden mitgeliefert; jede andere Sprache fällt automatisch auf en_US zurück.
- AT und CH werden **formell (Sie)** übersetzt; CH ohne „ß" (immer „ss").
- Werkzeug: **nur Poedit** – kein Composer, kein WP-CLI, kein Build.

---

## 16. Geteilte Initialisierung & Lebenszyklus (ImageManager Core)

### Ausgangslage

Neben diesem ACF-Feld-Plugin sind weitere FeichtMedia-ImageManager-Plugins geplant (z. B. eine Block-Editor-/Medienübersicht-Integration). Alle teilen sich dieselben Optionen (`api_key`, `project_id`, `domain`) und dieselbe Options-Page. Daraus folgen zwei Anforderungen:

1. **Geteilte Initialisierung:** Mehrere Plugins versuchen, dieselbe Options-Page und dieselben Optionen aufzubauen. Das darf genau **einmal** geschehen, unabhängig von Anzahl und Ladereihenfolge der Plugins.
2. **Sauberer Lebenszyklus:** Wird ein Plugin gelöscht, dürfen die geteilten Optionen **nicht** verschwinden, solange ein anderes Plugin sie noch braucht – aber sie sollen auch nicht für immer als Datenmüll in der DB liegen bleiben.

### Lösung: gebündelte, versionsverhandelte Core-Komponente

Jedes FeichtMedia-ImageManager-Plugin bündelt eine **identische** Kopie der Komponente `shared/imagemanager-core/`. Sie ist vollständig self-contained und wird 1:1 in jedes Plugin übernommen. Statt der fragilen Regel „wer zuerst lädt, gewinnt" (die Ladereihenfolge ist alphabetisch nach Verzeichnis und unzuverlässig) registriert jede Kopie ihre **Version**; nach `plugins_loaded` bootet die **höchste** Version genau einmal. So läuft immer der neueste Shared-Code, selbst wenn ein älteres Plugin zuerst geladen wird. (Etabliertes Muster, u. a. bei Action Scheduler, Carbon Fields, Freemius.)

```php
// shared/imagemanager-core/bootstrap.php  — IDENTISCH in jedem Plugin
// Registriert diese Kopie als Kandidat; die höchste Version bootet einmal.

$GLOBALS['fm_imagemanager_core_candidates'] ??= [];
$GLOBALS['fm_imagemanager_core_candidates'][] = [
    'version' => '1.0.0', // Version DIESER gebündelten Kopie
    'file'    => __DIR__ . '/class-imagemanager-core.php',
];

// Boot-Funktion nur EINMAL deklarieren (sonst Fatal: cannot redeclare).
if (!function_exists('fm_imagemanager_core_boot')) {
    function fm_imagemanager_core_boot() {
        if (defined('FM_IMAGEMANAGER_CORE_BOOTED')) {
            return;
        }
        $candidates = $GLOBALS['fm_imagemanager_core_candidates'] ?? [];
        usort($candidates, fn($a, $b) => version_compare($b['version'], $a['version']));
        $winner = $candidates[0];

        require_once $winner['file'];
        define('FM_IMAGEMANAGER_CORE_BOOTED', $winner['version']);

        FM_ImageManager_Core::instance()->init(); // registers options + options page (idempotent)
    }
    add_action('plugins_loaded', 'fm_imagemanager_core_boot', 5);
}
```

Jedes Plugin lädt diese Datei früh in seiner Hauptdatei:

```php
require_once __DIR__ . '/includes/shared/imagemanager-core/bootstrap.php';
```

**Eigenschaften:**

- **Genau ein Boot:** `FM_IMAGEMANAGER_CORE_BOOTED` verhindert mehrfaches Initialisieren; die Options-Page wird nur einmal via `add_options_page` registriert (keine doppelten Menüeinträge).
- **Höchste Version gewinnt:** unabhängig von der Ladereihenfolge.
- **Kein Redeclare:** `function_exists()`-Guard um die Boot-Funktion; `class-imagemanager-core.php` wird nur für die Gewinner-Version `require_once`d.
- **Besitz entkoppelt:** Die Page gehört der Core-Komponente, nicht einem bestimmten Plugin – sie überlebt das Löschen einzelner Plugins.

### Consumer-Registry (Reference-Counting)

Eine geteilte Option `feichtmedia_imagemanager_consumers` führt Buch, welche Plugins die geteilten Optionen nutzen.

```php
// register_activation_hook(__FILE__, …) je Plugin
function fm_imagemanager_register_consumer(string $self): void {
    $consumers = (array) get_option('feichtmedia_imagemanager_consumers', []);
    if (!in_array($self, $consumers, true)) {
        $consumers[] = $self;
        update_option('feichtmedia_imagemanager_consumers', $consumers);
    }
}
```

- **Aktivierung:** Plugin trägt sich ein.
- **Deaktivierung:** **keine** Änderung an Registry oder Options (Plugin bleibt installiert; Daten bleiben).
- **Deinstallation:** Plugin trägt sich aus; ist die Registry danach leer, werden die geteilten Optionen gelöscht (siehe Kap. 12).

### Lebenszyklus-Übersicht

| Ereignis | Geteilte Options | Consumer-Registry | post_meta |
| --- | --- | --- | --- |
| Aktivierung Plugin A | werden bei Bedarf angelegt | `+A` | — |
| Aktivierung Plugin B | bleiben | `+B` | — |
| Deaktivierung A | bleiben | unverändert | bleibt |
| Deinstallation A | bleiben (B nutzt sie noch) | `−A` | bleibt |
| Deinstallation B (letztes) | **gelöscht** | gelöscht | bleibt |

### Robustheit (optional)

Wird ein Plugin per FTP/Datei gelöscht, ohne dass `uninstall.php` läuft, bleibt sein Slug in der Registry und die geteilten Optionen würden nie aufgeräumt. Optionaler Selbstheilungs-Mechanismus: bei `admin_init` die Registry gegen die tatsächlich installierten Plugins (`get_plugins()`) abgleichen und verwaiste Einträge entfernen. Für V1 nicht zwingend, aber empfohlen, sobald das zweite Plugin existiert.

### Distribution

Es gibt **kein** separates „Core"-Plugin. Nutzer installieren ausschließlich die konkreten Plugins (z. B. „FeichtMedia ImageManager ACF", später die Block-Editor-Integration usw.). Die Core-Komponente ist in jedem dieser Plugins gebündelt und verhandelt untereinander, welche Kopie bootet – für den Nutzer unsichtbar.

---

## 17. Backend-Performance & Skalierung (viele Feld-Instanzen)

ACF-Blöcke und Pagebuilder können **20+ Instanzen** dieses Feldes auf einer Seite erzeugen. Das Plugin darf dabei keinen messbaren Overhead verursachen – weder im Editor noch im Frontend. Leitprinzip: **pro Feld nur das Nötigste, alles Geteilte genau einmal.**

### Ein Modal, Assets einmal

- **Genau ein** File-Browser-Modal pro Seite (siehe Kap. 9). Das Modal-DOM wird **lazy beim ersten Öffnen** erzeugt und danach wiederverwendet – nie 20×. `activeFieldKey` steuert das Zielfeld.
- JS/CSS werden über **ein** Skript-/Style-Handle registriert; `wp_enqueue_*` dedupliziert pro Handle, unabhängig von der Feldanzahl.
- `wp_localize_script` gibt das Konfigurations-/Strings-Objekt **einmal** aus (am geteilten Handle), nicht pro Feld.
- Das Feld selbst rendert nur: einen versteckten Input (Bild-ID) + eine `<img>`-Vorschau (CDN). Kein Modal, kein Skript-Inline pro Instanz.

### Keine API-Calls beim Rendern

- **Editor:** Ein Feld zu rendern erzeugt **null** HTTP-/API-Aufrufe. Die Vorschau lädt direkt über die CDN (`https://{domain}/{groupId}/{imageId}`). Der Proxy wird **erst** beim Öffnen des Browsers angesprochen – also einmal pro Interaktion, nicht pro Feld.
- **Thumbnails** kommen von der CDN, nicht über den Proxy/die API.

### Frontend: `get_field()` × N

Das Verhalten hängt am Rückgabeformat:

| Rückgabeformat | Kosten pro Feld | Skalierung bei 20+ Feldern |
| --- | --- | --- |
| `relative_url` | reiner String-Bau, **0 HTTP** | unkritisch, beliebig viele |
| `absolute_url` | reiner String-Bau, **0 HTTP** | unkritisch, beliebig viele |
| `metadata` | API-Call (über Helper) **bei kaltem Cache** | mit Transient-Cache nach erstem Aufruf 0 HTTP; siehe Batching |

**Empfehlung:** In Templates/Blöcken, die keine Metadaten brauchen (also nur die URL ausgeben), das **URL-Format** verwenden – das ist der Default und kostet nichts.

### Metadaten-Format: Caching & Batching

- **Transient-Cache** pro Bild (`fm_im_meta_{imageId}`, TTL ~1 h; Kap. 7). Nach dem ersten Laden ist die Seite cache-warm → 0 HTTP. Mit persistentem Object-Cache (Redis/Memcached) sind die Lookups ohnehin In-Memory.
- **Batching (empfohlen, sobald die API es unterstützt):** Statt 20 Einzelaufrufe bei kaltem Cache alle auf der Seite vorkommenden Bild-IDs **sammeln** und in **einem** API-Request laden, dann verteilt cachen. Ob/wie die API Mehrfach-IDs annimmt, wird gegen die API-Docs geklärt. Ohne Batching bleibt es bei Einzel-Calls mit Transient-Cache als Untergrenze.
- **Kein N+1 im GraphQL-Resolver:** Der Resolver nutzt denselben gecachten Helper; bei Bedarf später ein DataLoader-/Buffer-Pattern, um IDs einer Query zu bündeln.

### Registrierungs-/Init-Overhead

- Feldtyp, Proxy-Routen und GraphQL-Typen werden **einmal** registriert (nicht pro Instanz).
- Die Core-Komponente bootet genau einmal (Kap. 16); kein doppeltes Setup bei mehreren Plugins.

### Checkliste für die Umsetzung

- [ ] Modal-DOM genau einmal erzeugen (Guard gegen Mehrfach-Init).
- [ ] Assets nur auf relevanten Admin-Screens enqueuen; ein Handle.
- [ ] Feld-Render macht keine HTTP-Calls.
- [ ] `metadata`-Helper immer über Transient; Batch-Pfad vorbereitet.
- [ ] Default-Rückgabeformat = `relative_url`.
