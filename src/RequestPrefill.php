<?php

/**
 * Parst optionale Formular-Vorbefuellung aus Query-Parametern.
 * Werte werden im Template per htmlspecialchars ausgegeben.
 */
class RequestPrefill
{
    /**
     * @param array<string, mixed> $query Typischerweise $_GET
     * @return array{firstname: string, lastname: string, email: string}
     */
    public static function parse(array $query): array
    {
        return [
            'firstname' => self::name($query['firstname'] ?? $query['vorname'] ?? null),
            'lastname'  => self::name($query['lastname']  ?? $query['nachname'] ?? null),
            'email'     => self::email($query['email'] ?? null),
        ];
    }

    private static function name(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        return mb_substr(trim($raw), 0, 100);
    }

    private static function email(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        // Lowercase + trim entsprechen der Normalisierung in BookingService::book().
        $candidate = mb_substr(strtolower(trim($raw)), 0, 254);
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }
}
