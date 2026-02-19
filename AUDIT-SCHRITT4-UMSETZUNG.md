# Schritt 4 – Umsetzungsdokumentation

**Datum:** 19.02.2026
**Schritt:** Frontend-Performance & HTTP-Optimierung (5 Massnahmen)

---

## 4.1 HTTP-Cache-Headers fuer GET-APIs

### Geaenderte Dateien
- `api/slots.php`

### Was wurde gemacht
1. **Blanket `no-cache, no-store` entfernt** – wurde bei jedem Request gesetzt und verhinderte jegliches Browser-Caching.
2. **Differenzierte Cache-Strategie:**
   - `?action=days` (Monats-Uebersicht): `Cache-Control: private, max-age=60` – 1 Minute Cache. Bei erneutem Aufrufen desselben Monats wird der Browser-Cache genutzt.
   - `?action=slots` (Tages-Slots): `Cache-Control: private, max-age=30` – 30 Sekunden Cache. Kuerzer, da Slot-Verfuegbarkeit sich schneller aendern kann.
   - `api/book.php` (Buchung): behaelt `no-cache, no-store` bei (POST-Endpoint, keine Caching-Aenderung).
3. **HTTP-Methoden-Pruefung hinzugefuegt:** `api/slots.php` akzeptiert jetzt nur noch GET-Requests (405 bei anderen Methoden).

### Vorher
```
Jeder GET-Request: Cache-Control: no-cache, no-store
→ Browser-Cache komplett deaktiviert, selbst bei identischen Abfragen
```

### Nachher
```
?action=days:  Cache-Control: private, max-age=60
?action=slots: Cache-Control: private, max-age=30
POST/PUT/...:  HTTP 405 (Method Not Allowed)
```

---

## 4.2 Hardcodierte Timezone durch Config ersetzen

### Geaenderte Dateien
- `src/MicrosoftCalendarService.php` – Konstruktor, `prepareFreeBusyCurl()`, `graphGet()`, `createEvent()`
- `src/GoogleCalendarService.php` – Konstruktor, `prepareFreeBusyCurl()`
- `src/BookingService.php` – `initCalendarServices()`, `getCalendarServiceBySourceId()`

### Was wurde gemacht
1. **Neuer Konstruktor-Parameter `$timezone`** in beiden Calendar-Services. Default: `'Europe/Berlin'` (abwaertskompatibel).
2. **Alle hardcodierten `'Europe/Berlin'` ersetzt** durch `$this->timezone`:
   - MicrosoftCalendarService: 2x `Prefer: outlook.timezone=...` Header, 2x `timeZone` im Event-Body
   - GoogleCalendarService: 1x `timeZone` im FreeBusy-Request-Body
3. **BookingService uebergibt Config-Timezone** (`$config['app']['timezone']`) an beide Service-Konstruktoren.

### Vorher
```php
// Hardcodiert in MicrosoftCalendarService:
'Prefer: outlook.timezone="Europe/Berlin"'
'timeZone' => $eventData['timezone'] ?? 'Europe/Berlin'

// Hardcodiert in GoogleCalendarService:
'timeZone' => 'Europe/Berlin'
```

### Nachher
```php
// Konfigurierbar ueber $config['app']['timezone']:
'Prefer: outlook.timezone="' . $this->timezone . '"'
'timeZone' => $eventData['timezone'] ?? $this->timezone

'timeZone' => $this->timezone
```

---

## 4.3 Lazy-Loading fuer Calendar-Services

### Geaenderte Dateien
- `src/BookingService.php` – Konstruktor, neue Methoden `getCalendarServices()`, `getAvailabilityEngine()`

### Was wurde gemacht
1. **Konstruktor verschlankt:** `initCalendarServices()` und `AvailabilityEngine`-Erstellung werden nicht mehr im Konstruktor aufgerufen.
2. **Lazy-Init Pattern:** Zwei neue private Methoden:
   - `getCalendarServices()`: Initialisiert Services beim ersten Aufruf, gibt danach den Cache zurueck.
   - `getAvailabilityEngine()`: Erstellt die AvailabilityEngine beim ersten Aufruf.
3. **Alle Zugriffe umgestellt:** `$this->availabilityEngine` → `$this->getAvailabilityEngine()`, `$this->calendarServices` → `$this->getCalendarServices()`.

### Vorher
```php
public function __construct()
{
    $this->config = require __DIR__ . '/../config.php';
    $this->tokenStore = new TokenStore(...);
    $this->initCalendarServices();    // IMMER Token-Validierung
    $this->availabilityEngine = new AvailabilityEngine(...);  // IMMER erstellt
}
```

### Nachher
```php
public function __construct()
{
    $this->config = require __DIR__ . '/../config.php';
    $this->tokenStore = new TokenStore(...);
    // Services werden erst bei Bedarf erstellt (z.B. getCalendarServiceBySourceId()
    // braucht keine AvailabilityEngine)
}
```

