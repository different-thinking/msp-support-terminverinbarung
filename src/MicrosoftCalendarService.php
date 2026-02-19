<?php

/**
 * Microsoft 365 Kalender-Service über Microsoft Graph API.
 * Handles OAuth2, Free/Busy-Abfragen, Terminerstellung und Teams-Meetings.
 */
class MicrosoftCalendarService
{
    private array $sourceConfig;
    private TokenStore $tokenStore;
    private string $sourceId;

    private const AUTH_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';
    private const SCOPES = 'offline_access Calendars.ReadWrite OnlineMeetings.ReadWrite Mail.Send';

    public function __construct(array $sourceConfig, TokenStore $tokenStore)
    {
        $this->sourceConfig = $sourceConfig;
        $this->tokenStore = $tokenStore;
        $this->sourceId = $sourceConfig['id'];
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getAuthUrl(string $state = ''): string
    {
        $tenant = $this->sourceConfig['tenant_id'] ?: 'common';
        $url = str_replace('{tenant}', $tenant, self::AUTH_URL);

        $params = [
            'client_id' => $this->sourceConfig['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->getRedirectUri(),
            'scope' => self::SCOPES,
            'response_mode' => 'query',
            'state' => $state,
        ];

        return $url . '?' . http_build_query($params);
    }

    public function handleCallback(string $code): bool
    {
        $tenant = $this->sourceConfig['tenant_id'] ?: 'common';
        $url = str_replace('{tenant}', $tenant, self::TOKEN_URL);

        $response = $this->httpPost($url, [
            'client_id' => $this->sourceConfig['client_id'],
            'client_secret' => $this->sourceConfig['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code',
            'scope' => self::SCOPES,
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

        $busySlots = [];
        foreach ($handles as $entry) {
            $response = curl_exec($entry['handle']);
            curl_close($entry['handle']);
            $busySlots = array_merge($busySlots, $this->parseFreeBusyResponse($response));
        }

        return $busySlots;
    }

    /**
     * Bereitet curl-Handles für Free/Busy-Abfragen vor (für parallele Ausführung).
     * @return array [['handle' => resource], ...]
     */
    public function prepareFreeBusyCurl(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            return [];
        }

        $handles = [];
        foreach ($this->sourceConfig['calendars'] as $calendarId) {
            $calPath = ($calendarId === 'primary') ? '' : "/calendars/{$calendarId}";
            $url = self::GRAPH_URL . "/me{$calPath}/calendarView?"
                . http_build_query([
                    'startDateTime' => $start->format('Y-m-d\TH:i:s'),
                    'endDateTime' => $end->format('Y-m-d\TH:i:s'),
                    '$select' => 'start,end,showAs',
                    '$top' => 500,
                ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                    'Prefer: outlook.timezone="Europe/Berlin"',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);

            $handles[] = ['handle' => $ch];
        }

        return $handles;
    }

    /**
     * Parsed eine Free/Busy-Response zu Busy-Slots.
     */
    public function parseFreeBusyResponse(string $response): array
    {
        $data = json_decode($response, true) ?: [];
        $busySlots = [];

        if (isset($data['value'])) {
            foreach ($data['value'] as $event) {
                $showAs = $event['showAs'] ?? 'busy';
                if (in_array($showAs, ['busy', 'oof', 'tentative'])) {
                    $busySlots[] = [
                        'start' => new \DateTime($event['start']['dateTime'], new \DateTimeZone($event['start']['timeZone'] ?? 'UTC')),
                        'end' => new \DateTime($event['end']['dateTime'], new \DateTimeZone($event['end']['timeZone'] ?? 'UTC')),
                    ];
                }
            }
        }

        return $busySlots;
    }

    /**
     * Erstellt einen Termin mit optionalem Teams-Meeting.
     *
     * Die Einladung wird direkt über den M365-Account versendet –
     * alle Attendees erhalten eine echte Outlook-Termineinladung,
     * genau wie bei manueller Erstellung in Outlook/Teams.
     */
    public function createEvent(array $eventData, bool $withTeamsMeeting = false): ?array
    {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            return null;
        }

        $attendees = [];
        foreach ($eventData['attendees'] as $att) {
            $attendees[] = [
                'emailAddress' => [
                    'address' => $att['email'],
                    'name' => $att['name'] ?? $att['email'],
                ],
                'type' => 'required',
            ];
        }

        $body = [
            'subject' => $eventData['subject'],
            'body' => [
                'contentType' => 'HTML',
                'content' => $eventData['description'] ?? '',
            ],
            'start' => [
                'dateTime' => $eventData['start']->format('Y-m-d\TH:i:s'),
                'timeZone' => $eventData['timezone'] ?? 'Europe/Berlin',
            ],
            'end' => [
                'dateTime' => $eventData['end']->format('Y-m-d\TH:i:s'),
                'timeZone' => $eventData['timezone'] ?? 'Europe/Berlin',
            ],
            'attendees' => $attendees,
            // Erinnerung 15 Min vorher
            'isReminderOn' => true,
            'reminderMinutesBeforeStart' => 15,
            // Einladungen werden automatisch von Graph versendet
            'responseRequested' => true,
        ];

        if ($withTeamsMeeting) {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        $calendarId = $this->sourceConfig['calendars'][0] ?? 'primary';
        $calPath = ($calendarId === 'primary') ? '' : "/calendars/{$calendarId}";
        $url = self::GRAPH_URL . "/me{$calPath}/events";

        $response = $this->graphPost($url, $body, $accessToken);

        return $response;
    }

    /**
     * Sendet eine E-Mail über den M365-Account (Microsoft Graph sendMail).
     * Kann für zusätzliche Benachrichtigungen genutzt werden.
     */
    public function sendMail(array $mailData): bool
    {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            return false;
        }

        $toRecipients = [];
        foreach ($mailData['to'] as $recipient) {
            $toRecipients[] = [
                'emailAddress' => [
                    'address' => $recipient['email'],
                    'name' => $recipient['name'] ?? $recipient['email'],
                ],
            ];
        }

        $body = [
            'message' => [
                'subject' => $mailData['subject'],
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $mailData['body_html'],
                ],
                'toRecipients' => $toRecipients,
            ],
            'saveToSentItems' => true,
        ];

        $url = self::GRAPH_URL . '/me/sendMail';
        $response = $this->graphPost($url, $body, $accessToken);

        // sendMail gibt bei Erfolg 202 (Accepted) ohne Body zurück
        // Bei Fehler enthält die Response einen error-Key
        return !isset($response['error']);
    }

    private function getValidAccessToken(): ?string
    {
        $tokenData = $this->tokenStore->get($this->sourceId);
        if (!$tokenData) {
            return null;
        }

        // Token noch gültig (mit 5 Min Puffer)
        if (($tokenData['expires_at'] ?? 0) > time() + 300) {
            return $tokenData['access_token'];
        }

        // Token erneuern
        if (!empty($tokenData['refresh_token'])) {
            return $this->refreshToken($tokenData['refresh_token']);
        }

        return null;
    }

    private function refreshToken(string $refreshToken): ?string
    {
        $tenant = $this->sourceConfig['tenant_id'] ?: 'common';
        $url = str_replace('{tenant}', $tenant, self::TOKEN_URL);

        $response = $this->httpPost($url, [
            'client_id' => $this->sourceConfig['client_id'],
            'client_secret' => $this->sourceConfig['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => self::SCOPES,
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
        return rtrim($config['app']['url'], '/') . '/admin/auth-microsoft.php';
    }

    private function graphGet(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Prefer: outlook.timezone="Europe/Berlin"',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }

    private function graphPost(string $url, array $data, string $accessToken): array
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
