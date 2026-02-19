# Schritt 5 & 6 – Umsetzungsdokumentation

**Datum:** 19.02.2026
**Schritt 5:** Code-Qualitaet & Architektur (5 Massnahmen)
**Schritt 6:** Feinschliff & Haertung (5 Massnahmen)

---

## Schritt 5 – Code-Qualitaet & Architektur

### 5.1 CalendarService-Interface einfuehren

**Geaenderte Dateien:**
- `src/CalendarServiceInterface.php` – **NEU**
- `src/MicrosoftCalendarService.php` – implementiert Interface
- `src/GoogleCalendarService.php` – implementiert Interface
- `src/BookingService.php` – nutzt Interface-Typ statt `object`
- `src/AvailabilityEngine.php` – PHPDoc-Annotation fuer Array-Typ

**Was wurde gemacht:**
1. Neues Interface `CalendarServiceInterface` mit den gemeinsamen Methoden: `getSourceId()`, `isAuthenticated()`, `getAuthUrl()`, `handleCallback()`, `getFreeBusy()`, `prepareFreeBusyCurl()`, `parseFreeBusyResponse()`.
2. Beide Services implementieren das Interface (`implements CalendarServiceInterface`).
3. `BookingService::getBookingTarget()` und `getCalendarServiceBySourceId()` geben `?CalendarServiceInterface` statt `?object` zurueck.
4. `getServiceId()` nimmt `CalendarServiceInterface` als Parameter.

**Vorteil:** Formaler Vertrag – neue Kalender-Provider (z.B. Apple Calendar) muessen das Interface implementieren. IDE kann Typen pruefen.

---

### 5.2 Return-Type-Hints und PHP-Typisierung

**Ergebnis:** Alle Methoden in allen PHP-Klassen haben bereits vollstaendige Return-Type-Hints. Keine Aenderung noetig.

---

### 5.3 Magic Numbers durch Konstanten ersetzen

**Geaenderte Dateien:**
- `src/MicrosoftCalendarService.php` – 3 neue Konstanten
- `src/GoogleCalendarService.php` – 2 neue Konstanten
- `src/SecurityHelper.php` – 3 neue Konstanten
- `admin/api.php` – 3 neue Konstanten

**Ersetzte Magic Numbers:**

| Konstante | Wert | Vorher |
|-----------|------|--------|
| `TOKEN_REFRESH_BUFFER_SECONDS` | 300 | `time() + 300` |
| `MAX_CALENDAR_EVENTS` | 500 | `'$top' => 500` |
| `HTTP_TIMEOUT_SECONDS` | 30 | `CURLOPT_TIMEOUT => 30` |
| `CSRF_TOKEN_BYTES` | 32 | `random_bytes(32)` |
| `RATELIMIT_CLEANUP_AGE_SECONDS` | 7200 | `time() - 7200` |
| `HSTS_MAX_AGE_SECONDS` | 31536000 | `max-age=31536000` |
| `MAX_UPLOAD_SIZE_BYTES` | 5 MB | `5 * 1024 * 1024` |
| `MAX_ADDITIONAL_ATTENDEES` | 20 | `min(20, ...)` |
| `MIN_PASSWORD_LENGTH` | 12 | `strlen(...) < 12` |

---

### 5.4 Zentrale Error-Logging-Funktion

**Geaenderte Dateien:**
- `src/SecurityHelper.php` – neue Methode `logError()` und `redactSensitiveData()`
- `src/MicrosoftCalendarService.php` – 5x `error_log()` → `SecurityHelper::logError()`
- `src/GoogleCalendarService.php` – 3x `error_log()` → `SecurityHelper::logError()`
- `src/AvailabilityEngine.php` – 2x `error_log()` → `SecurityHelper::logError()`
- `src/BookingService.php` – 2x `error_log()` → `SecurityHelper::logError()`

**Was wurde gemacht:**
1. `SecurityHelper::logError(string $context, string $message, ?Throwable $e)` – Einheitliches Format: `[Context] Message | Exception: ...`
2. `redactSensitiveData()` – Entfernt automatisch Bearer-Tokens, access_token, refresh_token, client_secret, Passwoerter aus Log-Meldungen.
3. Alle 12 `error_log()`-Aufrufe in `src/` durch `SecurityHelper::logError()` ersetzt.

**Vorher:**
```
Microsoft Graph POST error: curl timeout
Graph API Event creation failed: Token expired: eyJhbGci...
```

**Nachher:**
```
[Graph API] POST error: curl timeout
[Booking] Event creation failed: Token expired: access_token=[REDACTED]
```

