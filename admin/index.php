<?php
/**
 * Admin-Panel: Kalender-Verbindungen verwalten.
 * Hier werden OAuth-Tokens für die Kalender-Quellen eingerichtet.
 */
session_start();

require_once __DIR__ . '/../src/BookingService.php';

$config = require __DIR__ . '/../config.php';
$service = new BookingService();
$tokenStore = $service->getTokenStore();

// Einfache Passwort-Authentifizierung
$authenticated = false;
$authError = '';

if (!empty($config['admin']['password_hash'])) {
    if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
        $authenticated = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify($_POST['password'], $config['admin']['password_hash'])) {
            $_SESSION['admin_auth'] = true;
            $authenticated = true;
        } else {
            $authError = 'Falsches Passwort.';
        }
    }
} else {
    // Kein Passwort konfiguriert - Warnung anzeigen
    $authenticated = true;
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_auth']);
    header('Location: index.php');
    exit;
}

// Disconnect
if (isset($_GET['disconnect']) && $authenticated) {
    $sourceId = $_GET['disconnect'];
    $tokenStore->remove($sourceId);
    header('Location: index.php?msg=disconnected');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Terminbuchung</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 20px;
        }
        .admin-card h3 {
            font-size: 18px;
            margin-bottom: 4px;
        }
        .admin-card .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .type-badge.microsoft { background: #dbeafe; color: #1e40af; }
        .type-badge.google { background: #fef3c7; color: #92400e; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        .status.connected { background: var(--success-light); color: var(--success); }
        .status.disconnected { background: var(--error-light); color: var(--error); }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .status.connected .status-dot { background: var(--success); }
        .status.disconnected .status-dot { background: var(--error); }
        .admin-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .login-box {
            max-width: 400px;
            margin: 80px auto;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #1e40af;
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #92400e;
        }
        .source-meta {
            display: flex;
            gap: 12px;
            align-items: center;
            margin: 8px 0;
        }
        .calendar-list {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="container">

<?php if (!$authenticated): ?>
    <!-- Login -->
    <div class="login-box">
        <div class="admin-card">
            <h2 class="panel-title">Admin-Zugang</h2>
            <?php if ($authError): ?>
                <div class="alert alert-error"><?= htmlspecialchars($authError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary">Anmelden</button>
            </form>
        </div>
    </div>
<?php else: ?>

    <div class="admin-header">
        <h1>Kalender-Verbindungen</h1>
        <?php if (!empty($config['admin']['password_hash'])): ?>
            <a href="?logout=1" class="btn btn-secondary">Abmelden</a>
        <?php endif; ?>
    </div>

    <?php if (empty($config['admin']['password_hash'])): ?>
        <div class="warning-box">
            <strong>Achtung:</strong> Kein Admin-Passwort konfiguriert. Bitte setzen Sie
            <code>admin.password_hash</code> in der <code>config.php</code>.
            <br>Generieren: <code>php -r "echo password_hash('IhrPasswort', PASSWORD_DEFAULT);"</code>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] === 'connected'): ?>
            <div class="alert alert-info" style="background: var(--success-light); color: var(--success); border-color: #86efac;">
                Kalender erfolgreich verbunden!
            </div>
        <?php elseif ($_GET['msg'] === 'disconnected'): ?>
            <div class="alert alert-info">Kalender-Verbindung getrennt.</div>
        <?php elseif ($_GET['msg'] === 'error'): ?>
            <div class="alert alert-error">Fehler bei der Authentifizierung. Bitte versuchen Sie es erneut.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="info-box">
        Verbinden Sie hier Ihre Kalender. Die App prüft die Verfügbarkeit über alle
        verbundenen Kalender hinweg. Neue Termine werden im als "Buchungsziel" markierten
        Kalender erstellt.
    </div>

    <?php foreach ($config['calendar_sources'] as $source): ?>
        <?php
        $isConnected = $tokenStore->has($source['id']);
        $calService = $service->getCalendarServiceBySourceId($source['id']);
        ?>
        <div class="admin-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <h3><?= htmlspecialchars($source['label']) ?></h3>
                    <div class="source-meta">
                        <span class="type-badge <?= $source['type'] ?>"><?= $source['type'] === 'microsoft' ? 'Microsoft 365' : 'Google' ?></span>
                        <?php if (!empty($source['is_booking_target'])): ?>
                            <span class="type-badge" style="background: #d1fae5; color: #065f46;">Buchungsziel</span>
                        <?php endif; ?>
                    </div>
                    <div class="calendar-list">
                        Kalender: <?= htmlspecialchars(implode(', ', $source['calendars'])) ?>
                    </div>
                </div>
                <div>
                    <?php if ($isConnected): ?>
                        <span class="status connected"><span class="status-dot"></span> Verbunden</span>
                    <?php else: ?>
                        <span class="status disconnected"><span class="status-dot"></span> Nicht verbunden</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-actions">
                <?php if ($isConnected): ?>
                    <a href="?disconnect=<?= urlencode($source['id']) ?>" class="btn btn-secondary"
                       onclick="return confirm('Verbindung wirklich trennen?')">
                        Verbindung trennen
                    </a>
                <?php else: ?>
                    <?php if (empty($source['client_id'])): ?>
                        <span style="color: var(--gray-400); font-size: 13px;">
                            Client-ID nicht konfiguriert. Bitte <code>config.php</code> bearbeiten.
                        </span>
                    <?php elseif ($calService): ?>
                        <a href="<?= htmlspecialchars($calService->getAuthUrl($source['id'])) ?>" class="btn btn-primary">
                            Jetzt verbinden
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

</div>
</body>
</html>
