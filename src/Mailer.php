<?php

/**
 * E-Mail-Versand mit ICS-Anhang.
 * Unterstützt SMTP (via fsockopen) und PHP mail().
 */
class Mailer
{
    private array $config;

    public function __construct(array $mailConfig)
    {
        $this->config = $mailConfig;
    }

    /**
     * Sendet eine Termineinladung per E-Mail.
     */
    public function sendInvitation(array $params): bool
    {
        $to = $params['to']; // ['email' => ..., 'name' => ...]
        $subject = $params['subject'];
        $bodyHtml = $params['body_html'];
        $icsContent = $params['ics'];

        $boundary = md5(uniqid(time()));
        $boundaryAlt = md5(uniqid(time() + 1));

        $headers = $this->buildHeaders($boundary);

        $body = $this->buildMimeBody($boundary, $boundaryAlt, $bodyHtml, $icsContent);

        if ($this->config['method'] === 'smtp') {
            return $this->sendSmtp($to['email'], $subject, $body, $headers);
        }

        // PHP mail() Fallback
        $headerStr = implode("\r\n", $headers);
        return mail($to['email'], $subject, $body, $headerStr);
    }

    private function buildHeaders(string $boundary): array
    {
        $fromName = $this->config['from_name'];
        $fromEmail = $this->config['from_email'];

        return [
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-Mailer: Terminbuchung/1.0',
        ];
    }

    private function buildMimeBody(string $boundary, string $boundaryAlt, string $bodyHtml, string $icsContent): string
    {
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

        // Plaintext-Version
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHtml));
        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($plainText) . "\r\n";

        // HTML-Version
        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($bodyHtml) . "\r\n";

        // ICS als text/calendar (damit Outlook es als Einladung erkennt)
        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/calendar; charset=UTF-8; method=REQUEST\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($icsContent)) . "\r\n";

        $body .= "--{$boundaryAlt}--\r\n";

        // ICS als Dateianhang
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/ics; name=\"einladung.ics\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"einladung.ics\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($icsContent)) . "\r\n";

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    private function sendSmtp(string $to, string $subject, string $body, array $headers): bool
    {
        $smtp = $this->config['smtp'];
        $host = $smtp['host'];
        $port = $smtp['port'];
        $encryption = $smtp['encryption'] ?? '';

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $fp = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);

        if (!$fp) {
            error_log("SMTP connection failed: {$errstr} ({$errno})");
            return false;
        }

        $this->smtpRead($fp);

        // EHLO
        $this->smtpWrite($fp, "EHLO " . gethostname());
        $this->smtpRead($fp);

        // STARTTLS wenn nötig
        if ($encryption === 'tls') {
            $this->smtpWrite($fp, "STARTTLS");
            $this->smtpRead($fp);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpWrite($fp, "EHLO " . gethostname());
            $this->smtpRead($fp);
        }

        // Authentifizierung
        if (!empty($smtp['username'])) {
            $this->smtpWrite($fp, "AUTH LOGIN");
            $this->smtpRead($fp);
            $this->smtpWrite($fp, base64_encode($smtp['username']));
            $this->smtpRead($fp);
            $this->smtpWrite($fp, base64_encode($smtp['password']));
            $response = $this->smtpRead($fp);
            if (strpos($response, '235') === false) {
                error_log("SMTP auth failed: {$response}");
                fclose($fp);
                return false;
            }
        }

        // Mail senden
        $this->smtpWrite($fp, "MAIL FROM:<{$this->config['from_email']}>");
        $this->smtpRead($fp);
        $this->smtpWrite($fp, "RCPT TO:<{$to}>");
        $this->smtpRead($fp);
        $this->smtpWrite($fp, "DATA");
        $this->smtpRead($fp);

        $headerStr = implode("\r\n", $headers);
        $data = "To: <{$to}>\r\nSubject: " . $this->encodeHeader($subject) . "\r\n{$headerStr}\r\n\r\n{$body}\r\n.";
        $this->smtpWrite($fp, $data);
        $response = $this->smtpRead($fp);

        $this->smtpWrite($fp, "QUIT");
        fclose($fp);

        return strpos($response, '250') !== false;
    }

    private function smtpWrite($fp, string $data): void
    {
        fwrite($fp, $data . "\r\n");
    }

    private function smtpRead($fp): string
    {
        $response = '';
        while ($line = fgets($fp, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    private function encodeHeader(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }
}
