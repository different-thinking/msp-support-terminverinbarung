<?php
/**
 * OAuth2 Callback für Microsoft 365.
 * Empfängt den Authorization Code und tauscht ihn gegen Tokens.
 */
session_start();

require_once __DIR__ . '/../src/BookingService.php';

$config = require __DIR__ . '/../config.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    error_log('Microsoft OAuth error: ' . $error . ' - ' . ($_GET['error_description'] ?? ''));
    header('Location: index.php?msg=error');
    exit;
}

if (empty($code)) {
    header('Location: index.php?msg=error');
    exit;
}

// Source anhand des State-Parameters finden
$service = new BookingService();
$sourceId = $state;

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
