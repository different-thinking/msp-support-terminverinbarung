<?php

/**
 * Funnel-Verwaltung: Allowlist + Lookup von Funnel-Definitionen.
 *
 * Funnels werden in config.json unter 'funnels' gespeichert. Jeder Funnel
 * hat einen Slug (URL-Parameter), einen Anzeigenamen, eine Webhook-URL
 * (POST nach Buchung) und ein optionales HMAC-Secret.
 *
 * Schema:
 *   {
 *     "slug": "webinar-a",
 *     "name": "Webinar A – Mai 2026",
 *     "webhook_url": "https://hooks.example.com/abc",
 *     "webhook_secret": "optional",
 *     "enabled": true
 *   }
 */
class FunnelManager
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,49}$/';
    public const SLUG_MAX_LENGTH = 50;

    /**
     * Normalisiert und validiert einen Slug-String.
     * Liefert den getrimmten/lowercased Slug oder null bei Ungueltigkeit.
     */
    public static function normalizeSlug(?string $raw): ?string
    {
        if ($raw === null) return null;
        $slug = strtolower(trim($raw));
        if ($slug === '') return null;
        if (!preg_match(self::SLUG_PATTERN, $slug)) return null;
        return $slug;
    }

    /**
     * Sucht einen aktiven Funnel anhand des Slugs in der Funnel-Liste.
     */
    public static function findActive(array $funnels, string $slug): ?array
    {
        foreach ($funnels as $f) {
            if (!is_array($f)) continue;
            if (empty($f['enabled'])) continue;
            if (($f['slug'] ?? '') === $slug) return $f;
        }
        return null;
    }

    /**
     * Validiert einen einzelnen Funnel-Datensatz.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateOne(array $funnel): void
    {
        $slug = self::normalizeSlug($funnel['slug'] ?? '');
        if ($slug === null) {
            throw new \InvalidArgumentException('Slug ungueltig (Kleinbuchstaben, Ziffern, Bindestrich; 1-50 Zeichen, Start alphanumerisch).');
        }
        $name = trim((string)($funnel['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name darf nicht leer sein.');
        }
        $url = trim((string)($funnel['webhook_url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Webhook-URL ist keine gueltige URL.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Webhook-URL muss mit http:// oder https:// beginnen.');
        }
    }

    /**
     * Normalisiert eine Liste von Funnels (von der Admin-API empfangen) zu
     * persistierbaren Datensaetzen. Duplikate (gleicher Slug) und ungueltige
     * Eintraege werfen InvalidArgumentException.
     */
    public static function normalizeList(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $raw) {
            if (!is_array($raw)) continue;
            self::validateOne($raw);
            $slug = self::normalizeSlug($raw['slug']);
            if (isset($seen[$slug])) {
                throw new \InvalidArgumentException("Slug '{$slug}' ist mehrfach vergeben.");
            }
            $seen[$slug] = true;
            $out[] = [
                'slug' => $slug,
                'name' => trim((string)$raw['name']),
                'webhook_url' => trim((string)$raw['webhook_url']),
                'webhook_secret' => trim((string)($raw['webhook_secret'] ?? '')),
                'enabled' => !empty($raw['enabled']),
            ];
        }
        return $out;
    }
}
