<?php
/**
 * Terminbuchungs-App Konfiguration
 *
 * Lädt die Konfiguration aus data/config.json (verwaltet über das Admin-Interface).
 * Falls die JSON-Datei nicht existiert, werden Standardwerte verwendet.
 */

require_once __DIR__ . '/src/ConfigManager.php';

$configManager = new ConfigManager();
return $configManager->getAppConfig();
