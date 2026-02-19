# Schritt 3 – Umsetzungsdokumentation

**Datum:** 19.02.2026
**Schritt:** Fehlerbehandlung & Robustheit (5 Massnahmen)

---

## 3.1 curl-Fehlerbehandlung

### Geaenderte Dateien
- `src/MicrosoftCalendarService.php` – `graphGet()`, `graphPost()`, `httpPost()`
- `src/GoogleCalendarService.php` – `apiPost()`, `httpPost()`
- `src/AvailabilityEngine.php` – `collectBusySlots()` (single + curl_multi)

### Was wurde gemacht
Alle curl-Aufrufe pruefen jetzt:
1. `curl_exec()` Rueckgabewert auf `false` (Netzwerkfehler)
2. HTTP-Statuscode via `curl_getinfo($ch, CURLINFO_HTTP_CODE)`
3. Bei curl_multi: `curl_errno()` pro Handle

Bei Fehlern:
- Fehler wird mit `error_log()` geloggt (inkl. `curl_error()` bzw. HTTP-Code)
- Es wird ein Error-Array zurueckgegeben statt `[]` oder `false` an `json_decode()`
- Bei curl_multi: fehlerhafte Handles werden uebersprungen, funktionierende weiter verarbeitet

### Vorher
```php
$response = curl_exec($ch);
curl_close($ch);
return json_decode($response, true) ?: [];  // $response kann false sein!
```

### Nachher
```php
$response = curl_exec($ch);
if ($response === false) {
    error_log('... error: ' . curl_error($ch));
    curl_close($ch);
    return ['error' => ['message' => 'Netzwerkfehler ...']];
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// HTTP-Fehler loggen, Ergebnis zurueckgeben
```

---

## 3.2 Token-Refresh Race-Condition

### Geaenderte Dateien
- `src/TokenStore.php` – Komplett ueberarbeitet

### Was wurde gemacht
1. **Lesen mit Shared-Lock:** Neue `loadWithLock()` Methode nutzt `fopen()` + `flock(LOCK_SH)` statt `file_get_contents()`. Verhindert, dass waehrend eines Writes partielles JSON gelesen wird.
2. **Re-Read vor Write:** `set()` und `remove()` laden die aktuelle Datei neu (`loadWithLock()`) bevor sie schreiben. Damit wird vermieden, dass ein paralleler Request aeltere Daten ueberschreibt.
3. **Validierung:** `json_decode()`-Ergebnis wird auf `is_array()` geprueft.

### Vorher
```php
// Konstruktor: file_get_contents ohne Lock
$data = file_get_contents($path);
$this->tokens = json_decode($data, true) ?: [];

// set(): schreibt in-memory Cache ohne neu zu laden
$this->tokens[$sourceId] = $tokenData;
$this->save();  // Koennte aeltere Daten eines anderen Prozesses ueberschreiben
```

### Nachher
```php
// Konstruktor: Shared-Lock beim Lesen
$this->tokens = $this->loadWithLock();

// set(): Re-Read vor Write
$this->tokens = $this->loadWithLock();  // Aktuellste Daten laden
$this->tokens[$sourceId] = $tokenData;
$this->save();  // Exclusive Lock beim Schreiben (war schon vorhanden)
```

---

## 3.3 Zeitformat-Parsing

### Geaenderte Dateien
- `src/AvailabilityEngine.php` – Neue Methode `parseTime()`, alle `substr()`-Aufrufe ersetzt

### Was wurde gemacht
Neue Private Methode `parseTime(string $time): array`:
```php
private function parseTime(string $time): array
{
    $parts = explode(':', $time);
    return [(int)($parts[0] ?? 0), (int)($parts[1] ?? 0)];
}
```

12 `substr()`-Aufrufe in 3 Methoden (`getAvailableSlots`, `isSlotAvailable`, `getAvailableDays`) durch `parseTime()` ersetzt.

### Vorher (fragil)
```php
$dayStart->setTime(
    (int)substr($workingHours['start'], 0, 2),  // "9:00" → "9:" statt 9
    (int)substr($workingHours['start'], 3, 2),   // "9:00" → "0" statt 0
    0
);
```

### Nachher (robust)
```php
[$sh, $sm] = $this->parseTime($workingHours['start']);  // "9:00" → [9, 0]
$dayStart->setTime($sh, $sm, 0);
```

---

## 3.4 Path-Traversal bei Bild-Loeschung

### Geaenderte Dateien
- `admin/api.php` – Neue Funktion `safeDeleteUpload()`, beide `unlink()`-Stellen ersetzt

### Was wurde gemacht
Neue Funktion `safeDeleteUpload(string $relativePath)`:
1. Ermittelt den realen Pfad des Upload-Verzeichnisses mit `realpath()`
2. Prueft ob die zu loeschende Datei innerhalb von `assets/uploads/` liegt
3. Nur wenn `str_starts_with($realPath, $baseDir . DIRECTORY_SEPARATOR)` zutrifft, wird geloescht

### Vorher (unsicher)
```php
$oldPath = $design[$field] ?? '';
if ($oldPath && file_exists(dirname(__DIR__) . '/' . $oldPath)) {
    unlink(dirname(__DIR__) . '/' . $oldPath);  // ../../../etc/passwd moeglich!
}
```

### Nachher (sicher)
```php
safeDeleteUpload($oldPath);
// Intern: realpath() + str_starts_with() Validierung
```

---

## 3.5 Upload-Verzeichnis-Berechtigungen

### Geaenderte Dateien
- `admin/api.php` – `mkdir()` und `.htaccess`-Erstellung

### Was wurde gemacht
1. Verzeichnis-Berechtigungen von `0755` auf `0750` geaendert (nicht mehr weltweit lesbar)
2. Bei Verzeichnis-Erstellung wird automatisch eine `.htaccess` angelegt:
   ```apache
   php_flag engine off
   <FilesMatch "\.(php|phtml|php3|php4|php5|phps)$">
       Deny from all
   </FilesMatch>
   ```
   Dies verhindert die Ausfuehrung hochgeladener PHP-Dateien, selbst wenn ein Angreifer die MIME-Type-Pruefung umgehen sollte.

---

## Zusammenfassung geaenderter Dateien

| Datei | Aenderungen |
|-------|-------------|
| `src/MicrosoftCalendarService.php` | curl-Fehlerbehandlung in 3 Methoden |
| `src/GoogleCalendarService.php` | curl-Fehlerbehandlung in 2 Methoden |
| `src/AvailabilityEngine.php` | curl-Fehlerbehandlung in collectBusySlots, parseTime()-Methode, 12x substr() ersetzt |
| `src/TokenStore.php` | Shared-Lock beim Lesen, Re-Read vor Write, JSON-Validierung |
| `admin/api.php` | Path-Traversal-Schutz (safeDeleteUpload), Upload-Dir 0750 + .htaccess |
