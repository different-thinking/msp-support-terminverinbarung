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