### Vorteil
Requests die nur `getCalendarServiceBySourceId()` nutzen (z.B. OAuth-Callbacks), erstellen keine AvailabilityEngine und initialisieren keine Services – schnellerer Response.

---

## 4.4 HTML-Template-Duplikation beseitigen

### Geaenderte Dateien
- `templates/booking-form.php` – **NEU** – Gemeinsames Buchungsformular-Template
- `index.php` – Nutzt jetzt `include` statt eigenes Markup
- `embed.php` – Nutzt jetzt `include` statt eigenes Markup

### Was wurde gemacht
1. **Gemeinsames Template extrahiert:** Die ~120 identischen HTML-Zeilen (Schrittanzeige, Kalender-Panel, Formular-Panel, Bestaetigungs-Panel, Erfolgs-Panel) wurden in `templates/booking-form.php` verschoben.
2. **Variablen-Vertrag:** Das Template erwartet definierte Variablen ($duration, $additionalFields, $allowAttendees, $maxAttendees, $organizerName, $bookingInfo).
3. **Einbindung:** Beide Dateien setzen die Variablen und binden das Template per `include` ein.

### Vorher
```
index.php:  ~219 Zeilen (davon ~120 identisches Formular-Markup)
embed.php:  ~213 Zeilen (davon ~120 identisches Formular-Markup)
→ Aenderungen mussten in beiden Dateien gemacht werden
```

### Nachher
```
templates/booking-form.php:  ~140 Zeilen (gemeinsames Template)
index.php:                   ~68 Zeilen  (Layout + Header + include)
embed.php:                   ~74 Zeilen  (Embed-Layout + include)
→ Formular-Aenderungen nur noch an einer Stelle noetig
```

---

## 4.5 Frontend-Fehlerbehandlung verbessern

### Geaenderte Dateien
- `assets/js/app.js` – `loadAvailableDays()`, `prefetchNextMonth()`, `renderCalendar()`

### Was wurde gemacht
1. **HTTP-Statuscode-Pruefung:** `fetch()`-Aufrufe pruefen jetzt `r.ok` und werfen bei HTTP-Fehlern eine Exception (statt fehlerhaftes JSON zu parsen).
2. **Fehler-Zustand im Kalender:** Neuer `state.loadError` Flag. Bei Ladefehler wird statt eines leeren Kalenders eine Fehlermeldung mit "Erneut versuchen"-Button angezeigt.
3. **Prefetch-Fehlerbehandlung verbessert:** Bei fehlgeschlagenem Prefetch wird kein Cache-Eintrag angelegt. Wenn der User zum Monat navigiert, wird automatisch erneut geladen.

### Vorher
```javascript
.catch(() => {
    state.loading = false;
    state.availableDays = {};
    renderCalendar();  // Zeigt leeren Kalender ohne Hinweis
});

// Prefetch:
.catch(() => {}); // Stilles Fehlschlagen
```

### Nachher
```javascript
.catch(() => {
    state.loading = false;
    state.loadError = true;
    state.availableDays = {};
    renderCalendar();  // Zeigt "Laden fehlgeschlagen" mit Retry-Button
});

// Prefetch:
.catch(() => {
    // Cache-Key wird nicht gesetzt → erneuter Ladeversuch bei Navigation
});
```

---

## Zusaetzlicher Fix

### admin/index.php – Passwort-Formular
Das Passwort-Aenderungsformular im Admin-Panel (Tab "Zugang") hatte noch `minlength="6"` und "Mindestens 6 Zeichen" statt der in Schritt 2 eingefuehrten Mindestlaenge von 12 Zeichen. Korrigiert auf `minlength="12"` und "Mindestens 12 Zeichen".

---

## Zusammenfassung geaenderter Dateien

| Datei | Aenderungen |
|-------|-------------|
| `api/slots.php` | Differenzierte Cache-Headers (60s/30s), HTTP-Methoden-Pruefung (405) |
| `src/MicrosoftCalendarService.php` | Timezone-Parameter im Konstruktor, alle hardcodierten Timezones ersetzt |
| `src/GoogleCalendarService.php` | Timezone-Parameter im Konstruktor, hardcodierte Timezone ersetzt |
| `src/BookingService.php` | Lazy-Init fuer Services und AvailabilityEngine, Timezone-Weitergabe |
| `templates/booking-form.php` | **NEU** – Gemeinsames Buchungsformular-Template |
| `index.php` | Nutzt gemeinsames Template (120 Zeilen weniger Duplikation) |
| `embed.php` | Nutzt gemeinsames Template (120 Zeilen weniger Duplikation) |
| `assets/js/app.js` | HTTP-Fehler-Handling, Retry-Button, Prefetch-Absicherung |
| `admin/index.php` | Fix: Passwort-minlength 6 → 12 |
