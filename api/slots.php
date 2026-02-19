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
header('Cache-Control: no-cache, no-store');

require_once __DIR__ . '/../src/BookingService.php';

try {
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
            jsonResponse(['days' => $days]);
            break;

        case 'slots':
            $date = $_GET['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonResponse(['error' => 'Ungültiges Datumsformat. Erwartet: YYYY-MM-DD'], 400);
            }

            $slots = $service->getAvailableSlots($date);
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
