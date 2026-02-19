# Audit & Verbesserungsplan – MSP Support Terminvereinbarung

**Datum:** 18.02.2026
**Umfang:** Security-Audit, Performance-Audit, Code-Qualitaet-Audit
**Ergebnis:** 25 Security-Findings, 18 Performance-Findings, 50 Code-Quality-Findings

---

## Zusammenfassung der Findings

| Kategorie     | Kritisch | Hoch | Mittel | Niedrig |
|---------------|----------|------|--------|---------|
| Security      | 4        | 7    | 9      | 5       |
| Performance   | 0        | 5    | 5      | 8       |
| Code-Qualitaet| 6       | 6    | 10     | 28      |

---

## Schritt 1 – Kritische Sicherheitsluecken schliessen

> **Prioritaet:** SOFORT | **Risiko ohne Fix:** Hoch (Datenverlust, unautorisierter Zugriff)

### 1.1 CSRF-Schutz implementieren
- **Datei:** `admin/api.php`, `api/book.php`
- **Problem:** Keine CSRF-Token-Validierung. Angreifer koennen ueber praeparierte Webseiten Admin-Aktionen oder Buchungen ausloesen.
- **Massnahme:** CSRF-Token in Session generieren, bei jedem POST-Request validieren. Token im Admin-Panel als Meta-Tag und im Booking-Formular als Hidden-Field.
- **Aufwand:** ~2h

### 1.2 OAuth State-Parameter validieren
- **Datei:** `admin/auth-microsoft.php`, `admin/auth-google.php`
- **Problem:** Der OAuth-State-Parameter wird direkt als Source-ID verwendet ohne Validierung. Manipulation moeglich.
- **Massnahme:** Zufaelligen State-Wert in Session speichern, beim Callback gegen Session pruefen. Source-ID separat in Session ablegen.
- **Aufwand:** ~1h

### 1.3 Security-Headers setzen
- **Datei:** Alle PHP-Endpoints + `.htaccess`
- **Problem:** Fehlende Headers: X-Frame-Options, X-Content-Type-Options, CSP, HSTS, Referrer-Policy.
- **Massnahme:** Zentrale `headers.php` erstellen, die von allen Endpoints eingebunden wird:
  ```
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
  Strict-Transport-Security: max-age=31536000
  Referrer-Policy: strict-origin-when-cross-origin
  Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'
  ```
- **Aufwand:** ~1h

### 1.4 Admin-Authentifizierung absichern
- **Datei:** `admin/index.php`
- **Problem:** Ohne gesetztes Passwort ist das Admin-Panel komplett offen. Kein `session_regenerate_id()` nach Login (Session-Fixation).
- **Massnahme:** Passwort-Pflicht beim ersten Aufruf erzwingen. Nach erfolgreichem Login `session_regenerate_id(true)` aufrufen. Session-Cookie mit `httponly`, `secure`, `samesite=strict`.
- **Aufwand:** ~2h

### 1.5 Rate-Limiting fuer Buchungs-Endpoint
- **Datei:** `api/book.php`
- **Problem:** Keine Begrenzung der Buchungsanfragen. Missbrauch (Spam, API-Quota-Erschoepfung) moeglich.
- **Massnahme:** Dateibasiertes Rate-Limiting (z.B. `data/ratelimit/`): Max. 5 Buchungen pro IP pro Stunde. Alternativ Token-Bucket in Session.
- **Aufwand:** ~2h

---

## Schritt 2 – Sicherheit haerten & kritische Performance-Probleme beheben

> **Prioritaet:** Diese Woche | **Risiko ohne Fix:** Mittel-Hoch

### 2.1 Passwort-Anforderungen erhoehen
- **Datei:** `admin/api.php` (Zeile 339)
- **Problem:** Minimum nur 6 Zeichen – zu schwach.
- **Massnahme:** Minimum 12 Zeichen. Warnung bei bekannten schwachen Passwoertern.
- **Aufwand:** ~30min

### 2.2 Input-Laengenbegrenzung
- **Datei:** `src/BookingService.php` (validateBooking)
- **Problem:** Keine Laengenbegrenzung bei Name, E-Mail, Zusatzfeldern. Extrem lange Strings moeglich.
- **Massnahme:** Max. 100 Zeichen fuer Name, 254 fuer E-Mail, 500 fuer Freitextfelder. Pruefen in `validateBooking()`.
- **Aufwand:** ~1h

