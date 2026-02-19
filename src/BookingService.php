<?php

require_once __DIR__ . '/TokenStore.php';
require_once __DIR__ . '/MicrosoftCalendarService.php';
require_once __DIR__ . '/GoogleCalendarService.php';
require_once __DIR__ . '/AvailabilityEngine.php';

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
    private array $calendarServices = [];
    private AvailabilityEngine $availabilityEngine;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
        $this->tokenStore = new TokenStore($this->config['token_store']['path']);

        $this->initCalendarServices();
        $this->availabilityEngine = new AvailabilityEngine($this->config, $this->calendarServices);
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
        return $this->availabilityEngine->getAvailableDays($year, $month);
    }

    /**
     * Gibt verfügbare Zeitslots für ein Datum zurück.
     */
    public function getAvailableSlots(string $date): array
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $dateObj = new \DateTime($date, $tz);
        $slots = $this->availabilityEngine->getAvailableSlots($dateObj);

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

        // Pruefen ob Slot noch verfuegbar (gezielter Check nur fuer diesen Zeitraum)
        if (!$this->availabilityEngine->isSlotAvailable($start, $end)) {
            return ['success' => false, 'message' => 'Dieser Zeitslot ist leider nicht mehr verfügbar.'];
        }

        // Teilnehmer zusammenstellen
        $attendees = [
            [
                'email' => $bookingData['email'],
                'name' => $bookingData['firstname'] . ' ' . $bookingData['lastname'],
            ],
        ];

        if (!empty($bookingData['additional_attendees'])) {
            foreach ($bookingData['additional_attendees'] as $attEmail) {
                $attEmail = trim($attEmail);
                if (filter_var($attEmail, FILTER_VALIDATE_EMAIL)) {
                    $attendees[] = [
                        'email' => $attEmail,
                        'name' => $attEmail,
                    ];
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
            error_log('Graph API Event creation failed: ' . $errorMsg);
            return ['success' => false, 'message' => 'Der Termin konnte nicht erstellt werden. Bitte versuchen Sie es später erneut.'];
        }

        if (isset($event['onlineMeeting']['joinUrl'])) {
            $teamsLink = $event['onlineMeeting']['joinUrl'];
        }

        // Informationsmail an Kalenderinhaber senden
        $this->sendOwnerNotification($bookingTarget, $bookingData, $start, $end, $teamsLink);

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
    public function getBookingTarget(): ?object
    {
        foreach ($this->config['calendar_sources'] as $source) {
            if (!empty($source['is_booking_target'])) {
                $id = $source['id'];
                foreach ($this->calendarServices as $service) {
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
    public function getCalendarServiceBySourceId(string $sourceId): ?object
    {
        foreach ($this->config['calendar_sources'] as $source) {
            if ($source['id'] === $sourceId) {
                if ($source['type'] === 'microsoft') {
                    return new MicrosoftCalendarService($source, $this->tokenStore);
                } elseif ($source['type'] === 'google') {
                    return new GoogleCalendarService($source, $this->tokenStore);
                }
            }
        }
        return null;
    }

    private function initCalendarServices(): void
    {
        foreach ($this->config['calendar_sources'] as $source) {
            if (empty($source['client_id'])) {
                continue; // Nicht konfiguriert
            }

            if ($source['type'] === 'microsoft') {
                $service = new MicrosoftCalendarService($source, $this->tokenStore);
                if ($service->isAuthenticated()) {
                    $this->calendarServices[] = $service;
                }
            } elseif ($source['type'] === 'google') {
                $service = new GoogleCalendarService($source, $this->tokenStore);
                if ($service->isAuthenticated()) {
                    $this->calendarServices[] = $service;
                }
            }
        }
    }

    private function getServiceId($service): string
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
            error_log('Owner notification mail failed: ' . $e->getMessage());
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
