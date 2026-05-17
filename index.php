<?php
/**
 * Terminbuchung – Hauptseite
 */
require_once __DIR__ . '/src/SecurityHelper.php';
require_once __DIR__ . '/src/FunnelManager.php';
SecurityHelper::sendSecurityHeaders();

$config = require __DIR__ . '/config.php';

// Funnel aus URL gegen Allowlist pruefen; nur bekannte und aktive Slugs durchreichen.
$activeFunnelSlug = '';
$rawFunnel = $_GET['funnel'] ?? null;
if (is_string($rawFunnel)) {
    $candidate = FunnelManager::normalizeSlug($rawFunnel);
    if ($candidate !== null && FunnelManager::findActive($config['funnels'] ?? [], $candidate) !== null) {
        $activeFunnelSlug = $candidate;
    }
}

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
$design = $config['page_design'] ?? [];
$headerImage = $design['header_image'] ?? '';
$profileImage = $design['profile_image'] ?? '';
$welcomeTitle = $design['welcome_title'] ?? '';
$welcomeText = $design['welcome_text'] ?? '';
$bookingInfo = $design['booking_info'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> – <?= htmlspecialchars($organizerName) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body data-duration="<?= $duration ?>">

<?php if ($headerImage): ?>
<div class="header-banner">
    <img src="<?= htmlspecialchars($headerImage) ?>" alt="<?= htmlspecialchars($appName) ?>">
</div>
<?php endif; ?>

<div class="container <?= $headerImage ? 'has-banner' : '' ?>">
    <header class="app-header">
        <?php if ($profileImage): ?>
        <div class="profile-section">
            <img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= htmlspecialchars($organizerName) ?>" class="profile-avatar">
        </div>
        <?php endif; ?>
        <h1><?= htmlspecialchars($welcomeTitle ?: $appName) ?></h1>
        <p class="organizer-name"><?= htmlspecialchars($organizerName) ?></p>
        <?php if ($welcomeText): ?>
        <p class="welcome-text"><?= formatText($welcomeText) ?></p>
        <?php else: ?>
        <p class="welcome-text">Buchen Sie einen <?= $duration ?>-Minuten-Termin</p>
        <?php endif; ?>
    </header>

<?php include __DIR__ . '/templates/booking-form.php'; ?>

</div>

<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