### 2.3 Cache-Busting mit Content-Hash statt time()
- **Datei:** `index.php`, `embed.php`
- **Problem:** `?v=<?= time() ?>` erzeugt bei jedem Seitenaufruf neue URL – Browser-Cache wird komplett umgangen.
- **Massnahme:** `filemtime()` oder `md5_file()` verwenden:
  ```php
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime('assets/css/style.css') ?>">
  ```
- **Aufwand:** ~15min

### 2.4 Redundante Slot-Verfuegbarkeitspruefung entfernen
- **Datei:** `src/BookingService.php` (Zeilen 85-97)
- **Problem:** Bei jeder Buchung wird `getAvailableSlots()` erneut aufgerufen – ein kompletter API-Call zu M365/Google nur zur Validierung.
- **Massnahme:** Statt nochmal alle Slots abzufragen, direkt pruefen ob der Zeitraum frei ist (einzelner Free/Busy-Check fuer den konkreten Slot).
- **Aufwand:** ~2h

### 2.5 Reflection durch public Getter ersetzen
- **Datei:** `src/BookingService.php` (Zeilen 230-236)
- **Problem:** `ReflectionProperty` wird benutzt um private `$sourceId` zu lesen – langsam und fragil.
- **Massnahme:** `getSourceId(): string` Methode zu `MicrosoftCalendarService` und `GoogleCalendarService` hinzufuegen. Reflection entfernen.
- **Aufwand:** ~15min

---

## Schritt 3 – Fehlerbehandlung & Robustheit

> **Prioritaet:** Naechste Woche | **Risiko ohne Fix:** Mittel (stille Fehler, unzuverlaessige Verfuegbarkeit)

### 3.1 curl-Fehlerbehandlung hinzufuegen
- **Datei:** `src/MicrosoftCalendarService.php`, `src/GoogleCalendarService.php`, `src/AvailabilityEngine.php`
- **Problem:** Alle `curl_exec()`-Aufrufe pruefen nicht auf Fehler. Bei Netzwerkproblemen wird `false` an `json_decode()` uebergeben – stille Fehler.
- **Massnahme:** Nach jedem `curl_exec()`: `curl_errno()` pruefen, HTTP-Statuscode mit `curl_getinfo($ch, CURLINFO_HTTP_CODE)` validieren, Fehler loggen.
- **Aufwand:** ~2h

### 3.2 Token-Refresh Race-Condition absichern
- **Datei:** `src/MicrosoftCalendarService.php` (Zeilen 259-277), `src/TokenStore.php`
- **Problem:** Parallele Requests koennen gleichzeitig Token-Refresh ausloesen – doppelte Writes, inkonsistente Tokens.
- **Massnahme:** File-Locking (`LOCK_EX`) auch beim Lesen in TokenStore. Alternativ: Token-Refresh mit Mutex (Lockfile).
- **Aufwand:** ~2h

### 3.3 Zeitformat-Parsing absichern
- **Datei:** `src/AvailabilityEngine.php` (Zeilen 37-45, 131-139)
- **Problem:** `substr($workingHours['start'], 0, 2)` erwartet Format "HH:MM". Bei "9:00" statt "09:00" falsche Ergebnisse.
- **Massnahme:** Helper-Methode `parseTime(string $time): array` mit Validierung und `explode(':', $time)` statt fester String-Positionen.
- **Aufwand:** ~1h

### 3.4 Path-Traversal bei Bild-Loeschung verhindern
- **Datei:** `admin/api.php` (Zeilen 289-298)
- **Problem:** Alte Bilder werden mit `dirname(__DIR__) . '/' . $oldPath` geloescht. Falls `$oldPath` Traversal-Sequenzen enthaelt (aus Config), koennten beliebige Dateien geloescht werden.
- **Massnahme:** `realpath()` pruefen und sicherstellen, dass Pfad innerhalb von `uploads/` liegt:
  ```php
  $realPath = realpath($fullPath);
  if ($realPath && str_starts_with($realPath, realpath($uploadDir))) { unlink($realPath); }
  ```
- **Aufwand:** ~30min

### 3.5 Upload-Verzeichnis-Berechtigungen einschraenken
- **Datei:** `admin/api.php` (Zeile 291)
- **Problem:** Upload-Verzeichnis wird mit `0755` erstellt – weltweit lesbar.
- **Massnahme:** Auf `0750` aendern. `.htaccess` im Upload-Ordner mit `php_flag engine off` um PHP-Ausfuehrung zu verhindern.
- **Aufwand:** ~15min

