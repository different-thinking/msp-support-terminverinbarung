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
     */
    public function markRefreshFailed(string $sourceId, string $reason): bool
    {
        $this->tokens = $this->loadWithLock();
        if (!isset($this->tokens[$sourceId])) {
            return false; // Quelle wurde zwischenzeitlich getrennt
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
     * Schreibt die Tokens atomar: erst vollstaendig in eine temporaere Datei,
     * dann per rename() an ihren Platz. Ein abgebrochener Schreibvorgang kann
     * die bestehende Datei damit nicht mehr beschaedigen – Leser sehen immer
     * entweder den alten oder den neuen Stand, nie einen halben.
     *
     * Jeder Schritt wird geprueft. Schlaegt einer fehl (fehlende Schreibrechte,
     * volle Platte), wird das protokolliert und false zurueckgegeben, statt den
     * Verlust still hinzunehmen.
     */
    private function save(): bool
    {
        $json = json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            SecurityHelper::logError('TokenStore', 'Tokens nicht serialisierbar: ' . json_last_error_msg());
            return false;
        }

        // Die temporaere Datei muss im Zielverzeichnis liegen: rename() ist nur
        // innerhalb desselben Dateisystems atomar.
        $dir = dirname($this->path);
        $tmp = @tempnam($dir, '.tokens');
        if ($tmp === false) {
            SecurityHelper::logError(
                'TokenStore',
                'Keine temporaere Datei in ' . $dir . ' anlegbar – Schreibrechte des Verzeichnisses pruefen.'
            );
            return false;
        }

        // tempnam() weicht auf das System-Temp-Verzeichnis aus, wenn $dir nicht
        // beschreibbar ist. Dort haetten die Tokens nichts zu suchen, und
        // rename() waere ueber Dateisystemgrenzen hinweg nicht mehr atomar.
        $tmpDir = realpath(dirname($tmp));
        $targetDir = realpath($dir);
        if ($tmpDir === false || $targetDir === false || $tmpDir !== $targetDir) {
            @unlink($tmp);
            SecurityHelper::logError(
                'TokenStore',
                'Verzeichnis ' . $dir . ' ist nicht beschreibbar – Rechte pruefen.'
            );
            return false;
        }

        // Rechte setzen, bevor Tokens in der Datei stehen.
        @chmod($tmp, 0600);

        if (!$this->writeFileDurably($tmp, $json)) {
            @unlink($tmp);
            SecurityHelper::logError(
                'TokenStore',
                'Tokens konnten nicht nach ' . $tmp . ' geschrieben werden – Plattenplatz pruefen.'
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
     * Schreibt den Inhalt vollstaendig und gibt ihn an die Platte weiter.
     * Ohne das Durchreichen haette rename() zwar Erfolg, der Inhalt koennte bei
     * einem Absturz aber noch im Cache stehen – und ein rotiertes Refresh-Token
     * ist nicht wiederherstellbar.
     */
    private function writeFileDurably(string $path, string $content): bool
    {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            return false;
        }

        $written = @fwrite($fh, $content);
        $complete = ($written === strlen($content)) && @fflush($fh);

        // fsync() gibt es erst ab PHP 8.1.
        if ($complete && function_exists('fsync')) {
            $complete = @fsync($fh);
        }

        fclose($fh);
        return $complete;
    }
}
