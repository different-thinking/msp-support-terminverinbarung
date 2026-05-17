<?php
/**
 * API-Endpoint: Termin buchen.
 *
 * POST mit JSON-Body:
 * {
 *   "date": "2024-01-15",
 *   "time": "10:00",
 *   "firstname": "Max",
 *   "lastname": "Mustermann",
 *   "email": "max@example.com",
 *   "fields": {"company": "Firma GmbH", "phone": "+49...", "message": "..."},
 *   "additional_attendees": ["kollege@example.com"]
 * }
 */

require_once __DIR__ . '/../src/SecurityHelper.php';

SecurityHelper::sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Nur POST-Anfragen erlaubt'], 405);
}

require_once __DIR__ . '/../src/BookingService.php';

try {
    // Gelegentlich alte Rate-Limit-Dateien bereinigen (~1% Wahrscheinlichkeit)
    if (random_int(1, 100) === 1) {
        SecurityHelper::cleanupRateLimitFiles();
    }

    // Rate-Limiting: Max. 5 Buchungen pro IP pro Stunde
    if (!SecurityHelper::checkRateLimit('booking', 5, 3600)) {
        jsonResponse(['error' => 'Zu viele Buchungsanfragen. Bitte versuchen Sie es spaeter erneut.'], 429);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse(['error' => 'Ungültige JSON-Daten'], 400);
    }

    $service = new BookingService();
    $result = $service->book($input);

    $status = $result['success'] ? 200 : 422;

    // Probabilistischer Webhook-Worker-Trigger (~5% der Buchungen)
    if (random_int(1, 20) === 1) {
        require_once __DIR__ . '/../src/WebhookQueue.php';
        try { (new WebhookQueue())->processBatch(); } catch (\Throwable $e) { /* nicht stoeren */ }
    }

    jsonResponse($result, $status);

} catch (\Throwable $e) {
    error_log('Booking API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Interner Serverfehler'], 500);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
