<?php
/**
 * Terminbuchung – Embed-Version für iframes
 * Optimiert für die Einbettung in externe Webseiten.
 */
require_once __DIR__ . '/src/SecurityHelper.php';
SecurityHelper::sendSecurityHeaders(allowFrame: true);

$config = require __DIR__ . '/config.php';

/** Wandelt **text** in <strong>text</strong> um (nach htmlspecialchars). */
function formatText(string $text): string {
    $safe = htmlspecialchars($text);
    $safe = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $safe);
    return nl2br($safe);
}

$appName = $config['app']['name'];
$duration = $config['app']['appointment_duration_minutes'];
$additionalFields = $config['booking_form']['additional_fields'];
$allowAttendees = $config['booking_form']['allow_additional_attendees'];
$maxAttendees = $config['booking_form']['max_additional_attendees'];
$organizerName = $config['organizer']['name'];
$bookingInfo = '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        body {
            background: transparent;
            min-height: auto;
        }
        .container {
            max-width: 100%;
            padding: 16px;
            margin: 0;
        }
        .booking-panel {
            box-shadow: none;
            border: 1px solid var(--gray-200);
        }
        /* Höhe an Parent melden */
        html { overflow: hidden; }
    </style>
</head>
<body data-duration="<?= $duration ?>" data-embed="true">

<div class="container">
<?php include __DIR__ . '/templates/booking-form.php'; ?>
</div>

<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script>
// iframe-Höhe automatisch an Parent melden
(function() {
    function sendHeight() {
        var height = document.documentElement.scrollHeight;
        window.parent.postMessage({ type: 'terminbuchung-resize', height: height }, '*');
    }
    // Initial + bei Änderungen
    sendHeight();
    new MutationObserver(sendHeight).observe(document.body, { childList: true, subtree: true, attributes: true });
    window.addEventListener('resize', sendHeight);
    // Regelmäßig prüfen (Fallback)
    setInterval(sendHeight, 500);
})();
</script>
</body>
</html>
