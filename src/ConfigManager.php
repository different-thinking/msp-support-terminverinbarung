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
     * Speichert einen Konfigurations-Abschnitt.
     */
    public function saveSection(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
        $this->save();
    }

    /**
     * Speichert mehrere Abschnitte gleichzeitig.
     */
    public function saveSections(array $sections): void
    {
        foreach ($sections as $key => $value) {
            $this->config[$key] = $value;
        }
        $this->save();
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
            'admin' => [
                'password_hash' => '',
            ],
        ];
    }
}
