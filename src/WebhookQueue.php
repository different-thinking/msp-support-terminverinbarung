<?php

/**
 * Dateibasierte Webhook-Queue mit Retry-Logik.
 *
 * Jobs werden als JSON-Dateien in data/webhook-queue/pending/ abgelegt.
 * Bei erfolgreicher Zustellung wandern sie nach done/, bei Aufgabe nach failed/.
 *
 * Backoff-Schedule (10 Versuche, ueber 24h verteilt):
 *   1min, 5min, 15min, 30min, 1h, 2h, 4h, 8h, 12h, 24h
 *
 * Der Worker wird probabilistisch aus api/book.php und api/slots.php heraus
 * gestartet (kein Cron noetig). Zusaetzlich gibt es api/webhook-worker.php
 * fuer optionalen Cron-Betrieb.
 */
class WebhookQueue
{
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
     * Fuegt einen neuen Job in die pending/-Queue ein.
     *
     * @param string $funnelSlug
     * @param string $targetUrl
     * @param string $secret  Optional, fuer HMAC-Signatur
     * @param array  $payload Beliebiges JSON-serialisierbares Payload
     * @return string Job-ID
     */
    public function enqueue(string $funnelSlug, string $targetUrl, string $secret, array $payload): string
    {
        $id = $this->generateId();
        $now = time();

        $job = [
            'id' => $id,
            'funnel_slug' => $funnelSlug,
            'target_url' => $targetUrl,
            'secret' => $secret,
            'payload' => $payload,
            'attempts' => 0,
            'next_run_at' => $now,
            'first_queued_at' => $now,
            'last_attempt_at' => null,
            'last_status_code' => null,
            'last_error' => '',
        ];

        $this->writeJob($this->baseDir . '/pending/' . $id . '.json', $job);
        return $id;
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
        if ($lockHandle === false) {
            return ['processed' => 0, 'succeeded' => 0, 'requeued' => 0, 'failed' => 0];
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return ['processed' => 0, 'succeeded' => 0, 'requeued' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'succeeded' => 0, 'requeued' => 0, 'failed' => 0];

        try {
            $jobs = $this->loadDueJobs();
            foreach ($jobs as $job) {
                $stats['processed']++;
                $result = $this->deliver($job);
                if ($result === 'succeeded') {
                    $stats['succeeded']++;
                } elseif ($result === 'failed') {
                    $stats['failed']++;
                } else {
                    $stats['requeued']++;
                }
            }
            $this->prune($this->baseDir . '/done', self::DONE_KEEP_COUNT);
            $this->prune($this->baseDir . '/failed', self::FAILED_KEEP_COUNT);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $stats;
    }

    /**
     * Listet Jobs aus einem Bucket (pending/done/failed).
     *
     * @param string $bucket 'pending' | 'done' | 'failed'
     * @param int    $limit
     */
    public function list(string $bucket, int $limit = 50): array
    {
        if (!in_array($bucket, ['pending', 'done', 'failed'], true)) {
            return [];
        }
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
     * Verschiebt einen Job aus failed/ zurueck nach pending/ mit attempts=0.
     */
    public function retry(string $jobId): bool
    {
        $src = $this->baseDir . '/failed/' . basename($jobId) . '.json';
        if (!is_file($src)) return false;

        $job = json_decode((string)file_get_contents($src), true);
        if (!is_array($job)) return false;

        $job['attempts'] = 0;
        $job['next_run_at'] = time();
        $job['last_error'] = '';

        $this->writeJob($this->baseDir . '/pending/' . $job['id'] . '.json', $job);
        @unlink($src);
        return true;
    }

    /**
     * Loescht einen Job aus einem beliebigen Bucket.
     */
    public function delete(string $jobId, string $bucket): bool
    {
        if (!in_array($bucket, ['pending', 'done', 'failed'], true)) return false;
        $path = $this->baseDir . '/' . $bucket . '/' . basename($jobId) . '.json';
        if (!is_file($path)) return false;
        return @unlink($path);
    }

    /**
     * Anzahl Jobs in jedem Bucket – fuer Admin-UI Counts.
     */
    public function counts(): array
    {
        return [
            'pending' => $this->countBucket('pending'),
            'done' => $this->countBucket('done'),
            'failed' => $this->countBucket('failed'),
        ];
    }

    // ==================== Intern ====================

    private function loadDueJobs(): array
    {
        $dir = $this->baseDir . '/pending';
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

    /**
     * Versucht eine Zustellung. Verschiebt den Job entsprechend.
     * @return string 'succeeded' | 'requeued' | 'failed'
     */
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

        $pendingPath = $this->baseDir . '/pending/' . $id . '.json';

        if ($result['success']) {
            $job['delivered_at'] = time();
            $this->writeJob($this->baseDir . '/done/' . $id . '.json', $job);
            @unlink($pendingPath);
            return 'succeeded';
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->writeJob($this->baseDir . '/failed/' . $id . '.json', $job);
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

    private function prune(string $dir, int $keep): void
    {
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) <= $keep) return;
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $toDelete = array_slice($files, $keep);
        foreach ($toDelete as $f) @unlink($f);
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
        foreach (['', '/pending', '/done', '/failed'] as $sub) {
            $dir = $this->baseDir . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
    }
}