---

## Schritt 4 – Frontend-Performance & HTTP-Optimierung

> **Prioritaet:** Naechster Sprint | **Risiko ohne Fix:** Niedrig (UX-Verbesserung)

### 4.1 HTTP-Cache-Headers fuer GET-APIs
- **Datei:** `api/slots.php`
- **Problem:** `Cache-Control: no-cache, no-store` verhindert jegliches Caching – auch fuer wiederholte Abfragen desselben Monats.
- **Massnahme:** Fuer `?action=days`: `Cache-Control: private, max-age=60` (1 Minute). Fuer `?action=slots`: `max-age=30`. Fuer `book.php`: `no-store` beibehalten.
- **Aufwand:** ~30min

### 4.2 Hardcodierte Timezone durch Config ersetzen
- **Datei:** `src/MicrosoftCalendarService.php` (Zeile 123), `src/GoogleCalendarService.php` (Zeile 105)
- **Problem:** `"Europe/Berlin"` ist in API-Headers und Request-Bodies hardcodiert statt aus `$config['app']['timezone']` zu lesen.
- **Massnahme:** Config-Timezone an Services uebergeben oder aus Config lesen.
- **Aufwand:** ~30min

### 4.3 Lazy-Loading fuer Calendar-Services
- **Datei:** `src/BookingService.php` (Konstruktor, Zeilen 28-29)
- **Problem:** `initCalendarServices()` initialisiert ALLE Services bei jedem Request – inkl. Token-Validierung und Objekt-Erstellung.
- **Massnahme:** Services erst bei Bedarf initialisieren (Lazy-Init Pattern). `getCalendarServices()` Methode statt Konstruktor-Init.
- **Aufwand:** ~1.5h

### 4.4 HTML-Template-Duplikation beseitigen
- **Datei:** `index.php` (~213 Zeilen), `embed.php` (~209 Zeilen)
- **Problem:** ~85% identisches HTML-Markup. Aenderungen muessen in beiden Dateien gemacht werden.
- **Massnahme:** Gemeinsames Template `templates/booking-form.php` extrahieren. `index.php` und `embed.php` binden es mit unterschiedlichem Layout ein.
- **Aufwand:** ~2h

### 4.5 Frontend-Fehlerbehandlung verbessern
- **Datei:** `assets/js/app.js`
- **Problem:** Prefetch-Fehler werden still verschluckt (`catch(() => {})`). Wenn Prefetch fehlschlaegt und User zum Monat navigiert, kann leerer Kalender erscheinen.
- **Massnahme:** Bei Prefetch-Fehler den Cache-Key NICHT setzen (schon korrekt), aber bei Navigation mit fehlendem Cache erneut laden. Optional: Retry nach 5 Sekunden.
- **Aufwand:** ~30min

---

## Schritt 5 – Code-Qualitaet & Architektur

> **Prioritaet:** Mittelfristig | **Risiko ohne Fix:** Niedrig (Wartbarkeit)

### 5.1 CalendarService-Interface einfuehren
- **Datei:** Neue Datei `src/CalendarServiceInterface.php`
- **Problem:** `MicrosoftCalendarService` und `GoogleCalendarService` haben gemeinsame Methoden ohne formalen Vertrag.
- **Massnahme:** Interface mit `getFreeBusy()`, `prepareFreeBusyCurl()`, `parseFreeBusyResponse()`, `getSourceId()`, `isAuthenticated()`. Beide Services implementieren es.
- **Aufwand:** ~1h

### 5.2 Return-Type-Hints und PHP-Typisierung
- **Datei:** Alle PHP-Klassen in `src/`
- **Problem:** Viele Methoden ohne Return-Type-Hints. Erschwert Fehlersuche und IDE-Unterstuetzung.
- **Massnahme:** Return-Types (`array`, `string`, `bool`, `?object`, `void`) zu allen public/private Methoden hinzufuegen.
- **Aufwand:** ~1h

### 5.3 Magic Numbers durch Konstanten ersetzen
- **Datei:** Diverse
- **Problem:** `300` (Token-Puffer), `500` (API-Limit), `5 * 1024 * 1024` (Upload-Limit), `20` (Max-Attendees) sind als Zahlen im Code verstreut.
- **Massnahme:** Konstanten in den jeweiligen Klassen definieren:
  ```php
  private const TOKEN_REFRESH_BUFFER_SECONDS = 300;
  private const MAX_CALENDAR_EVENTS = 500;
  ```
