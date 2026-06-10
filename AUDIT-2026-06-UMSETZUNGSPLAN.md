# Umsetzungsplan – Audit Juni 2026

**Basis:** `AUDIT-2026-06-BERICHT.md` (Finding-IDs S/P/A/Q/B/D/U beziehen sich darauf)
**Gesamtaufwand:** ca. 18–22 Personentage, gestaffelt in 5 Phasen. Phasen 0–1 (~3 PT) beseitigen den Großteil des Geschäfts- und Sicherheitsrisikos.

---

## Phase 0 — SOFORT (heute, ~1 Personentag)

Kompromittierungs- und Rechtsrisiken. Keine Abhängigkeiten, jede Maßnahme einzeln deploybar.

| # | Maßnahme | Findings | Aufwand | Akzeptanzkriterium |
|---|---------|----------|---------|--------------------|
| 0.1 | **FTP-Passwort beim Hoster (KAS) rotieren**; `deploy.yml` auf `${{ secrets.FTP_SERVER/USERNAME/PASSWORD }}` umstellen; Git-History bereinigen (`git filter-repo`) oder Repo als kompromittiert einstufen; prüfen, ob M365-/Google-Client-Secrets rotiert werden müssen (waren via FTP lesbar) | S1/D1 | 2–3 h | Kein Klartext-Secret mehr in Repo + History; Deploy läuft mit Secrets |
| 0.2 | `data/.htaccess` mit `Require all denied` automatisch beim Anlegen erzeugen (Muster aus `admin/api.php:362` übernehmen); `php_flag`-Zeilen aus Root-`.htaccess` entfernen | S2 | 1 h | `curl https://<domain>/data/config.json` ⇒ 403, auch ohne mod_rewrite |
| 0.3 | Admin-API im Setup-Zustand sperren: bei `!hasAdminPassword()` nur Passwort-Setup-Aktion zulassen (403 sonst); CSRF-Meta nur authentifiziert rendern | S6 | 1–2 h | Frische Installation: keine API-Aktion außer Setup möglich |
| 0.4 | `.gitignore` → `data/*` + `!data/.gitkeep` (Queue-Payloads mit personenbezogenen Daten) | S16 | 10 min | `git status` zeigt keine `data/`-Inhalte |
| 0.5 | Quick-Win-Bündel Frontend: `result.message \|\| result.error`-Mapping (U3); `<noscript>`-Hinweis (U4); `% 24`-Fix Endzeit; `l&ouml;schen`-Entity-Bug | U3, U4, U14 | 1–2 h | Rate-Limit-Meldung erscheint wörtlich beim Nutzer |
| 0.6 | `AUDIT-*.md` vom Deploy ausschließen (`exclude:` in deploy.yml) + `.md` per `.htaccess` sperren | D2 (Teil) | 30 min | `curl https://<domain>/AUDIT-2026-06-BERICHT.md` ⇒ 403 |

---

## Phase 1 — Diese Woche (~2–3 Personentage)

Verhindert Doppelbuchungen, Falsch-Verfügbarkeit und unbemerkte Ausfälle — die geschäftskritischen Betriebsrisiken.

| # | Maßnahme | Findings | Aufwand | Akzeptanzkriterium |
|---|---------|----------|---------|--------------------|
| 1.1 | **Buchungs-Mutex:** `flock(LOCK_EX)` auf `data/booking.lock` um die Sequenz `isSlotAvailable()`→`createEvent()`; Timeout ⇒ 409 mit verständlicher Meldung | B1 | 2–3 h | Zwei parallele Buchungs-POSTs auf denselben Slot: genau 1 Erfolg, 1 sauberer Konflikt |
| 1.2 | **Fail-closed:** curl-Fehler / HTTP≥400 / Token-Fehler / Error-JSON einer verbundenen Quelle ⇒ Exception statt leerer Busy-Liste. `slots.php` ⇒ 503 „Verfügbarkeit derzeit nicht abrufbar", `book.php` ⇒ Ablehnung. Frontend zeigt Fehlerzustand mit Retry (Muster `app.js:201-212` wiederverwenden) — behebt zugleich U5 | B2/P1(b), U5 | 0,5–1 Tag | Quelle mit ungültigem Token ⇒ Fehlermeldung statt „alle Slots frei" / „Tag ausgebucht" |
| 1.3 | **FreeBusy-Server-Cache:** gemergte Busy-Slots pro Zeitraum+Quellen-Hash in `data/cache/`, TTL 30–60 s, flock, Invalidierung nach erfolgreicher Buchung | P1(a) | 0,5 Tag | 100 parallele `days`-Requests ⇒ max. 1 Upstream-Runde pro TTL-Fenster |
| 1.4 | **Buchungshorizont durchsetzen** in `slots.php` (beide Actions) und `isSlotAvailable()`; mildes IP-Rate-Limit auf `slots.php` (z. B. 120/h) | P3/B6 | 2 h | `?action=days&year=2098` ⇒ leere Antwort ohne Upstream-Call |
| 1.5 | **Webhook-Worker entkoppeln:** Inline-Trigger aus `slots.php` entfernen; Cronjob (alle 5 min) auf `webhook-worker.php`; Worker-Token als Admin-Feld + Header-Auth statt GET-Query | P2/B4/S11 | 3–4 h | Kein Besucher-Request führt Queue-Jobs aus; Retries laufen traffic-unabhängig |
| 1.6 | **Login-Härtung:** Rate-Limit (5/15 min/IP) + CSRF-Token an Admin- und Kalender-Login | S5/S7 | 2–3 h | 6. Fehlversuch ⇒ 429 |
| 1.7 | `session_write_close()` nach Auth-/CSRF-Check in `calendar/api.php` und `admin/api.php` | P4 | 15 min | Parallele Kalender-Requests laufen tatsächlich parallel |
| 1.8 | Connect-Timeouts (`CONNECTTIMEOUT=5`, Read `TIMEOUT=10`) auf allen Google/MS-Calls; 1 Retry bei 429/5xx mit `Retry-After` | P6 | 2–4 h | Nicht erreichbarer Endpoint blockiert Worker max. ~5 s |

