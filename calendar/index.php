<?php
/**
 * Kalenderansicht – Zeigt Termine in einer Monats-/Wochenansicht.
 * Passwortgeschützt über das Admin-Passwort.
 */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../src/ConfigManager.php';
require_once __DIR__ . '/../src/SecurityHelper.php';

SecurityHelper::sendSecurityHeaders();

$cm = new ConfigManager();
$config = $cm->getAppConfig();

// Authentifizierung
$authenticated = false;
$authError = '';

$hasCalViewPw = $cm->hasCalendarViewPassword();
$hasAdminPw = $cm->hasAdminPassword();

if ($hasCalViewPw || $hasAdminPw) {
    if (isset($_SESSION['calendar_auth']) && $_SESSION['calendar_auth'] === true) {
        $authenticated = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $passwordValid = false;
        if ($hasCalViewPw) {
            $passwordValid = $cm->verifyCalendarViewPassword($_POST['password']);
        } else {
            $passwordValid = $cm->verifyAdminPassword($_POST['password']);
        }
        if ($passwordValid) {
            session_regenerate_id(true);
            $_SESSION['calendar_auth'] = true;
            $authenticated = true;
        } else {
            $authError = 'Falsches Passwort.';
        }
    }
} else {
    // Kein Passwort konfiguriert
    $authError = 'Kein Passwort konfiguriert. Bitte zuerst im Admin-Bereich ein Passwort setzen.';
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['calendar_auth']);
    header('Location: index.php');
    exit;
}

$appName = htmlspecialchars($config['app']['name'] ?: 'Terminbuchung');
$timezone = htmlspecialchars($config['app']['timezone'] ?? 'Europe/Berlin');
$csrfToken = SecurityHelper::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalender – <?= $appName ?></title>
    <link rel="stylesheet" href="../assets/css/calendar.css?v=<?= filemtime(__DIR__ . '/../assets/css/calendar.css') ?>">
</head>
<body>

<?php if (!$authenticated): ?>
<div class="login-wrapper">
    <div class="login-box">
        <div class="login-icon">&#128197;</div>
        <h1>Kalenderansicht</h1>
        <p>Bitte melden Sie sich an, um die Termine einzusehen.</p>
        <?php if ($authError): ?>
            <div class="alert alert-error"><?= htmlspecialchars($authError) ?></div>
        <?php endif; ?>
        <?php if ($hasCalViewPw || $hasAdminPw): ?>
        <form method="POST">
            <div class="form-group">
                <input type="password" name="password" placeholder="Passwort eingeben" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Anmelden</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="cal-app">
    <!-- Sidebar -->
    <aside class="cal-sidebar">
        <div class="cal-sidebar-header">
            <h2>&#128197; Kalender</h2>
            <a href="?logout" class="btn-logout" title="Abmelden">&#x2716;</a>
        </div>

        <!-- Mini-Kalender -->
        <div class="mini-calendar" id="miniCalendar"></div>

        <!-- Kalender-Auswahl -->
        <div class="cal-sources" id="calSources">
            <h3>Kalender</h3>
            <div class="cal-sources-list" id="calSourcesList">
                <div class="cal-loading">Lade Kalender...</div>
            </div>
        </div>
    </aside>

    <!-- Hauptbereich -->
    <main class="cal-main">
        <div class="cal-toolbar">
            <div class="cal-toolbar-left">
                <button class="btn btn-hamburger" id="btnHamburger" type="button">&#9776;</button>
                <button class="btn btn-today" id="btnToday">Heute</button>
                <button class="btn btn-nav" id="btnPrev">&#9664;</button>
                <button class="btn btn-nav" id="btnNext">&#9654;</button>
                <h1 class="cal-title" id="calTitle"></h1>
            </div>
            <div class="cal-toolbar-right">
                <div class="cal-view-switcher">
                    <button class="btn btn-view btn-view-mobile" data-view="day">Tag</button>
                    <button class="btn btn-view btn-view-mobile" data-view="3day">3 Tage</button>
                    <button class="btn btn-view active" data-view="week">Woche</button>
                    <button class="btn btn-view" data-view="month">Monat</button>
                </div>
            </div>
        </div>

        <!-- Monatsansicht -->
        <div class="cal-month-view hidden" id="monthView">
            <div class="cal-month-header">
                <div class="cal-month-weekday">Mo</div>
                <div class="cal-month-weekday">Di</div>
                <div class="cal-month-weekday">Mi</div>
                <div class="cal-month-weekday">Do</div>
                <div class="cal-month-weekday">Fr</div>
                <div class="cal-month-weekday cal-weekend">Sa</div>
                <div class="cal-month-weekday cal-weekend">So</div>
            </div>
            <div class="cal-month-grid" id="monthGrid"></div>
        </div>

        <!-- Wochenansicht -->
        <div class="cal-week-view" id="weekView">
            <div class="cal-week-header" id="weekHeader"></div>
            <div class="cal-week-body">
                <div class="cal-week-times" id="weekTimes"></div>
                <div class="cal-week-columns" id="weekColumns"></div>
            </div>
        </div>
    </main>
</div>


<!-- Event-Detail-Popup -->
<div class="cal-popup-overlay hidden" id="eventPopupOverlay">
    <div class="cal-popup" id="eventPopup">
        <div class="cal-popup-header">
            <h3 id="popupTitle"></h3>
            <button class="btn-close" id="popupClose">&#x2716;</button>
        </div>
        <div class="cal-popup-body">
            <div class="popup-row" id="popupTime"></div>
            <div class="popup-row" id="popupLocation"></div>
            <div class="popup-row" id="popupStatus"></div>
            <div class="popup-row" id="popupCalendar"></div>
        </div>
    </div>
</div>

<?php $calViewConfig = $cm->getSection('calendar_view') ?: []; ?>
<script>
    window.CAL_CONFIG = {
        timezone: '<?= $timezone ?>',
        apiBase: 'api.php',
        showEventTitle: <?= !empty($calViewConfig['show_event_title'] ?? true) ? 'true' : 'false' ?>
    };
</script>
<script src="../assets/js/calendar.js?v=<?= filemtime(__DIR__ . '/../assets/js/calendar.js') ?>"></script>
<?php endif; ?>
</body>
</html>
