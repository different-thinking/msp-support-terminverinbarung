<?php

require_once __DIR__ . '/CalendarServiceInterface.php';
require_once __DIR__ . '/CalendarUnavailableException.php';
require_once __DIR__ . '/SecurityHelper.php';

/**
 * Microsoft 365 Kalender-Service über Microsoft Graph API.
 * Handles OAuth2, Free/Busy-Abfragen, Terminerstellung und Teams-Meetings.
 */
class MicrosoftCalendarService implements CalendarServiceInterface
{
    private array $sourceConfig;
    private TokenStore $tokenStore;
    private string $sourceId;
    private string $timezone;

    private const AUTH_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';
    private const SCOPES = 'offline_access Calendars.ReadWrite OnlineMeetings.ReadWrite Mail.Send';

    /** Token-Erneuerung N Sekunden vor Ablauf */
    private const TOKEN_REFRESH_BUFFER_SECONDS = 300;
    /** Max. Kalender-Events pro Abfrage-Seite */
    private const MAX_CALENDAR_EVENTS = 500;
    /** Max. Folgeseiten (@odata.nextLink), die pro Abfrage nachgeladen werden */
    private const MAX_FREEBUSY_PAGES = 20;
    /** HTTP-Timeout fuer API-Calls in Sekunden */
    private const HTTP_TIMEOUT_SECONDS = 30;
    /** showAs-Werte, die einen Zeitraum als belegt markieren */
    private const BUSY_SHOW_AS = ['busy', 'oof', 'tentative'];

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
            $error = curl_error($entry['handle']);
            curl_close($entry['handle']);

