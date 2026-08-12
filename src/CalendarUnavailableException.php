<?php

/**
 * Wird geworfen, wenn Free/Busy-Daten einer Kalender-Quelle nicht zuverlaessig
 * abgerufen werden koennen (abgelaufene Verbindung, API-Fehler, Netzwerkfehler).
 *
 * Wichtig: Diese Faelle duerfen NICHT als "keine Termine im Kalender" behandelt
 * werden – sonst wuerde die Webseite bereits vergebene Outlook-Zeiten als frei
 * anbieten (Fail-Open). Stattdessen wird die Verfuegbarkeit gar nicht angezeigt
 * bzw. eine Buchung abgelehnt (Fail-Closed).
 */
class CalendarUnavailableException extends \RuntimeException
{
    private string $sourceId;

    public function __construct(string $sourceId, string $message)
    {
        $this->sourceId = $sourceId;
        parent::__construct("Kalender-Quelle '{$sourceId}': {$message}");
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }
}
