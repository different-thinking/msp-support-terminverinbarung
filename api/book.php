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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Nur POST-Anfragen erlaubt'], 405);
}

require_once __DIR__ . '/../src/BookingService.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse(['error' => 'Ungültige JSON-Daten'], 400);
    }

    // CSRF / Rate-Limiting könnten hier ergänzt werden

    $service = new BookingService();
    $result = $service->book($input);

    $status = $result['success'] ? 200 : 422;
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
