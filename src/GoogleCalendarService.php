<?php

/**
 * Google Calendar Service über Google Calendar API v3.
 * Handles OAuth2 und Free/Busy-Abfragen.
 */
class GoogleCalendarService
{
    private array $sourceConfig;
    private TokenStore $tokenStore;
    private string $sourceId;

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.readonly';

    public function __construct(array $sourceConfig, TokenStore $tokenStore)
    {
        $this->sourceConfig = $sourceConfig;
        $this->tokenStore = $tokenStore;
        $this->sourceId = $sourceConfig['id'];
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
            return [];
        }

        $calendarIds = [];
        foreach ($this->sourceConfig['calendars'] as $calId) {
            $calendarIds[] = ['id' => $calId];
        }

        $body = [
            'timeMin' => $start->format(\DateTime::ATOM),
            'timeMax' => $end->format(\DateTime::ATOM),
            'timeZone' => 'Europe/Berlin',
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
            CURLOPT_TIMEOUT => 30,
        ]);

        return [['handle' => $ch]];
    }

    /**
     * Parsed eine Free/Busy-Response zu Busy-Slots.
     */
    public function parseFreeBusyResponse(string $response): array
    {
        $data = json_decode($response, true) ?: [];
        $busySlots = [];

        if (isset($data['calendars'])) {
            foreach ($data['calendars'] as $calData) {
                if (isset($calData['busy'])) {
                    foreach ($calData['busy'] as $busy) {
                        $busySlots[] = [
                            'start' => new \DateTime($busy['start']),
                            'end' => new \DateTime($busy['end']),
                        ];
                    }
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

        if (($tokenData['expires_at'] ?? 0) > time() + 300) {
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
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
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
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
}
