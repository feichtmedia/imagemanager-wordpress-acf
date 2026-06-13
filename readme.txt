=== FeichtMedia ImageManager ACF ===
Contributors: feichtmedia
Tags: acf, advanced custom fields, feichtmedia, imagemanager, dam, digital asset management, image optimization
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later

Integration des FeichtMedia ImageManager in Advanced Custom Fields (ACF) in WordPress.

== Beschreibung ==

Dieses Plugin ergänzt Advanced Custom Fields (ACF) um einen nativen Feldtyp für den **FeichtMedia ImageManager** – Ihr Digital Asset Management System. Redakteure wählen Bilder bequem über einen integrierten Dateibrowser direkt im WP-Admin-Bereich aus, ohne iFrame und ohne externe Weiterleitungen.

**Voraussetzungen:**

* WordPress 7.0 oder neuer
* PHP 8.2 oder neuer
* Advanced Custom Fields (kostenlose oder PRO-Version)

**Hauptfunktionen:**

* Nativer WP-Admin-Dateibrowser – kein iFrame, keine Cross-Origin-Problematik
* Server-seitiger API-Proxy – der API-Schlüssel verlässt niemals den Server; API-Anfragen sind nur über den WordPress-Editor möglich
* Drei Rückgabeformate: relative URL, absolute URL, Metadaten-Objekt
* WPGraphQL-Integration (Typ `String` und benutzerdefinierter Typ `ImageManagerImage`)
* Abwärtskompatibel mit gespeicherten relativen URLs aus einfachen Textfeldern
* Metadaten-Cache über WordPress Transients (konfigurierbare Laufzeit, Standard: 1 Stunde)
* Mehrsprachig: en_US (Quelle), en_GB, de_DE, de_DE_formal, de_AT, de_CH

== Installation ==

1. Laden Sie den Plugin-Ordner in das Verzeichnis `/wp-content/plugins/` hoch.
2. Aktivieren Sie das Plugin unter **Plugins** im WordPress-Admin.
3. Navigieren Sie zu **Einstellungen → FeichtMedia ImageManager** und tragen Sie Ihren API-Schlüssel, die Projekt-ID und die CDN-Domain ein.
4. Fügen Sie in einer beliebigen ACF-Feldgruppe ein Feld vom Typ **ImageManager > Bild** hinzu.

Nach der Einrichtung erscheint im ACF-Feldeditor eine neue Kategorie „FeichtMedia ImageManager" mit dem Feldtyp **ImageManager Image**.

== Häufig gestellte Fragen ==

= Welche Plugins werden vorausgesetzt? =

Zwingend erforderlich ist **Advanced Custom Fields** (kostenlose oder PRO-Version). Das Plugin gibt im WordPress-Admin einen Hinweis aus, wenn ACF nicht installiert oder aktiv ist.

= Ist WPGraphQL erforderlich? =

Nein. Die WPGraphQL-Integration ist optional und wird automatisch aktiviert, sofern WPGraphQL und WPGraphQL for ACF (v2.x) installiert und aktiv sind. Ohne diese Plugins funktioniert der Feldtyp vollständig.

= Wie richte ich das Plugin ein? =

Nach der Aktivierung gehen Sie zu **Einstellungen → FeichtMedia ImageManager**. Dort tragen Sie drei Werte ein:

1. **API-Schlüssel** – Ihr persönlicher Zugangsschlüssel für den ImageManager. Er wird verschlüsselt in der WordPress-Datenbank gespeichert und niemals an den Browser übertragen.
2. **Projekt-ID** – Die ID Ihres ImageManager-Projekts (auch Usergroup-ID genannt), z. B. `wordpress`. Sie ist Bestandteil jeder CDN-URL.
3. **CDN-Domain** – Die Domain Ihres CDN ohne Protokoll und ohne abschließenden Schrägstrich, z. B. `cdn.beispiel.de`. Das Plugin ergänzt `https://` automatisch.

= Was gebe ich als CDN-Domain ein? =

Tragen Sie die Domain **ohne** Protokoll und **ohne** abschließenden Schrägstrich ein, z. B. `cdn.beispiel.de`. Das Plugin ergänzt `https://` beim Speichern automatisch. Sollte versehentlich `https://` vorangestellt sein, wird es beim Speichern entfernt.

= Welche API-Schlüssel-Berechtigungen werden benötigt? =

Das Plugin greift ausschließlich lesend auf den ImageManager zu. Für den Dateibrowser und die Metadaten-Abfragen benötigt der API-Schlüssel mindestens folgende Berechtigungen:

