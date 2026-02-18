<?php

require_once __DIR__ . '/TokenStore.php';
require_once __DIR__ . '/MicrosoftCalendarService.php';
require_once __DIR__ . '/GoogleCalendarService.php';
require_once __DIR__ . '/AvailabilityEngine.php';
require_once __DIR__ . '/ICSGenerator.php';
require_once __DIR__ . '/Mailer.php';

/**
 * Haupt-Service für die Terminbuchung.
 * Koordiniert Kalender-Services, Verfügbarkeit, Terminerstellung und E-Mail-Versand.
 */
class BookingService
{
    private array $config;
    private TokenStore $tokenStore;
    private array $calendarServices = [];
    private AvailabilityEngine $availabilityEngine;
    private ICSGenerator $icsGenerator;
    private Mailer $mailer;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
        $this->tokenStore = new TokenStore($this->config['token_store']['path']);
        $this->icsGenerator = new ICSGenerator();
        $this->mailer = new Mailer($this->config['mail']);

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

        // Prüfen ob Slot noch verfügbar
        $slots = $this->availabilityEngine->getAvailableSlots(new \DateTime($bookingData['date'], $tz));
        $slotFound = false;
        foreach ($slots as $slot) {
            if ($slot['start'] === $bookingData['time']) {
                $slotFound = true;
                break;
            }
        }

        if (!$slotFound) {
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

        // Termin im Kalender erstellen (mit Teams-Meeting)
        $event = null;
        $teamsLink = '';
        $bookingTarget = $this->getBookingTarget();

        if ($bookingTarget && $bookingTarget instanceof MicrosoftCalendarService) {
            $event = $bookingTarget->createEvent([
                'subject' => $subject,
                'description' => $description,
                'start' => $start,
                'end' => $end,
                'timezone' => $this->config['app']['timezone'],
                'attendees' => $attendees,
            ], $this->config['teams']['enabled']);

            if ($event && isset($event['onlineMeeting']['joinUrl'])) {
                $teamsLink = $event['onlineMeeting']['joinUrl'];
            }
        }

        // ICS-Einladung erstellen
        $icsParams = [
            'subject' => $subject,
            'start' => $start,
            'end' => $end,
            'description' => strip_tags($description),
            'location' => $teamsLink ? 'Microsoft Teams Meeting' : '',
            'organizer' => $this->config['organizer'],
            'attendees' => $attendees,
        ];
        $icsContent = $this->icsGenerator->generate($icsParams);

        // E-Mail mit Einladung an den Buchenden senden
        $emailBody = $this->buildEmailBody($bookingData, $start, $end, $teamsLink);

        $mailSent = $this->mailer->sendInvitation([
            'to' => [
                'email' => $bookingData['email'],
                'name' => $bookingData['firstname'] . ' ' . $bookingData['lastname'],
            ],
            'subject' => $subject,
            'body_html' => $emailBody,
            'ics' => $icsContent,
        ]);

        // Einladung auch an weitere Teilnehmer senden
        if (!empty($bookingData['additional_attendees'])) {
            foreach ($bookingData['additional_attendees'] as $attEmail) {
                $attEmail = trim($attEmail);
                if (filter_var($attEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->mailer->sendInvitation([
                        'to' => ['email' => $attEmail, 'name' => $attEmail],
                        'subject' => $subject,
                        'body_html' => $emailBody,
                        'ics' => $icsContent,
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Termin erfolgreich gebucht! Sie erhalten eine Einladung per E-Mail.',
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
        // Reflection nutzen um die sourceId zu lesen
        $ref = new \ReflectionProperty($service, 'sourceId');
        $ref->setAccessible(true);
        return $ref->getValue($service);
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

        // Zusatzfelder validieren
        foreach ($this->config['booking_form']['additional_fields'] as $field) {
            if (!empty($field['required']) && empty($data['fields'][$field['name']])) {
                return ['valid' => false, 'error' => "Bitte füllen Sie das Feld \"{$field['label']}\" aus."];
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

    private function buildEmailBody(array $data, \DateTime $start, \DateTime $end, string $teamsLink): string
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px;">';
        $html .= '<h2 style="color: #333;">Terminbestätigung</h2>';
        $html .= '<p>Ihr Termin wurde erfolgreich gebucht.</p>';
        $html .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
        $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; color: #666;">Datum</td>';
        $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;"><b>' . $start->format('d.m.Y') . '</b></td></tr>';
        $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; color: #666;">Uhrzeit</td>';
        $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;"><b>' . $start->format('H:i') . ' – ' . $end->format('H:i') . ' Uhr</b></td></tr>';
        $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; color: #666;">Organisator</td>';
        $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($this->config['organizer']['name']) . '</td></tr>';

        if ($teamsLink) {
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; color: #666;">Meeting</td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">';
            $html .= '<a href="' . htmlspecialchars($teamsLink) . '" style="color: #6264a7; text-decoration: none;">';
            $html .= 'Microsoft Teams Meeting beitreten</a></td></tr>';
        }

        $html .= '</table>';

        if (!empty($data['fields'])) {
            foreach ($this->config['booking_form']['additional_fields'] as $field) {
                if (!empty($data['fields'][$field['name']])) {
                    $html .= '<p><b>' . htmlspecialchars($field['label']) . ':</b> '
                        . htmlspecialchars($data['fields'][$field['name']]) . '</p>';
                }
            }
        }

        $html .= '<p style="color: #999; font-size: 12px; margin-top: 30px;">Diese Einladung wurde automatisch erstellt. '
            . 'Im Anhang finden Sie die Kalenderdatei (.ics), die Sie in Ihren Kalender importieren können.</p>';
        $html .= '</div>';

        return $html;
    }
}
