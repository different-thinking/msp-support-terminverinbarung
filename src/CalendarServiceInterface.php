<?php

/**
 * Interface fuer Kalender-Services (Microsoft 365 / Google Calendar).
 * Definiert den gemeinsamen Vertrag fuer Free/Busy-Abfragen und Authentifizierung.
 */
interface CalendarServiceInterface
{
    /**
     * Gibt die Source-ID des Kalender-Services zurueck.
     */
    public function getSourceId(): string;

    /**
     * Prueft ob der Service authentifiziert ist (gueltige Tokens vorhanden).
     */
    public function isAuthenticated(): bool;

    /**
     * Gibt die OAuth-Autorisierungs-URL zurueck.
     */
    public function getAuthUrl(string $state = ''): string;

    /**
     * Verarbeitet den OAuth-Callback und speichert die Tokens.
     */
    public function handleCallback(string $code): bool;

    /**
     * Gibt Free/Busy-Daten fuer einen Zeitraum zurueck.
     *
     * @return array Liste von Busy-Zeitraeumen [['start' => DateTime, 'end' => DateTime], ...]
     * @throws CalendarUnavailableException wenn die Daten nicht zuverlaessig
     *         abrufbar sind. Implementierungen duerfen in diesem Fall KEIN
     *         leeres Array liefern – das wuerde "alles frei" bedeuten.
     */
    public function getFreeBusy(\DateTimeInterface $start, \DateTimeInterface $end): array;

    /**
     * Bereitet curl-Handles fuer Free/Busy-Abfragen vor (fuer parallele Ausfuehrung).
     *
     * @return array [['handle' => CurlHandle], ...] – leer nur, wenn fuer die
     *         Quelle gar kein Kalender konfiguriert ist
     * @throws CalendarUnavailableException bei fehlender/abgelaufener Authentifizierung
     */
    public function prepareFreeBusyCurl(\DateTimeInterface $start, \DateTimeInterface $end): array;

    /**
     * Parsed eine Free/Busy-API-Response zu Busy-Slots.
     *
     * @return array [['start' => DateTime, 'end' => DateTime], ...]
     * @throws CalendarUnavailableException bei fehlerhafter oder unvollstaendiger Response
     */
    public function parseFreeBusyResponse(string $response): array;
}
