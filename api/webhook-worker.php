<?php
/**
 * Optionaler Cron-Endpoint fuer den Webhook-Queue-Worker.
 *
 * Aufruf: GET /api/webhook-worker.php
 *
 * Nur lokal (IP 127.0.0.1) ohne Auth aufrufbar – sonst muss in der
 * Admin-Konfiguration unter 'webhook_worker.token' ein Token gesetzt und
 * als ?token=... uebergeben werden.
 */

require_once __DIR__ . '/../src/SecurityHelper.php';
require_once __DIR__ . '/../src/ConfigManager.php';
require_once __DIR__ . '/../src/WebhookQueue.php';

SecurityHelper::sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cm = new ConfigManager();
$config = $cm->get();
$configuredToken = trim((string)($config['webhook_worker']['token'] ?? ''));
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);

if (!$isLocal) {
    if ($configuredToken === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Kein Worker-Token konfiguriert']);
        exit;
    }
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($providedToken) || !hash_equals($configuredToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ungueltiges Token']);
        exit;
    }
}

$queue = new WebhookQueue();
$stats = $queue->processBatch();
echo json_encode(['success' => true, 'stats' => $stats]);
