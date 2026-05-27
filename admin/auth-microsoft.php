<?php
/**
 * OAuth2 Callback für Microsoft 365.
 * Empfängt den Authorization Code und tauscht ihn gegen Tokens.
 */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../src/BookingService.php';
require_once __DIR__ . '/../src/SecurityHelper.php';

SecurityHelper::sendSecurityHeaders();

$config = require __DIR__ . '/../config.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    error_log('Microsoft OAuth error: ' . $error . ' - ' . ($_GET['error_description'] ?? ''));
    header('Location: index.php?msg=error');
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: index.php?msg=error');
    exit;
}

// State validieren: muss in Session vorhanden und maximal 10 Minuten alt sein
$sessionKey = 'oauth_state_' . $state;
$timeKey = 'oauth_state_time_' . $state;

if (empty($_SESSION[$sessionKey])) {
    error_log('Microsoft OAuth: Invalid state parameter');
    header('Location: index.php?msg=error');
    exit;
}

$stateAge = time() - ($_SESSION[$timeKey] ?? 0);
if ($stateAge > 600) {
    unset($_SESSION[$sessionKey], $_SESSION[$timeKey]);
    error_log('Microsoft OAuth: State parameter expired');
    header('Location: index.php?msg=error');
    exit;
}

// Source-ID aus validiertem State lesen und State-Eintraege bereinigen
$sourceId = $_SESSION[$sessionKey];
unset($_SESSION[$sessionKey], $_SESSION[$timeKey]);

// Source anhand der ID finden
$service = new BookingService();
$calService = $service->getCalendarServiceBySourceId($sourceId);

if (!$calService) {
    error_log('Microsoft OAuth: Unknown source ID: ' . $sourceId);
    header('Location: index.php?msg=error');
    exit;
}

$success = $calService->handleCallback($code);

if ($success) {
    header('Location: index.php?msg=connected');
} else {
    header('Location: index.php?msg=error');
}
exit;
