# Gesamt-Audit – MSP Support Terminvereinbarung

**Datum:** 10.06.2026
**Geprüfter Stand:** Commit `be2d421` (Branch-Tip)
**Umfang:** Sicherheit, Performance, Codequalität, Architektur, Betrieb/Reliability, Deployment, Usability/UX/UI, Accessibility
**Methode:** Vollständige Lektüre aller 28 Quelldateien (~12.300 Zeilen PHP/JS/CSS/Config) durch vier spezialisierte Detail-Audits, anschließende Verifikation der kritischen Befunde am Code (`php -l` über alle PHP-Dateien fehlerfrei, PHP 8.4). Die früheren AUDIT-Dokumente (Feb. 2026) wurden bewusst ignoriert; geprüft wurde ausschließlich der tatsächliche Code.

---

## 1. Executive Summary

Das Projekt ist für ein framework-loses Tool **überdurchschnittlich solide**. Der frühere Audit (Schritte 1–6) wurde zu ca. 85 % real umgesetzt: CSRF-Schutz der Admin-API, OAuth-State-Validierung, Security-Header, Rate-Limit auf Buchungen, `CalendarServiceInterface`, durchgängiges Output-Escaping (kein XSS gefunden), `password_hash`, gehärtete Session-Cookies, curl_multi-Parallelisierung, Client-Caching.

**Dem stehen jedoch gravierende Lücken gegenüber:**

| # | Befund | Bereich | Schwere |
|---|--------|---------|---------|
| 1 | **FTP-Produktions-Zugangsdaten im Klartext im Repo + Git-History** | Security/Deploy | **KRITISCH** |
| 2 | **Schutz von `data/` (OAuth-Tokens, Secrets) hängt allein an `mod_rewrite`** | Security/Betrieb | **KRITISCH** |
| 3 | **Doppelbuchungs-Race:** kein Lock zwischen Verfügbarkeits-Check und Event-Erstellung | Betrieb | **KRITISCH** |
| 4 | **Fail-open:** Kalender-API-Fehler (429/Timeout/Token abgelaufen) ⇒ belegte Slots erscheinen frei | Betrieb/Perf | **KRITISCH** |
| 5 | **Kern-Buchungsflow für Tastatur/Screenreader unbedienbar** (Tage/Slots sind klickbare `<div>`s) | UX/A11y | **KRITISCH** |
| 6 | **Kein Datenschutzhinweis** im öffentlichen Formular (DSGVO Art. 13) | UX/Recht | **KRITISCH** |
| 7 | Admin-API bis zur Ersteinrichtung komplett offen (Lockout/Übernahme möglich) | Security | Hoch |
| 8 | Kein serverseitiges FreeBusy-Caching ⇒ Upstream-Last = O(Besucher), FPM-Pool ab ~20–40 parallelen Nutzern erschöpft | Performance | Hoch |
| 9 | Buchungen werden lokal nicht persistiert; fehlgeschlagene Webhook-Leads werden still gelöscht | Betrieb | Hoch |
| 10 | Kein Healthcheck, kein Monitoring, kein Backup, keine Tests, kein README | Betrieb | Hoch (kumuliert) |

**Gesamtbild:** Das System ist funktional gut gebaut, aber *fail-open*, *unbeobachtet* und *ungetestet* — und über die Deploy-Credentials aktuell vollständig kompromittierbar. Die Maßnahmen der Phasen 0–1 des Umsetzungsplans (~3 Personentage) beseitigen den Großteil des Risikos.

### Findings-Übersicht nach Kategorie

| Kategorie | Kritisch | Hoch | Mittel | Niedrig |
|---|---|---|---|---|
| Sicherheit | 2 | 3 | 5 | 5 |
| Performance | 2 | 5 | 7 | – |
| Codequalität/Architektur | – | 1 | 6 | 6 |
| Betrieb/Deployment | 2 | 5 | 5 | 2 |
| UX/UI/Accessibility | 4 | 9 | 14 | 8 |

---

## 2. Sicherheit

### S1 — KRITISCH: Hardcodierte FTP-Zugangsdaten im Deploy-Workflow
`.github/workflows/deploy.yml:20-22` enthält Server (`w01c3400.kasserver.com`), Benutzername und Passwort des Produktions-FTP-Zugangs im Klartext — verifiziert auch in der Git-History seit dem ersten Commit der Datei. Jeder mit Repo-Lesezugriff erhält **vollen Schreibzugriff auf den Produktions-Webspace**: Lesen von `data/tokens.json` (M365-Refresh-Token mit Scopes `Calendars.ReadWrite OnlineMeetings.ReadWrite Mail.Send` → Mailversand im Namen des Kontos!), `data/config.json` (Client-Secrets, Passwort-Hashes), Hinterlegen beliebigen PHP-Codes.
**Fix:** Passwort sofort beim Hoster rotieren; GitHub Secrets (`${{ secrets.FTP_PASSWORD }}`) verwenden; History bereinigen (`git filter-repo`/BFG) oder Repo als kompromittiert behandeln; prüfen, ob M365-/Google-Client-Secrets ebenfalls rotiert werden müssen (waren via FTP lesbar). **Aufwand: 2–3 h + Rotationen.**

### S2 — KRITISCH: `data/`-Schutz hängt allein an `mod_rewrite` (Fail-open)
`.htaccess:4-23`: Der gesamte Zugriffsschutz für `data/` und `src/` steckt in `<IfModule mod_rewrite.c>`. Ist mod_rewrite nicht geladen oder `AllowOverride` eingeschränkt, sind `https://<domain>/data/tokens.json` und `/data/config.json` direkt herunterladbar. Zusätzlich: `php_flag` (Z. 26-27) funktioniert nur mit mod_php; unter PHP-FPM/FastCGI (Standard bei All-Inkl für moderne PHP-Versionen) verursacht es je nach Konfiguration einen **500er für die gesamte Site** oder wird ignoriert. Auf nginx greift `.htaccess` generell nicht.
**Fix:** `data/.htaccess` mit `Require all denied` per Code beim Anlegen erzeugen (das Muster existiert bereits vorbildlich für `assets/uploads/` in `admin/api.php:362`); `php_flag` entfernen; mittelfristig `data/` und `src/` außerhalb des Document-Roots (`public/`-Umbau). **Aufwand: 1 h Quick-Fix; Umbau 1 Tag.**

