<?php
/**
 * API-Endpoint: Verfügbare Tage und Zeitslots abfragen.
 *
 * GET ?action=days&year=2024&month=1  → Verfügbare Tage im Monat
 * GET ?action=slots&date=2024-01-15   → Verfügbare Slots an einem Tag
 */

require_once __DIR__ . '/../src/SecurityHelper.php';

SecurityHelper::sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

// Nur GET-Requests erlaubt
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Cache-Control: no-store');
    jsonResponse(['error' => 'Nur GET-Anfragen erlaubt'], 405);
}

require_once __DIR__ . '/../src/BookingService.php';

try {
    // Probabilistischer Webhook-Worker-Trigger (~1% der Slot-Requests)
    if (random_int(1, 100) === 1) {
        require_once __DIR__ . '/../src/WebhookQueue.php';
        try { (new WebhookQueue())->processBatch(); } catch (\Throwable $e) { /* nicht stoeren */ }
    }

    $service = new BookingService();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'days':
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));

            if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
                jsonResponse(['error' => 'Ungültige Parameter'], 400);
            }

            $days = $service->getAvailableDays($year, $month);
            // Monats-Uebersicht kann 60 Sekunden gecacht werden (private = nur Browser, kein CDN)
            header('Cache-Control: private, max-age=60');
            jsonResponse(['days' => $days]);
            break;

        case 'slots':
            $date = $_GET['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonResponse(['error' => 'Ungültiges Datumsformat. Erwartet: YYYY-MM-DD'], 400);
            }

            $slots = $service->getAvailableSlots($date);
            // Tages-Slots kuerzer cachen – Verfuegbarkeit aendert sich schneller
            header('Cache-Control: private, max-age=30');
            jsonResponse(['slots' => $slots]);
            break;

        default:
            jsonResponse(['error' => 'Unbekannte Aktion. Verwenden Sie ?action=days oder ?action=slots'], 400);
    }
} catch (\Throwable $e) {
    error_log('Slots API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Interner Serverfehler'], 500);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
