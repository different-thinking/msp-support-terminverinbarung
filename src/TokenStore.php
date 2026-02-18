<?php

/**
 * Speichert und lädt OAuth-Tokens aus einer JSON-Datei.
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

        if (file_exists($path)) {
            $data = file_get_contents($path);
            $this->tokens = json_decode($data, true) ?: [];
        }
    }

    public function get(string $sourceId): ?array
    {
        return $this->tokens[$sourceId] ?? null;
    }

    public function set(string $sourceId, array $tokenData): void
    {
        $this->tokens[$sourceId] = $tokenData;
        $this->save();
    }

    public function remove(string $sourceId): void
    {
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