### S3 — HOCH: SSRF über Webhook-/Funnel-URLs
`FunnelManager::validateOne()` (`src/FunnelManager.php:65-71`) und `save-webhook` (`admin/api.php:415`) akzeptieren beliebige `http(s)`-URLs. Der Versand (`src/BookingService.php:536-546`, `src/WebhookQueue.php:272-282`) folgt Redirects (`CURLOPT_FOLLOWLOCATION`, max. 3) ohne IP-/Host-Filter. Ein Admin (oder eine gestohlene Admin-Session) kann `http://169.254.169.254/…` oder interne Hosts ansteuern; die Zustellung ist über das öffentliche Buchungsformular durch Unauthentifizierte triggerbar.
**Fix:** URL vor Request auflösen, private/link-local/loopback-Bereiche blocken (RFC1918, 127/8, 169.254/16, ::1, fc00::/7); Redirects deaktivieren oder Ziele erneut prüfen; `https` erzwingen. **Aufwand: 3–4 h.**

### S4 — HOCH: OAuth-Tokens unverschlüsselt auf der Platte
`src/TokenStore.php:85-93` schreibt Access-/Refresh-Tokens als Klartext-JSON (`chmod 0600`). In Kombination mit S1/S2 sind die Tokens das wertvollste Angriffsziel des Systems.
**Fix:** Verschlüsselung mit `sodium_crypto_secretbox`, Key aus Umgebungsvariable/Datei außerhalb des Webroots. **Aufwand: 3–5 h.**

### S5 — HOCH: Kein Brute-Force-Schutz an Admin- und Kalender-Login
`admin/index.php:34-41`, `calendar/index.php:34-48`: Passwort-Prüfung ohne Zähler/Delay/Lockout. `SecurityHelper::checkRateLimit()` existiert, wird aber nur in `api/book.php:36` genutzt. Bei reinem Passwort-Login (kein Username) ist Online-Brute-Force direkt möglich.
**Fix:** `checkRateLimit('admin_login', 5, 900)` pro IP vor `verifyAdminPassword`, exponentielles Backoff. **Aufwand: 1–2 h.**

### S6 — HOCH: Admin-API bis zur Ersteinrichtung vollständig offen
`admin/api.php:36-40` prüft die Session **nur, wenn bereits ein Admin-Passwort existiert**; das CSRF-Token wird auch unauthentifiziert gerendert (`admin/index.php:94`, im `<head>` vor der Auth-Verzweigung). Zwischen Deploy und erstem Passwort-Setzen kann jeder Besucher die Konfiguration ändern, Webhooks auf eigene Server setzen oder per `save-password` den Betreiber aussperren.
**Fix:** Bei `!hasAdminPassword()` alle Aktionen außer dem Passwort-Setup mit 403 beantworten; CSRF-Meta nur authentifiziert rendern. **Aufwand: 1–2 h.**

### S7 — MITTEL: Login-Formulare ohne CSRF-Token
`admin/index.php:108-137`, `calendar/index.php:85-90`: Login-POSTs ohne Token → Login-CSRF möglich; `SameSite=Lax` schützt nicht gegen Top-Level-POST. **Fix: 1 h.**

### S8 — MITTEL: Keine Content-Security-Policy
`SecurityHelper::sendSecurityHeaders()` (`src/SecurityHelper.php:114-127`) setzt keine CSP (einzige CSP-Direktive: `frame-ancestors *` in `embed.php:11`, dort beabsichtigt). Die Admin-Seite enthält Inline-`<script>` und `onclick=`-Handler (`admin/index.php:1136, 1466-1474`), die einer strikten CSP im Weg stehen. Bereits im Alt-Audit (1.3) spezifiziert, nie umgesetzt. **Fix: 3–4 h inkl. Inline-Refactor.**

### S9 — MITTEL: Interne Exception-Messages an den Client
`admin/api.php:661-664` gibt `'Interner Fehler: ' . $e->getMessage()` zurück (Pfad-/Konfig-Leaks; nur authentifiziert erreichbar). `api/book.php`/`api/slots.php` machen es korrekt. **Fix: 15 min.**

### S10 — MITTEL: DSGVO — personenbezogene Daten ohne Löschkonzept
`data/webhook-queue/{pending,done,failed}/*.json` enthält komplette Buchungs-Payloads (Name, E-Mail, Custom Fields); Pruning nur mengenbasiert (`DONE_KEEP_COUNT=100`, `FAILED_KEEP_COUNT=200`, `src/WebhookQueue.php:307-314`) — bei geringem Volumen unbegrenzte Aufbewahrung. Buchungsdetails landen zudem unredigiert in `error_log` (`src/WebhookQueue.php:249`). Es fehlt außerdem jeder Datenschutzhinweis im Formular (siehe U2). **Fix: zeitbasierte Aufbewahrung (z. B. 30 Tage), Log-Redaction, 2–3 h.**

### S11 — MITTEL: Webhook-Worker-Token nicht administrierbar
`api/webhook-worker.php:22-38`: ohne `webhook_worker.token` ist der Endpoint extern gesperrt (gut), aber das Token ist über keine UI/API setzbar (kein Default in `ConfigManager`, kein Admin-Feld) und wird als GET-Query übergeben (landet in Access-Logs). **Fix: Admin-Feld + Header-Auth, 1–2 h.**

### S12-S16 — NIEDRIG
- **S12** CRLF-Injection in benutzerdefinierten Webhook-Headern möglich (`src/BookingService.php:528-534`; nur Admin-konfigurierbar). Fix: `\r\n` strippen, Namen gegen `^[A-Za-z0-9-]+$` validieren. 30 min.
- **S13** Bild-Upload ohne Re-Encoding (GIF/Polyglot-Restrisiko; sonst solide gehärtet, `admin/api.php:330-376`). 2 h.
- **S14** Sessions ohne Idle-/Absolut-Timeout; OAuth-State-Session-Keys akkumulieren (`admin/index.php:533-537`).
- **S15** HSTS ohne `preload`; `Permissions-Policy` minimal (`src/SecurityHelper.php:122-126`).
- **S16** `.gitignore` deckt `data/ratelimit/` und `data/webhook-queue/` (personenbezogene Daten!) nicht ab — `data/*` + `!data/.gitkeep` verwenden. 10 min.

