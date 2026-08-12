<?php

require_once __DIR__ . '/SecurityHelper.php';

/**
 * Speichert und laedt OAuth-Tokens aus einer JSON-Datei.
 * Thread-safe durch File-Locking bei Lese- und Schreiboperationen.
 *
 * Alle schreibenden Methoden geben zurueck, ob die Aenderung tatsaechlich auf
 * der Platte gelandet ist. Aufrufer muessen das auswerten: ein verlorenes
 * (rotiertes) Refresh-Token laesst sich nicht wiederherstellen, die Verbindung
 * muss dann neu aufgebaut werden.
 */
class TokenStore
{
    private string $path;
    private array $tokens;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->tokens = [];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $this->tokens = $this->loadWithLock();
    }

    public function get(string $sourceId): ?array
    {
        return $this->tokens[$sourceId] ?? null;
    }

    /**
     * @return bool true, wenn der Eintrag persistiert wurde. Bei false bleibt
     *              das Token nur im Speicher dieses Requests gueltig.
     */
    public function set(string $sourceId, array $tokenData): bool
    {
        // Vor dem Schreiben neu laden um Race-Conditions zu minimieren
        $this->tokens = $this->loadWithLock();
        $this->tokens[$sourceId] = $tokenData;
        return $this->save();
    }

    /**
     * @return bool true, wenn die Quelle auch auf der Platte entfernt wurde.
     *              Bei false liegen die Tokens weiterhin in der Datei.
     */
    public function remove(string $sourceId): bool
    {
        $this->tokens = $this->loadWithLock();
        unset($this->tokens[$sourceId]);
        return $this->save();
    }

    /**
     * Vermerkt, dass der Provider die Token-Erneuerung abgelehnt hat.
     *
     * Das Flag erlaubt es dem Admin, eine tote Verbindung anzuzeigen, ohne
     * dafuer selbst einen Refresh auszuloesen (der wuerde bei jedem Seiten-
     * aufruf einen Provider-Request und einen Token-Rotationsschreibvorgang
     * verursachen). Ein erfolgreicher Refresh setzt den Eintrag per set()
     * komplett neu und loescht das Flag damit automatisch.
     *
     * @return bool false nur, wenn der Vermerk nicht gespeichert werden konnte.
     *              Eine zwischenzeitlich getrennte Quelle ist kein Fehlschlag –
     *              dann gibt es schlicht nichts zu vermerken.
     */
    public function markRefreshFailed(string $sourceId, string $reason): bool
    {
        $this->tokens = $this->loadWithLock();
        if (!isset($this->tokens[$sourceId])) {
            return true; // Quelle wurde zwischenzeitlich getrennt
        }
        $this->tokens[$sourceId]['refresh_failed_at'] = time();
        $this->tokens[$sourceId]['refresh_failed_reason'] = $reason;
        return $this->save();
    }

    /**
     * Gibt den letzten Refresh-Fehler zurueck oder null, wenn keiner vorliegt.
     * Reine Leseoperation – kein Netzwerkzugriff.
     *
     * @return array{at: int, reason: string}|null
     */
    public function getRefreshFailure(string $sourceId): ?array
    {
        $entry = $this->tokens[$sourceId] ?? null;
        if (!$entry || empty($entry['refresh_failed_at'])) {
            return null;
        }

        return [
            'at' => (int)$entry['refresh_failed_at'],
            'reason' => (string)($entry['refresh_failed_reason'] ?? ''),
        ];
    }

    public function getAll(): array
    {
        return $this->tokens;
    }

    public function has(string $sourceId): bool
    {
        return isset($this->tokens[$sourceId]);
    }

    /**
     * Liest die Token-Datei mit Shared-Lock. Gegen halbe Schreibvorgaenge
     * schuetzt inzwischen save() selbst; der Lock haelt zusaetzlich Leser und
     * Schreiber auseinander, solange nach einem Deploy noch alte Prozesse
     * ohne atomares Schreiben laufen.
     */
    private function loadWithLock(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $fh = fopen($this->path, 'r');
        if ($fh === false) {
            return [];
        }

        flock($fh, LOCK_SH);
        $data = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($data === false || $data === '') {
            return [];
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Schreibt die Tokens und meldet zurueck, ob sie tatsaechlich auf der
     * Platte gelandet sind.
     *
     * Bevorzugt wird der atomare Weg ueber eine temporaere Datei plus rename().
     * Ist das Verzeichnis nicht beschreibbar – manche Installationen haerten
     * data/ ab und lassen nur die Token-Datei selbst schreibbar – wird direkt
     * in die vorhandene Datei geschrieben.
     */
    private function save(): bool
    {
        $json = json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            SecurityHelper::logError('TokenStore', 'Tokens nicht serialisierbar: ' . json_last_error_msg());
            return false;
        }

        $atomic = $this->saveAtomically($json);
        if ($atomic !== null) {
            return $atomic;
        }

        return $this->saveInPlace($json);
    }

    /**
     * Schreibt erst vollstaendig in eine temporaere Datei im Zielverzeichnis
     * und verschiebt sie dann per rename() an ihren Platz. Ein abgebrochener
     * Schreibvorgang kann die bestehende Datei damit nicht beschaedigen –
     * Leser sehen immer entweder den alten oder den neuen Stand, nie einen
     * halben.
     *
     * @return bool|null true/false bei Erfolg bzw. Fehlschlag; null, wenn im
     *                   Zielverzeichnis gar keine temporaere Datei angelegt
     *                   werden kann und der Aufrufer ausweichen muss.
     */
    private function saveAtomically(string $json): ?bool
    {
        // Die temporaere Datei muss im Zielverzeichnis liegen: rename() ist nur
        // innerhalb desselben Dateisystems atomar.
        $dir = dirname($this->path);
        $tmp = @tempnam($dir, '.tokens');
        if ($tmp === false) {
            return null;
        }

        // tempnam() weicht auf das System-Temp-Verzeichnis aus, wenn $dir nicht
        // beschreibbar ist. Dort haetten die Tokens nichts zu suchen, und
        // rename() waere ueber Dateisystemgrenzen hinweg nicht mehr atomar.
        $tmpDir = realpath(dirname($tmp));
        $targetDir = realpath($dir);
        if ($tmpDir === false || $targetDir === false || $tmpDir !== $targetDir) {
            @unlink($tmp);
            return null;
        }

        // Rechte setzen, bevor Tokens in der Datei stehen.
        @chmod($tmp, 0600);

        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            return null;
        }

        $written = $this->writeStream($fh, $json);
        fclose($fh);

        if (!$written) {
            @unlink($tmp);
            SecurityHelper::logError(
                'TokenStore',
                'Tokens konnten nicht vollstaendig geschrieben werden – Plattenplatz pruefen.'
            );
            return false;
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            SecurityHelper::logError(
                'TokenStore',
                'Tokens konnten nicht nach ' . $this->path . ' verschoben werden – Schreibrechte pruefen.'
            );
            return false;
        }

        @chmod($this->path, 0600);
        return true;
    }

    /**
     * Faellt auf einen Schreibvorgang direkt in die Zieldatei zurueck. Ohne die
     * Absturzsicherheit von rename(), aber mit derselben Erfolgspruefung – und
     * die einzige Moeglichkeit, wenn nur die Datei und nicht ihr Verzeichnis
     * beschreibbar ist.
     */
    private function saveInPlace(string $json): bool
    {
        // 'cb+' legt die Datei bei Bedarf an, kuerzt sie aber nicht, bevor der
        // exklusive Lock steht.
        $fh = @fopen($this->path, 'cb+');
        if ($fh === false) {
            SecurityHelper::logError(
                'TokenStore',
                'Weder ' . dirname($this->path) . ' noch ' . $this->path
                . ' sind beschreibbar – Rechte pruefen.'
            );
            return false;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            SecurityHelper::logError('TokenStore', 'Kein exklusiver Lock auf ' . $this->path . ' erhaltbar.');
            return false;
        }

        $written = @ftruncate($fh, 0) && @rewind($fh) && $this->writeStream($fh, $json);

        flock($fh, LOCK_UN);
        fclose($fh);

        if (!$written) {
            // Hier kann die Datei tatsaechlich beschaedigt zurueckbleiben – ohne
            // Verzeichnisrechte gibt es keinen atomaren Weg.
            SecurityHelper::logError(
                'TokenStore',
                'Schreibvorgang in ' . $this->path . ' abgebrochen – die Datei kann unvollstaendig sein. '
                . 'Plattenplatz pruefen und Kalenderverbindungen neu herstellen.'
            );
            return false;
        }

        @chmod($this->path, 0600);
        return true;
    }

    /**
     * Schreibt den Inhalt vollstaendig in einen offenen Stream.
     *
     * fsync() reicht die Daten zusaetzlich an die Platte durch, damit ein
     * rotiertes Refresh-Token einen Absturz ueberlebt. Schlaegt das fehl (nicht
     * jedes Dateisystem unterstuetzt es), gilt der Schreibvorgang trotzdem als
     * erfolgreich – die Daten stehen in der Datei, nur die Absturzsicherheit
     * fehlt. Ein Fehlschlag hier duerfte niemals einen gelungenen Schreibvorgang
     * verwerfen.
     */
    private function writeStream($handle, string $content): bool
    {
        $written = @fwrite($handle, $content);
        if ($written !== strlen($content) || !@fflush($handle)) {
            return false;
        }

        // fsync() gibt es erst ab PHP 8.1.
        if (function_exists('fsync')) {
            @fsync($handle);
        }

        return true;
    }
}
