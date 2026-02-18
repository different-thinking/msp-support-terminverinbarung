<?php
/**
 * Terminbuchungs-App Konfiguration
 *
 * Alle Einstellungen für Kalender, OAuth, Formularfelder und E-Mail.
 */

return [
    // Allgemeine Einstellungen
    'app' => [
        'name' => 'Terminbuchung',
        'url' => 'https://example.com', // Basis-URL der App
        'timezone' => 'Europe/Berlin',
        'locale' => 'de',
        'appointment_duration_minutes' => 60,
        'booking_horizon_days' => 30, // Wie weit in die Zukunft buchbar
        'min_notice_hours' => 24, // Mindestvorlaufzeit in Stunden
        'slot_interval_minutes' => 30, // Intervall für Zeitslots
    ],

    // Arbeitszeiten (wann Termine buchbar sind)
    'working_hours' => [
        'monday'    => ['start' => '09:00', 'end' => '17:00'],
        'tuesday'   => ['start' => '09:00', 'end' => '17:00'],
        'wednesday' => ['start' => '09:00', 'end' => '17:00'],
        'thursday'  => ['start' => '09:00', 'end' => '17:00'],
        'friday'    => ['start' => '09:00', 'end' => '16:00'],
        'saturday'  => null, // nicht verfügbar
        'sunday'    => null,
    ],

    // Kalender-Quellen zur Verfügbarkeitsermittlung
    // Jeder Eintrag ist ein Kalender-Account mit einem oder mehreren Kalendern
    'calendar_sources' => [
        [
            'type' => 'microsoft', // 'google' oder 'microsoft'
            'id' => 'ms-primary',  // Eindeutige ID für diesen Account
            'label' => 'Microsoft 365 Hauptkonto',
            'client_id' => '',
            'client_secret' => '',
            'tenant_id' => 'common', // 'common' oder spezifische Tenant-ID
            'redirect_uri' => '', // wird automatisch gesetzt wenn leer
            'calendars' => ['primary'], // Kalender-IDs oder ['primary'] für Hauptkalender
            'is_booking_target' => true, // In diesen Kalender wird der Termin eingetragen
        ],
        // Beispiel: Weiterer Google-Kalender
        // [
        //     'type' => 'google',
        //     'id' => 'google-1',
        //     'label' => 'Google Kalender',
        //     'client_id' => '',
        //     'client_secret' => '',
        //     'redirect_uri' => '',
        //     'calendars' => ['primary', 'other-calendar-id@group.calendar.google.com'],
        //     'is_booking_target' => false,
        // ],
    ],

    // MS Teams Konferenz-Einstellungen
    'teams' => [
        'enabled' => true,
        // Der Account (calendar_sources ID) der für Teams-Meetings genutzt wird
        'source_id' => 'ms-primary',
    ],

    // Formularfelder für den Buchenden
    // Standardfelder: email, firstname, lastname sind immer enthalten
    'booking_form' => [
        'additional_fields' => [
            [
                'name' => 'company',
                'label' => 'Firma',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'Ihre Firma',
            ],
            [
                'name' => 'phone',
                'label' => 'Telefonnummer',
                'type' => 'tel',
                'required' => false,
                'placeholder' => '+49 ...',
            ],
            [
                'name' => 'message',
                'label' => 'Nachricht / Thema',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => 'Worum geht es bei dem Termin?',
            ],
        ],
        'allow_additional_attendees' => true,
        'max_additional_attendees' => 5,
    ],

    // Organizer-Informationen (erscheinen in der Einladung)
    'organizer' => [
        'name' => 'Max Mustermann',
        'email' => 'max@example.com',
    ],

    // E-Mail-Einstellungen
    'mail' => [
        'method' => 'smtp', // 'smtp' oder 'mail' (PHP mail())
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls', // 'tls', 'ssl', oder ''
            'username' => '',
            'password' => '',
        ],
        'from_name' => 'Terminbuchung',
        'from_email' => 'noreply@example.com',
    ],

    // Token-Speicher
    'token_store' => [
        'path' => __DIR__ . '/data/tokens.json',
    ],

    // Admin-Zugang
    'admin' => [
        'password_hash' => '', // password_hash('dein-passwort', PASSWORD_DEFAULT)
    ],
];