**Positiv verifiziert:** kein XSS (durchgängig `htmlspecialchars`/`escHtml`/`textContent`), CSRF der Admin-API korrekt (`hash_equals`), OAuth-State sauber (Session-gebunden, TTL, Einmal-Verbrauch), `password_hash`/`password_verify`, `session_regenerate_id(true)` nach Login, Cookie-Flags korrekt, `embed.php`-postMessage unkritisch (sendet nur Höhe, empfängt nichts).

---

## 3. Performance

### P1 — KRITISCH: Kein serverseitiges FreeBusy-Caching; API-Fehler ⇒ „alles frei"
`api/slots.php:41,53` → `src/AvailabilityEngine.php:258-324`: Jeder Slot-Request schlägt live bei Google/Microsoft auf; es gibt nur Browser-Cache-Header (`max-age=60/30`), die nur demselben Browser helfen. N Besucher = N×(Anzahl Kalender) Upstream-Calls. Microsoft drosselt pro Postfach (429) — und die Drossel-Antwort wird als „keine Busy-Slots" geparst (`MicrosoftCalendarService.php:153-171`, `AvailabilityEngine.php:279-287, 304-311`): **unter Last erscheinen belegte Slots als frei → Doppelbuchungen.** Gleichzeitig Skalierungs-Flaschenhals: jeder PHP-FPM-Worker hängt 0,5–2 s (bis 30 s Timeout) am Upstream; typischer Shared-Hosting-Pool (10–20 Worker) ist bei ~20–40 parallelen Nutzern erschöpft.
**Fix:** (a) Dateibasierter Micro-Cache der gemergten Busy-Slots (TTL 30–60 s, flock, Invalidierung nach Buchung); (b) API-Fehler nicht als „frei" interpretieren (→ B1, fail-closed). **Aufwand: 0,5–1 Tag. Größter Einzelhebel des gesamten Audits.**

### P2 — KRITISCH: Webhook-Retry-Worker läuft synchron im Besucher-Request
`api/slots.php:24-27`: ~1 % der Slot-Requests führen `WebhookQueue::processBatch()` aus — bis zu 10 Jobs × 10 s Timeout = **bis zu 100 s Blockade vor der eigentlichen Slot-Berechnung**. Umgekehrt werden Retries bei wenig Traffic beliebig spät zugestellt.
**Fix:** Inline-Trigger entfernen, echten Cron auf den vorhandenen `api/webhook-worker.php` einrichten; übergangsweise Trigger nach `fastcgi_finish_request()`. **Aufwand: 1–2 h.**

### P3 — HOCH: `booking_horizon_days` wird nie durchgesetzt (verifiziert per Grep)
Der Wert existiert nur in Default/Validierung/Admin-UI (`ConfigManager.php:89,297`, `admin/api.php:96`, `admin/index.php:269`) — kein Lesezugriff in `AvailabilityEngine`/`slots.php`/`BookingService`. `api/slots.php:37` erlaubt Jahre 2020–2099, ohne Rate-Limit auf dem Endpoint. Folgen: buchbar bis 2099; ~950 abfragbare Monate als kostenloser Verstärker gegen die API-Quota (DoS-Vektor, der via P1/B1 zu Falsch-Verfügbarkeit führt).
**Fix:** Beide Actions auf `[heute … heute+Horizont]` begrenzen, ebenso `isSlotAvailable()`; mildes IP-Rate-Limit auf `slots.php`. **Aufwand: 2 h.**

### P4 — HOCH: Session-Lock serialisiert parallele AJAX-Requests (Kalender/Admin)
`calendar/api.php:14` und `admin/api.php:17` starten Sessions; nirgends `session_write_close()` (Grep: 0 Treffer). Das Frontend feuert pro Ansicht einen Request **pro Kalender** parallel (`assets/js/calendar.js:97-119`) — die laufen durch das Session-File-Lock seriell: bei 5 Kalendern ~3 s statt ~0,6 s; ein hängender Upstream-Call (30 s) blockiert alle Folge-Requests des Nutzers.
**Fix:** `session_write_close()` direkt nach Auth-/CSRF-Check. **Aufwand: 15 min.**

### P5 — HOCH: Buchungs-Request mit bis zu 5 sequentiellen externen Calls
`BookingService::book()`: FreeBusy-Check (`:86`) → `createEvent` (`:135`, 30 s Timeout) → Owner-Mail (`:155`, 30 s) → Funnel-/Global-Webhook (`:159-161`, 10 s), plus ggf. Token-Refreshes. Normalfall 1,5–3 s; Worst Case > 60 s Spinner, obwohl der Termin längst existiert (Mail/Webhook-Fehler werden ohnehin nur geloggt).
**Fix:** Response nach `createEvent` senden (`fastcgi_finish_request()`), Mail+Webhook danach bzw. in die vorhandene Queue. **Aufwand: 0,5 Tag.**

### P6 — HOCH: Keine Connect-Timeouts, kein 429-/Retry-Handling
Alle Graph-/Google-/Token-Calls: nur `CURLOPT_TIMEOUT => 30`, kein `CONNECTTIMEOUT` (`MicrosoftCalendarService.php:141,340,370,404`, `GoogleCalendarService.php:132,222,251`, `calendar/api.php:351,385,415`, `calendar/api-functions.php:113,144,166`). Ein DNS-/Routing-Hänger hält jeden FPM-Worker volle 30 s; 429/`Retry-After` wird nirgends behandelt.
**Fix:** `CONNECTTIMEOUT => 5`, `TIMEOUT => 10` für Reads; 1 Retry mit Backoff bei 429/5xx. **Aufwand: 2–4 h.**

