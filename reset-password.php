#!/usr/bin/env php
<?php
/**
 * Admin-Passwort zurücksetzen.
 *
 * Nutzung:
 *   php reset-password.php          → Setzt den Hash zurück, sodass beim nächsten
 *                                      Admin-Zugriff ein neues Passwort vergeben werden kann.
 *   php reset-password.php <pw>     → Setzt direkt ein neues Passwort.
 */

require_once __DIR__ . '/src/ConfigManager.php';

$cm = new ConfigManager();

if (!$cm->hasAdminPassword()) {
    echo "Es ist kein Admin-Passwort gesetzt. Beim nächsten Aufruf des Admin-Panels wird die Ersteinrichtung angezeigt.\n";
    exit(0);
}

if (isset($argv[1])) {
    $newPw = $argv[1];
    if (strlen($newPw) < 12) {
        echo "Fehler: Das Passwort muss mindestens 12 Zeichen lang sein.\n";
        exit(1);
    }
    $cm->setAdminPassword($newPw);
    echo "Admin-Passwort wurde erfolgreich geändert.\n";
} else {
    // Passwort-Hash entfernen → löst Ersteinrichtung aus
    $cm->saveSection('admin', ['password_hash' => '']);
    echo "Admin-Passwort wurde zurückgesetzt.\n";
    echo "Beim nächsten Aufruf des Admin-Panels können Sie ein neues Passwort vergeben.\n";
}
