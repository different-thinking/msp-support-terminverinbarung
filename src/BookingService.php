<?php

require_once __DIR__ . '/TokenStore.php';
require_once __DIR__ . '/CalendarServiceInterface.php';
require_once __DIR__ . '/CalendarUnavailableException.php';
require_once __DIR__ . '/SecurityHelper.php';
require_once __DIR__ . '/MicrosoftCalendarService.php';
require_once __DIR__ . '/GoogleCalendarService.php';
require_once __DIR__ . '/AvailabilityEngine.php';
require_once __DIR__ . '/FunnelManager.php';
require_once __DIR__ . '/WebhookQueue.php';

/**
 * Haupt-Service für die Terminbuchung.
 * Koordiniert Kalender-Services, Verfügbarkeit und Terminerstellung.
 *
 * Die Einladungen werden direkt über den konfigurierten M365-Account versendet –
 * Microsoft Graph sendet beim Erstellen eines Events automatisch Outlook-Einladungen
 * an alle Attendees. Kein separater SMTP-Versand nötig.
 */
class BookingService
{
    private array $config;
    private TokenStore $tokenStore;
    private ?array $calendarServices = null;
    private ?AvailabilityEngine $availabilityEngine = null;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
        $this->tokenStore = new TokenStore($this->config['token_store']['path']);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getTokenStore(): TokenStore
    {
        return $this->tokenStore;
    }

    /**
     * Gibt verfügbare Tage für einen Monat zurück.
     */
    public function getAvailableDays(int $year, int $month): array
    {
        return $this->getAvailabilityEngine()->getAvailableDays($year, $month);
    }

    /**
     * Gibt verfügbare Zeitslots für ein Datum zurück.
     */
    public function getAvailableSlots(string $date): array
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $dateObj = new \DateTime($date, $tz);
        $slots = $this->getAvailabilityEngine()->getAvailableSlots($dateObj);

