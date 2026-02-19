<?php
/**
 * Admin API – Konfiguration lesen und speichern.
 *
 * POST /admin/api.php?action=<action>
 * Alle Aktionen erfordern eine authentifizierte Session.
 */
// Sichere Session-Cookie-Parameter
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/../src/ConfigManager.php';
require_once __DIR__ . '/../src/TokenStore.php';
require_once __DIR__ . '/../src/SecurityHelper.php';

// Konstanten
const MAX_UPLOAD_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB
const MAX_ADDITIONAL_ATTENDEES = 20;
const MIN_PASSWORD_LENGTH = 12;

SecurityHelper::sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

$cm = new ConfigManager();

// Authentifizierung prüfen
if ($cm->hasAdminPassword()) {
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        jsonResponse(['error' => 'Nicht authentifiziert'], 401);
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// CSRF-Schutz für alle POST-Requests
if ($method === 'POST') {
    // Token aus Header oder JSON-Body lesen
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    // Bei multipart-Requests (Upload) aus POST-Feld lesen
    if ($csrfToken === null && isset($_POST['csrf_token'])) {
        $csrfToken = $_POST['csrf_token'];
    }

    if (!SecurityHelper::validateCsrfToken($csrfToken)) {
        jsonResponse(['error' => 'Ungültiges CSRF-Token'], 403);
    }
}

try {
    switch ($action) {

        // ==================== Konfiguration lesen ====================
        case 'config':
            if ($method !== 'GET') jsonResponse(['error' => 'GET erwartet'], 405);
            // Config ohne sensible Felder zurückgeben
            $config = $cm->get();
            // Passwort-Hash nie an Frontend senden
            $config['admin'] = ['has_password' => $cm->hasAdminPassword()];
            // Client-Secrets maskieren
            foreach ($config['calendar_sources'] as &$src) {
                if (!empty($src['client_secret'])) {
                    $src['client_secret_set'] = true;
                    $src['client_secret'] = '';
                }
            }
            unset($src);
            jsonResponse(['config' => $config]);
            break;

        // ==================== Allgemeine Einstellungen ====================
        case 'save-app':
            requirePost($method);
            $input = getJsonInput();
            $app = [
                'name' => trim($input['name'] ?? ''),
                'url' => rtrim(trim($input['url'] ?? ''), '/'),
                'timezone' => trim($input['timezone'] ?? 'Europe/Berlin'),
                'locale' => trim($input['locale'] ?? 'de'),
                'appointment_duration_minutes' => max(15, (int)($input['appointment_duration_minutes'] ?? 60)),
                'booking_horizon_days' => max(1, (int)($input['booking_horizon_days'] ?? 30)),
                'min_notice_hours' => max(0, (int)($input['min_notice_hours'] ?? 24)),
                'slot_interval_minutes' => max(5, (int)($input['slot_interval_minutes'] ?? 30)),
            ];
            $cm->saveSection('app', $app);
            jsonResponse(['success' => true]);
            break;

        // ==================== Organizer ====================
        case 'save-organizer':
            requirePost($method);
            $input = getJsonInput();
            $organizer = [
                'name' => trim($input['name'] ?? ''),
                'email' => trim($input['email'] ?? ''),
            ];
            $cm->saveSection('organizer', $organizer);
            jsonResponse(['success' => true]);
            break;

        // ==================== Arbeitszeiten ====================
        case 'save-working-hours':
            requirePost($method);
            $input = getJsonInput();
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $hours = [];
            foreach ($days as $day) {
                if (isset($input[$day]) && !empty($input[$day]['enabled'])) {
                    $hours[$day] = [
                        'start' => $input[$day]['start'] ?? '09:00',
                        'end' => $input[$day]['end'] ?? '17:00',
                    ];
                } else {
                    $hours[$day] = null;
                }
            }
            $cm->saveSection('working_hours', $hours);

            // Pausenzeit speichern
            $breakTime = [
                'enabled' => !empty($input['break_time']['enabled']),
                'start' => $input['break_time']['start'] ?? '12:00',
                'end' => $input['break_time']['end'] ?? '13:00',
            ];
            $cm->saveSection('break_time', $breakTime);

            jsonResponse(['success' => true]);
            break;

        // ==================== Teams ====================
        case 'save-teams':
            requirePost($method);
            $input = getJsonInput();
            $teams = [
                'enabled' => !empty($input['enabled']),
                'source_id' => trim($input['source_id'] ?? ''),
            ];
            $cm->saveSection('teams', $teams);
            jsonResponse(['success' => true]);
            break;

        // ==================== Buchungsformular ====================
        case 'save-booking-form':
            requirePost($method);
            $input = getJsonInput();
            $fields = [];
            if (isset($input['additional_fields']) && is_array($input['additional_fields'])) {
                foreach ($input['additional_fields'] as $f) {
                    $name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($f['name'] ?? '')));
                    if (empty($name)) continue;
                    $fields[] = [
                        'name' => $name,
                        'label' => trim($f['label'] ?? $name),
                        'type' => in_array($f['type'] ?? 'text', ['text', 'email', 'tel', 'url', 'number', 'textarea', 'select']) ? $f['type'] : 'text',
                        'required' => !empty($f['required']),
                        'placeholder' => trim($f['placeholder'] ?? ''),
                        'options' => $f['options'] ?? [],
                    ];
                }
            }
            $form = [
                'additional_fields' => $fields,
                'allow_additional_attendees' => !empty($input['allow_additional_attendees']),
                'max_additional_attendees' => max(0, min(MAX_ADDITIONAL_ATTENDEES, (int)($input['max_additional_attendees'] ?? 5))),
            ];
            $cm->saveSection('booking_form', $form);
            jsonResponse(['success' => true]);
            break;

        // ==================== Kalender-Quellen ====================
        case 'save-calendar-source':
            requirePost($method);
            $input = getJsonInput();
            $sources = $cm->getSection('calendar_sources') ?: [];

            $id = trim($input['id'] ?? '');
            $isNew = empty($id);

            if ($isNew) {
                $id = strtolower(trim($input['type'] ?? 'ms')) . '-' . bin2hex(random_bytes(4));
            }

            // Kalender-IDs parsen (kommasepariert)
            $calendars = array_filter(array_map('trim', explode(',', $input['calendars'] ?? 'primary')));
            if (empty($calendars)) $calendars = ['primary'];

            $newSource = [
                'type' => in_array($input['type'] ?? '', ['microsoft', 'google']) ? $input['type'] : 'microsoft',
                'id' => $id,
                'label' => trim($input['label'] ?? ''),
                'client_id' => trim($input['client_id'] ?? ''),
                'tenant_id' => trim($input['tenant_id'] ?? 'common'),
                'redirect_uri' => trim($input['redirect_uri'] ?? ''),
                'calendars' => $calendars,
                'is_booking_target' => !empty($input['is_booking_target']),
            ];

            // Client Secret: nur setzen wenn übermittelt (nicht-leer)
            if (!empty($input['client_secret'])) {
                $newSource['client_secret'] = $input['client_secret'];
            }

            // Existing source aktualisieren oder neu hinzufügen
            $found = false;
            foreach ($sources as $i => $src) {
                if ($src['id'] === $id) {
                    // Client Secret beibehalten wenn nicht neu gesetzt
                    if (empty($newSource['client_secret']) && !empty($src['client_secret'])) {
                        $newSource['client_secret'] = $src['client_secret'];
                    }
                    $sources[$i] = $newSource;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                if (empty($newSource['client_secret'])) {
                    $newSource['client_secret'] = '';
                }
                $sources[] = $newSource;
            }

            // Wenn is_booking_target, andere Sources deaktivieren
            if ($newSource['is_booking_target']) {
                foreach ($sources as $i => $src) {
                    if ($src['id'] !== $id) {
                        $sources[$i]['is_booking_target'] = false;
                    }
                }
            }

            $cm->saveSection('calendar_sources', $sources);
            jsonResponse(['success' => true, 'id' => $id]);
            break;

        case 'delete-calendar-source':
            requirePost($method);
            $input = getJsonInput();
            $deleteId = $input['id'] ?? '';
            $sources = $cm->getSection('calendar_sources') ?: [];
            $sources = array_values(array_filter($sources, fn($s) => $s['id'] !== $deleteId));
            $cm->saveSection('calendar_sources', $sources);

            // Token auch entfernen
            $config = $cm->getAppConfig();
            $tokenStore = new TokenStore($config['token_store']['path']);
            $tokenStore->remove($deleteId);

            jsonResponse(['success' => true]);
            break;

        case 'disconnect-calendar':
            requirePost($method);
            $input = getJsonInput();
            $disconnectId = $input['id'] ?? '';
            $config = $cm->getAppConfig();
            $tokenStore = new TokenStore($config['token_store']['path']);
            $tokenStore->remove($disconnectId);
            jsonResponse(['success' => true]);
            break;

        // ==================== Kalender-Verbindungsstatus ====================
        case 'calendar-status':
            if ($method !== 'GET') jsonResponse(['error' => 'GET erwartet'], 405);
            $config = $cm->getAppConfig();
            $tokenStore = new TokenStore($config['token_store']['path']);
            $status = [];
            foreach ($config['calendar_sources'] as $src) {
                $status[$src['id']] = $tokenStore->has($src['id']);
            }
            jsonResponse(['status' => $status]);
            break;

        // ==================== Seitendesign ====================
        case 'save-page-design':
            requirePost($method);
            $input = getJsonInput();
            $design = $cm->getSection('page_design') ?: [];
            $design['welcome_title'] = trim($input['welcome_title'] ?? '');
            $design['welcome_text'] = trim($input['welcome_text'] ?? '');
            $design['booking_info'] = trim($input['booking_info'] ?? '');
            $cm->saveSection('page_design', $design);
            jsonResponse(['success' => true]);
            break;

        case 'upload-image':
            requirePost($method);
            $field = $_POST['field'] ?? '';
            if (!in_array($field, ['header_image', 'profile_image'])) {
                jsonResponse(['error' => 'Ungültiges Feld'], 400);
            }
            if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(['error' => 'Kein Bild hochgeladen'], 400);
            }
            $file = $_FILES['image'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed)) {
                jsonResponse(['error' => 'Nur JPG, PNG, WebP und GIF erlaubt'], 400);
            }
            if ($file['size'] > MAX_UPLOAD_SIZE_BYTES) {
                jsonResponse(['error' => 'Maximale Dateigröße: 5 MB'], 400);
            }
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };
            $filename = $field . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = dirname(__DIR__) . '/assets/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0750, true);
                // PHP-Ausfuehrung im Upload-Verzeichnis verhindern
                file_put_contents(
                    $uploadDir . '.htaccess',
                    "# Keine PHP-Ausfuehrung in diesem Verzeichnis\nphp_flag engine off\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps)$\">\n    Deny from all\n</FilesMatch>\n"
                );
            }

            // Altes Bild sicher loeschen (Path-Traversal-Schutz)
            $design = $cm->getSection('page_design') ?: [];
            $oldPath = $design[$field] ?? '';
            safeDeleteUpload($oldPath);

            $destPath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonResponse(['error' => 'Upload fehlgeschlagen'], 500);
            }

            $relativePath = 'assets/uploads/' . $filename;
            $design[$field] = $relativePath;
            $cm->saveSection('page_design', $design);
            jsonResponse(['success' => true, 'path' => $relativePath]);
            break;

        case 'delete-image':
            requirePost($method);
            $input = getJsonInput();
            $field = $input['field'] ?? '';
            if (!in_array($field, ['header_image', 'profile_image'])) {
                jsonResponse(['error' => 'Ungültiges Feld'], 400);
            }
            $design = $cm->getSection('page_design') ?: [];
            $oldPath = $design[$field] ?? '';
            safeDeleteUpload($oldPath);
            $design[$field] = '';
            $cm->saveSection('page_design', $design);
            jsonResponse(['success' => true]);
            break;

        // ==================== Admin-Passwort ====================
        case 'save-password':
            requirePost($method);
            $input = getJsonInput();
            $newPassword = $input['new_password'] ?? '';
            $currentPassword = $input['current_password'] ?? '';

            if ($cm->hasAdminPassword() && !$cm->verifyAdminPassword($currentPassword)) {
                jsonResponse(['error' => 'Aktuelles Passwort ist falsch'], 403);
            }

            if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
                jsonResponse(['error' => 'Passwort muss mindestens 12 Zeichen lang sein'], 422);
            }

            $cm->setAdminPassword($newPassword);
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['error' => 'Unbekannte Aktion: ' . $action], 400);
    }
} catch (\Throwable $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Interner Fehler: ' . $e->getMessage()], 500);
}

// ==================== Hilfsfunktionen ====================

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requirePost(string $method): void
{
    if ($method !== 'POST') {
        jsonResponse(['error' => 'POST erwartet'], 405);
    }
}

function getJsonInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['error' => 'Ungültige JSON-Daten'], 400);
    }
    return $input;
}

/**
 * Loescht eine Bild-Datei sicher – nur wenn der Pfad innerhalb von assets/uploads/ liegt.
 * Verhindert Path-Traversal-Angriffe.
 */
function safeDeleteUpload(string $relativePath): void
{
    if (empty($relativePath)) {
        return;
    }

    $baseDir = realpath(dirname(__DIR__) . '/assets/uploads');
    if ($baseDir === false) {
        return; // Upload-Verzeichnis existiert nicht
    }

    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    $realPath = realpath($fullPath);

    // Nur loeschen wenn die Datei existiert UND innerhalb des Upload-Verzeichnisses liegt
    if ($realPath !== false && str_starts_with($realPath, $baseDir . DIRECTORY_SEPARATOR)) {
        unlink($realPath);
    }
}
