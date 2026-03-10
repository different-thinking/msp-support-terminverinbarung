<?php
/**
 * Gemeinsame Kalender-API-Funktionen.
 * Werden sowohl von calendar/api.php als auch admin/api.php verwendet.
 */

require_once __DIR__ . '/../src/TokenStore.php';

/**
 * Holt die Liste der verfügbaren Kalender einer Quelle.
 */
function calApiFetchCalendarList(array $source, TokenStore $tokenStore, string $timezone): array
{
    $accessToken = calApiGetValidAccessToken($source, $tokenStore);
    if (!$accessToken) {
        return [];
    }

    if ($source['type'] === 'microsoft') {
        return calApiFetchMicrosoftCalendarList($accessToken, $timezone);
    } elseif ($source['type'] === 'google') {
        return calApiFetchGoogleCalendarList($accessToken);
    }

    return [];
}

function calApiFetchMicrosoftCalendarList(string $accessToken, string $timezone): array
{
    $url = 'https://graph.microsoft.com/v1.0/me/calendars?$select=id,name,color&$top=50';
    $data = calApiGraphGet($url, $accessToken, $timezone);
    $calendars = [];

    if (isset($data['value'])) {
        foreach ($data['value'] as $cal) {
            $calendars[] = [
                'id' => $cal['id'],
                'name' => $cal['name'] ?? 'Kalender',
                'color' => calApiMapMicrosoftColor($cal['color'] ?? ''),
            ];
        }
    }

    return $calendars;
}

function calApiFetchGoogleCalendarList(string $accessToken): array
{
    $url = 'https://www.googleapis.com/calendar/v3/users/me/calendarList?fields=items(id,summary,backgroundColor)';
    $data = calApiHttpGet($url, $accessToken);
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

function calApiGetValidAccessToken(array $source, TokenStore $tokenStore): ?string
{
    $tokenData = $tokenStore->get($source['id']);
    if (!$tokenData) {
        return null;
    }

    if (($tokenData['expires_at'] ?? 0) > time() + 300) {
        return $tokenData['access_token'];
    }

    if (!empty($tokenData['refresh_token'])) {
        return calApiRefreshAccessToken($source, $tokenStore, $tokenData['refresh_token']);
    }

    return null;
}

function calApiRefreshAccessToken(array $source, TokenStore $tokenStore, string $refreshToken): ?string
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

function calApiGraphGet(string $url, string $accessToken, string $timezone): array
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return [];
    }

    return json_decode($response, true) ?: [];
}

function calApiHttpGet(string $url, string $accessToken): array
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return [];
    }

    return json_decode($response, true) ?: [];
}

function calApiMapMicrosoftColor(string $color): string
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
