<?php

require_once __DIR__ . '/CalendarServiceInterface.php';
require_once __DIR__ . '/CalendarUnavailableException.php';

/**
 * Google Calendar Service über Google Calendar API v3.
 * Handles OAuth2 und Free/Busy-Abfragen.
 */
class GoogleCalendarService implements CalendarServiceInterface
{
    private array $sourceConfig;
    private TokenStore $tokenStore;
    private string $sourceId;
    private string $timezone;

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.readonly';

    /** Token-Erneuerung N Sekunden vor Ablauf */
    private const TOKEN_REFRESH_BUFFER_SECONDS = 300;
    /** HTTP-Timeout fuer API-Calls in Sekunden */
    private const HTTP_TIMEOUT_SECONDS = 30;

    public function __construct(array $sourceConfig, TokenStore $tokenStore, string $timezone = 'Europe/Berlin')
    {
        $this->sourceConfig = $sourceConfig;
        $this->tokenStore = $tokenStore;
        $this->sourceId = $sourceConfig['id'];
        $this->timezone = $timezone;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getAuthUrl(string $state = ''): string
    {
        $params = [
            'client_id' => $this->sourceConfig['client_id'],
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function handleCallback(string $code): bool
    {
        $response = $this->httpPost(self::TOKEN_URL, [
            'client_id' => $this->sourceConfig['client_id'],
            'client_secret' => $this->sourceConfig['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (isset($response['access_token'])) {
            $this->tokenStore->set($this->sourceId, [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? '',
                'expires_at' => time() + ($response['expires_in'] ?? 3600),
                'token_type' => $response['token_type'] ?? 'Bearer',
            ]);
            return true;
        }

        return false;
    }

    public function isAuthenticated(): bool
    {
        return $this->tokenStore->has($this->sourceId);
    }

    /**
     * Gibt Free/Busy-Daten für einen Zeitraum zurück.
     * @return array Liste von busy-Zeiträumen [['start' => DateTime, 'end' => DateTime], ...]
     */
    public function getFreeBusy(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $handles = $this->prepareFreeBusyCurl($start, $end);
        if (empty($handles)) {
            return [];
        }

        $entry = $handles[0];
        $response = curl_exec($entry['handle']);
        curl_close($entry['handle']);

        return $this->parseFreeBusyResponse($response);
    }

    /**
     * Bereitet curl-Handle für Free/Busy-Abfrage vor (für parallele Ausführung).
     * @return array [['handle' => resource], ...]
     */
    public function prepareFreeBusyCurl(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            throw new CalendarUnavailableException(
                $this->sourceId,
                'Google-Verbindung abgelaufen oder ungueltig – Free/Busy nicht abrufbar'
            );
        }

        $calendarIds = [];
        foreach ($this->sourceConfig['calendars'] as $calId) {
            $calendarIds[] = ['id' => $calId];
        }

        $body = [
            'timeMin' => $start->format(\DateTime::ATOM),
            'timeMax' => $end->format(\DateTime::ATOM),
            'timeZone' => $this->timezone,
            'items' => $calendarIds,
        ];

        $ch = curl_init(self::CALENDAR_API . '/freeBusy');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);

        return [['handle' => $ch]];
    }

    /**
     * Parsed eine Free/Busy-Response zu Busy-Slots.
     *
     * @throws CalendarUnavailableException bei unlesbarer oder fehlerhafter Response
     */
    public function parseFreeBusyResponse(string $response): array
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new CalendarUnavailableException($this->sourceId, 'Unlesbare Calendar-API-Antwort');
        }
        if (isset($data['error'])) {
            throw new CalendarUnavailableException(
                $this->sourceId,
                'Calendar-API-Fehler: ' . ($data['error']['message'] ?? 'unbekannt')
            );
        }
        if (!isset($data['calendars']) || !is_array($data['calendars'])) {
            throw new CalendarUnavailableException($this->sourceId, 'Antwort ohne Free/Busy-Daten');
        }

        $busySlots = [];
        foreach ($data['calendars'] as $calId => $calData) {
            // Pro-Kalender-Fehler (z.B. notFound) duerfen nicht als "frei" gelten
            if (!empty($calData['errors'])) {
                $reason = $calData['errors'][0]['reason'] ?? 'unbekannt';
                throw new CalendarUnavailableException(
                    $this->sourceId,
                    "Kalender '{$calId}' nicht abrufbar: {$reason}"
                );
            }
            foreach ($calData['busy'] ?? [] as $busy) {
                try {
                    $busySlots[] = [
                        'start' => new \DateTime($busy['start']),
                        'end' => new \DateTime($busy['end']),
                    ];
                } catch (\Exception $e) {
                    throw new CalendarUnavailableException($this->sourceId, 'Unlesbare Busy-Zeit');
                }
            }
        }

        return $busySlots;
    }

    private function getValidAccessToken(): ?string
    {
        $tokenData = $this->tokenStore->get($this->sourceId);
        if (!$tokenData) {
            return null;
        }

        if (($tokenData['expires_at'] ?? 0) > time() + self::TOKEN_REFRESH_BUFFER_SECONDS) {
            return $tokenData['access_token'];
        }

        if (!empty($tokenData['refresh_token'])) {
            return $this->refreshToken($tokenData['refresh_token']);
        }

        return null;
    }

    private function refreshToken(string $refreshToken): ?string
    {
        $response = $this->httpPost(self::TOKEN_URL, [
            'client_id' => $this->sourceConfig['client_id'],
            'client_secret' => $this->sourceConfig['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (isset($response['access_token'])) {
            $this->tokenStore->set($this->sourceId, [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? $refreshToken,
                'expires_at' => time() + ($response['expires_in'] ?? 3600),
                'token_type' => $response['token_type'] ?? 'Bearer',
            ]);
            return $response['access_token'];
        }

        return null;
    }

    private function getRedirectUri(): string
    {
        if (!empty($this->sourceConfig['redirect_uri'])) {
            return $this->sourceConfig['redirect_uri'];
        }
        $config = require __DIR__ . '/../config.php';
        return rtrim($config['app']['url'], '/') . '/admin/auth-google.php';
    }

    private function apiPost(string $url, array $data, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            SecurityHelper::logError('Google API', 'API error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => ['message' => 'Netzwerkfehler bei Google-API-Anfrage']];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?: [];
        if ($httpCode >= 400) {
            SecurityHelper::logError('Google API', "HTTP {$httpCode}: " . ($decoded['error']['message'] ?? $response));
        }
        return $decoded;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            SecurityHelper::logError('Google Token', 'Token endpoint error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => 'Netzwerkfehler bei Token-Anfrage'];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
}
