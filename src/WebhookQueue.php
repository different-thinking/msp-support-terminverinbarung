<?php

/**
 * Dateibasierte Webhook-Queue mit Retry-Logik.
 *
 * Aufrufmuster:
 * - dispatch(): synchroner erster POST; nur bei Fehlschlag wird ein
 *   Job in pending/ angelegt. So sind erfolgreiche Zustellungen
 *   weiterhin "frei" und der User merkt den Webhook am Buchungs-Request.
 * - processBatch(): arbeitet faellige Retry-Jobs ab (probabilistisch aus
 *   api/slots.php heraus oder per Cron ueber api/webhook-worker.php).
 *
 * Backoff-Schedule (10 Versuche, verteilt ueber 24h):
 *   1min, 5min, 15min, 30min, 1h, 2h, 4h, 8h, 12h, 24h
 */
class WebhookQueue
{
    public const BUCKET_PENDING = 'pending';
    public const BUCKET_DONE = 'done';
    public const BUCKET_FAILED = 'failed';
    public const BUCKETS = [self::BUCKET_PENDING, self::BUCKET_DONE, self::BUCKET_FAILED];

    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600, 7200, 14400, 28800, 43200, 86400];
    private const MAX_ATTEMPTS = 10;
    private const MAX_BATCH = 10;
    private const HTTP_TIMEOUT_SECONDS = 10;
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;
    private const DONE_KEEP_COUNT = 100;
    private const FAILED_KEEP_COUNT = 200;

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?: dirname(__DIR__) . '/data/webhook-queue';
        $this->ensureDirs();
    }

    /**
     * Versucht einen Webhook synchron zuzustellen. Bei Erfolg ist nichts
     * weiter zu tun; bei Fehlschlag wird ein Retry-Job in pending/ abgelegt
     * (attempts=1, naechster Versuch nach Backoff).
     *
     * @param array $funnel  Funnel-Definition (slug, webhook_url, webhook_secret, ...)
     * @param array $payload JSON-serialisierbares Payload
     * @return array ['delivered' => bool, 'enqueued' => bool, 'status_code' => int, 'error' => string]
     */
    public function dispatch(array $funnel, array $payload): array
    {
        $id = $this->generateId();
        $url = (string)($funnel['webhook_url'] ?? '');
        $secret = (string)($funnel['webhook_secret'] ?? '');

        $payloadForSend = $payload;
        $payloadForSend['delivery_id'] = $id;
        $payloadForSend['attempt'] = 1;

        $result = $this->httpPost($url, $payloadForSend, $secret);

        if ($result['success']) {
            return [
                'delivered' => true,
                'enqueued' => false,
                'status_code' => $result['status_code'],
                'error' => '',
            ];
        }

        $now = time();
        $job = [
            'id' => $id,
            'funnel_slug' => (string)($funnel['slug'] ?? ''),
            'target_url' => $url,
            'secret' => $secret,
            'payload' => $payload,
            'attempts' => 1,
            'next_run_at' => $now + self::BACKOFF_SECONDS[0],
            'first_queued_at' => $now,
            'last_attempt_at' => $now,
            'last_status_code' => $result['status_code'],
            'last_error' => $result['error'] ?: '',
        ];
        $this->writeJob($this->baseDir . '/' . self::BUCKET_PENDING . '/' . $id . '.json', $job);

        return [
            'delivered' => false,
            'enqueued' => true,
            'status_code' => $result['status_code'],
            'error' => $result['error'],
        ];
    }

    /**
     * Verarbeitet faellige Jobs aus der Queue.
     * Lock verhindert paralleles Bearbeiten durch mehrere Requests.
     *
     * @return array ['processed' => int, 'succeeded' => int, 'requeued' => int, 'failed' => int]
     */
    public function processBatch(): array
    {
        $lockFile = $this->baseDir . '/.lock';
        $lockHandle = fopen($lockFile, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle !== false) fclose($lockHandle);
            return ['processed' => 0, 'succeeded' => 0, 'requeued' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'succeeded' => 0, 'requeued' => 0, 'failed' => 0];

        try {
            foreach ($this->loadDueJobs() as $job) {
                $stats['processed']++;
                $result = $this->deliver($job);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            }
            $this->prune(self::BUCKET_DONE, self::DONE_KEEP_COUNT);
            $this->prune(self::BUCKET_FAILED, self::FAILED_KEEP_COUNT);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $stats;
    }

    public function list(string $bucket, int $limit = 50): array
    {
        if (!in_array($bucket, self::BUCKETS, true)) return [];
        $dir = $this->baseDir . '/' . $bucket;
        if (!is_dir($dir)) return [];

        $files = glob($dir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $files = array_slice($files, 0, $limit);

        $jobs = [];
        foreach ($files as $f) {
            $data = json_decode((string)file_get_contents($f), true);
            if (is_array($data)) {
                $data['bucket'] = $bucket;
                $jobs[] = $data;
            }
        }
        return $jobs;
    }

    /**
     * Test-Versand: macht denselben HTTP-POST wie dispatch(), aber persistiert
     * nichts. Sicher fuer "Test senden"-Buttons im Admin-UI.
     */
    public function testSend(array $funnel, array $payload): array
    {
        $payload['delivery_id'] = $this->generateId();
        $payload['attempt'] = 1;
        return $this->httpPost(
            (string)($funnel['webhook_url'] ?? ''),
            $payload,
            (string)($funnel['webhook_secret'] ?? '')
        );
    }

    /** Verschiebt einen Job aus failed/ zurueck nach pending/ mit attempts=0. */
    public function retry(string $jobId): bool
    {
        $src = $this->baseDir . '/' . self::BUCKET_FAILED . '/' . basename($jobId) . '.json';
        if (!is_file($src)) return false;

        $job = json_decode((string)file_get_contents($src), true);
        if (!is_array($job)) return false;

        $job['attempts'] = 0;
        $job['next_run_at'] = time();
        $job['last_error'] = '';

        $this->writeJob($this->baseDir . '/' . self::BUCKET_PENDING . '/' . $job['id'] . '.json', $job);
        @unlink($src);
        return true;
    }

    public function delete(string $jobId, string $bucket): bool
    {
        if (!in_array($bucket, self::BUCKETS, true)) return false;
        $path = $this->baseDir . '/' . $bucket . '/' . basename($jobId) . '.json';
        if (!is_file($path)) return false;
        return @unlink($path);
    }

    public function counts(): array
    {
        $out = [];
        foreach (self::BUCKETS as $b) $out[$b] = $this->countBucket($b);
        return $out;
    }

    // ==================== Intern ====================

    private function loadDueJobs(): array
    {
        $dir = $this->baseDir . '/' . self::BUCKET_PENDING;
        $files = glob($dir . '/*.json') ?: [];

        $now = time();
        $due = [];
        foreach ($files as $f) {
            $data = json_decode((string)file_get_contents($f), true);
            if (!is_array($data) || empty($data['id'])) continue;
            if (($data['next_run_at'] ?? 0) > $now) continue;
            $due[] = $data;
        }

        usort($due, fn($a, $b) => ($a['next_run_at'] ?? 0) <=> ($b['next_run_at'] ?? 0));
        return array_slice($due, 0, self::MAX_BATCH);
    }

    /** @return string 'succeeded' | 'requeued' | 'failed' */
    private function deliver(array $job): string
    {
        $id = $job['id'];
        $attempt = (int)$job['attempts'] + 1;

        $payloadForSend = $job['payload'];
        $payloadForSend['delivery_id'] = $id;
        $payloadForSend['attempt'] = $attempt;

        $result = $this->httpPost($job['target_url'], $payloadForSend, (string)($job['secret'] ?? ''));

        $job['attempts'] = $attempt;
        $job['last_attempt_at'] = time();
        $job['last_status_code'] = $result['status_code'];
        $job['last_error'] = $result['error'];

        $pendingPath = $this->baseDir . '/' . self::BUCKET_PENDING . '/' . $id . '.json';

        if ($result['success']) {
            $job['delivered_at'] = time();
            $this->writeJob($this->baseDir . '/' . self::BUCKET_DONE . '/' . $id . '.json', $job);
            @unlink($pendingPath);
            return 'succeeded';
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->writeJob($this->baseDir . '/' . self::BUCKET_FAILED . '/' . $id . '.json', $job);
            @unlink($pendingPath);
            error_log("WebhookQueue: job {$id} failed permanently after {$attempt} attempts (funnel={$job['funnel_slug']}, status={$result['status_code']}, err={$result['error']})");
            return 'failed';
        }

        $schedule = self::BACKOFF_SECONDS;
        $delay = $schedule[$attempt - 1] ?? end($schedule);
        $job['next_run_at'] = time() + $delay;
        $this->writeJob($pendingPath, $job);
        return 'requeued';
    }

    private function httpPost(string $url, array $payload, string $secret): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: MSP-Terminbuchung-FunnelWebhook/1.0',
        ];
        if ($secret !== '') {
            $headers[] = 'X-Funnel-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'success' => $status >= 200 && $status < 300,
            'status_code' => $status,
            'response' => is_string($response) ? mb_substr($response, 0, 500) : '',
            'error' => $error,
        ];
    }

    private function writeJob(string $path, array $job): void
    {
        file_put_contents(
            $path,
            json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($path, 0600);
    }

    private function prune(string $bucket, int $keep): void
    {
        $dir = $this->baseDir . '/' . $bucket;
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) <= $keep) return;
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, $keep) as $f) @unlink($f);
    }

    private function countBucket(string $bucket): int
    {
        $dir = $this->baseDir . '/' . $bucket;
        if (!is_dir($dir)) return 0;
        return count(glob($dir . '/*.json') ?: []);
    }

    private function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function ensureDirs(): void
    {
        if (!is_dir($this->baseDir)) @mkdir($this->baseDir, 0700, true);
        foreach (self::BUCKETS as $b) {
            $dir = $this->baseDir . '/' . $b;
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
        }
    }
}
