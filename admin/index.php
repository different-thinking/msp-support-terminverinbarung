<?php
/**
 * Admin-Panel: Komplette Konfiguration der Terminbuchungs-App.
 */
session_start();

require_once __DIR__ . '/../src/ConfigManager.php';
require_once __DIR__ . '/../src/TokenStore.php';

$cm = new ConfigManager();
$config = $cm->getAppConfig();
$tokenStore = new TokenStore($config['token_store']['path']);

// Authentifizierung
$authenticated = false;
$authError = '';

if ($cm->hasAdminPassword()) {
    if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
        $authenticated = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($cm->verifyAdminPassword($_POST['password'])) {
            $_SESSION['admin_auth'] = true;
            $authenticated = true;
        } else {
            $authError = 'Falsches Passwort.';
        }
    }
} else {
    $authenticated = true;
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_auth']);
    header('Location: index.php');
    exit;
}

// Flash-Messages (z.B. nach OAuth-Callback)
$flashMsg = '';
$flashType = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'connected': $flashMsg = 'Kalender erfolgreich verbunden!'; $flashType = 'success'; break;
        case 'disconnected': $flashMsg = 'Kalender-Verbindung getrennt.'; $flashType = 'info'; break;
        case 'error': $flashMsg = 'Fehler bei der Authentifizierung.'; $flashType = 'error'; break;
    }
}