### P7 — HOCH: Kalenderansicht: N+1-Requests + sequentieller Kalenderlisten-Abruf
`assets/js/calendar.js:98-117`: 1 Fetch pro Kalender pro Navigation; `setView()` (`:256-265`) lädt selbst beim Umschalten Woche↔Tag neu. `calendar/api.php:42-83` ruft `fetchCalendarList` pro Quelle **sequentiell** bei jedem Seitenaufruf auf. Initial: 6 PHP-Requests / 7 Upstream-Calls bei 2 Quellen/5 Kalendern — seriell durch P4.
**Fix:** Multi-Source-Endpoint mit curl_multi (Muster existiert in `AvailabilityEngine::collectBusySlots`), Kalenderlisten 10–15 min serverseitig cachen, Event-Cache im JS. **Aufwand: 0,5–1 Tag.**

### P8 — MITTEL: Token-Refresh: Thundering Herd + nicht-atomares Read-Modify-Write
Ablauf-Check (5-Min-Puffer) in **vier** duplizierten Implementierungen (`MicrosoftCalendarService.php:275-293`, `GoogleCalendarService.php:162-178`, `calendar/api-functions.php:66-82`, `calendar/api.php:302-320`). `TokenStore::set()` (`TokenStore.php:30-36`) hält kein Lock über Read+Write. Im Refresh-Fenster refresht jeder Request einzeln; parallele Writes können sich überschreiben — bei Microsofts rotierenden Refresh-Tokens kann der gespeicherte Token **entwertet** werden ⇒ Quelle „verliert" die Verbindung, manuelle Re-Auth nötig. (Alt-Audit 3.2 nur teilweise umgesetzt.)
**Fix:** Exklusives flock über die gesamte RMW-Sequenz + Double-Check nach Lock; Refresh-Mutex pro Source; Token-Logik deduplizieren (→ A1). **Aufwand: 3–4 h.**

### P9 — MITTEL: `.htaccess` ohne Kompression und Asset-Cache-Header
Kein `mod_deflate`/`mod_expires`: `app.js` 20,7 KB → 4,9 KB gzip (−77 %), Admin gesamt 86 KB → ~17 KB. Cache-Busting via `?v=<filemtime>` existiert (`index.php:50,80`), aber ohne `Cache-Control: immutable` revalidiert der Browser jedes Mal; `admin/index.php:92-93` bindet CSS sogar **ohne** `?v=` ein (veraltete Styles nach Deploy). `php_flag` bricht auf FPM-Hosts (→ S2).
**Fix:** deflate-Block + `Cache-Control: public, max-age=31536000, immutable` für `assets/`; `?v=` im Admin nachziehen. **Aufwand: 1 h.**

### P10 — MITTEL: Microsoft FreeBusy: 1 Request pro Kalender, keine Pagination
`MicrosoftCalendarService::prepareFreeBusyCurl()` (`:115-148`): pro Kalender ein `calendarView`-Request mit `$top=500`; `@odata.nextLink` wird nie gefolgt — bei >500 Events/Monat werden Busy-Slots **still abgeschnitten** (belegte Slots erscheinen frei). Google bündelt korrekt in einen `freeBusy`-Call.
**Fix:** Graph `getSchedule` (bis 20 Kalender, 1 Call, keine Pagination nötig). **Aufwand: 0,5 Tag.**

### P11 — MITTEL: Embed: 2-Hz-Polling + MutationObserver mit `attributes: true`
`embed.php:83-86`: `setInterval(sendHeight, 500)` für die gesamte iframe-Lebensdauer + Observer, der bei jedem Klassenwechsel feuert (Layout-Reflow pro Messung; Akku/CPU auf Mobile). **Fix:** `ResizeObserver`, Interval entfernen, nur bei Änderung posten. **Aufwand: 1 h.**

### P12 — MITTEL: Frontend: unbedingter Prefetch + fehlende Abbruchlogik
`assets/js/app.js:135,175`: Folgemonat wird bei jedem Seitenaufruf geprefetcht (verdoppelt ohne Server-Cache die Upstream-Last); kein `AbortController`/Sequenz-Check — eine verspätete ältere Antwort kann den falschen Monat rendern (`:140-148`). **Fix:** Prefetch nach erster Interaktion; Antwort gegen Cache-Key validieren. **Aufwand: 1–2 h.**

### P13 — MITTEL: Redundante Config-Ladungen
`config.php:11-12` erzeugt pro `require` einen neuen `ConfigManager`; `getRedirectUri()` beider Services lädt `config.php` erneut (`MicrosoftCalendarService.php:326`, `GoogleCalendarService.php:207`). Kein Hotspot (<1 ms), aber Hygiene. **Fix:** Memoization/Injection, 1 h.**

**Positiv verifiziert:** `api/slots.php`/`api/book.php` starten keine Session (kein Lock im Hot-Path); `AvailabilityEngine`-Algorithmik in Ordnung (1 API-Runde pro Monat, <10 ms CPU); WebhookQueue-I/O bei den vorgesehenen Volumina unkritisch; Worker-Lock (`LOCK_NB`) korrekt.

---

## 4. Codequalität & Architektur