        return array_map(function ($slot) {
            return [
                'start' => $slot['start'],
                'end' => $slot['end'],
            ];
        }, $slots);
    }

    /**
     * Bucht einen Termin.
     * @param array $bookingData ['date', 'time', 'firstname', 'lastname', 'email', 'additional_attendees', 'fields']
     * @return array ['success' => bool, 'message' => string, 'event' => ?array]
     */
    public function book(array $bookingData): array
    {
        // Validierung
        $validation = $this->validateBooking($bookingData);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['error']];
        }

        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $start = new \DateTime($bookingData['date'] . ' ' . $bookingData['time'], $tz);
        $end = clone $start;
        $end->modify('+' . $this->config['app']['appointment_duration_minutes'] . ' minutes');

        // Pruefen ob Slot noch verfuegbar (gezielter Check nur fuer diesen Zeitraum).
        // Ist der Kalender nicht abrufbar, wird NICHT gebucht – sonst koennte
        // ein in Outlook bereits vergebener Termin doppelt belegt werden.
        try {
            $slotFree = $this->getAvailabilityEngine()->isSlotAvailable($start, $end);
        } catch (CalendarUnavailableException $e) {
            SecurityHelper::logError('Booking', 'Slot-Pruefung fehlgeschlagen: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Der Kalender ist derzeit nicht erreichbar. Bitte versuchen Sie es in Kürze erneut.',
            ];
        }

        if (!$slotFree) {
            return ['success' => false, 'message' => 'Dieser Zeitslot ist leider nicht mehr verfügbar.'];
        }

        // Teilnehmer zusammenstellen (Duplikate per E-Mail verhindern)
        $primaryEmail = strtolower(trim($bookingData['email']));
        $attendees = [
            [
                'email' => $primaryEmail,
                'name' => $bookingData['firstname'] . ' ' . $bookingData['lastname'],
            ],
        ];
        $seenEmails = [$primaryEmail];

        if (!empty($bookingData['additional_attendees'])) {
            foreach ($bookingData['additional_attendees'] as $attEmail) {
                $attEmail = strtolower(trim($attEmail));
                if (filter_var($attEmail, FILTER_VALIDATE_EMAIL) && !in_array($attEmail, $seenEmails)) {
                    $attendees[] = [
                        'email' => $attEmail,
                        'name' => $attEmail,
                    ];
                    $seenEmails[] = $attEmail;
                }
            }
        }

        // Betreff erstellen
        $subject = sprintf(
            'Termin: %s %s – %s',
            $bookingData['firstname'],
            $bookingData['lastname'],
            $this->config['organizer']['name']
        );

        // Beschreibung erstellen
        $description = $this->buildDescription($bookingData);

        // Termin über den M365-Account erstellen (mit Teams-Meeting).
        // Graph API versendet dabei automatisch Outlook-Einladungen an alle
        // Attendees – identisch zu manuell in Outlook erstellten Terminen.
        $event = null;
        $teamsLink = '';
        $bookingTarget = $this->getBookingTarget();

        if (!$bookingTarget || !($bookingTarget instanceof MicrosoftCalendarService)) {
            return ['success' => false, 'message' => 'Kein M365-Kalender als Buchungsziel konfiguriert.'];
        }

        $event = $bookingTarget->createEvent([
            'subject' => $subject,
            'description' => $description,
            'start' => $start,
            'end' => $end,
            'timezone' => $this->config['app']['timezone'],
            'attendees' => $attendees,
        ], $this->config['teams']['enabled']);

        if (!$event || isset($event['error'])) {
            $errorMsg = $event['error']['message'] ?? 'Unbekannter Fehler bei der Terminerstellung.';
            SecurityHelper::logError('Booking', 'Event creation failed: ' . $errorMsg);
            return ['success' => false, 'message' => 'Der Termin konnte nicht erstellt werden. Bitte versuchen Sie es später erneut.'];
        }

        if (isset($event['onlineMeeting']['joinUrl'])) {
            $teamsLink = $event['onlineMeeting']['joinUrl'];
        }

        // Informationsmail an Kalenderinhaber senden
        $this->sendOwnerNotification($bookingTarget, $bookingData, $start, $end, $teamsLink);

        // Webhook: bei Funnel-Match nur den Funnel-Webhook (sync mit Retry),
        // sonst den globalen Webhook (legacy).
        if (!$this->dispatchFunnelWebhook($bookingData, $start, $end, $teamsLink)) {
            $this->fireWebhook($bookingData, $start, $end, $teamsLink);
        }

        return [
            'success' => true,
            'message' => 'Termin erfolgreich gebucht! Sie erhalten eine Einladung per E-Mail von ' . $this->config['organizer']['name'] . '.',
            'event' => [
                'date' => $start->format('d.m.Y'),
                'time' => $start->format('H:i') . ' – ' . $end->format('H:i'),
                'teams_link' => $teamsLink,
            ],
        ];
    }

    /**
     * Gibt den Kalender-Service zurück, der Booking-Ziel ist.
     */
    public function getBookingTarget(): ?CalendarServiceInterface
    {
        foreach ($this->config['calendar_sources'] as $source) {
            if (!empty($source['is_booking_target'])) {
                $id = $source['id'];
                foreach ($this->getCalendarServices() as $service) {
                    if ($this->getServiceId($service) === $id) {
                        return $service;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Gibt einen Kalender-Service nach Source-ID zurück.
     */
    public function getCalendarServiceBySourceId(string $sourceId): ?CalendarServiceInterface
    {
        $timezone = $this->config['app']['timezone'] ?? 'Europe/Berlin';

        foreach ($this->config['calendar_sources'] as $source) {
            if ($source['id'] === $sourceId) {
                if ($source['type'] === 'microsoft') {
                    return new MicrosoftCalendarService($source, $this->tokenStore, $timezone);
                } elseif ($source['type'] === 'google') {
                    return new GoogleCalendarService($source, $this->tokenStore, $timezone);
                }
            }
        }
        return null;
    }

    /**
     * Gibt die Calendar-Services zurueck (Lazy-Init: erst bei Bedarf erstellt).
     */
    private function getCalendarServices(): array
    {
        if ($this->calendarServices === null) {
            $this->initCalendarServices();
        }
        return $this->calendarServices;
    }

    /**
     * Gibt die AvailabilityEngine zurueck (Lazy-Init: erst bei Bedarf erstellt).
     */
    private function getAvailabilityEngine(): AvailabilityEngine
    {
        if ($this->availabilityEngine === null) {
            $this->availabilityEngine = new AvailabilityEngine($this->config, $this->getCalendarServices());
        }
        return $this->availabilityEngine;
    }

    private function initCalendarServices(): void
    {
        $this->calendarServices = [];
        $timezone = $this->config['app']['timezone'] ?? 'Europe/Berlin';

        foreach ($this->config['calendar_sources'] as $source) {
            if (empty($source['client_id'])) {
                continue; // Nicht konfiguriert
            }

            if ($source['type'] === 'microsoft') {
                $service = new MicrosoftCalendarService($source, $this->tokenStore, $timezone);
                if ($service->isAuthenticated()) {
                    $this->calendarServices[] = $service;
                }
            } elseif ($source['type'] === 'google') {
                $service = new GoogleCalendarService($source, $this->tokenStore, $timezone);
                if ($service->isAuthenticated()) {
                    $this->calendarServices[] = $service;
                }
            }
        }
    }

    private function getServiceId(CalendarServiceInterface $service): string
    {
        return $service->getSourceId();
    }

    private function validateBooking(array $data): array
    {
        if (empty($data['date'])) {
            return ['valid' => false, 'error' => 'Bitte wählen Sie ein Datum.'];
        }
        if (empty($data['time'])) {
            return ['valid' => false, 'error' => 'Bitte wählen Sie eine Uhrzeit.'];
        }
        if (empty($data['firstname'])) {
            return ['valid' => false, 'error' => 'Bitte geben Sie Ihren Vornamen ein.'];
        }
        if (empty($data['lastname'])) {
            return ['valid' => false, 'error' => 'Bitte geben Sie Ihren Nachnamen ein.'];
        }
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'];
        }

        // Laengenbegrenzung fuer Standardfelder
        if (mb_strlen($data['firstname']) > 100) {
            return ['valid' => false, 'error' => 'Vorname darf maximal 100 Zeichen lang sein.'];
        }
        if (mb_strlen($data['lastname']) > 100) {
            return ['valid' => false, 'error' => 'Nachname darf maximal 100 Zeichen lang sein.'];
        }
        if (mb_strlen($data['email']) > 254) {
            return ['valid' => false, 'error' => 'E-Mail-Adresse darf maximal 254 Zeichen lang sein.'];
        }

        // Zusatzfelder validieren (Pflicht + Laenge)
        foreach ($this->config['booking_form']['additional_fields'] as $field) {
            $fieldValue = $data['fields'][$field['name']] ?? '';
            if (!empty($field['required']) && empty($fieldValue)) {
                return ['valid' => false, 'error' => "Bitte füllen Sie das Feld \"{$field['label']}\" aus."];
            }
            if (!empty($fieldValue)) {
                // Textarea-Felder: 5000 Zeichen (z.B. Ausgangssituation), sonstige: 500
                $maxLen = ($field['type'] === 'textarea') ? 5000 : 500;
                if (mb_strlen($fieldValue) > $maxLen) {
                    return ['valid' => false, 'error' => "Das Feld \"{$field['label']}\" darf maximal {$maxLen} Zeichen lang sein."];
                }
            }
        }

        // Datum-Format prüfen
        $dateObj = \DateTime::createFromFormat('Y-m-d', $data['date']);
        if (!$dateObj) {
            return ['valid' => false, 'error' => 'Ungültiges Datumsformat.'];
        }

        // Zeit-Format prüfen
        if (!preg_match('/^\d{2}:\d{2}$/', $data['time'])) {
            return ['valid' => false, 'error' => 'Ungültiges Zeitformat.'];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Sendet eine Informationsmail an den Kalenderinhaber über die neue Buchung.
     */
    private function sendOwnerNotification(
        MicrosoftCalendarService $service,
        array $bookingData,
        \DateTime $start,
        \DateTime $end,
        string $teamsLink
    ): void {
        $organizerEmail = $this->config['organizer']['email'] ?? '';
        if (empty($organizerEmail)) {
            return;
        }

        $subject = sprintf(
            'Neue Buchung: %s %s am %s um %s',
            $bookingData['firstname'],
            $bookingData['lastname'],
            $start->format('d.m.Y'),
            $start->format('H:i')
        );

        $bodyHtml = $this->buildOwnerMailHtml($bookingData, $start, $end, $teamsLink);

        try {
            $service->sendMail([
                'to' => [
                    [
                        'email' => $organizerEmail,
                        'name' => $this->config['organizer']['name'] ?? '',
                    ],
                ],
                'subject' => $subject,
                'body_html' => $bodyHtml,
            ]);
        } catch (\Exception $e) {
            // Mail-Fehler nicht an den Buchenden weitergeben,
            // der Termin wurde bereits erfolgreich erstellt
            SecurityHelper::logError('Booking', 'Owner notification mail failed', $e);
        }
    }

    /**
     * Baut den HTML-Body für die Benachrichtigungsmail an den Kalenderinhaber.
     */
    private function buildOwnerMailHtml(
        array $data,
        \DateTime $start,
        \DateTime $end,
        string $teamsLink
    ): string {
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px">';
        $html .= '<h2 style="color:#1a73e8">Neue Terminbuchung</h2>';
        $html .= '<table style="border-collapse:collapse;width:100%">';

        $rows = [
            'Datum' => $start->format('d.m.Y'),
            'Uhrzeit' => $start->format('H:i') . ' – ' . $end->format('H:i'),
            'Vorname' => htmlspecialchars($data['firstname']),
            'Nachname' => htmlspecialchars($data['lastname']),
            'E-Mail' => htmlspecialchars($data['email']),
        ];

        // Zusatzfelder hinzufügen
        if (!empty($data['fields'])) {
            foreach ($this->config['booking_form']['additional_fields'] as $field) {
                if (!empty($data['fields'][$field['name']])) {
                    $rows[htmlspecialchars($field['label'])] = htmlspecialchars($data['fields'][$field['name']]);
                }
            }
        }

        // Weitere Teilnehmer
        if (!empty($data['additional_attendees'])) {
            $rows['Weitere Teilnehmer'] = htmlspecialchars(implode(', ', $data['additional_attendees']));
        }

        $i = 0;
        foreach ($rows as $label => $value) {
            $bg = ($i++ % 2 === 0) ? '#f8f9fa' : '#ffffff';
            $html .= '<tr style="background:' . $bg . '">';
            $html .= '<td style="padding:8px 12px;font-weight:600;border-bottom:1px solid #eee">' . $label . '</td>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee">' . $value . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        if (!empty($teamsLink)) {
            $html .= '<p style="margin-top:16px"><a href="' . htmlspecialchars($teamsLink)
                . '" style="background:#6264a7;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block">'
                . 'Teams-Meeting beitreten</a></p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Wenn die Buchung einem aktiven Funnel zugeordnet ist, wird der
     * Funnel-Webhook synchron versucht – schlaegt der Aufruf fehl,
     * wandert der Job in die Retry-Queue.
     *
     * @return bool true wenn ein Funnel zugeordnet wurde (egal ob HTTP-Erfolg
     *              oder Queue-Fallback); false wenn kein Funnel matched
     *              (Caller faellt dann auf den globalen Webhook zurueck).
     */
    private function dispatchFunnelWebhook(
        array $bookingData,
        \DateTime $start,
        \DateTime $end,
        string $teamsLink
    ): bool {
        $rawSlug = $bookingData['funnel'] ?? '';
        $slug = FunnelManager::normalizeSlug(is_string($rawSlug) ? $rawSlug : '');
        if ($slug === null) return false;

        $funnel = FunnelManager::findActive($this->config['funnels'] ?? [], $slug);
        if ($funnel === null) return false;

        $payload = $this->buildWebhookPayload($bookingData, $start, $end, $teamsLink);
        $payload['event'] = 'booking.created';
        $payload['funnel'] = [
            'slug' => $funnel['slug'],
            'name' => $funnel['name'],
        ];

        try {
            $result = (new WebhookQueue())->dispatch($funnel, $payload);
            if (!empty($result['enqueued'])) {
                SecurityHelper::logError(
                    'FunnelWebhook',
                    "Direct dispatch failed (slug={$funnel['slug']}, http={$result['status_code']}, err={$result['error']}) – queued for retry"
                );
            }
        } catch (\Throwable $e) {
            SecurityHelper::logError('FunnelWebhook', 'Dispatch failed', $e);
        }

        return true;
    }

    /**
     * Baut das Webhook-Payload aus den Buchungsdaten.
     */
    public function buildWebhookPayload(
        array $bookingData,
        \DateTime $start,
        \DateTime $end,
        string $teamsLink
    ): array {
        $rawFields = $bookingData['fields'] ?? [];

        $data = [
            'date' => $start->format('Y-m-d'),
            'date_formatted' => $start->format('d.m.Y'),
            'time_start' => $start->format('H:i'),
            'time_end' => $end->format('H:i'),
            'timezone' => $this->config['app']['timezone'],
            'firstname' => $bookingData['firstname'],
            'lastname' => $bookingData['lastname'],
            'email' => $bookingData['email'],
        ];

        // Custom Fields flach auf gleicher Ebene einfügen (Feldname als Key)
        foreach ($this->config['booking_form']['additional_fields'] ?? [] as $fieldDef) {
            $name = $fieldDef['name'] ?? '';
            if ($name !== '' && isset($rawFields[$name]) && $rawFields[$name] !== '') {
                $data[$name] = $rawFields[$name];
            }
        }

        $data['additional_attendees'] = $bookingData['additional_attendees'] ?? [];
        $data['teams_link'] = $teamsLink;
        $data['organizer'] = [
            'name' => $this->config['organizer']['name'] ?? '',
            'email' => $this->config['organizer']['email'] ?? '',
        ];

        $payload = [
            'event' => 'booking.created',
            'timestamp' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('c'),
            'data' => $data,
        ];

        return $payload;
    }

    /**
     * Sendet einen Webhook-Request.
     * @return array ['success' => bool, 'status_code' => int, 'response' => string, 'error' => string]
     */
    public function sendWebhookRequest(string $url, array $payload, string $secret = '', array $customHeaders = []): array
    {
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: MSP-Terminbuchung-Webhook/1.0',
        ];

        if (!empty($secret)) {
            $signature = hash_hmac('sha256', $jsonBody, $secret);
            $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
        }

        foreach ($customHeaders as $header) {
            $name = trim($header['name'] ?? '');
            $value = trim($header['value'] ?? '');
            if (!empty($name) && !empty($value)) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!empty($error)) {
            return ['success' => false, 'status_code' => 0, 'response' => '', 'error' => $error];
        }

        return [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'response' => mb_substr($response, 0, 1000),
            'error' => '',
        ];
    }

    /**
     * Feuert den konfigurierten Webhook nach einer erfolgreichen Buchung.
     */
    private function fireWebhook(
        array $bookingData,
        \DateTime $start,
        \DateTime $end,
        string $teamsLink
    ): void {
        $webhookConfig = $this->config['webhook'] ?? [];
        if (empty($webhookConfig['enabled']) || empty($webhookConfig['url'])) {
            return;
        }

        $payload = $this->buildWebhookPayload($bookingData, $start, $end, $teamsLink);

        try {
            $result = $this->sendWebhookRequest(
                $webhookConfig['url'],
                $payload,
                $webhookConfig['secret'] ?? '',
                $webhookConfig['headers'] ?? []
            );

            if (!$result['success']) {
                error_log('Webhook failed: HTTP ' . $result['status_code'] . ' – ' . ($result['error'] ?: $result['response']));
            }
        } catch (\Exception $e) {
            error_log('Webhook error: ' . $e->getMessage());
        }
    }

    private function buildDescription(array $data): string
    {
        $lines = [
            '<b>Teilnehmer:</b> ' . htmlspecialchars($data['firstname'] . ' ' . $data['lastname']),
            '<b>E-Mail:</b> ' . htmlspecialchars($data['email']),
        ];

        if (!empty($data['fields'])) {
            foreach ($this->config['booking_form']['additional_fields'] as $field) {
                if (!empty($data['fields'][$field['name']])) {
                    $lines[] = '<b>' . htmlspecialchars($field['label']) . ':</b> '
                        . htmlspecialchars($data['fields'][$field['name']]);
                }
            }
        }

        if (!empty($data['additional_attendees'])) {
            $lines[] = '<b>Weitere Teilnehmer:</b> '
                . htmlspecialchars(implode(', ', $data['additional_attendees']));
        }

        return implode('<br>', $lines);
    }

}