// Kalender-Verbindungsstatus
$calendarStatus = [];
foreach ($config['calendar_sources'] as $src) {
    $calendarStatus[$src['id']] = $tokenStore->has($src['id']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – <?= htmlspecialchars($config['app']['name'] ?: 'Terminbuchung') ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<?php if (!$authenticated): ?>
<div class="container">
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
</div>
<?php else: ?>

<div class="admin-layout">
    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h2>Terminbuchung</h2>
            <span class="sidebar-subtitle">Administration</span>
        </div>
        <nav class="sidebar-nav">
            <a href="#" class="nav-item active" data-tab="general">
                <span class="nav-icon">&#9881;</span> Allgemein
            </a>
            <a href="#" class="nav-item" data-tab="design">
                <span class="nav-icon">&#127912;</span> Seitendesign
            </a>
            <a href="#" class="nav-item" data-tab="organizer">
                <span class="nav-icon">&#128100;</span> Organisator
            </a>
            <a href="#" class="nav-item" data-tab="hours">
                <span class="nav-icon">&#128339;</span> Arbeitszeiten
            </a>
            <a href="#" class="nav-item" data-tab="calendars">
                <span class="nav-icon">&#128197;</span> Kalender
            </a>
            <a href="#" class="nav-item" data-tab="form">
                <span class="nav-icon">&#128221;</span> Buchungsformular
            </a>
            <a href="#" class="nav-item" data-tab="teams">
                <span class="nav-icon">&#128247;</span> Teams-Meeting
            </a>
            <a href="#" class="nav-item" data-tab="embed">
                <span class="nav-icon">&#128444;</span> Einbetten
            </a>
            <a href="#" class="nav-item" data-tab="access">
                <span class="nav-icon">&#128274;</span> Zugang
            </a>
            <hr style="border:none;border-top:1px solid var(--gray-100);margin:8px 12px;">
            <a href="#" class="nav-item" data-tab="setup-m365">
                <span class="nav-icon">&#128214;</span> M365-Anleitung
            </a>
            <a href="#" class="nav-item" data-tab="setup-google">
                <span class="nav-icon">&#128214;</span> Google-Anleitung
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="../" class="nav-item" target="_blank">
                <span class="nav-icon">&#8599;</span> Buchungsseite
            </a>
            <?php if ($cm->hasAdminPassword()): ?>
            <a href="?logout=1" class="nav-item">
                <span class="nav-icon">&#9211;</span> Abmelden
            </a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <div id="toast-container"></div>

        <?php if ($flashMsg): ?>
        <div class="alert alert-<?= $flashType === 'success' ? 'info' : $flashType ?>"
             style="<?= $flashType === 'success' ? 'background:var(--success-light);color:var(--success);border-color:#86efac;' : '' ?>">
            <?= htmlspecialchars($flashMsg) ?>
        </div>
        <?php endif; ?>

        <?php if (!$cm->hasAdminPassword()): ?>
        <div class="alert alert-warning">
            <strong>Achtung:</strong> Kein Admin-Passwort gesetzt. Bitte konfigurieren Sie eines unter "Zugang".
        </div>
        <?php endif; ?>

        <!-- ==================== Tab: Allgemein ==================== -->
        <section id="tab-general" class="tab-content active">
            <div class="tab-header">
                <h1>Allgemeine Einstellungen</h1>
                <p>Grundkonfiguration der Buchungsseite</p>
            </div>
            <form id="form-general" class="admin-card">
                <div class="form-row">
                    <div class="form-group">
                        <label for="app-name">Name der Buchungsseite</label>
                        <input type="text" id="app-name" name="name"
                               value="<?= htmlspecialchars($config['app']['name']) ?>"
                               placeholder="z.B. Terminbuchung">
                    </div>
                    <div class="form-group">
                        <label for="app-url">URL der App</label>
                        <input type="url" id="app-url" name="url"
                               value="<?= htmlspecialchars($config['app']['url']) ?>"
                               placeholder="https://termine.example.com">
                        <div class="form-hint">Wird für OAuth-Redirects benötigt</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="app-timezone">Zeitzone</label>
                        <select id="app-timezone" name="timezone">
                            <?php
                            $timezones = ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo', 'UTC'];
                            foreach ($timezones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $config['app']['timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="app-duration">Termindauer (Minuten)</label>
                        <input type="number" id="app-duration" name="appointment_duration_minutes"
                               value="<?= (int)$config['app']['appointment_duration_minutes'] ?>" min="15" step="15">
                    </div>
                </div>
                <div class="form-row form-row-3">
                    <div class="form-group">
                        <label for="app-horizon">Buchungshorizont (Tage)</label>
                        <input type="number" id="app-horizon" name="booking_horizon_days"
                               value="<?= (int)$config['app']['booking_horizon_days'] ?>" min="1">
                        <div class="form-hint">Wie weit in die Zukunft buchbar</div>
                    </div>
                    <div class="form-group">
                        <label for="app-notice">Vorlaufzeit (Stunden)</label>
                        <input type="number" id="app-notice" name="min_notice_hours"
                               value="<?= (int)$config['app']['min_notice_hours'] ?>" min="0">
                        <div class="form-hint">Mindestens X Stunden im Voraus</div>
                    </div>
                    <div class="form-group">
                        <label for="app-interval">Slot-Intervall (Minuten)</label>
                        <input type="number" id="app-interval" name="slot_interval_minutes"
                               value="<?= (int)$config['app']['slot_interval_minutes'] ?>" min="5" step="5">
                        <div class="form-hint">Abstand zwischen Zeitslots</div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Seitendesign ==================== -->
        <section id="tab-design" class="tab-content">
            <div class="tab-header">
                <h1>Seitendesign</h1>
                <p>Header-Bild, Profilbild und Texte der Buchungsseite anpassen</p>
            </div>

            <div class="admin-card">
                <h3 class="card-section-title">Header-Bild</h3>
                <p class="text-muted" style="margin-bottom:12px;">Wird als Banner oben auf der Buchungsseite angezeigt (empfohlen: 1200 x 300 px)</p>
                <div class="image-upload-area" id="header-image-area">
                    <?php $headerImg = $config['page_design']['header_image'] ?? ''; ?>
                    <?php if ($headerImg): ?>
                        <div class="image-preview" id="header-image-preview">
                            <img src="../<?= htmlspecialchars($headerImg) ?>" alt="Header">
                            <button type="button" class="btn-remove-image" data-field="header_image" title="Bild entfernen">&times;</button>
                        </div>
                    <?php else: ?>
                        <div class="image-preview" id="header-image-preview" style="display:none;">
                            <img src="" alt="Header">
                            <button type="button" class="btn-remove-image" data-field="header_image" title="Bild entfernen">&times;</button>
                        </div>
                    <?php endif; ?>
                    <label class="image-upload-btn" <?= $headerImg ? 'style="display:none;"' : '' ?> id="header-image-upload-label">
                        <input type="file" accept="image/*" data-field="header_image" class="image-upload-input" style="display:none;">
                        <span>Bild hochladen</span>
                    </label>
                </div>
            </div>

            <div class="admin-card">
                <h3 class="card-section-title">Profilbild</h3>
                <p class="text-muted" style="margin-bottom:12px;">Wird neben Ihrem Namen angezeigt (empfohlen: 200 x 200 px, quadratisch)</p>
                <div class="image-upload-area" id="profile-image-area">
                    <?php $profileImg = $config['page_design']['profile_image'] ?? ''; ?>
                    <?php if ($profileImg): ?>
                        <div class="image-preview profile-preview" id="profile-image-preview">
                            <img src="../<?= htmlspecialchars($profileImg) ?>" alt="Profil">
                            <button type="button" class="btn-remove-image" data-field="profile_image" title="Bild entfernen">&times;</button>
                        </div>
                    <?php else: ?>
                        <div class="image-preview profile-preview" id="profile-image-preview" style="display:none;">
                            <img src="" alt="Profil">
                            <button type="button" class="btn-remove-image" data-field="profile_image" title="Bild entfernen">&times;</button>
                        </div>
                    <?php endif; ?>
                    <label class="image-upload-btn" <?= $profileImg ? 'style="display:none;"' : '' ?> id="profile-image-upload-label">
                        <input type="file" accept="image/*" data-field="profile_image" class="image-upload-input" style="display:none;">
                        <span>Bild hochladen</span>
                    </label>
                </div>
            </div>

            <form id="form-page-design" class="admin-card">
                <h3 class="card-section-title">Texte</h3>
                <p class="text-muted" style="margin-bottom:12px;">Diese Texte erscheinen auf der Buchungsseite</p>
                <div class="form-group">
                    <label for="design-welcome-title">Begrüßungstitel</label>
                    <input type="text" id="design-welcome-title"
                           value="<?= htmlspecialchars($config['page_design']['welcome_title'] ?? '') ?>"
                           placeholder="z.B. Willkommen bei meiner Terminbuchung">
                    <div class="form-hint">Wird unter dem Profilbild angezeigt. Leer lassen für Standardtext.</div>
                </div>
                <div class="form-group">
                    <label for="design-welcome-text">Beschreibungstext</label>
                    <textarea id="design-welcome-text" rows="3"
                              placeholder="z.B. Buchen Sie einen Beratungstermin – ich freue mich auf unser Gespräch."><?= htmlspecialchars($config['page_design']['welcome_text'] ?? '') ?></textarea>
                    <div class="form-hint">Kurze Beschreibung unter dem Begrüßungstitel. Für <strong>fett</strong> schreiben Sie **text**</div>
                </div>
                <div class="form-group">
                    <label for="design-booking-info">Zusatzinfo bei der Terminauswahl</label>
                    <textarea id="design-booking-info" rows="2"
                              placeholder="z.B. Wählen Sie einen passenden Termin aus meinem Kalender."><?= htmlspecialchars($config['page_design']['booking_info'] ?? '') ?></textarea>
                    <div class="form-hint">Wird im Kalender-Bereich angezeigt (optional). Für <strong>fett</strong> schreiben Sie **text**</div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Texte speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Organisator ==================== -->
        <section id="tab-organizer" class="tab-content">
            <div class="tab-header">
                <h1>Organisator</h1>
                <p>Ihre Informationen, die in Termineinladungen erscheinen</p>
            </div>
            <form id="form-organizer" class="admin-card">
                <div class="form-row">
                    <div class="form-group">
                        <label for="org-name">Name</label>
                        <input type="text" id="org-name" name="name"
                               value="<?= htmlspecialchars($config['organizer']['name']) ?>"
                               placeholder="Max Mustermann">
                    </div>
                    <div class="form-group">
                        <label for="org-email">E-Mail-Adresse</label>
                        <input type="email" id="org-email" name="email"
                               value="<?= htmlspecialchars($config['organizer']['email']) ?>"
                               placeholder="max@example.com">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Arbeitszeiten ==================== -->
        <section id="tab-hours" class="tab-content">
            <div class="tab-header">
                <h1>Arbeitszeiten</h1>
                <p>Definieren Sie, wann Termine buchbar sind</p>
            </div>
            <form id="form-hours" class="admin-card">
                <?php
                $dayLabels = [
                    'monday' => 'Montag', 'tuesday' => 'Dienstag', 'wednesday' => 'Mittwoch',
                    'thursday' => 'Donnerstag', 'friday' => 'Freitag', 'saturday' => 'Samstag', 'sunday' => 'Sonntag',
                ];
                foreach ($dayLabels as $dayKey => $dayLabel):
                    $dayConf = $config['working_hours'][$dayKey] ?? null;
                    $enabled = $dayConf !== null;
                    $start = $dayConf['start'] ?? '09:00';
                    $end = $dayConf['end'] ?? '17:00';
                ?>
                <div class="hours-row">
                    <label class="hours-toggle">
                        <input type="checkbox" class="hours-enabled" data-day="<?= $dayKey ?>"
                               <?= $enabled ? 'checked' : '' ?>>
                        <span class="hours-day-label"><?= $dayLabel ?></span>
                    </label>
                    <div class="hours-times <?= !$enabled ? 'disabled' : '' ?>">
                        <input type="time" class="hours-start" data-day="<?= $dayKey ?>"
                               value="<?= $start ?>" <?= !$enabled ? 'disabled' : '' ?>>
                        <span class="hours-separator">–</span>
                        <input type="time" class="hours-end" data-day="<?= $dayKey ?>"
                               value="<?= $end ?>" <?= !$enabled ? 'disabled' : '' ?>>
                    </div>
                </div>
                <?php endforeach; ?>

                <hr style="border:none;border-top:1px solid var(--gray-100);margin:24px 0 16px;">
                <h3 class="card-section-title" style="margin-bottom:12px;">Tägliche Pause</h3>
                <p class="text-muted" style="margin-bottom:12px;">Pausenzeit, die jeden Tag als nicht buchbar gilt</p>
                <?php
                    $breakConf = $config['break_time'] ?? ['enabled' => false, 'start' => '12:00', 'end' => '13:00'];
                    $breakEnabled = !empty($breakConf['enabled']);
                    $breakStart = $breakConf['start'] ?? '12:00';
                    $breakEnd = $breakConf['end'] ?? '13:00';
                ?>
                <div class="hours-row">
                    <label class="hours-toggle">
                        <input type="checkbox" id="break-enabled"
                               <?= $breakEnabled ? 'checked' : '' ?>>
                        <span class="hours-day-label">Pause</span>
                    </label>
                    <div class="hours-times <?= !$breakEnabled ? 'disabled' : '' ?>" id="break-times">
                        <input type="time" id="break-start"
                               value="<?= htmlspecialchars($breakStart) ?>" <?= !$breakEnabled ? 'disabled' : '' ?>>
                        <span class="hours-separator">&ndash;</span>
                        <input type="time" id="break-end"
                               value="<?= htmlspecialchars($breakEnd) ?>" <?= !$breakEnabled ? 'disabled' : '' ?>>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Kalender ==================== -->
        <section id="tab-calendars" class="tab-content">
            <div class="tab-header">
                <h1>Kalender-Quellen</h1>
                <p>Verbinden Sie Google- und Microsoft 365-Kalender zur Verfügbarkeitsermittlung</p>
            </div>

            <div class="info-box">
                Die App prüft die Verfügbarkeit über alle verbundenen Kalender hinweg.
                Der als <strong>Buchungsziel</strong> markierte M365-Kalender wird zum Erstellen
                neuer Termine und Versenden der Einladungen verwendet.
                <br><br>
                <strong>Ersteinrichtung?</strong>
                <a href="#" class="nav-link-inline" data-tab="setup-m365">M365-Anleitung</a> |
                <a href="#" class="nav-link-inline" data-tab="setup-google">Google-Anleitung</a>
            </div>

            <div id="calendar-sources-list">
                <?php foreach ($config['calendar_sources'] as $src):
                    $isConnected = $calendarStatus[$src['id']] ?? false;
                ?>
                <div class="admin-card calendar-source-card" data-id="<?= htmlspecialchars($src['id']) ?>">
                    <div class="card-header">
                        <div>
                            <h3><?= htmlspecialchars($src['label'] ?: $src['id']) ?></h3>
                            <div class="source-meta">
                                <span class="type-badge <?= $src['type'] ?>">
                                    <?= $src['type'] === 'microsoft' ? 'Microsoft 365' : 'Google' ?>
                                </span>
                                <?php if (!empty($src['is_booking_target'])): ?>
                                    <span class="type-badge booking-target">Buchungsziel</span>
                                <?php endif; ?>
                            </div>
                            <div class="source-detail">
                                Kalender: <?= htmlspecialchars(implode(', ', $src['calendars'] ?? ['primary'])) ?>
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
                    <div class="card-actions">
                        <button type="button" class="btn btn-secondary btn-sm btn-edit-source"
                                data-source='<?= htmlspecialchars(json_encode($src, JSON_UNESCAPED_UNICODE)) ?>'>
                            Bearbeiten
                        </button>
                        <?php if ($isConnected): ?>
                            <button type="button" class="btn btn-secondary btn-sm btn-disconnect-source"
                                    data-id="<?= htmlspecialchars($src['id']) ?>">
                                Trennen
                            </button>
                        <?php elseif (!empty($src['client_id'])): ?>
                            <?php
                            require_once __DIR__ . '/../src/BookingService.php';
                            $svc = new BookingService();
                            $calSvc = $svc->getCalendarServiceBySourceId($src['id']);
                            if ($calSvc): ?>
                                <a href="<?= htmlspecialchars($calSvc->getAuthUrl($src['id'])) ?>"
                                   class="btn btn-primary btn-sm">
                                    Verbinden
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Client-ID fehlt</span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-danger btn-sm btn-delete-source"
                                data-id="<?= htmlspecialchars($src['id']) ?>"
                                data-label="<?= htmlspecialchars($src['label']) ?>">
                            Entfernen
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" id="btn-add-source" class="btn btn-primary" style="margin-top: 16px;">
                + Kalender-Quelle hinzufügen
            </button>

            <!-- Modal: Kalender bearbeiten/neu -->
            <div id="modal-source" class="modal" style="display:none;">
                <div class="modal-backdrop"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="modal-source-title">Kalender-Quelle</h2>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    <form id="form-source">
                        <input type="hidden" id="source-id" name="id">
                        <div class="form-group">
                            <label for="source-type">Typ</label>
                            <select id="source-type" name="type">
                                <option value="microsoft">Microsoft 365</option>
                                <option value="google">Google</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="source-label">Bezeichnung</label>
                            <input type="text" id="source-label" name="label"
                                   placeholder="z.B. Microsoft 365 Hauptkonto" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="source-client-id">Client-ID</label>
                                <input type="text" id="source-client-id" name="client_id"
                                       placeholder="Application (client) ID">
                            </div>
                            <div class="form-group">
                                <label for="source-client-secret">Client Secret</label>
                                <input type="password" id="source-client-secret" name="client_secret"
                                       placeholder="Client Secret">
                                <div class="form-hint" id="secret-hint" style="display:none;">
                                    Secret ist gesetzt. Leer lassen um beizubehalten.
                                </div>
                            </div>
                        </div>
                        <div id="ms-fields">
                            <div class="form-group">
                                <label for="source-tenant">Tenant-ID</label>
                                <input type="text" id="source-tenant" name="tenant_id"
                                       value="common" placeholder="common oder Tenant-ID">
                                <div class="form-hint">"common" für Multi-Tenant oder spezifische Tenant-ID</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="source-calendars">Kalender-IDs</label>
                            <input type="text" id="source-calendars" name="calendars"
                                   value="primary" placeholder="primary, zweiter-kalender">
                            <div class="form-hint">Kommasepariert. "primary" für den Hauptkalender.</div>
                        </div>
                        <div class="form-group">
                            <label for="source-redirect">Redirect-URI (optional)</label>
                            <input type="url" id="source-redirect" name="redirect_uri"
                                   placeholder="Automatisch wenn leer">
                            <div class="form-hint">Leer lassen für automatische Erkennung</div>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="source-booking-target" name="is_booking_target">
                                <span>Als Buchungsziel verwenden</span>
                            </label>
                            <div class="form-hint">Termine werden in diesen Kalender eingetragen und Einladungen über diesen Account versendet</div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary modal-cancel">Abbrechen</button>
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- ==================== Tab: Buchungsformular ==================== -->
        <section id="tab-form" class="tab-content">
            <div class="tab-header">
                <h1>Buchungsformular</h1>
                <p>Konfigurieren Sie die Felder, die der Buchende ausfüllen muss</p>
            </div>

            <div class="admin-card">
                <h3 class="card-section-title">Standardfelder</h3>
                <p class="text-muted" style="margin-bottom:12px;">Immer enthalten, nicht änderbar.</p>
                <div class="fixed-fields">
                    <div class="fixed-field">Vorname <span class="badge">Pflicht</span></div>
                    <div class="fixed-field">Nachname <span class="badge">Pflicht</span></div>
                    <div class="fixed-field">E-Mail <span class="badge">Pflicht</span></div>
                </div>
            </div>

            <form id="form-booking-fields" class="admin-card">
                <h3 class="card-section-title">Zusätzliche Felder</h3>
                <div id="custom-fields-list">
                    <?php foreach ($config['booking_form']['additional_fields'] as $i => $field): ?>
                    <div class="custom-field-row" data-index="<?= $i ?>">
                        <div class="field-drag-handle" title="Ziehen zum Sortieren">&#9776;</div>
                        <div class="field-config">
                            <div class="form-row form-row-4">
                                <div class="form-group">
                                    <label>Feldname</label>
                                    <input type="text" class="cf-name"
                                           value="<?= htmlspecialchars($field['name']) ?>"
                                           placeholder="feldname" pattern="[a-z0-9_]+">
                                </div>
                                <div class="form-group">
                                    <label>Bezeichnung</label>
                                    <input type="text" class="cf-label"
                                           value="<?= htmlspecialchars($field['label']) ?>"
                                           placeholder="Anzeigename">
                                </div>
                                <div class="form-group">
                                    <label>Typ</label>
                                    <select class="cf-type">
                                        <?php foreach (['text' => 'Text', 'email' => 'E-Mail', 'tel' => 'Telefon', 'url' => 'URL', 'number' => 'Zahl', 'textarea' => 'Textbereich'] as $t => $tl): ?>
                                            <option value="<?= $t ?>" <?= ($field['type'] ?? 'text') === $t ? 'selected' : '' ?>><?= $tl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Platzhalter</label>
                                    <input type="text" class="cf-placeholder"
                                           value="<?= htmlspecialchars($field['placeholder'] ?? '') ?>">
                                </div>
                            </div>
                            <label class="checkbox-label">
                                <input type="checkbox" class="cf-required"
                                       <?= !empty($field['required']) ? 'checked' : '' ?>>
                                <span>Pflichtfeld</span>
                            </label>
                        </div>
                        <button type="button" class="btn-remove-field" title="Entfernen">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="btn-add-field" class="btn btn-secondary" style="margin-top:12px;">
                    + Feld hinzufügen
                </button>

                <hr class="form-divider">

                <h3 class="card-section-title">Teilnehmer-Optionen</h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="allow-attendees"
                               <?= !empty($config['booking_form']['allow_additional_attendees']) ? 'checked' : '' ?>>
                        <span>Buchende können weitere Teilnehmer hinzufügen</span>
                    </label>
                </div>
                <div class="form-group" id="max-attendees-group">
                    <label for="max-attendees">Maximale Anzahl</label>
                    <input type="number" id="max-attendees"
                           value="<?= (int)$config['booking_form']['max_additional_attendees'] ?>"
                           min="1" max="20" style="max-width:120px;">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Teams ==================== -->
        <section id="tab-teams" class="tab-content">
            <div class="tab-header">
                <h1>Microsoft Teams</h1>
                <p>Automatische Teams-Meeting-Erstellung für gebuchte Termine</p>
            </div>
            <form id="form-teams" class="admin-card">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="teams-enabled"
                               <?= !empty($config['teams']['enabled']) ? 'checked' : '' ?>>
                        <span>Teams-Meeting automatisch erstellen</span>
                    </label>
                    <div class="form-hint">Jeder gebuchte Termin erhält automatisch einen Teams-Meeting-Link</div>
                </div>
                <div class="form-group" id="teams-source-group">
                    <label for="teams-source">M365-Account für Teams</label>
                    <select id="teams-source" name="source_id">
                        <option value="">– Bitte wählen –</option>
                        <?php foreach ($config['calendar_sources'] as $src):
                            if ($src['type'] !== 'microsoft') continue; ?>
                            <option value="<?= htmlspecialchars($src['id']) ?>"
                                <?= ($config['teams']['source_id'] ?? '') === $src['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($src['label'] ?: $src['id']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Tab: Einbetten ==================== -->
        <section id="tab-embed" class="tab-content">
            <div class="tab-header">
                <h1>Einbetten</h1>
                <p>Binden Sie den Buchungskalender in andere Webseiten ein</p>
            </div>

            <?php
            $embedUrl = rtrim($config['app']['url'] ?: '', '/') . '/embed.php';
            ?>

            <div class="admin-card">
                <h3 class="card-section-title">iframe Embed-Code</h3>
                <p class="text-muted" style="margin-bottom:16px;">
                    Kopieren Sie diesen Code und fügen Sie ihn in Ihre Webseite ein.
                    Der Kalender passt sich automatisch an die Breite des Containers an.
                </p>

                <?php if (empty($config['app']['url'])): ?>
                <div class="alert alert-warning" style="margin-bottom:16px;">
                    <strong>Hinweis:</strong> Sie haben noch keine App-URL konfiguriert.
                    Bitte tragen Sie diese zuerst unter
                    <a href="#" class="nav-link-inline" data-tab="general">Allgemein</a> ein.
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="embed-width">Breite</label>
                    <input type="text" id="embed-width" value="100%" placeholder="z.B. 100% oder 600px" style="max-width:200px;">
                </div>
                <div class="form-group">
                    <label for="embed-height">Mindesthöhe</label>
                    <input type="text" id="embed-height" value="700px" placeholder="z.B. 700px" style="max-width:200px;">
                </div>

                <div class="form-group">
                    <label>Embed-Code</label>
                    <textarea id="embed-code" readonly rows="8" style="font-family:monospace;font-size:13px;background:var(--gray-50);"></textarea>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="button" id="btn-copy-embed" class="btn btn-primary">Code kopieren</button>
                </div>
            </div>

            <div class="admin-card">
                <h3 class="card-section-title">Vorschau</h3>
                <div id="embed-preview" style="border:1px solid var(--gray-200);border-radius:var(--radius);overflow:hidden;margin-top:12px;">
                    <?php if (!empty($config['app']['url'])): ?>
                    <iframe src="<?= htmlspecialchars($embedUrl) ?>" id="embed-preview-iframe"
                            style="width:100%;min-height:700px;border:none;"></iframe>
                    <?php else: ?>
                    <div style="padding:40px;text-align:center;color:var(--gray-400);">
                        Vorschau verfügbar nach Konfiguration der App-URL
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ==================== Tab: Zugang ==================== -->
        <section id="tab-access" class="tab-content">
            <div class="tab-header">
                <h1>Admin-Zugang</h1>
                <p>Passwort für die Administrationsoberfläche</p>
            </div>
            <form id="form-password" class="admin-card">
                <?php if ($cm->hasAdminPassword()): ?>
                <div class="form-group">
                    <label for="current-password">Aktuelles Passwort</label>
                    <input type="password" id="current-password" required>
                </div>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="new-password">Neues Passwort</label>
                        <input type="password" id="new-password" minlength="6" required>
                        <div class="form-hint">Mindestens 6 Zeichen</div>
                    </div>
                    <div class="form-group">
                        <label for="confirm-password">Passwort bestätigen</label>
                        <input type="password" id="confirm-password" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Passwort speichern</button>
                </div>
            </form>
        </section>

        <!-- ==================== Anleitung: M365 ==================== -->
        <section id="tab-setup-m365" class="tab-content">
            <div class="tab-header">
                <h1>Microsoft 365 einrichten</h1>
                <p>Schritt-für-Schritt-Anleitung zur Konfiguration der Azure App-Registrierung</p>
            </div>

            <?php
            $appUrl = $config['app']['url'] ?: 'https://ihre-domain.de';
            $redirectMs = rtrim($appUrl, '/') . '/admin/auth-microsoft.php';
            ?>

            <!-- Schritt 1 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">1</span>
                    <h3>Azure Portal öffnen</h3>
                </div>
                <div class="guide-step-body">
                    <p>Öffnen Sie das Azure Portal und navigieren Sie zu <strong>App-Registrierungen</strong>:</p>
                    <div class="guide-url-box">
                        <code>https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade</code>
                    </div>
                    <p>Melden Sie sich mit Ihrem Microsoft 365 Administrator-Konto an.</p>
                </div>
            </div>

            <!-- Schritt 2 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">2</span>
                    <h3>Neue Registrierung erstellen</h3>
                </div>
                <div class="guide-step-body">
                    <p>Klicken Sie auf <strong>"+ Neue Registrierung"</strong> und füllen Sie aus:</p>
                    <table class="guide-table">
                        <tr>
                            <td class="guide-label">Name</td>
                            <td><code>Terminbuchung</code> <span class="text-muted">(frei wählbar)</span></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Unterstützte Kontotypen</td>
                            <td>
                                <strong>"Nur Konten in diesem Organisationsverzeichnis"</strong><br>
                                <span class="text-muted">(Single Tenant – empfohlen für ein Unternehmen)</span><br>
                                <span class="text-muted">Oder "Konten in einem beliebigen Organisationsverzeichnis" für Multi-Tenant</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="guide-label">Umleitungs-URI</td>
                            <td>
                                Plattform: <strong>Web</strong><br>
                                URI: <code id="redirect-uri-ms"><?= htmlspecialchars($redirectMs) ?></code>
                                <button type="button" class="btn-copy" onclick="copyText('redirect-uri-ms')" title="Kopieren">&#128203;</button>
                            </td>
                        </tr>
                    </table>
                    <p>Klicken Sie auf <strong>"Registrieren"</strong>.</p>
                    <div class="guide-info">
                        <?php if (empty($config['app']['url'])): ?>
                        <strong>Hinweis:</strong> Sie haben noch keine App-URL konfiguriert.
                        Bitte tragen Sie diese zuerst unter <a href="#" class="nav-link-inline" data-tab="general">Allgemein</a> ein,
                        damit die Redirect-URI korrekt ist.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Schritt 3 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">3</span>
                    <h3>Client-ID kopieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Nach der Registrierung werden Sie zur Übersicht weitergeleitet. Kopieren Sie:</p>
                    <table class="guide-table">
                        <tr>
                            <td class="guide-label">Anwendungs-ID (Client)</td>
                            <td>
                                Die <strong>Application (client) ID</strong> – eine UUID wie<br>
                                <code>12345678-abcd-efgh-ijkl-123456789012</code>
                            </td>
                        </tr>
                        <tr>
                            <td class="guide-label">Verzeichnis-ID (Tenant)</td>
                            <td>
                                Die <strong>Directory (tenant) ID</strong> – benötigen Sie gleich<br>
                                <span class="text-muted">Bei Multi-Tenant stattdessen <code>common</code> verwenden</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Schritt 4 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">4</span>
                    <h3>Client Secret erstellen</h3>
                </div>
                <div class="guide-step-body">
                    <p>Navigieren Sie in der linken Sidebar zu <strong>"Zertifikate & Geheimnisse"</strong>:</p>
                    <ol class="guide-ol">
                        <li>Klicken Sie auf <strong>"+ Neuer geheimer Clientschlüssel"</strong></li>
                        <li>Beschreibung: <code>Terminbuchung</code></li>
                        <li>Ablauf: <strong>24 Monate</strong> (oder nach Bedarf)</li>
                        <li>Klicken Sie <strong>"Hinzufügen"</strong></li>
                        <li>
                            <strong class="guide-important">Sofort den "Wert" kopieren!</strong><br>
                            <span class="text-muted">Der Wert wird nur einmal angezeigt. Nach dem Verlassen der Seite ist er nicht mehr sichtbar.</span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Schritt 5 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">5</span>
                    <h3>API-Berechtigungen konfigurieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Navigieren Sie zu <strong>"API-Berechtigungen"</strong> in der linken Sidebar:</p>
                    <ol class="guide-ol">
                        <li>Klicken Sie auf <strong>"+ Berechtigung hinzufügen"</strong></li>
                        <li>Wählen Sie <strong>"Microsoft Graph"</strong></li>
                        <li>Wählen Sie <strong>"Delegierte Berechtigungen"</strong></li>
                        <li>Suchen und aktivieren Sie folgende Berechtigungen:</li>
                    </ol>
                    <div class="guide-permissions">
                        <div class="guide-perm">
                            <code>Calendars.ReadWrite</code>
                            <span>Kalender lesen und Termine erstellen</span>
                        </div>
                        <div class="guide-perm">
                            <code>OnlineMeetings.ReadWrite</code>
                            <span>Teams-Meetings erstellen</span>
                        </div>
                        <div class="guide-perm">
                            <code>Mail.Send</code>
                            <span>E-Mails senden (für Einladungen)</span>
                        </div>
                        <div class="guide-perm">
                            <code>offline_access</code>
                            <span>Token automatisch erneuern</span>
                        </div>
                    </div>
                    <ol class="guide-ol" start="5">
                        <li>Klicken Sie <strong>"Berechtigungen hinzufügen"</strong></li>
                        <li>Klicken Sie dann auf <strong>"Administratorzustimmung für [Ihr Verzeichnis] erteilen"</strong></li>
                        <li>Bestätigen Sie mit <strong>"Ja"</strong></li>
                    </ol>
                    <div class="guide-info">
                        <strong>Wichtig:</strong> Die Admin-Zustimmung ist erforderlich, damit die App im Namen
                        des angemeldeten Benutzers Kalender und Teams-Meetings verwalten darf.
                    </div>
                </div>
            </div>

            <!-- Schritt 6 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">6</span>
                    <h3>In der App konfigurieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Jetzt haben Sie alle benötigten Daten. Gehen Sie zu
                        <a href="#" class="nav-link-inline" data-tab="calendars"><strong>Kalender</strong></a>
                        und klicken Sie <strong>"+ Kalender-Quelle hinzufügen"</strong>:
                    </p>
                    <table class="guide-table">
                        <tr>
                            <td class="guide-label">Typ</td>
                            <td><strong>Microsoft 365</strong></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Bezeichnung</td>
                            <td>z.B. <code>Mein M365 Konto</code></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Client-ID</td>
                            <td>Die Application (client) ID aus Schritt 3</td>
                        </tr>
                        <tr>
                            <td class="guide-label">Client Secret</td>
                            <td>Der Geheimniswert aus Schritt 4</td>
                        </tr>
                        <tr>
                            <td class="guide-label">Tenant-ID</td>
                            <td>Die Directory (tenant) ID aus Schritt 3<br>
                                <span class="text-muted">Oder <code>common</code> bei Multi-Tenant</span></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Kalender-IDs</td>
                            <td><code>primary</code> <span class="text-muted">(für den Hauptkalender)</span></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Buchungsziel</td>
                            <td><strong>Aktivieren</strong> – Termine werden in diesen Kalender eingetragen</td>
                        </tr>
                    </table>
                    <p>Klicken Sie <strong>"Speichern"</strong>.</p>
                </div>
            </div>

            <!-- Schritt 7 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">7</span>
                    <h3>Kalender verbinden (OAuth)</h3>
                </div>
                <div class="guide-step-body">
                    <p>Nach dem Speichern erscheint bei der Kalender-Quelle ein <strong>"Verbinden"</strong>-Button:</p>
                    <ol class="guide-ol">
                        <li>Klicken Sie <strong>"Verbinden"</strong></li>
                        <li>Sie werden zum Microsoft-Login weitergeleitet</li>
                        <li>Melden Sie sich mit dem M365-Konto an, dessen Kalender verwendet werden soll</li>
                        <li>Bestätigen Sie die angeforderten Berechtigungen</li>
                        <li>Sie werden zurück zur Admin-Seite geleitet</li>
                        <li>Der Status wechselt auf <span class="status connected" style="display:inline-flex;"><span class="status-dot"></span> Verbunden</span></li>
                    </ol>
                    <div class="guide-info">
                        <strong>Fertig!</strong> Die App kann jetzt den Kalender lesen, Termine erstellen,
                        Teams-Meetings anlegen und Einladungen direkt aus dem M365-Konto versenden.
                    </div>
                </div>
            </div>

            <!-- Schritt 8 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">8</span>
                    <h3>Teams-Meeting aktivieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Abschließend unter <a href="#" class="nav-link-inline" data-tab="teams"><strong>Teams-Meeting</strong></a>:</p>
                    <ol class="guide-ol">
                        <li>Haken bei <strong>"Teams-Meeting automatisch erstellen"</strong> setzen</li>
                        <li>Den soeben eingerichteten M365-Account als <strong>Teams-Account</strong> auswählen</li>
                        <li><strong>"Speichern"</strong> klicken</li>
                    </ol>
                </div>
            </div>

            <div class="guide-done-box">
                <strong>Geschafft!</strong> Ihre Terminbuchung ist jetzt vollständig mit Microsoft 365 verbunden.
                <br>Buchende erhalten eine echte Outlook-Einladung mit Teams-Link direkt aus Ihrem M365-Postfach.
                <br><br>
                <a href="#" class="btn btn-primary nav-link-inline" data-tab="calendars">Jetzt Kalender konfigurieren</a>
            </div>
        </section>

        <!-- ==================== Anleitung: Google ==================== -->
        <section id="tab-setup-google" class="tab-content">
            <div class="tab-header">
                <h1>Google Kalender einrichten</h1>
                <p>Schritt-für-Schritt-Anleitung zur Konfiguration der Google Cloud Console</p>
            </div>

            <?php
            $redirectGoogle = rtrim($appUrl, '/') . '/admin/auth-google.php';
            ?>

            <!-- Schritt 1 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">1</span>
                    <h3>Google Cloud Console öffnen</h3>
                </div>
                <div class="guide-step-body">
                    <p>Öffnen Sie die Google Cloud Console:</p>
                    <div class="guide-url-box">
                        <code>https://console.cloud.google.com/apis/credentials</code>
                    </div>
                    <p>Erstellen Sie bei Bedarf ein neues Projekt oder wählen Sie ein bestehendes.</p>
                </div>
            </div>

            <!-- Schritt 2 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">2</span>
                    <h3>Google Calendar API aktivieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Navigieren Sie zu <strong>"APIs und Dienste" &rarr; "Bibliothek"</strong>:</p>
                    <ol class="guide-ol">
                        <li>Suchen Sie nach <strong>"Google Calendar API"</strong></li>
                        <li>Klicken Sie darauf und dann <strong>"Aktivieren"</strong></li>
                    </ol>
                </div>
            </div>

            <!-- Schritt 3 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">3</span>
                    <h3>OAuth-Zustimmungsbildschirm einrichten</h3>
                </div>
                <div class="guide-step-body">
                    <p>Unter <strong>"APIs und Dienste" &rarr; "OAuth-Zustimmungsbildschirm"</strong>:</p>
                    <ol class="guide-ol">
                        <li>Typ: <strong>"Extern"</strong> (oder "Intern" bei Google Workspace)</li>
                        <li>App-Name: <code>Terminbuchung</code></li>
                        <li>Support-E-Mail: Ihre E-Mail-Adresse</li>
                        <li>Scopes hinzufügen: <code>Google Calendar API - .../auth/calendar.readonly</code></li>
                        <li>Test-User hinzufügen: Ihre Google-E-Mail</li>
                    </ol>
                </div>
            </div>

            <!-- Schritt 4 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">4</span>
                    <h3>OAuth-Client-ID erstellen</h3>
                </div>
                <div class="guide-step-body">
                    <p>Unter <strong>"APIs und Dienste" &rarr; "Anmeldedaten"</strong>:</p>
                    <ol class="guide-ol">
                        <li>Klicken Sie <strong>"+ Anmeldedaten erstellen" &rarr; "OAuth-Client-ID"</strong></li>
                        <li>Anwendungstyp: <strong>Webanwendung</strong></li>
                        <li>Name: <code>Terminbuchung</code></li>
                        <li>Autorisierte Weiterleitungs-URIs hinzufügen:</li>
                    </ol>
                    <div class="guide-url-box">
                        <code id="redirect-uri-google"><?= htmlspecialchars($redirectGoogle) ?></code>
                        <button type="button" class="btn-copy" onclick="copyText('redirect-uri-google')" title="Kopieren">&#128203;</button>
                    </div>
                    <ol class="guide-ol" start="5">
                        <li>Klicken Sie <strong>"Erstellen"</strong></li>
                        <li>Kopieren Sie <strong>Client-ID</strong> und <strong>Client Secret</strong></li>
                    </ol>
                </div>
            </div>

            <!-- Schritt 5 -->
            <div class="admin-card guide-step">
                <div class="guide-step-header">
                    <span class="guide-step-number">5</span>
                    <h3>In der App konfigurieren</h3>
                </div>
                <div class="guide-step-body">
                    <p>Unter <a href="#" class="nav-link-inline" data-tab="calendars"><strong>Kalender</strong></a>
                        &rarr; <strong>"+ Kalender-Quelle hinzufügen"</strong>:</p>
                    <table class="guide-table">
                        <tr>
                            <td class="guide-label">Typ</td>
                            <td><strong>Google</strong></td>
                        </tr>
                        <tr>
                            <td class="guide-label">Client-ID</td>
                            <td>Die Client-ID aus Schritt 4</td>
                        </tr>
                        <tr>
                            <td class="guide-label">Client Secret</td>
                            <td>Das Client Secret aus Schritt 4</td>
                        </tr>
                        <tr>
                            <td class="guide-label">Kalender-IDs</td>
                            <td><code>primary</code> oder spezifische Kalender-IDs</td>
                        </tr>
                        <tr>
                            <td class="guide-label">Buchungsziel</td>
                            <td><strong>Nicht aktivieren</strong> – Google dient nur zur Verfügbarkeitsprüfung</td>
                        </tr>
                    </table>
                    <p>Speichern und dann <strong>"Verbinden"</strong> klicken.</p>
                </div>
            </div>

            <div class="guide-done-box">
                <strong>Hinweis:</strong> Google-Kalender werden nur zur Verfügbarkeitsprüfung verwendet.
                Termine und Einladungen werden über den M365-Account erstellt und versendet.
                <br><br>
                <a href="#" class="btn btn-primary nav-link-inline" data-tab="calendars">Jetzt Kalender konfigurieren</a>
            </div>
        </section>

    </main>
</div>

<script>
function copyText(id) {
    const el = document.getElementById(id);
    navigator.clipboard.writeText(el.textContent.trim()).then(() => {
        const btn = el.nextElementSibling;
        btn.textContent = '\u2713';
        setTimeout(() => { btn.textContent = '\uD83D\uDCCB'; }, 1500);
    });
}
</script>
<script src="../assets/js/admin.js"></script>
<?php endif; ?>
</body>
</html>