---

### 5.5 TokenStore Lese-Locking und Validierung

**Status:** Bereits in Schritt 3 umgesetzt (Shared-Lock mit `flock(LOCK_SH)`, `is_array()`-Pruefung).

---

## Schritt 6 – Feinschliff & Haertung

### 6.1 API-Methoden-Pruefung

**Status:** Bereits in Schritt 4 umgesetzt (`api/slots.php` akzeptiert nur GET, 405 bei anderen).

---

### 6.2 Content-Security-Policy fuer Embed

**Geaenderte Dateien:**
- `embed.php`

**Was wurde gemacht:**
Header `Content-Security-Policy: frame-ancestors *` hinzugefuegt. Erlaubt die Einbettung des Booking-Widgets per iframe von jeder Domain. Kann spaeter auf spezifische Domains eingeschraenkt werden.

---

### 6.3 Attendee-Duplikate verhindern

**Geaenderte Dateien:**
- `src/BookingService.php` – `book()` Methode

**Was wurde gemacht:**
1. E-Mail-Adressen werden vor dem Vergleich mit `strtolower()` normalisiert.
2. Ein `$seenEmails`-Array verhindert doppelte Eintraege – sowohl wenn zusaetzliche Teilnehmer die Hauptadresse wiederholen als auch wenn sie untereinander identisch sind.

**Vorher:** Ein Teilnehmer konnte `max@example.com` und `Max@example.com` angeben → Graph API erhielt 2 Einladungen an dieselbe Person.

**Nachher:** Case-insensitive Deduplizierung. Jede E-Mail-Adresse erscheint maximal einmal.

---

### 6.4 Admin-JS in IIFE wrappen

**Status:** Bereits vorhanden. `admin.js` Zeile 5: `(function () { 'use strict';`.

---

### 6.5 Config-Validierung bei Aenderungen

**Geaenderte Dateien:**
- `src/ConfigManager.php` – neue Methode `validateSection()`

**Was wurde gemacht:**
`saveSection()` und `saveSections()` rufen vor dem Speichern `validateSection()` auf. Validierungen:

| Config-Abschnitt | Validierung |
|-------------------|-------------|
| `app` | Timezone muss gueltige PHP-Timezone sein, Termindauer >= 5min, Slot-Intervall >= 5min, Horizont >= 1 Tag |
| `working_hours` | Nur gueltige Wochentage, jeder Tag braucht `start`+`end` oder `null` |
| `organizer` | E-Mail wird mit `FILTER_VALIDATE_EMAIL` geprueft (wenn nicht leer) |
| `calendar_sources` | Jede Quelle braucht `id` und `type` (microsoft\|google) |
| `booking_form` | `max_additional_attendees` >= 0 |

Bei Validierungsfehler wird eine `\InvalidArgumentException` geworfen, die der Admin-API-Error-Handler als Fehlermeldung zurueckgibt.

---

## Zusammenfassung geaenderter Dateien

| Datei | Aenderungen |
|-------|-------------|
| `src/CalendarServiceInterface.php` | **NEU** – Interface fuer Kalender-Services |
| `src/MicrosoftCalendarService.php` | Implements Interface, Konstanten, logError() |
| `src/GoogleCalendarService.php` | Implements Interface, Konstanten, logError() |
| `src/AvailabilityEngine.php` | PHPDoc, SecurityHelper-Import, logError() |
| `src/BookingService.php` | Interface-Typen, Attendee-Deduplizierung, logError() |
| `src/SecurityHelper.php` | logError(), redactSensitiveData(), Konstanten |
| `src/ConfigManager.php` | validateSection() Typ- und Werte-Pruefung |
| `admin/api.php` | Konstanten statt Magic Numbers |
| `embed.php` | Content-Security-Policy: frame-ancestors |

**Bereits in frueheren Schritten erledigt:** 5.5 (Schritt 3), 6.1 (Schritt 4), 6.4 (schon vorhanden)

---

## Gesamtstand nach Schritt 6

Alle 30 Massnahmen aus dem Audit-Verbesserungsplan sind jetzt umgesetzt:

| Schritt | Fokus | Status |
|---------|-------|--------|
| 1 | Kritische Sicherheitsluecken | Erledigt |
| 2 | Sicherheit + Performance | Erledigt |
| 3 | Fehlerbehandlung & Robustheit | Erledigt |
| 4 | Frontend-Performance & HTTP | Erledigt |
| 5 | Code-Qualitaet & Architektur | Erledigt |
| 6 | Feinschliff & Haertung | Erledigt |
