<?php

/**
 * Zentrale Security-Hilfsfunktionen: CSRF-Schutz, Rate-Limiting, Security-Headers.
 */
class SecurityHelper
{
    // ==================== CSRF ====================

    /**
     * Generiert ein CSRF-Token und speichert es in der Session.
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validiert ein CSRF-Token gegen das in der Session gespeicherte.
     */
    public static function validateCsrfToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // ==================== Rate-Limiting ====================

    /**
     * Dateibasiertes Rate-Limiting pro IP.
     *
     * @param string $action  Identifikator fuer die Aktion (z.B. 'booking')
     * @param int    $maxRequests  Max. Anfragen im Zeitfenster
     * @param int    $windowSeconds  Zeitfenster in Sekunden
     * @return bool true wenn Request erlaubt, false wenn Limit erreicht
     */
    public static function checkRateLimit(string $action, int $maxRequests = 5, int $windowSeconds = 3600): bool
    {
        $rateLimitDir = dirname(__DIR__) . '/data/ratelimit';
        if (!is_dir($rateLimitDir)) {
            mkdir($rateLimitDir, 0700, true);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = md5($action . ':' . $ip);
        $file = $rateLimitDir . '/' . $key . '.json';

        $now = time();
        $requests = [];

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                // Nur Requests innerhalb des Zeitfensters behalten
                $requests = array_filter($data, fn($ts) => $ts > ($now - $windowSeconds));
            }
        }

        if (count($requests) >= $maxRequests) {
            return false;
        }

        $requests[] = $now;
        file_put_contents($file, json_encode(array_values($requests)), LOCK_EX);

        return true;
    }

    /**
     * Bereinigt alte Rate-Limit-Dateien (aelter als 2 Stunden).
     */
    public static function cleanupRateLimitFiles(): void
    {
        $rateLimitDir = dirname(__DIR__) . '/data/ratelimit';
        if (!is_dir($rateLimitDir)) return;

        $cutoff = time() - 7200;
        foreach (glob($rateLimitDir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    // ==================== Security Headers ====================

    /**
     * Setzt Security-Headers fuer alle Responses.
     *
     * @param bool $allowFrame  true fuer embed.php (erlaubt iframe-Einbettung)
     */
    public static function sendSecurityHeaders(bool $allowFrame = false): void
    {
        if (!$allowFrame) {
            header('X-Frame-Options: DENY');
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Prueft ob die Verbindung ueber HTTPS laeuft.
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        return false;
    }
}
