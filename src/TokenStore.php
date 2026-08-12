<?php

/**
 * Speichert und laedt OAuth-Tokens aus einer JSON-Datei.
 * Thread-safe durch File-Locking bei Lese- und Schreiboperationen.
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

    public function set(string $sourceId, array $tokenData): void
    {
        // Vor dem Schreiben neu laden um Race-Conditions zu minimieren
        $this->tokens = $this->loadWithLock();
        $this->tokens[$sourceId] = $tokenData;
        $this->save();
    }

    public function remove(string $sourceId): void
    {
        $this->tokens = $this->loadWithLock();
        unset($this->tokens[$sourceId]);
        $this->save();
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
    public function markRefreshFailed(string $sourceId, string $reason): void
    {
        $this->tokens = $this->loadWithLock();
        if (!isset($this->tokens[$sourceId])) {
            return; // Quelle wurde zwischenzeitlich getrennt
        }
        $this->tokens[$sourceId]['refresh_failed_at'] = time();
        $this->tokens[$sourceId]['refresh_failed_reason'] = $reason;
        $this->save();
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
     * Liest Token-Datei mit Shared-Lock (verhindert korrupte Reads waehrend eines Writes).
     */
    private function loadWithLock(): array
    {
        if (!file_exists($this->path)) {
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
     * Speichert Tokens atomar mit exklusivem Lock.
     */
    private function save(): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        chmod($this->path, 0600);
    }
}
