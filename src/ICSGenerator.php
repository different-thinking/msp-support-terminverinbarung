<?php

/**
 * Generiert ICS-Kalendereinladungen (RFC 5545).
 * Erstellt Standard-iCalendar-Dateien mit ORGANIZER und ATTENDEE-Properties.
 */
class ICSGenerator
{
    /**
     * Erstellt eine ICS-Einladung als String.
     */
    public function generate(array $params): string
    {
        $uid = $params['uid'] ?? $this->generateUID();
        $now = gmdate('Ymd\THis\Z');
        $dtStart = $this->formatDateTime($params['start']);
        $dtEnd = $this->formatDateTime($params['end']);

        $summary = $this->escapeICS($params['subject']);
        $description = $this->escapeICS($params['description'] ?? '');
        $location = $this->escapeICS($params['location'] ?? '');

        $organizer = $params['organizer']; // ['name' => ..., 'email' => ...]
        $attendees = $params['attendees']; // [['name' => ..., 'email' => ...], ...]

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Terminbuchung//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $summary,
        ];

        if ($description) {
            $lines[] = 'DESCRIPTION:' . $description;
        }

        if ($location) {
            $lines[] = 'LOCATION:' . $location;
        }

        // Organizer
        $lines[] = 'ORGANIZER;CN=' . $this->escapeICS($organizer['name'])
            . ':mailto:' . $organizer['email'];

        // Attendees
        foreach ($attendees as $attendee) {
            $name = $attendee['name'] ?? $attendee['email'];
            $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION'
                . ';RSVP=TRUE;CN=' . $this->escapeICS($name)
                . ':mailto:' . $attendee['email'];
        }

        // Erinnerung (15 Minuten vorher)
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT15M';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Terminerinnerung';
        $lines[] = 'END:VALARM';

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SEQUENCE:0';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    private function formatDateTime(\DateTimeInterface $dt): string
    {
        $utc = clone $dt;
        if ($utc instanceof \DateTime) {
            $utc->setTimezone(new \DateTimeZone('UTC'));
        }
        return $utc->format('Ymd\THis\Z');
    }

    private function escapeICS(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        return $text;
    }

    private function generateUID(): string
    {
        return bin2hex(random_bytes(16)) . '@terminbuchung';
    }
}
