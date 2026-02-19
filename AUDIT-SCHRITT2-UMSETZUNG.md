# Schritt 2 – Umsetzungsdokumentation

**Datum:** 19.02.2026
**Schritt:** Sicherheit haerten & kritische Performance-Probleme beheben (5 Massnahmen)

---

## 2.1 Passwort-Anforderungen erhoehen

### Geaenderte Dateien
- `admin/api.php` – Minimum von 6 auf 12 Zeichen erhoehen
- `admin/index.php` – Ersteinrichtungs-Formular: `minlength="12"`, Fehlertext angepasst
- `assets/js/admin.js` – Frontend-Validierung von 6 auf 12 Zeichen

### Vorher → Nachher
```
Minimum 6 Zeichen → Minimum 12 Zeichen
```

---

## 2.2 Input-Laengenbegrenzung

### Geaenderte Dateien
- `src/BookingService.php` (`validateBooking`) – Server-seitige Laengenvalidierung
- `index.php` – `maxlength`-Attribute an allen Formularfeldern
- `embed.php` – Identische `maxlength`-Attribute

### Limits
| Feld | Typ | Max. Zeichen |
|------|-----|-------------|
| Vorname | text | 100 |
| Nachname | text | 100 |
| E-Mail | email | 254 (RFC 5321) |
| Zusatzfelder (text, email, tel, etc.) | input | 500 |
| Zusatzfelder (Ausgangssituation etc.) | textarea | 5000 |

### Hinweis
Textarea-Felder haben bewusst 5000 Zeichen, damit Nutzer ihre Ausgangssituation
ausfuehrlich beschreiben koennen. Normale Eingabefelder sind auf 500 Zeichen begrenzt.

Validierung erfolgt doppelt:
1. Frontend: `maxlength`-Attribute verhindern Eingabe ueber dem Limit
2. Backend: `mb_strlen()` in `validateBooking()` als serverseitige Absicherung

---

## 2.3 Cache-Busting mit filemtime()

### Geaenderte Dateien
- `index.php` – `time()` durch `filemtime()` ersetzt
- `embed.php` – `time()` durch `filemtime()` ersetzt

### Vorher → Nachher
```php
// Vorher: bei jedem Request neue URL → Browser-Cache nutzlos
<link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">

// Nachher: URL aendert sich nur wenn Datei geaendert wird → Browser kann cachen
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
```

### Auswirkung
- CSS und JS werden vom Browser gecacht solange sich die Dateien nicht aendern
- Bei Aenderungen (Deploy) wird automatisch ein neuer Hash generiert
- Reduziert Netzwerk-Traffic und verbessert Ladezeiten

---

## 2.4 Redundante Slot-Verfuegbarkeitspruefung

### Geaenderte Dateien
- `src/BookingService.php` (`book()`) – Aufruf von `getAvailableSlots()` durch `isSlotAvailable()` ersetzt
- `src/AvailabilityEngine.php` – Neue Methode `isSlotAvailable()` hinzugefuegt

### Vorher
```php
// Alle Slots des ganzen Tages abrufen (kompletter API-Call an M365/Google)
$slots = $this->availabilityEngine->getAvailableSlots(new \DateTime($date, $tz));
foreach ($slots as $slot) {
    if ($slot['start'] === $time) { $slotFound = true; break; }
}
```

### Nachher
```php
// Gezielter Check nur fuer den konkreten Zeitraum
if (!$this->availabilityEngine->isSlotAvailable($start, $end)) {
    return ['success' => false, 'message' => '...'];
}
```

### Neue Methode: `AvailabilityEngine::isSlotAvailable()`
Prueft fuer einen konkreten Zeitraum:
1. Ist es ein Arbeitstag?
2. Liegt der Slot innerhalb der Arbeitszeiten?
3. Wird die Mindestvorlaufzeit eingehalten?
4. Ueberschneidet er sich mit der Pausenzeit?
5. Gibt es Ueberschneidungen mit Kalender-Busy-Slots?

### Auswirkung
- API-Call an M365/Google nur fuer den konkreten Zeitraum statt fuer den ganzen Tag
- Schnellere Buchungsbestaetigung
- Weniger API-Quota-Verbrauch

---

## 2.5 Reflection durch public Getter ersetzen

### Geaenderte Dateien
- `src/MicrosoftCalendarService.php` – Neue Methode `getSourceId(): string`
- `src/GoogleCalendarService.php` – Neue Methode `getSourceId(): string`
- `src/BookingService.php` (`getServiceId()`) – Reflection durch `$service->getSourceId()` ersetzt

### Vorher
```php
private function getServiceId($service): string
{
    $ref = new \ReflectionProperty($service, 'sourceId');
    $ref->setAccessible(true);
    return $ref->getValue($service);
}
```

### Nachher
```php
private function getServiceId($service): string
{
    return $service->getSourceId();
}
```

### Auswirkung
- Kein Reflection-Overhead mehr im Hot-Path (wird bei jeder Buchung und Slot-Abfrage aufgerufen)
- Saubere API statt Zugriff auf private Properties
- Bessere IDE-Unterstuetzung und Type-Safety

---

## Zusammenfassung geaenderter Dateien

| Datei | Aenderungen |
|-------|-------------|
| `admin/api.php` | Passwort-Minimum 12 Zeichen |
| `admin/index.php` | Passwort-Minimum 12 Zeichen (Ersteinrichtung) |
| `assets/js/admin.js` | Passwort-Minimum 12 Zeichen (Frontend) |
| `src/BookingService.php` | Input-Validierung, isSlotAvailable(), Reflection entfernt |
| `src/AvailabilityEngine.php` | Neue Methode `isSlotAvailable()` |
| `src/MicrosoftCalendarService.php` | Neuer Getter `getSourceId()` |
| `src/GoogleCalendarService.php` | Neuer Getter `getSourceId()` |
| `index.php` | maxlength-Attribute, filemtime() statt time() |
| `embed.php` | maxlength-Attribute, filemtime() statt time() |
