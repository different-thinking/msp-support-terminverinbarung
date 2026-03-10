<?php

/**
 * Verwaltet die Konfiguration der App.
 * Speichert in data/config.json, merged mit Defaults.
 */
class ConfigManager
{
    private string $configPath;
    private array $defaults;
    private array $config;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?: dirname(__DIR__) . '/data/config.json';

        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $this->defaults = $this->getDefaults();
        $this->config = $this->load();
    }

    public function get(): array
    {
        return $this->config;
    }

    /**
     * Gibt einen Konfigurations-Abschnitt zurück.
     */
    public function getSection(string $key): mixed
    {
        return $this->config[$key] ?? $this->defaults[$key] ?? null;
    }

    /**
     * Speichert einen Konfigurations-Abschnitt (mit Typ-Validierung).
     *
     * @throws \InvalidArgumentException bei ungueltigem Typ oder Wert
     */
    public function saveSection(string $key, mixed $value): void
    {
        $this->validateSection($key, $value);
        $this->config[$key] = $value;
        $this->save();
    }

    /**
     * Speichert mehrere Abschnitte gleichzeitig (mit Typ-Validierung).
     *
     * @throws \InvalidArgumentException bei ungueltigem Typ oder Wert
     */
    public function saveSections(array $sections): void
    {
        foreach ($sections as $key => $value) {
            $this->validateSection($key, $value);
            $this->config[$key] = $value;
        }
        $this->save();
    }

    /**
     * Validiert einen Config-Abschnitt (Typ und Wertebereiche).
     *
     * @throws \InvalidArgumentException bei Validierungsfehler
     */
    private function validateSection(string $key, mixed $value): void
    {
        switch ($key) {
            case 'app':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config 'app' muss ein Array sein");
                }
                if (isset($value['timezone']) && !in_array($value['timezone'], \DateTimeZone::listIdentifiers())) {
                    throw new \InvalidArgumentException("Ungueltige Zeitzone: {$value['timezone']}");
                }
                if (isset($value['appointment_duration_minutes']) && (!is_int($value['appointment_duration_minutes']) || $value['appointment_duration_minutes'] < 5)) {
                    throw new \InvalidArgumentException('Termindauer muss mindestens 5 Minuten betragen');
                }
                if (isset($value['slot_interval_minutes']) && (!is_int($value['slot_interval_minutes']) || $value['slot_interval_minutes'] < 5)) {
                    throw new \InvalidArgumentException('Slot-Intervall muss mindestens 5 Minuten betragen');
                }
                if (isset($value['buffer_minutes']) && (!is_int($value['buffer_minutes']) || $value['buffer_minutes'] < 0)) {
                    throw new \InvalidArgumentException('Pufferzeit muss mindestens 0 Minuten betragen');
                }
                if (isset($value['booking_horizon_days']) && (!is_int($value['booking_horizon_days']) || $value['booking_horizon_days'] < 1)) {
                    throw new \InvalidArgumentException('Buchungshorizont muss mindestens 1 Tag sein');
                }
                break;

            case 'working_hours':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config 'working_hours' muss ein Array sein");
                }
                $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                foreach ($value as $day => $hours) {
                    if (!in_array($day, $validDays)) {
                        throw new \InvalidArgumentException("Ungueltiger Wochentag: {$day}");
                    }
                    if ($hours !== null && (!is_array($hours) || !isset($hours['start'], $hours['end']))) {
                        throw new \InvalidArgumentException("Arbeitszeiten fuer {$day} muessen 'start' und 'end' enthalten");
                    }
                }
                break;

            case 'organizer':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config 'organizer' muss ein Array sein");
                }
                if (isset($value['email']) && !empty($value['email']) && !filter_var($value['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Ungueltige Organisator-E-Mail-Adresse');
                }
                break;

            case 'calendar_sources':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config 'calendar_sources' muss ein Array sein");
                }
                foreach ($value as $source) {
                    if (!is_array($source) || empty($source['id']) || empty($source['type'])) {
                        throw new \InvalidArgumentException('Kalender-Quellen benoetigen mindestens id und type');
                    }
                    if (!in_array($source['type'], ['microsoft', 'google'])) {
                        throw new \InvalidArgumentException("Ungueltiger Kalender-Typ: {$source['type']}");
                    }
                }
                break;

            case 'booking_form':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config 'booking_form' muss ein Array sein");
                }
                if (isset($value['max_additional_attendees']) && (!is_int($value['max_additional_attendees']) || $value['max_additional_attendees'] < 0)) {
                    throw new \InvalidArgumentException('Max-Teilnehmer muss >= 0 sein');
                }
                break;

            // break_time, teams, page_design, admin – nur Typ-Check
            default:
                // Kein spezifischer Validator – nur generelle Typpruefung
                break;
        }
    }

    /**
     * Setzt das Admin-Passwort.
     */
    public function setAdminPassword(string $password): void
    {
        $this->config['admin'] = [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ];
        $this->save();
    }

    /**
     * Prüft das Admin-Passwort.
     */
    public function verifyAdminPassword(string $password): bool
    {
        $hash = $this->config['admin']['password_hash'] ?? '';
        if (empty($hash)) {
            return true; // Kein Passwort gesetzt
        }
        return password_verify($password, $hash);
    }

    /**
     * Gibt true zurück wenn ein Admin-Passwort konfiguriert ist.
     */
    public function hasAdminPassword(): bool
    {
        return !empty($this->config['admin']['password_hash']);
    }

    /**
     * Setzt das Kalenderansicht-Passwort.
     */
    public function setCalendarViewPassword(string $password): void
    {
        $calView = $this->config['calendar_view'] ?? [];
        $calView['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $this->config['calendar_view'] = $calView;
        $this->save();
    }

    /**
     * Prüft das Kalenderansicht-Passwort.
     */
    public function verifyCalendarViewPassword(string $password): bool
    {
        $hash = $this->config['calendar_view']['password_hash'] ?? '';
        if (empty($hash)) {
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * Gibt true zurück wenn ein Kalenderansicht-Passwort konfiguriert ist.
     */
    public function hasCalendarViewPassword(): bool
    {
        return !empty($this->config['calendar_view']['password_hash']);
    }

    /**
     * Gibt die komplette Konfiguration als Array für die App zurück.
     * Inkl. berechneter Felder wie token_store path.
     */
    public function getAppConfig(): array
    {
        $config = $this->config;
        // token_store Pfad immer relativ zum Projekt
        $config['token_store'] = [
            'path' => dirname(__DIR__) . '/data/tokens.json',
        ];
        return $config;
    }

    private function load(): array
    {
        if (file_exists($this->configPath)) {
            $data = file_get_contents($this->configPath);
            $stored = json_decode($data, true);
            if (is_array($stored)) {
                return $this->mergeDefaults($this->defaults, $stored);
            }
        }
        return $this->defaults;
    }

    private function save(): void
    {
        file_put_contents(
            $this->configPath,
            json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        chmod($this->configPath, 0600);
    }

    /**
     * Merged gespeicherte Werte über Defaults (gespeichert hat Vorrang).
     */
    private function mergeDefaults(array $defaults, array $stored): array
    {
        $result = $defaults;
        foreach ($stored as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])
                && $this->isAssocArray($value) && $this->isAssocArray($result[$key])) {
                $result[$key] = $this->mergeDefaults($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function isAssocArray(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function getDefaults(): array
    {
        return [
            'app' => [
                'name' => 'Terminbuchung',
                'url' => '',
                'timezone' => 'Europe/Berlin',
                'locale' => 'de',
                'appointment_duration_minutes' => 60,
                'booking_horizon_days' => 30,
                'min_notice_hours' => 24,
                'slot_interval_minutes' => 30,
                'buffer_minutes' => 0,
            ],
            'working_hours' => [
                'monday'    => ['start' => '09:00', 'end' => '17:00'],
                'tuesday'   => ['start' => '09:00', 'end' => '17:00'],
                'wednesday' => ['start' => '09:00', 'end' => '17:00'],
                'thursday'  => ['start' => '09:00', 'end' => '17:00'],
                'friday'    => ['start' => '09:00', 'end' => '16:00'],
                'saturday'  => null,
                'sunday'    => null,
            ],
            'break_time' => [
                'enabled' => false,
                'start' => '12:00',
                'end' => '13:00',
            ],
            'calendar_sources' => [],
            'teams' => [
                'enabled' => true,
                'source_id' => '',
            ],
            'booking_form' => [
                'additional_fields' => [],
                'allow_additional_attendees' => true,
                'max_additional_attendees' => 5,
            ],
            'organizer' => [
                'name' => '',
                'email' => '',
            ],
            'page_design' => [
                'header_image' => '',
                'profile_image' => '',
                'welcome_title' => '',
                'welcome_text' => '',
                'booking_info' => '',
            ],
            'webhook' => [
                'enabled' => false,
                'url' => '',
                'secret' => '',
                'headers' => [],
            ],
            'calendar_view' => [
                'password_hash' => '',
                'show_event_title' => true,
                'visible_calendars' => [],
            ],
            'admin' => [
                'password_hash' => '',
            ],
        ];
    }
}