---

## Phase 2 — Nächste 2 Wochen (~4–5 Personentage)

Datensicherheit, Nachvollziehbarkeit, Beobachtbarkeit, Rechtskonformität.

| # | Maßnahme | Findings | Aufwand |
|---|---------|----------|---------|
| 2.1 | **Lokales Buchungs-Log:** Append-only `data/bookings/YYYY-MM.jsonl` nach Event-Erstellung; `failed`-Queue nie automatisch prunen; Admin-Anzeige + Alert bei failed>0 | B3 | 1 Tag |
| 2.2 | **Healthcheck `api/health.php`** (data/ beschreibbar, Token-Refresh pro Quelle, Queue-Tiefe, 200/503) + externes Uptime-Monitoring darauf; `logError()` mit Level/Request-ID | B5 | 1 Tag |
| 2.3 | **Datenschutz:** konfigurierbarer Datenschutz-Link + Hinweistext im Buchungsformular (Admin-Feld „Seitendesign"); zeitbasierte Löschung der Queue-Payloads (30 Tage); keine Klardaten in `error_log` | U2/S10 | 1 Tag |
| 2.4 | **Atomare Writes + echte Locks:** `ConfigManager` und `TokenStore` auf tmp+`rename`, RMW unter durchgehendem `flock`, Refresh-Mutex pro Quelle, Exception statt Default-Fallback bei korrupter Datei, `.bak`-Rotation | B6/B7/P8 | 1 Tag |
| 2.5 | **Token-Verschlüsselung:** `sodium_crypto_secretbox` für `tokens.json`, Key außerhalb des Webroots | S4 | 0,5 Tag |
| 2.6 | **SSRF-Schutz:** privater IP-Filter + Redirect-Härtung für Webhook-/Funnel-URLs (zentral in einer Helper-Methode, von BookingService, WebhookQueue und Funnel-Test genutzt); CRLF-Filter für Custom-Header | S3/S12 | 0,5 Tag |
| 2.7 | **Graph-Zeitzonen-Fix:** `calendarView` mit `DateTime::ATOM`; Verifikation mit Randzeit-Termin (23:30) | B8 | 2 h |
| 2.8 | **Deploy-Pipeline:** Deploy nur von `main` (protected branch), `exclude:` für `data/**`/`.github/**`/Doku, vorgelagerter `php -l`-Job, nachgelagerter Health-Gate-Check | D2 | 0,5 Tag |

---

## Phase 3 — Nächster Monat (~5–6 Personentage)

Accessibility-Konformität, UX-Robustheit, Performance-Feinschliff.

| # | Maßnahme | Findings | Aufwand |
|---|---------|----------|---------|
| 3.1 | **Tastatur-/Screenreader-Bedienung des Buchungsflows:** Tage/Slots als `<button>`, `aria-pressed`/`aria-label`, Roving-Tabindex; `<form>`-Element + `autocomplete`; `aria-live` für Slots/Fehler/Erfolg; `aria-describedby`/`aria-invalid`; `<main>`; `aria-current` an Schrittanzeige; Icon-`aria-label`s | U1/U9/U13 | 2 Tage |
| 3.2 | **Kontrast-Fixes** gemäß Tabelle in U8 (`--gray-400`→500/600, Grün→`#15803d`, Info→`#1e40af`); `prefers-reduced-motion`-Block | U8 | 0,5 Tag |
| 3.3 | **Doppelbuchungs-Recovery:** Cache-Invalidierung, Rücksprung zu Schritt 1, Neuladen, `role="alert"`+`scrollIntoView`; Zeitzonen-Hinweis unter der Slot-Liste | U6/U7 | 0,5 Tag |
| 3.4 | **Formular-UX:** Live-Validierung on-blur, Fokus aufs erste Fehlerfeld, Fehlertext für Teilnehmer-E-Mail, Scroll-Reset bei Schrittwechsel (auch Embed), Auto-Scroll zu Slots auf Mobile, Touch-Targets ≥44 px | U13 | 1 Tag |
| 3.5 | **Admin-UX:** 401-Erkennung („Sitzung abgelaufen") in `apiPost`, Arbeitszeiten-Plausibilität (start<end), persistente Fehler-Toasts mit `role="status"`, Modal-Semantik + Fokus-Trap, Kalenderansicht-Fehlerbanner pro Quelle | U10/U11/U12/U13 | 1 Tag |
| 3.6 | **Kalenderansicht-Performance:** Multi-Source-Events-Endpoint (curl_multi), Kalenderlisten-Cache (10–15 min), JS-Event-Cache pro Range; MS `getSchedule` statt N×`calendarView` (löst auch das 500-Events-Pagination-Problem) | P7/P10/M5 | 1 Tag |
| 3.7 | `.htaccess`: gzip + `Cache-Control: immutable` für Assets; `?v=` im Admin-CSS; Embed auf `ResizeObserver` umstellen; Prefetch nach Interaktion + AbortController | P9/P11/P12 | 0,5 Tag |

---

## Phase 4 — Backlog / bei Weiterentwicklung (~5–7 Personentage)

| # | Maßnahme | Findings | Aufwand |
|---|---------|----------|---------|
| 4.1 | CI: `php -l` + ESLint als Pflicht-Gate; PHPUnit für `AvailabilityEngine` (Slot-Grenzen, Puffer, Pause, min_notice, **DST März/Oktober**), `FunnelManager`, `ConfigManager` | D3 | 1,5–2,5 Tage |
| 4.2 | `calendar/`-Duplikation auflösen: auf `TokenStore`+Services umstellen, `api-functions.php` löschen, Scopes zentralisieren; Fehler-Logging in den verbleibenden Pfaden (Q1) | A1/Q1 | 1 Tag |
| 4.3 | README + OPERATIONS.md (Setup, PHP-Anforderungen, **Cron-Pflicht**, Backup/Restore, Runbook „Quelle getrennt", Secret-Rotation Azure ≤24 Monate) | D4 | 0,5 Tag |
| 4.4 | Backup-Cron für `data/` mit Rotation; `_version`-Feld in config.json + Migrations-Hook | B9 | 0,5 Tag |
| 4.5 | CSP einführen (`default-src 'self'`; Inline-Skripte/`onclick` im Admin refactoren); Exception-Details aus `admin/api.php`-Antworten entfernen | S8/S9 | 0,5 Tag |
| 4.6 | Architektur-Hygiene: Bootstrap + DI (A3), `strict_types` + Composer-Autoload (Q4), Validierung allein im ConfigManager (Q5), toten Code entfernen (`teams.source_id`, `select`-Feldtyp, ungenutzte Methoden) (Q3), `is_booking_target` für Google ablehnen (A2) | A2/A3/Q3-Q5 | 1,5 Tage |
| 4.7 | Optional: i18n-Schicht (`Intl.DateTimeFormat`), Design-Token-Konsolidierung (`tokens.css`), SVG-Icons statt Emojis, Dark Mode fürs Embed, Buchungsreferenz auf Erfolgsseite, Funnel-Konfliktschutz (Versions-Check) | U13/U14 | 2–3 Tage |

---

## Abhängigkeiten & Hinweise

- **0.1 ist Voraussetzung für alles Weitere** — solange die FTP-Credentials gültig und öffentlich sind, ist jede andere Härtung wirkungslos.
- 1.2 (fail-closed) und 1.3 (Cache) gehören zusammen deployed: fail-closed ohne Cache erhöht die Fehlerquote bei Throttling, Cache ohne fail-closed cached Falsch-Verfügbarkeit.
- 1.5 (Cron) setzt Zugang zur KAS-Verwaltung voraus (Cronjob anlegen).
- 2.4 sollte vor 2.5 erfolgen (erst atomare Writes, dann Verschlüsselung im selben Code-Pfad).
- 2.8 setzt einen `main`-Branch als neuen Deploy-Standard voraus — Umstellung mit dem Team abstimmen, der bisherige Deploy-Branch (`claude/appointment-booking-calendar-4dCxg`) ist ein generierter Feature-Branch.
- Nach 0.1/2.5: prüfen, ob bestehende M365-/Google-Verbindungen neu autorisiert werden müssen.

## Erfolgskontrolle (nach Phase 1 messbar)

| Metrik | Heute | Ziel |
|---|---|---|
| Upstream-Calls bei 100 parallelen Besuchern | ~100–300/Welle | ≤ 2/Minute (Cache) |
| Verhalten bei Graph-429/Token-Ablauf | „alles frei" (fail-open) | 503 + Nutzerhinweis (fail-closed) |
| Paralleler Doppelbuchungs-Versuch | 2 Events | 1 Event + 409 |
| Schlechtester Besucher-Request (Webhook-Inline-Worker) | bis ~100 s | < 3 s |
| Buchbarer Zeitraum | bis 2099 | `booking_horizon_days` |
| Kompromittierungspfad über Repo | FTP-Vollzugriff | keiner |