* `image:list` – Bildliste im Dateibrowser anzeigen
* `image:read` – Metadaten eines einzelnen Bildes abrufen (für das Rückgabeformat „Metadaten")
* `category:list` – Kategorieordner im Dateibrowser laden
* `category:read` – Einzelne Kategorie laden (für die Breadcrumb-Navigation)

Die Berechtigung `*:read` (Lesezugriff auf alle Ressourcen) deckt alle obigen Punkte mit einem einzigen Eintrag ab und ist die empfohlene Wahl, sofern Ihr ImageManager-Konto Wildcard-Berechtigungen unterstützt.

API-Schlüssel, die ausschließlich Schreibberechtigungen (`image:create`, `image:update` usw.) gewähren, führen im Dateibrowser zu 403-Fehlern und dürfen nicht verwendet werden.

= Wird der API-Schlüssel an den Browser übertragen? =

Nein. Alle Anfragen an den ImageManager werden über einen serverseitigen WP-REST-API-Proxy geleitet. Der API-Schlüssel wird ausschließlich auf dem Server aus der WordPress-Datenbank gelesen und ist zu keinem Zeitpunkt im Browser oder in der Netzwerkkommunikation des Browsers sichtbar.

= Was wird in der Datenbank gespeichert? =

Pro Bild wird ausschließlich die **Bild-ID** (das Feld `newFilename`, z. B. `20260612-085316-mein-foto.jpg`) als `post_meta` gespeichert. Keine URLs, keine Metadaten, keine CDN-Domain. Die vollständige URL wird zur Laufzeit aus der gespeicherten Bild-ID, der Projekt-ID und der CDN-Domain zusammengesetzt.

= Welche Rückgabeformate stehen zur Verfügung und wann setze ich welches ein? =

Das Rückgabeformat legen Sie pro ACF-Feld in den Feldeinstellungen fest:

* **Relative URL** (Standard) – Gibt einen Pfad zurück, z. B. `/wordpress/20260612-085316-mein-foto.jpg`. Kein API-Aufruf, schnellste Option. Geeignet, wenn Ihr Theme die vollständige URL selbst zusammensetzt oder den Pfad an einen CDN-Helper weitergibt.
* **Absolute URL** – Gibt die vollständige CDN-URL zurück, z. B. `https://cdn.beispiel.de/wordpress/…`. Ebenfalls kein API-Aufruf. Geeignet, wenn Sie eine direkt verwendbare URL in Templates benötigen.
* **Metadaten** – Gibt ein Objekt mit `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype` und `filesize` zurück. Erfordert einen API-Aufruf pro Bild und Cache-Intervall (Standard: 1 Stunde, konfigurierbar). Geeignet, wenn Ihr Template Alt-Text, Copyright-Angaben oder Bildabmessungen benötigt.

= Was bedeutet die Projekt-ID? =

Die Projekt-ID (auch Usergroup-ID genannt) ist die eindeutige Kennung Ihres ImageManager-Projekts, z. B. `wordpress`. Sie ist Bestandteil jeder CDN-URL und wird benötigt, um aus einer gespeicherten Bild-ID die vollständige Bild-URL zu konstruieren. Sie finden die Projekt-ID in Ihrem ImageManager-Dashboard.

= Wie funktioniert der Dateibrowser im Editor? =

Klicken Sie im ACF-Feld auf **Bild hinzufügen** (oder **Bild ändern**, wenn bereits eines gesetzt ist). Es öffnet sich ein modaler Dateibrowser, der Ihnen die Kategorien und Bilder aus Ihrem ImageManager-Projekt anzeigt:

* Oben werden Kategorie-Kacheln (Ordner) angezeigt, darunter die Bilder der aktuellen Kategorie.
* Auf der obersten Ebene sehen Sie sowohl Unterkategorien als auch nicht kategorisierte Bilder.
* Über das Suchfeld können Sie projektübergreifend nach Bildern suchen.
* Ein Klick auf ein Bild wählt es aus; ein weiterer Klick hebt die Auswahl wieder auf.
* Klicken Sie auf **Auswählen**, um das gewählte Bild in das Feld zu übernehmen.
* Das Info-Symbol auf einer Bildkachel öffnet das Bild direkt im ImageManager-Dashboard.

= Ich habe Metadaten im ImageManager aktualisiert, aber die Änderungen erscheinen nicht in WordPress. Warum? =

Das Rückgabeformat **Metadaten** speichert die Daten jedes Bildes als WordPress Transient. Das ist beabsichtigt: Der ImageManager-API wird entlastet und Seiten mit vielen Bildern überschreiten das API-Request-Limit nicht.

Die Cache-Laufzeit beträgt standardmäßig **3.600 Sekunden (1 Stunde)** und kann unter **Einstellungen → FeichtMedia ImageManager** im Abschnitt **ACF-Feld** angepasst oder vollständig deaktiviert werden.

Änderungen an Titel, Alt-Text, Copyright oder Abmessungen im ImageManager erscheinen erst nach Ablauf des Transients oder nach einer manuellen Cache-Löschung in WordPress.

Um den Cache für ein bestimmtes Bild sofort zu löschen, stehen folgende Möglichkeiten zur Verfügung:

* **WP-CLI:** `wp transient delete fm_img_meta_$(php -r "echo md5('IHRE_BILD_ID');")`
* **Code / mu-plugin:** `delete_transient( 'fm_img_meta_' . md5( 'IHRE_BILD_ID' ) );`
* **Caching-Plugin:** Nutzen Sie die Funktion „Alle Transients löschen" oder „Object-Cache leeren" Ihres Caching-Plugins.

Die Formate **Relative URL** und **Absolute URL** werden nie gecacht – sie werden ausschließlich aus der gespeicherten Bild-ID, der Projekt-ID und der CDN-Domain zur Laufzeit berechnet, ohne API-Aufrufe.

= Kann ich die Cache-Laufzeit anpassen oder den Cache deaktivieren? =

Ja. Unter **Einstellungen → FeichtMedia ImageManager** im Abschnitt **ACF-Feld** können Sie:

* Die **Cache-Laufzeit** in Sekunden festlegen (Standard: 3.600). Der Wert `0` bedeutet, dass der Cache nie abläuft.
* Den **Cache vollständig deaktivieren**, indem Sie die entsprechende Option deaktivieren. In diesem Fall wird bei jedem Seitenaufruf ein API-Request ausgeführt.

= Was passiert, wenn mehrere Redakteure gleichzeitig arbeiten? =

Das Plugin ist rein lesend und schreibt ausschließlich die Bild-ID als `post_meta`. Konflikte entstehen nur durch das übliche WordPress-Speicherverhalten bei gleichzeitiger Bearbeitung desselben Beitrags, nicht durch das Plugin selbst.

= Wird WPGraphQL unterstützt? =

Ja. Sofern **WPGraphQL** und **WPGraphQL for ACF** (v2.x) installiert und aktiv sind, registriert das Plugin automatisch einen GraphQL-Feldtyp-Handler:

* Rückgabeformate `relative_url` und `absolute_url` werden als `String` zurückgegeben.
* Das Rückgabeformat `metadata` gibt den benutzerdefinierten Objekttyp `ImageManagerImage` zurück mit den Feldern: `imageId`, `relativeUrl`, `absoluteUrl`, `orgFilename`, `title`, `alt`, `copyright`, `width`, `height`, `filetype`, `filesize`.

= Was passiert mit gespeicherten Daten, wenn das Plugin deaktiviert wird? =

Nichts. Deaktivierung löscht keinerlei Daten. Gespeicherte Bild-IDs bleiben als `post_meta` erhalten und sind nach einer erneuten Aktivierung sofort wieder verfügbar.

= Was passiert beim Deinstallieren des Plugins? =

Beim Deinstallieren entfernt das Plugin seine eigenen Einstellungen (`feichtmedia_imagemanager_acf_cache_enabled`, `feichtmedia_imagemanager_acf_cache_ttl`). Die **gemeinsamen Einstellungen** (API-Schlüssel, Projekt-ID, CDN-Domain) werden nur gelöscht, wenn kein anderes FeichtMedia-ImageManager-Plugin mehr aktiv ist. Sind weitere solche Plugins installiert, bleiben die gemeinsamen Einstellungen erhalten. `post_meta` (gespeicherte Bild-IDs) wird **niemals** gelöscht.

= Wie verwende ich das Feld in meinem Theme oder Plugin? =

Rufen Sie das ACF-Feld wie gewohnt mit `get_field()` oder `the_field()` ab. Der zurückgegebene Wert hängt vom konfigurierten Rückgabeformat ab:

**Relative URL:**
`$path = get_field('mein_imagemanager_feld'); // z. B. /wordpress/20260612-085316-mein-foto.jpg`

**Absolute URL:**
`$url = get_field('mein_imagemanager_feld'); // z. B. https://cdn.beispiel.de/wordpress/…`

**Metadaten:**
`$bild = get_field('mein_imagemanager_feld');`
`// $bild['alt'], $bild['title'], $bild['absoluteUrl'], $bild['width'], …`

= Werden Bilder direkt in WordPress hochgeladen oder gespeichert? =

Nein. Das Plugin lädt keine Bilder in WordPress hoch und speichert auch keine Bilddateien. Es speichert ausschließlich die Bild-ID (einen kurzen Textstring) als `post_meta`. Bilder werden weiterhin über den FeichtMedia ImageManager und Ihr CDN ausgeliefert.

= Kann das Plugin Bilder hochladen, umbenennen oder löschen? =

Nein. Das Plugin hat ausschließlich Lesezugriff auf den ImageManager. Es lädt Bilder weder hoch noch bearbeitet oder löscht es sie.

== Changelog ==

= 1.0.0 – 2026-06-13 =
* Erstveröffentlichung.

== Upgrade-Hinweise ==

= 1.0.0 =
Erstveröffentlichung.
