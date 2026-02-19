# Schritt 1 – Umsetzungsdokumentation

**Datum:** 19.02.2026
**Schritt:** Kritische Sicherheitsluecken schliessen (5 Massnahmen)

---

## Neue Datei

### `src/SecurityHelper.php`
Zentrale Security-Klasse mit drei Bereichen:
- **CSRF-Schutz:** `generateCsrfToken()` und `validateCsrfToken()` – Token wird in der PHP-Session gespeichert und per `hash_equals()` timing-safe verglichen.
- **Rate-Limiting:** `checkRateLimit()` – dateibasiert unter `data/ratelimit/`, zaehlt Requests pro IP innerhalb eines konfigurierbaren Zeitfensters. `cleanupRateLimitFiles()` raeumt alte Dateien auf.
- **Security-Headers:** `sendSecurityHeaders()` – setzt `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` und `Strict-Transport-Security` (nur bei HTTPS). Parameter `$allowFrame=true` fuer die Embed-Seite.

---

## 1.1 CSRF-Schutz

### Geaenderte Dateien
- `admin/api.php` – Alle POST-Requests pruefen jetzt ein CSRF-Token. Token wird aus dem `X-CSRF-Token`-Header gelesen (fuer JSON-Requests) oder aus dem `csrf_token`-POST-Feld (fuer Multipart-Uploads).
- `admin/index.php` – CSRF-Token wird als `<meta name="csrf-token">` im HTML-Head ausgegeben.
- `assets/js/admin.js` – Liest das CSRF-Token aus dem Meta-Tag. `apiPost()` sendet es als `X-CSRF-Token`-Header bei jedem JSON-Request. Image-Upload sendet es als FormData-Feld.

### Funktionsweise
1. Beim Laden des Admin-Panels wird ein CSRF-Token generiert und in der Session gespeichert.
2. Das Token wird als Meta-Tag im HTML ausgegeben.
3. JavaScript liest das Token und sendet es bei jedem POST-Request mit.
4. Der Server validiert das Token gegen die Session – bei Mismatch wird HTTP 403 zurueckgegeben.

---

## 1.2 OAuth State-Parameter Validierung

### Geaenderte Dateien
- `admin/index.php` – Beim Erzeugen des "Verbinden"-Links wird ein zufaelliger 32-Zeichen-Hex-State generiert (`bin2hex(random_bytes(16))`). Die zugehoerige Source-ID und ein Zeitstempel werden in der Session gespeichert (`$_SESSION['oauth_state_<state>']`, `$_SESSION['oauth_state_time_<state>']`).
- `admin/auth-microsoft.php` – Komplett ueberarbeitet: State wird gegen Session validiert, Ablauf nach 10 Minuten, Source-ID wird aus Session gelesen statt direkt aus dem State-Parameter. State-Eintraege werden nach Verwendung geloescht (One-Time-Use).
- `admin/auth-google.php` – Identische Aenderungen wie `auth-microsoft.php`.

### Vorher
```
State = Source-ID → direkt als Source-ID verwendet (manipulierbar)
```

### Nachher
```
State = zufaelliger Token → Session-Lookup → Source-ID (nicht manipulierbar)
```

---

## 1.3 Security-Headers

### Geaenderte Dateien
Alle Endpoints binden jetzt `SecurityHelper::sendSecurityHeaders()` ein:
- `admin/api.php`
- `admin/index.php`
- `admin/auth-microsoft.php`
- `admin/auth-google.php`
- `api/book.php`
- `api/slots.php`
- `index.php`
- `embed.php` (mit `allowFrame: true`, setzt kein `X-Frame-Options`)

### Gesetzte Headers
```
X-Frame-Options: DENY                    (nicht bei embed.php)
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
Strict-Transport-Security: max-age=31536000; includeSubDomains   (nur bei HTTPS)
```

---

## 1.4 Admin-Authentifizierung

### Geaenderte Dateien
- `admin/index.php`:
  - **Session-Fixation verhindert:** Nach erfolgreichem Login wird `session_regenerate_id(true)` aufgerufen.
  - **Passwort-Pflicht bei Ersteinrichtung:** Ohne gesetztes Passwort wird ein Setup-Formular angezeigt statt das Admin-Panel offen zugaenglich zu machen. Erst nach Setzen eines Passworts (min. 6 Zeichen, mit Bestaetigung) wird Zugang gewaehrt.
  - **Sichere Session-Cookies:** `httponly`, `secure` (bei HTTPS) und `samesite=Strict` werden vor `session_start()` gesetzt.
- `admin/api.php`:
  - Gleiche Session-Cookie-Haertung wie `index.php`.

### Vorher
```
Kein Passwort → Admin-Panel komplett offen
Login → keine session_regenerate_id() → Session-Fixation moeglich
Cookies → ohne httponly/secure/samesite
```

### Nachher
```
Kein Passwort → Pflicht-Setup-Formular
Login → session_regenerate_id(true) → neue Session-ID
Cookies → httponly + secure + samesite=Strict
```

---

## 1.5 Rate-Limiting fuer Buchungs-Endpoint

### Geaenderte Dateien
- `api/book.php`:
  - Rate-Limiting vor der Buchungslogik: Max. 5 Buchungen pro IP pro Stunde.
  - Bei Ueberschreitung wird HTTP 429 mit Fehlermeldung zurueckgegeben.
  - Probabilistisches Cleanup (~1% der Requests) raeumt alte Rate-Limit-Dateien auf.

### Funktionsweise
1. IP-Adresse des Clients wird als MD5-Hash zusammen mit der Aktion verwendet.
2. Timestamps der Requests werden in `data/ratelimit/<hash>.json` gespeichert.
3. Bei jedem Request werden abgelaufene Eintraege gefiltert.
4. Wenn die Anzahl der verbleibenden Eintraege >= 5 ist, wird der Request abgelehnt.
5. Das `data/`-Verzeichnis ist bereits per `.htaccess` RewriteRule geschuetzt.

---

## Zusammenfassung der geaenderten Dateien

| Datei | Aenderungen |
|-------|-------------|
| `src/SecurityHelper.php` | **NEU** – CSRF, Rate-Limiting, Security-Headers |
| `admin/api.php` | Session-Haertung, Security-Headers, CSRF-Validierung |
| `admin/index.php` | Session-Haertung, CSRF-Token, OAuth-State, Passwort-Pflicht, `session_regenerate_id()` |
| `admin/auth-microsoft.php` | Security-Headers, OAuth-State-Validierung |
| `admin/auth-google.php` | Security-Headers, OAuth-State-Validierung |
| `api/book.php` | Security-Headers, Rate-Limiting |
| `api/slots.php` | Security-Headers |
| `index.php` | Security-Headers |
| `embed.php` | Security-Headers (mit Frame-Erlaubnis) |
| `assets/js/admin.js` | CSRF-Token aus Meta-Tag, Header bei API-Calls |