- **Aufwand:** ~1h

### 5.4 Zentrale Error-Logging-Funktion
- **Datei:** Neue Hilfsfunktion oder Klasse
- **Problem:** `error_log()` wird inkonsistent mit unterschiedlichen Formaten verwendet. Manche Stellen loggen sensitive Daten.
- **Massnahme:** Helper `logError(string $context, string $message, ?Throwable $e = null)` der einheitlich formatiert und keine Tokens/Secrets loggt.
- **Aufwand:** ~1h

### 5.5 TokenStore Lese-Locking und Validierung
- **Datei:** `src/TokenStore.php`
- **Problem:** `file_get_contents()` liest ohne Lock – kann waehrend eines Writes partielles JSON erhalten. Keine Validierung der gelesenen Daten.
- **Massnahme:** `fopen()` + `flock(LOCK_SH)` fuer Reads. Nach JSON-Decode pruefen ob erwartete Keys vorhanden.
- **Aufwand:** ~1h

---

## Schritt 6 – Feinschliff & Haertung

> **Prioritaet:** Langfristig | **Risiko ohne Fix:** Sehr niedrig

### 6.1 API-Methoden-Pruefung
- **Datei:** `api/slots.php`
- **Problem:** Akzeptiert alle HTTP-Methoden, nicht nur GET.
- **Massnahme:** `if ($_SERVER['REQUEST_METHOD'] !== 'GET')` mit 405-Response.
- **Aufwand:** ~10min

### 6.2 Content-Security-Policy fuer Embed
- **Datei:** `embed.php`
- **Problem:** Embed-Seite hat keinen X-Frame-Options Header – absichtlich fuer iFrame-Einbettung. Aber keine CSP frame-ancestors Einschraenkung.
- **Massnahme:** `Content-Security-Policy: frame-ancestors *` fuer embed.php (oder auf erlaubte Domains einschraenken).
- **Aufwand:** ~15min

### 6.3 Attendee-Duplikate verhindern
- **Datei:** `src/BookingService.php` (Zeilen 107-117)
- **Problem:** Doppelte E-Mail-Adressen in Teilnehmerliste werden nicht erkannt.
- **Massnahme:** `array_unique()` auf Attendee-Liste vor Event-Erstellung.
- **Aufwand:** ~10min

### 6.4 Admin-JS in IIFE wrappen
- **Datei:** `assets/js/admin.js`
- **Problem:** Funktionen im globalen Scope (anders als `app.js` das eine IIFE nutzt).
- **Massnahme:** Gesamten Code in `(function() { 'use strict'; ... })();` wrappen.
- **Aufwand:** ~10min

### 6.5 Config-Validierung bei Aenderungen
- **Datei:** `src/ConfigManager.php`
- **Problem:** Gespeicherte Config wird bei `mergeDefaults()` nicht typ-validiert. Falscher Typ (z.B. `timezone: 123`) wird akzeptiert.
- **Massnahme:** Validierungsmethode `validateConfig(array $config): array` die Typen und Wertebereiche prueft.
- **Aufwand:** ~2h

---

## Uebersicht Gesamtaufwand

| Schritt | Fokus                              | Massnahmen | Geschaetzter Aufwand |
|---------|------------------------------------|------------|----------------------|
| 1       | Kritische Sicherheitsluecken       | 5          | ~8h                  |
| 2       | Sicherheit + Performance (kritisch)| 5          | ~6h                  |
| 3       | Fehlerbehandlung & Robustheit      | 5          | ~6h                  |
| 4       | Frontend-Performance & HTTP        | 5          | ~5h                  |
| 5       | Code-Qualitaet & Architektur       | 5          | ~5h                  |
| 6       | Feinschliff & Haertung             | 5          | ~3h                  |
| **Gesamt** | **6 Schritte**                  | **30**     | **~33h**             |

---

## Empfohlene Reihenfolge

```
Woche 1:  Schritt 1 (Kritische Security)
Woche 1:  Schritt 2 (Security + Performance)
Woche 2:  Schritt 3 (Fehlerbehandlung)
Woche 2:  Schritt 4 (Frontend-Optimierung)
Woche 3:  Schritt 5 (Code-Qualitaet)
Woche 3+: Schritt 6 (Feinschliff)
```