### A1 — MITTEL: `calendar/` ist ein paralleler Silo — Token-Logik 4-fach dupliziert
Token-Refresh + `getValidAccessToken` existieren viermal (`src/MicrosoftCalendarService.php:275-319`, `src/GoogleCalendarService.php:162-200`, `calendar/api.php:302-369`, `calendar/api-functions.php:66-131`); Kalenderlisten + `mapMicrosoftColor` doppelt. Der Kommentar in `calendar/api-functions.php:4` („werden sowohl von calendar/api.php als auch admin/api.php verwendet") ist falsch — `calendar/api.php` definiert eigene Kopien; der dokumentierte Refactor des Alt-Audits ist unvollständig. OAuth-Scopes 3× hardcodiert. Real sichtbare Folge: die `calApi*`-Kopien loggen Fehler nicht (→ Q3).
**Fix:** `calendar/api.php` auf `TokenStore` + Service-Klassen umstellen, gemeinsame `AbstractOAuthCalendarService`, `api-functions.php` löschen. **Aufwand: 0,5–1 Tag.**

### A2 — MITTEL: Buchung hart auf Microsoft verdrahtet, Interface unvollständig
`CalendarServiceInterface` deckt nur FreeBusy+Auth ab; `BookingService.php:131` prüft per `instanceof MicrosoftCalendarService`. Das Admin-UI bietet `is_booking_target` aber auch für Google-Quellen an (`admin/api.php:211` validiert nicht) → Fehlkonfiguration fällt erst zur Buchungszeit auf.
**Fix:** Quick-Fix: `is_booking_target` für `type=google` ablehnen (30 min); sauber: `BookableCalendarInterface`. **Aufwand: 2–4 h.**

### A3 — MITTEL: Globale Zustände, keine Dependency Injection
`BookingService`-Konstruktor lädt Config selbst (`src/BookingService.php:26-30`); `admin/index.php:529-531` instanziiert `BookingService` in einer Schleife. Nicht testbar (kein Injection-Punkt für Config/TokenStore/HTTP). **Fix:** Konstruktor-Injection + gemeinsames Bootstrap. **Aufwand: 0,5 Tag.**

### Q1 — MITTEL: Verschluckte Fehler
- `calendar/api-functions.php:150-154, 173-177`: curl-Fehler/HTTP≥400 ⇒ stumm `[]`, **ohne Logging** (die Pendants in `calendar/api.php:389-401` loggen korrekt) — Admin-Tab zeigt „keine Kalender" ohne diagnostizierbare Ursache.
- `GoogleCalendarService::getFreeBusy():94`: `curl_exec()===false` ungeprüft → `parseFreeBusyResponse(false)` = TypeError/500 unter PHP 8.
- `handleCallback()` beider Services loggt OAuth-Fehlerantworten (`invalid_client`, `redirect_uri_mismatch`) nicht → „Fehler bei der Authentifizierung" ist im Betrieb nicht debugbar.
**Fix: 2 h.**

### Q2 — NIEDRIG: Duplizierter Boilerplate
Identischer Session-Setup-Block 6× (`admin/index.php:5-14`, `admin/api.php:8-17`, `admin/auth-*.php:6-13`, `calendar/index.php:6-14`, `calendar/api.php:6-14`); `jsonResponse()` 3×; `formatText()` 2× (`index.php:25-29`, `embed.php:28-32`); HTML-Escape-Helfer 3× im JS. **Fix:** `src/bootstrap.php`. **Aufwand: 2–3 h.**

### Q3 — NIEDRIG: Toter/irreführender Code
- Feldtyp `select` wird in der API akzeptiert (`admin/api.php:170`), aber weder im Admin-UI angeboten noch im Formular gerendert (`templates/booking-form.php:97-116`).
- `teams.source_id` wird gespeichert und gepflegt (`admin/index.php:736-748`), aber **nie gelesen** — toter Konfigurationsschalter, der Admins in die Irre führt.
- `MicrosoftCalendarService::graphGet()` und `GoogleCalendarService::apiPost()` werden nirgends aufgerufen; `calendar.js:286` definiert eine ungenutzte Icon-Variable (Private-Use-Glyph).
**Fix: 2 h.**

### Q4 — NIEDRIG: Kein `strict_types`, keine Namespaces, kein Autoloading
0 Treffer für `declare(strict_types=1)`; manuelle `require_once`-Ketten; kein Composer → PHPStan/Rector ohne Vorarbeit nicht einsetzbar. **Fix: 0,5 Tag.**

### Q5 — NIEDRIG: Validierung doppelt und abweichend
`admin/api.php:95` clampt Termindauer auf ≥15, `ConfigManager:80` verlangt nur ≥5; Arbeitszeit-Strings ohne `HH:MM`-Formatcheck (`"abc"` ⇒ still `00:00` via `AvailabilityEngine::parseTime():409`). **Fix:** Validierung allein im `ConfigManager`, 2 h.**

### Q6 — NIEDRIG: Monolithische Dateien
`admin/api.php`: 713-Zeilen-Switch mit 25 Cases; `admin/index.php`: 1.479 Zeilen inkl. OAuth-State-Erzeugung (Z. 533-537) und Anleitungstexten; JS-Dateien als je eine IIFE ohne Modulgrenzen (Escaping aber durchgängig vorhanden). Kein ESLint/Build. **Fix (optional bei Weiterentwicklung): 0,5–1 Tag.**

---

## 5. Datenhaltung & Betrieb

### B1 — KRITISCH: Doppelbuchungs-Race (TOCTOU)
`src/BookingService.php:86→135`: Zwischen `isSlotAvailable()` und `createEvent()` liegen zwei HTTP-Roundtrips ohne jeden Mutex. Zwei Kunden, die denselben Slot gleichzeitig bestätigen, erhalten beide „erfolgreich gebucht" + zwei Kalender-Events. Das IP-Rate-Limit verhindert das nicht. Direkt geschäftsschädigend bei öffentlich verlinkten Funnels.
**Fix:** `flock(LOCK_EX)` auf `data/booking.lock` um Check→Create (globale Serialisierung ist bei diesen Volumina völlig ausreichend), Timeout + 409. **Aufwand: 2–3 h.**

### B2 — KRITISCH/HOCH: Fail-open bei Kalender-API-Ausfall
Deckungsgleich mit P1: curl-Fehler, HTTP≥400, abgelaufene/widerrufene Tokens (`prepareFreeBusyCurl()` liefert dann `[]`, `MicrosoftCalendarService.php:117-119`) und Fehler-JSON-Antworten werden sämtlich als „keine Busy-Slots" weiterverarbeitet — die Buchungsseite zeigt dann **alle Arbeitszeiten als frei**. Auch der finale `isSlotAvailable()`-Check hat denselben Fail-open-Pfad.
**Fix:** Fail-closed: bei nicht-valider Antwort einer verbundenen Quelle Exception → `slots.php` 503 „Verfügbarkeit derzeit nicht abrufbar", `book.php` lehnt ab. **Aufwand: 0,5 Tag.**

### B3 — HOCH: Buchungen werden lokal nicht persistiert; stiller Lead-Verlust
Es gibt kein lokales Buchungs-Log (keine `bookings.json` — Buchung existiert nur als Graph-Event + Webhook). Nach `MAX_ATTEMPTS=10` landet ein Webhook-Job im `failed/`-Bucket; `prune(BUCKET_FAILED, 200)` (`src/WebhookQueue.php:109,307-314`) löscht ab dem 201. Eintrag **kommentarlos die ältesten Buchungs-Payloads**. Kein Alert, keine Admin-Übersicht „alle Buchungen", kein Export.
**Fix:** Append-only `data/bookings/YYYY-MM.jsonl` nach erfolgreicher Event-Erstellung (`LOCK_EX|FILE_APPEND`); `failed` nie automatisch prunen, stattdessen Admin-Zähler + Mail-Alert. **Aufwand: 1 Tag.**

### B4 — HOCH: Webhook-Retry hängt am Zufalls-Trigger; Worker nicht einrichtbar
Siehe P2 + S11: Retries laufen nur bei ~1 % der Slot-GETs oder per Cron, dessen Auth-Token aber nicht administrierbar ist. Ohne Traffic (nachts/Wochenende) werden fällige Retries beliebig spät zugestellt — der dokumentierte Backoff (1 min–24 h) ist faktisch wirkungslos.
**Fix:** Cron alle 5 min als Pflicht dokumentieren (KAS unterstützt Cronjobs), Token-Feld im Admin, Header-Auth. **Aufwand: 3–4 h.**

### B5 — HOCH: Kein Healthcheck, kein Monitoring, keine Fehler-Sichtbarkeit
Kein `/health`-Endpoint; Logging ausschließlich unstrukturiertes `error_log()` ins Hoster-Log. Niemand erfährt von: abgelaufenen Azure-Client-Secrets (laufen nach max. 24 Monaten ab — die eigene Anleitung `admin/index.php:1189` weist darauf hin!), dauerhaft fehlschlagenden Webhooks, voller Disk, getrennten Kalenderquellen.
**Fix:** `api/health.php` (Schreibbarkeit `data/`, Token-`expires_at`/Refresh pro Quelle, `failed`-Queue-Tiefe; 200/503) + externes Uptime-Monitoring; `logError()` um Level/Request-ID erweitern. **Aufwand: 1 Tag.**

### B6 — MITTEL: `config.json` ohne atomares Schreiben; Lost Updates; Fail-silent bei Korruption
`ConfigManager.php:255-263`: direktes `file_put_contents` (kein tmp+`rename`); zwei parallele Admin-Saves überschreiben sich gegenseitig (Last-write-wins über **alle** Sektionen — auch von der UX-Seite bestätigt: zwei Admin-Tabs = stiller Datenverlust bei Funnels). Bei Crash mitten im Write fällt `load():243-252` **stillschweigend auf Defaults zurück** — die App liefe ohne Kalenderquellen weiter, als wäre nichts.
**Fix:** tmp+rename, Re-Read unter Lock vor Merge, Exception statt Defaults bei Decode-Fehler einer existierenden Datei, `.bak`-Rotation. **Aufwand: 3–4 h.**

### B7 — MITTEL: `TokenStore`-RMW nicht atomar (Refresh-Token-Verlust)
Siehe P8. Zusätzlich kein tmp+rename: Crash beim Schreiben ⇒ leere `tokens.json` ⇒ Verlust aller Verbindungen. **Fix: 3 h.**

### B8 — MITTEL: Graph-`calendarView` ohne UTC-Offset — Abfragefenster um 1–2 h verschoben
`MicrosoftCalendarService.php:127-128`: `startDateTime/endDateTime` als `Y-m-d\TH:i:s` ohne Offset → Graph interpretiert sie als **UTC**; der `Prefer: outlook.timezone`-Header (Z. 139) betrifft nur Antwort-Zeiten. Bei Monatsabfragen durch Tagesgrenzen meist kaschiert; kritisch bei `isSlotAvailable()` (Fenster = Slot ± Puffer) — Konflikte am Tagesrand können übersehen werden. Google-Seite korrekt (`DateTime::ATOM`).
**Fix:** `format(DateTime::ATOM)`; Test mit 23:30-Termin. **Aufwand: 2 h.**

### B9 — MITTEL: Kein Backup, kein Migrationspfad
`data/` (Secrets, Tokens, Config) existiert nur auf dem FTP-Server; kein Backup-Mechanismus, kein `_version`-Feld für Schema-Migrationen. **Fix:** Cron-Backup mit Rotation + Versionsfeld. **Aufwand: 2–3 h.**

### B10 — NIEDRIG: Sonstiges
Rate-Limit-RMW ohne Lock (Limit minimal durchlässig — akzeptabel); unbekannte Funnel-Slugs liefern 200 statt 404 (`.htaccess:22`, `index.php:13-20`); Cleanup-Jobs nur probabilistisch.

---

## 6. Deployment & CI

### D1 — KRITISCH: = S1 (FTP-Credentials). Sofortmaßnahme Nr. 1.

### D2 — HOCH: Deploy-Pipeline ohne Gate, Rollback und Schutz
`deploy.yml:6` deployt einen generierten Feature-Branch (`claude/appointment-booking-calendar-4dCxg`) direkt in den Prod-Root; kein Lint-/Test-Schritt, kein Healthcheck danach, FTP-Sync nicht atomar (alte+neue Dateien gemischt während des Uploads), kein Staging.
**`data/` überlebt Deploys** (Action löscht nur getrackte Dateien) — aber nur implizit: ein `exclude:` fehlt komplett; ein Tool-Wechsel oder `dangerous-clean-slate` würde Produktionsdaten löschen.
**Außerdem:** Die `AUDIT-*.md` werden mit deployed und sind öffentlich unter `https://<domain>/AUDIT-*.md` abrufbar — eine Schwachstellen-Lektüre für Angreifer.
**Fix:** Deploy nur von `main` (protected); `exclude:` für `AUDIT-*.md`, `data/**`, `.github/**`; `php -l`-Job davor; `curl /api/health.php` danach; `.md` zusätzlich per `.htaccess` sperren. **Aufwand: 0,5 Tag.**

### D3 — HOCH: Null Tests, null CI-Checks
Keine Tests, kein `composer.json`, kein Lint — ein Syntaxfehler auf dem Deploy-Branch geht ungeprüft live. Gut testbare pure Logik existiert (`AvailabilityEngine` inkl. DST-Fällen, `FunnelManager`, `ConfigManager::mergeDefaults`, `SecurityHelper::redactSensitiveData`). **Fix (gestaffelt):** CI mit `php -l` + ESLint (2 h); PHPUnit für die Kernlogik (1–2 Tage).

### D4 — MITTEL: Kein README/Betriebshandbuch
Kein Wort zu Systemvoraussetzungen, Erstinstallation, dem **erforderlichen Cronjob**, Backup/Restore, Secret-Rotation. Die exzellenten M365/Google-Anleitungen existieren nur im Admin-UI. **Fix: 0,5 Tag.**

---

## 7. Usability, UX & UI

### U1 — KRITISCH: Kern-Buchungsflow nicht tastatur-/screenreaderbedienbar (WCAG 2.1.1)
`assets/js/app.js:250-263` (Kalendertage) und `:308-321` (Zeitslots) rendern klickbare `<div>`s ohne `<button>`, `tabindex`, `role` oder `keydown`-Handler. **Datum und Uhrzeit sind ohne Maus nicht wählbar — Schritt 2 ist nie erreichbar.** AA-Blocker auf einem öffentlichen Tool.
**Fix:** `<button type="button">` (CSS bleibt fast identisch), `aria-pressed`, sprechende `aria-label`; ideal Roving-Tabindex im Grid. **Aufwand: 1–2 Tage inkl. Test.**

### U2 — KRITISCH: Kein Datenschutzhinweis/-link im Formular
`templates/booking-form.php:69-139` erhebt Name/E-Mail/Custom-Fields; Daten gehen an Microsoft/Google und optional an Dritt-Webhooks. Repo-weite Suche nach `datenschutz|privacy|consent`: kein Treffer in der UI. DSGVO-Art.-13-Risiko, besonders heikel beim Embed auf fremden Seiten.
**Fix:** Konfigurierbarer Datenschutz-Link + Kurzhinweis über dem Buchen-Button; Admin-Feld. **Aufwand: 0,5–1 Tag.**

### U3 — KRITISCH: Server-Fehlermeldungen erreichen den Nutzer nie
`api/book.php:37,43,54` liefert Fehler unter dem Key `error`; `app.js:531` liest nur `result.message` → bei Rate-Limit (429: „Zu viele Buchungsanfragen…"), ungültigem JSON und 500ern sieht der Nutzer nur „Ein Fehler ist aufgetreten." (verifiziert). Wer das Limit von 5 Buchungen/h/IP trifft (Firmen-NAT!), probiert es sinnlos erneut.
**Fix:** `result.message || result.error || '…'`; Keys serverseitig vereinheitlichen. **Aufwand: 15 min — größter Quick-Win.**

### U4 — KRITISCH: Kein `<noscript>`-Fallback
`index.php:44-82`/`embed.php`: ohne JS bleibt das Widget für immer leer — keine Meldung, kein Kontakt-Hinweis. **Fix: 30 min.**

### U5 — HOCH: Netzwerkfehler beim Slot-Laden wird als „Keine verfügbaren Zeitslots" angezeigt
`app.js:280-289`: `.catch()` setzt leere Slot-Liste; kein `r.ok`-Check. Bei API-Timeout glaubt der Nutzer, der Tag sei ausgebucht → verlorene Conversion + Falschinformation. **Fix:** Fehlerzustand mit Retry-Button (Muster existiert bereits für den Tages-Loader, `app.js:201-212`). **Aufwand: 1 h.**

### U6 — HOCH: Doppelbuchungs-Konflikt ohne Recovery
Bei „Slot nicht mehr verfügbar" (`BookingService.php:87`) bleibt der Nutzer auf Schritt 3; `daysCache` und Slotliste werden **nicht invalidiert** — der tote Slot bleibt buchbar sichtbar. Die Fehlerbox erscheint zudem oben im Panel, während der Nutzer unten geklickt hat (auf Mobile außerhalb des Viewports, `app.js:574-584`). **Fix:** Cache invalidieren, zu Schritt 1 springen, Slots neu laden, `scrollIntoView` + `role="alert"`. **Aufwand: 2–3 h.**

### U7 — HOCH: Keine Zeitzonen-Anzeige für Buchende
Zeiten werden ohne Zonenangabe gerendert; die konfigurierte Zone wird Endkunden nie gezeigt. Beim Embed (beliebige Besucher) bucht ein Nutzer in London u. U. die falsche Stunde. **Fix:** mindestens statischer Hinweis „Alle Zeiten in Europe/Berlin"; ideal Browser-TZ-Erkennung mit Warnung. **Aufwand: 1 h / 1 Tag.**

### U8 — HOCH: Konkrete Kontrastverstöße (WCAG 1.4.3, berechnet)
| Element | Kontrast | Soll | Fundstelle |
|---|---|---|---|
| Form-Hints/`.text-muted`/Wochentage (`#9ca3af` auf Weiß) | 2,54:1 | 4,5:1 | `style.css:262-263,318-319,374-375`, `admin.css:147-150` |
| Info-Alert inkl. Bestätigungstext Schritt 3 (`#2563eb` auf `#dbeafe`) | 4,24:1 | 4,5:1 | `style.css:657-661` |
| Abgeschlossener Schritt / „Verbunden"-Status (`#16a34a` auf `#dcfce7`) | 3,00:1 | 4,5:1 | `style.css:155-158`, `admin.css:211` |
| Slot-Badge 9 px / Erfolgs-Toast (`#16a34a`-Kombis) | 3,30:1 | 4,5:1 | `style.css:307-314`, `admin.css:522-525` |
| Fehler-Alert (`#dc2626` auf `#fee2e2`) | 3,95:1 | 4,5:1 | `style.css:651-655` |

**Fix:** `--gray-400`→`--gray-500/600` für Text; Grün auf `#15803d`; Info-Alert auf `#1e40af` (im Admin-`info-box` bereits korrekt gelöst, `admin.css:475-484`). **Aufwand: 0,5 Tag.**

### U9 — HOCH: Fehlende Formular-/ARIA-Semantik
Kein `<form>`-Element um die Eingabefelder (`booking-form.php:69-139`) → Enter wirkungslos, `required` wirkungslos, kein `autocomplete="given-name|family-name|email"`; keine `aria-live`-Regionen für Slots/Fehler/Erfolg; `.form-error` nicht via `aria-describedby` verknüpft; kein `<main>`-Landmark; Schrittanzeige ohne `aria-current`. **Aufwand: 1 Tag.**

### U10 — HOCH: Modals ohne Dialog-Semantik/Fokus-Management
Admin-Quellen-Modal (`admin/index.php:561-630`) und Kalender-Event-Popup (`calendar/index.php:163-176`): kein `role="dialog"`/`aria-modal`, kein Fokus-Trap/-Restore (ESC funktioniert). **Aufwand: 0,5–1 Tag.**

### U11 — HOCH: Abgelaufene Admin-Session ⇒ kommentarloser Speicherfehler
`admin.js:62-71`: `apiPost` wertet HTTP-Status nicht aus; nach Session-Timeout sieht der Admin nur „Fehler"-Toast und verliert ggf. lange editierte Funnel-/Feld-Formulare. **Fix:** 401-Erkennung → „Sitzung abgelaufen" + Login-Redirect. **Aufwand: 1–2 h.**

### U12 — HOCH: Kalenderansicht verschluckt Quellen-Fehler
`calendar.js:115`: pro Quelle `.catch(() => [])` — abgelaufenes Token ⇒ kommentarlos leerer Kalender; `loadSources`-Fehler (`calendar.js:82-86`) hinterlässt tote Hauptansicht, auf Mobile ist die Fehlermeldung in der versteckten Sidebar unsichtbar. **Fix:** Fehlerbanner pro Quelle. **Aufwand: 2–4 h.**

### U13 — MITTEL (Auswahl, vollständige Liste im UX-Detailbericht)
- **Validierung erst beim Klick auf „Weiter"** statt on-blur; kein Fokus/Scroll zum ersten Fehlerfeld (`app.js:339-382`). 2–3 h.
- **Teilnehmer-E-Mail-Fehler nur als roter Rahmen** ohne Text (`app.js:374-379`; auch WCAG 1.4.1). 15 min.
- **Kein Scroll-/Fokus-Reset beim Schrittwechsel**, besonders im Embed (`app.js:80-83`, `embed.php:74-87`). 1–2 h.
- **Touch-Targets unter 44 px:** Zeitslots ≈41 px, Monatsnav ≈38 px, Mini-Kalender 28 px, Quellen-Checkboxen 14 px (`style.css:348-358,226-235`, `calendar.css:236-249,327-336`). 2–3 h.
- **Mobile: nach Tageswahl kein Auto-Scroll zu den Slots** (`style.css:725-735`) — wirkt „kaputt". 30 min.
- **Design-Tokens dreifach gepflegt, `.btn`/`.alert` divergieren** zwischen `style.css` und `calendar.css`; `admin.css` ohne eigene Tokens (`style.css:3-24` vs. `calendar.css:3-26,116-131`). 1 Tag.
- **Arbeitszeiten ohne Plausibilitätsprüfung** (Ende vor Beginn wird gespeichert; Buchungsseite zeigt dann kommentarlos keine Slots; `admin/index.php:426-439`). 2 h.
- **Fehler-Toasts verschwinden nach 3 s**, ohne `role="status"` (`admin.js:54-58`). 1 h.
- **Icon-Buttons ohne `aria-label`** (~10 Stellen: Monatsnav, Logout, Hamburger, Teilnehmer-Entfernen). 1–2 h.
- **`prefers-reduced-motion` fehlt komplett** (0 Treffer in 3 CSS-Dateien). 30 min.
- **i18n:** alle Strings hardcodiert, Monats-/Wochentagsnamen doppelt gepflegt (`app.js:8-12`, `calendar.js:202-207`), totes `locale: 'de'`-Feld (`admin.js:94`). 2–3 Tage (optional).
- **Funnel-Speichern überträgt immer die komplette Liste** → zwei Admin-Tabs überschreiben sich (deckt sich mit B6). 0,5 Tag.

### U14 — NIEDRIG (Auswahl)
Endzeit kann „24:30 Uhr" anzeigen (`app.js:436-441`, fehlendes `% 24`); Confirm-Dialog zeigt wörtlich `l&ouml;schen` (`admin.js:1210`); keine Buchungsreferenz auf der Erfolgsseite; „* Pflichtfeld"-Legende fehlt; nicht verfügbare Tage mit 1,47:1 quasi unsichtbar; Embed-Snippet steuert bei zwei iframes nur das erste (`admin.js:735-746`); Queue-Zeitstempel browserabhängig formatiert (`admin.js:1181`); kein Dark Mode (für Embed auf dunklen Seiten relevant).

**Positiv:** klarer 3-Schritte-Wizard, Retry-Button beim Tages-Laden, Doppelklick-Schutz, gute Fehlertexte der Feld-Validierung, hervorragende M365/Google-Einrichtungs-Guides mit Copy-Buttons, Toast-Feedback, Empty-States bei Funnels, Erwartungssteuerung vor dem Buchen („Sie erhalten eine Outlook-Termineinladung…"), Pflichtfeld-Sternchen.

---

## 8. Abgleich mit dem Alt-Audit (Feb. 2026)

| Alt-Maßnahme | Status |
|---|---|
| 1.1 CSRF | ✅ Admin-API korrekt; ⚠️ Login-Formulare und der im Plan genannte Booking-Schutz fehlen |
| 1.2 OAuth-State | ✅ vollständig |
| 1.3 Security-Header | ⚠️ Header ja — die spezifizierte **CSP wurde nie umgesetzt** |
| 1.4/1.5, 2.x, 3.1, 3.3-3.5, 4.x, 5.x, 6.x | ✅ verifiziert umgesetzt |
| 3.2 Token-Race | ⚠️ nur Abmilderung; RMW weiterhin ohne durchgehendes Lock (B7/P8) |
| „api-functions.php wird von calendar/api.php verwendet" | ❌ falsch — Duplikat besteht (A1) |
| Doku-Drift | `AUDIT-SCHRITT1` nennt 6-Zeichen-Minimum (Code: 12) und `SameSite=Strict` (Code: `Lax`, Commit `23c0b29`) |

---

*Der priorisierte Umsetzungsplan befindet sich in `AUDIT-2026-06-UMSETZUNGSPLAN.md`.*