            if ($response === false) {
                throw new CalendarUnavailableException($this->sourceId, 'Netzwerkfehler: ' . $error);
            }
            $busySlots = array_merge($busySlots, $this->parseFreeBusyResponse($response));
        }

        return $busySlots;
    }

    /**
     * Bereitet curl-Handles für Free/Busy-Abfragen vor (für parallele Ausführung).
     *
     * @return array [['handle' => resource], ...]
     * @throws CalendarUnavailableException wenn kein gueltiges Access-Token
     *         beschafft werden kann (Fail-Closed statt "alles frei")
     */
    public function prepareFreeBusyCurl(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            throw new CalendarUnavailableException(
                $this->sourceId,
                'M365-Verbindung abgelaufen oder ungueltig – Free/Busy nicht abrufbar'
            );
        }

        $handles = [];
        foreach ($this->sourceConfig['calendars'] as $calendarId) {
            $calPath = ($calendarId === 'primary') ? '' : '/calendars/' . rawurlencode($calendarId);
            $url = self::GRAPH_URL . "/me{$calPath}/calendarView?"
                . http_build_query([
                    // WICHTIG: mit Zeitzonen-Offset (ATOM). Graph interpretiert
                    // Werte ohne Offset als UTC – ein naives "09:00" wuerde in
                    // Europe/Berlin real 11:00 abfragen, wodurch alle Termine
                    // der ersten Stunden des Tages unsichtbar bleiben und die
                    // Webseite bereits vergebene Zeiten als frei anbietet.
                    'startDateTime' => $start->format(\DateTimeInterface::ATOM),
                    'endDateTime' => $end->format(\DateTimeInterface::ATOM),
                    '$select' => 'start,end,showAs,isCancelled',
                    '$top' => self::MAX_CALENDAR_EVENTS,
                ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                    'Prefer: outlook.timezone="' . $this->timezone . '"',
                ],
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            ]);

            $handles[] = ['handle' => $ch];
        }

        return $handles;
    }

    /**
     * Parsed eine Free/Busy-Response zu Busy-Slots.
     *
     * Folgt @odata.nextLink, damit bei vielen Terminen keine Belegungen
     * verloren gehen (eine unvollstaendige Seite wuerde belegte Zeiten
     * faelschlich als frei erscheinen lassen).
     *
     * @throws CalendarUnavailableException bei unlesbarer oder fehlerhafter Response
     */
    public function parseFreeBusyResponse(string $response): array
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new CalendarUnavailableException($this->sourceId, 'Unlesbare Graph-Antwort');
        }

        $busySlots = [];
        $page = 0;

        while (true) {
            $busySlots = array_merge($busySlots, $this->mapBusyEvents($data));

            $nextLink = $data['@odata.nextLink'] ?? '';
            if ($nextLink === '') {
                break;
            }
            if (++$page >= self::MAX_FREEBUSY_PAGES) {
                // Nicht alle Belegungen gelesen – lieber abbrechen als
                // eine lueckenhafte Verfuegbarkeit anzeigen.
                throw new CalendarUnavailableException(
                    $this->sourceId,
                    'Zu viele Kalendereintraege im Zeitraum (Seitenlimit erreicht)'
                );
            }

            $accessToken = $this->getValidAccessToken();
            if (!$accessToken) {
                throw new CalendarUnavailableException($this->sourceId, 'Token beim Nachladen ungueltig');
            }
            // graphGet liefert bereits ein dekodiertes Array – ohne JSON-Umweg weiter
            $data = $this->graphGet($nextLink, $accessToken);
        }

        return $busySlots;
    }

    /**
     * Prueft eine dekodierte Graph-Seite und wandelt ihre Events in Busy-Slots.
     *
     * @throws CalendarUnavailableException bei Fehler-Payload oder fehlender Event-Liste
     */
    private function mapBusyEvents(array $data): array
    {
        if (isset($data['error'])) {
            throw new CalendarUnavailableException(
                $this->sourceId,
                'Graph-Fehler: ' . ($data['error']['message'] ?? 'unbekannt')
            );
        }
        if (!isset($data['value']) || !is_array($data['value'])) {
            throw new CalendarUnavailableException($this->sourceId, 'Graph-Antwort ohne Event-Liste');
        }

        $busySlots = [];
        foreach ($data['value'] as $event) {
            if (!empty($event['isCancelled'])) {
                continue;
            }
            if (!in_array($event['showAs'] ?? 'busy', self::BUSY_SHOW_AS, true)) {
                continue;
            }
            $busySlots[] = $this->toBusySlot($event);
        }

        return $busySlots;
    }

    /**
     * Wandelt ein Graph-Event in einen Busy-Slot um.
     *
     * Fail-Closed: Ist die Zeitangabe unbrauchbar, wird abgebrochen statt den
     * Termin zu ueberspringen – ein uebersprungener Termin wuerde als freie
     * Zeit auf der Webseite erscheinen.
     *
     * @throws CalendarUnavailableException
     */
    private function toBusySlot(array $event): array
    {
        $startRaw = $event['start']['dateTime'] ?? '';
        $endRaw = $event['end']['dateTime'] ?? '';

        if ($startRaw === '' || $endRaw === '') {
            throw new CalendarUnavailableException($this->sourceId, 'Event ohne Start-/Endzeit');
        }

        try {
            return [
                'start' => new \DateTime($startRaw, $this->resolveTimeZone($event['start']['timeZone'] ?? null)),
                'end' => new \DateTime($endRaw, $this->resolveTimeZone($event['end']['timeZone'] ?? null)),
            ];
        } catch (\Exception $e) {
            throw new CalendarUnavailableException(
                $this->sourceId,
                'Unlesbare Event-Zeit: ' . $e->getMessage()
            );
        }
    }

    /**
     * Loest den von Graph gelieferten Zeitzonen-Namen auf.
     * Graph kann auch Windows-Namen ("W. Europe Standard Time") oder
     * "tzone://..." liefern – dann greift die konfigurierte Zeitzone.
     */
    private function resolveTimeZone(?string $name): \DateTimeZone
    {
        foreach ([$name, $this->timezone] as $candidate) {
            if (empty($candidate)) {
                continue;
            }
            try {
                return new \DateTimeZone($candidate);
            } catch (\Exception $e) {
                // naechsten Kandidaten probieren
            }
        }

        return new \DateTimeZone('UTC');
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
                'timeZone' => $eventData['timezone'] ?? $this->timezone,
            ],
            'end' => [
                'dateTime' => $eventData['end']->format('Y-m-d\TH:i:s'),
                'timeZone' => $eventData['timezone'] ?? $this->timezone,
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
        if (($tokenData['expires_at'] ?? 0) > time() + self::TOKEN_REFRESH_BUFFER_SECONDS) {
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
            // set() ersetzt den Eintrag komplett und loescht damit auch ein
            // evtl. gesetztes refresh_failed_at-Flag.
            $this->tokenStore->set($this->sourceId, [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? $refreshToken,
                'expires_at' => time() + ($response['expires_in'] ?? 3600),
                'token_type' => $response['token_type'] ?? 'Bearer',
            ]);
            return $response['access_token'];
        }

        // Nur eine echte Ablehnung durch Microsoft (z.B. invalid_grant) macht
        // die Verbindung tot – ein Netzwerkfehler ist voruebergehend.
        if (!isset($response['_network_error'])) {
            $reason = (string)($response['error_description'] ?? $response['error'] ?? 'unbekannt');
            SecurityHelper::logError('MS Token', 'Refresh abgelehnt: ' . $reason);
            $this->tokenStore->markRefreshFailed($this->sourceId, $reason);
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
                'Prefer: outlook.timezone="' . $this->timezone . '"',
            ],
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            SecurityHelper::logError('Graph API', 'GET error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => ['message' => 'Netzwerkfehler bei Graph-API-Abfrage']];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?: [];
        if ($httpCode >= 400) {
            SecurityHelper::logError('Graph API', "GET HTTP {$httpCode}: " . ($decoded['error']['message'] ?? $response));
        }
        return $decoded;
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
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            SecurityHelper::logError('Graph API', 'POST error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => ['message' => 'Netzwerkfehler bei Graph-API-Anfrage']];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // sendMail gibt 202 ohne Body zurueck
        if ($httpCode >= 200 && $httpCode < 300 && empty($response)) {
            return [];
        }

        $decoded = json_decode($response, true) ?: [];
        if ($httpCode >= 400) {
            SecurityHelper::logError('Graph API', "POST HTTP {$httpCode}: " . ($decoded['error']['message'] ?? $response));
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
            SecurityHelper::logError('MS Token', 'Token endpoint error: ' . curl_error($ch));
            curl_close($ch);
            // Eigener Schluessel: ein Netzwerkfehler ist KEINE Ablehnung durch
            // den Provider und darf die Verbindung nicht als tot markieren.
            return ['_network_error' => 'Netzwerkfehler bei Token-Anfrage'];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
}
