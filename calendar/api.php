<?php
/**
 * Kalenderansicht API – Gibt echte Kalender-Events (Titel, Zeit, etc.) zurück.
 * Passwortgeschützt über Admin-Session.
 */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/../src/ConfigManager.php';
require_once __DIR__ . '/../src/TokenStore.php';
require_once __DIR__ . '/../src/SecurityHelper.php';

header('Content-Type: application/json; charset=utf-8');

$cm = new ConfigManager();
$config = $cm->getAppConfig();

// Auth prüfen
if (!isset($_SESSION['calendar_auth']) || $_SESSION['calendar_auth'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht authentifiziert']);
    exit;
}

$tokenStore = new TokenStore($config['token_store']['path']);
$timezone = $config['app']['timezone'] ?? 'Europe/Berlin';

$action = $_GET['action'] ?? '';

if ($action === 'sources') {
    // Verfügbare Kalender-Quellen zurückgeben
    $sources = [];
    foreach ($config['calendar_sources'] as $src) {
        $connected = $tokenStore->has($src['id']);
        $calendars = [];
        if ($connected) {
            $calendars = fetchCalendarList($src, $tokenStore, $timezone);
            // Fallback: Wenn die API keine Kalender liefert, die konfigurierten IDs verwenden
            if (empty($calendars) && !empty($src['calendars'])) {
                foreach ($src['calendars'] as $calId) {
                    $calendars[] = [
                        'id' => $calId,
                        'name' => ($calId === 'primary') ? 'Hauptkalender' : $calId,
                        'color' => ($src['type'] === 'google') ? '#4285f4' : '#2563eb',
                    ];
                }
            }
        }
        $sources[] = [
            'id' => $src['id'],
            'type' => $src['type'],
            'connected' => $connected,
            'calendars' => $calendars,
        ];
    }
    echo json_encode(['sources' => $sources]);
    exit;
}

if ($action === 'events') {
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';
    $sourceId = $_GET['source_id'] ?? '';
    $calendarId = $_GET['calendar_id'] ?? '';

    if (empty($start) || empty($end) || empty($sourceId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter start, end, source_id erforderlich']);
        exit;
    }

    // Source finden
    $source = null;
    foreach ($config['calendar_sources'] as $src) {
        if ($src['id'] === $sourceId) {
            $source = $src;
            break;
        }
    }

    if (!$source || !$tokenStore->has($sourceId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Kalender-Quelle nicht gefunden oder nicht verbunden']);
        exit;
    }

    $events = fetchEvents($source, $tokenStore, $timezone, $start, $end, $calendarId);
    echo json_encode(['events' => $events]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte Aktion']);
exit;

// ============================================================
// Hilfsfunktionen
// ============================================================

/**
 * Holt die Liste der verfügbaren Kalender einer Quelle.
 */
function fetchCalendarList(array $source, TokenStore $tokenStore, string $timezone): array
{
    $accessToken = getValidAccessToken($source, $tokenStore);
    if (!$accessToken) {
        return [];
    }

    if ($source['type'] === 'microsoft') {
        return fetchMicrosoftCalendarList($accessToken, $timezone);
    } elseif ($source['type'] === 'google') {
        return fetchGoogleCalendarList($accessToken);
    }

    return [];
}

function fetchMicrosoftCalendarList(string $accessToken, string $timezone): array
{
    $url = 'https://graph.microsoft.com/v1.0/me/calendars?$select=id,name,color&$top=50';
    $data = graphGet($url, $accessToken, $timezone);
    $calendars = [];

    if (isset($data['value'])) {
        foreach ($data['value'] as $cal) {
            $calendars[] = [
                'id' => $cal['id'],
                'name' => $cal['name'] ?? 'Kalender',
                'color' => mapMicrosoftColor($cal['color'] ?? ''),
            ];
        }
    }

    return $calendars;
}

function fetchGoogleCalendarList(string $accessToken): array
{
    $url = 'https://www.googleapis.com/calendar/v3/users/me/calendarList?fields=items(id,summary,backgroundColor)';
    $data = apiGet($url, $accessToken);
    $calendars = [];

    if (isset($data['items'])) {
        foreach ($data['items'] as $cal) {
            $calendars[] = [
                'id' => $cal['id'],
                'name' => $cal['summary'] ?? 'Kalender',
                'color' => $cal['backgroundColor'] ?? '#4285f4',
            ];
        }
    }

    return $calendars;
}

/**
 * Holt Events für einen Zeitraum von einer Kalender-Quelle.
 */
function fetchEvents(array $source, TokenStore $tokenStore, string $timezone, string $start, string $end, string $calendarId = ''): array
{
    $accessToken = getValidAccessToken($source, $tokenStore);
    if (!$accessToken) {
        return [];
    }

    if ($source['type'] === 'microsoft') {
        return fetchMicrosoftEvents($accessToken, $timezone, $start, $end, $calendarId);
    } elseif ($source['type'] === 'google') {
        return fetchGoogleEvents($accessToken, $timezone, $start, $end, $calendarId);
    }

    return [];
}

function fetchMicrosoftEvents(string $accessToken, string $timezone, string $start, string $end, string $calendarId = ''): array
{
    $calPath = '';
    if (!empty($calendarId) && $calendarId !== 'primary') {
        $calPath = '/calendars/' . urlencode($calendarId);
    }

    $params = http_build_query([
        'startDateTime' => $start,
        'endDateTime' => $end,
        '$select' => 'subject,start,end,location,isAllDay,showAs,categories,isCancelled,organizer',
        '$top' => 500,
        '$orderby' => 'start/dateTime',
    ]);

    $url = "https://graph.microsoft.com/v1.0/me{$calPath}/calendarView?{$params}";
    $data = graphGet($url, $accessToken, $timezone);
    $events = [];

    if (isset($data['value'])) {
        foreach ($data['value'] as $event) {
            if (!empty($event['isCancelled'])) {
                continue;
            }
            $events[] = [
                'subject' => $event['subject'] ?? '(Kein Titel)',
                'start' => $event['start']['dateTime'] ?? '',
                'end' => $event['end']['dateTime'] ?? '',
                'startTz' => $event['start']['timeZone'] ?? $timezone,
                'endTz' => $event['end']['timeZone'] ?? $timezone,
                'isAllDay' => $event['isAllDay'] ?? false,
                'location' => $event['location']['displayName'] ?? '',
                'showAs' => $event['showAs'] ?? 'busy',
                'categories' => $event['categories'] ?? [],
            ];
        }
    }

    return $events;
}

function fetchGoogleEvents(string $accessToken, string $timezone, string $start, string $end, string $calendarId = ''): array
{
    if (empty($calendarId)) {
        $calendarId = 'primary';
    }

    // Google Calendar API erfordert RFC3339 mit Timezone-Offset
    $startRfc = toRfc3339($start, $timezone);
    $endRfc = toRfc3339($end, $timezone);

    $params = http_build_query([
        'timeMin' => $startRfc,
        'timeMax' => $endRfc,
        'singleEvents' => 'true',
        'orderBy' => 'startTime',
        'timeZone' => $timezone,
        'maxResults' => 500,
        'fields' => 'items(summary,start,end,location,status,colorId)',
    ]);

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendarId) . '/events?' . $params;
    $data = apiGet($url, $accessToken);
    $events = [];

    if (isset($data['items'])) {
        foreach ($data['items'] as $event) {
            if (($event['status'] ?? '') === 'cancelled') {
                continue;
            }
            $isAllDay = isset($event['start']['date']);
            $events[] = [
                'subject' => $event['summary'] ?? '(Kein Titel)',
                'start' => $isAllDay ? $event['start']['date'] : ($event['start']['dateTime'] ?? ''),
                'end' => $isAllDay ? $event['end']['date'] : ($event['end']['dateTime'] ?? ''),
                'startTz' => $timezone,
                'endTz' => $timezone,
                'isAllDay' => $isAllDay,
                'location' => $event['location'] ?? '',
                'showAs' => 'busy',
                'categories' => [],
            ];
        }
    }

    return $events;
}

// ============================================================
// Token-Verwaltung (wiederverwendet bestehende Logik)
// ============================================================

function getValidAccessToken(array $source, TokenStore $tokenStore): ?string
{
    $tokenData = $tokenStore->get($source['id']);
    if (!$tokenData) {
        return null;
    }

    // Token noch gültig (5 Min Puffer)
    if (($tokenData['expires_at'] ?? 0) > time() + 300) {
        return $tokenData['access_token'];
    }

    // Token erneuern
    if (!empty($tokenData['refresh_token'])) {
        return refreshAccessToken($source, $tokenStore, $tokenData['refresh_token']);
    }

    return null;
}

function refreshAccessToken(array $source, TokenStore $tokenStore, string $refreshToken): ?string
{
    if ($source['type'] === 'microsoft') {
        $tenant = $source['tenant_id'] ?: 'common';
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
        $params = [
            'client_id' => $source['client_id'],
            'client_secret' => $source['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access Calendars.ReadWrite OnlineMeetings.ReadWrite Mail.Send',
        ];
    } else {
        $url = 'https://oauth2.googleapis.com/token';
        $params = [
            'client_id' => $source['client_id'],
            'client_secret' => $source['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true) ?: [];

    if (isset($data['access_token'])) {
        $tokenStore->set($source['id'], [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => time() + ($data['expires_in'] ?? 3600),
            'token_type' => $data['token_type'] ?? 'Bearer',
        ]);
        return $data['access_token'];
    }

    return null;
}

// ============================================================
// HTTP-Hilfsfunktionen
// ============================================================

function graphGet(string $url, string $accessToken, string $timezone): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Prefer: outlook.timezone="' . $timezone . '"',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        SecurityHelper::logError('Calendar View', 'Graph GET error: ' . curl_error($ch));
        curl_close($ch);
        return [];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true) ?: [];
    if ($httpCode >= 400) {
        SecurityHelper::logError('Calendar View', "Graph GET HTTP {$httpCode}: " . ($decoded['error']['message'] ?? $response));
        return [];
    }

    return $decoded;
}

function apiGet(string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        SecurityHelper::logError('Calendar API', 'GET error: ' . curl_error($ch));
        curl_close($ch);
        return [];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true) ?: [];
    if ($httpCode >= 400) {
        SecurityHelper::logError('Calendar API', "GET HTTP {$httpCode}: " . ($decoded['error']['message'] ?? $response));
        return [];
    }

    return $decoded;
}

/**
 * Konvertiert einen Datums-String (z.B. 2024-01-15T00:00:00) in RFC3339 mit Timezone-Offset.
 * Google Calendar API erfordert dieses Format für timeMin/timeMax.
 */
function toRfc3339(string $dateStr, string $timezone): string
{
    try {
        $dt = new \DateTime($dateStr, new \DateTimeZone($timezone));
        return $dt->format(\DateTime::ATOM);
    } catch (\Exception $e) {
        return $dateStr;
    }
}

/**
 * Mapped Microsoft-Kalenderfarben auf Hex-Werte.
 */
function mapMicrosoftColor(string $color): string
{
    $map = [
        'auto' => '#2563eb',
        'lightBlue' => '#3b82f6',
        'lightGreen' => '#22c55e',
        'lightOrange' => '#f97316',
        'lightGray' => '#9ca3af',
        'lightYellow' => '#eab308',
        'lightTeal' => '#14b8a6',
        'lightPink' => '#ec4899',
        'lightBrown' => '#a16207',
        'lightRed' => '#ef4444',
        'maxColor' => '#8b5cf6',
    ];
    return $map[$color] ?? '#2563eb';
}
