<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (SSL on 465 or STARTTLS on 587, AUTH LOGIN).
 * Works with Hostinger mail (smtp.hostinger.com), Gmail app passwords, etc.
 */
final class SmtpMailer
{
    private $fp;
    public array $log = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $secure, // ssl | tls | none
        private string $user,
        private string $pass,
        private int $timeout = 15
    ) {}

    public function send(string $from, string $fromName, array $to, string $subject, string $html, string $replyTo = ''): void
    {
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) throw new RuntimeException("No se pudo conectar a {$this->host}:{$this->port} ($errstr)");
        stream_set_timeout($this->fp, $this->timeout);
        $this->expect(220);
        $ehloHost = preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        $this->cmd('EHLO ' . $ehloHost, 250);
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS');
            }
            $this->cmd('EHLO ' . $ehloHost, 250);
        }
        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($this->user), 334);
            $this->cmd(base64_encode($this->pass), 235, true);
        }
        $this->cmd('MAIL FROM:<' . $from . '>', 250);
        foreach ($to as $rcpt) $this->cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
        $this->cmd('DATA', 354);

        $boundary = 'b' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encode($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h\d|\/tr|\/li)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";
        // dot-stuffing
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($this->fp, $body . "\r\n.\r\n");
        $this->expect(250);
        $this->cmd('QUIT', 221);
        fclose($this->fp);
    }

    private function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private function cmd(string $line, $expect, bool $secret = false): string
    {
        fwrite($this->fp, $line . "\r\n");
        $this->log[] = '> ' . ($secret ? '********' : (str_starts_with($line, 'AUTH') || strlen($line) > 60 ? substr($line, 0, 12) . '…' : $line));
        return $this->expect($expect);
    }

    private function expect($codes): string
    {
        $codes = (array)$codes;
        $resp = '';
        while (($line = fgets($this->fp, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $this->log[] = '< ' . trim($resp);
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('El servidor SMTP respondió: ' . trim($resp));
        }
        return $resp;
    }
}

/** Send an HTML email using the SMTP settings (falls back to PHP mail()). Returns true on success. */
function send_mail($to, string $subject, string $html, string $replyTo = '', ?string &$error = null): bool
{
    $to = array_values(array_filter(array_map('trim', is_array($to) ? $to : explode(',', (string)$to)), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
    if (!$to) { $error = 'Sin destinatarios válidos'; return false; }
    $host = trim((string)setting('smtp_host', ''));
    $from = trim((string)setting('smtp_from', '')) ?: trim((string)setting('smtp_user', ''));
    $fromName = (string)setting('smtp_from_name', 'Sofi Love & Faith');
    try {
        if ($host !== '' && $from !== '') {
            $m = new SmtpMailer($host, (int)setting('smtp_port', '465'), (string)setting('smtp_secure', 'ssl'), (string)setting('smtp_user', ''), (string)setting('smtp_pass', ''));
            $m->send($from, $fromName, $to, $subject, $html, $replyTo);
            return true;
        }
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        if ($from) $headers .= 'From: ' . $fromName . ' <' . $from . ">\r\n";
        if ($replyTo) $headers .= 'Reply-To: ' . $replyTo . "\r\n";
        $ok = @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
        if (!$ok) $error = 'mail() no está disponible. Configura SMTP en Ajustes.';
        return $ok;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        error_log('[mail] ' . $error);
        return false;
    }
}

function notify_admin(string $subject, string $html, string $replyTo = ''): bool
{
    $to = (string)setting('notify_emails', '');
    if (trim($to) === '') return false;
    $wrapped = '<div style="font-family:Arial,sans-serif;color:#2E1F25;max-width:600px">' . $html
        . '<p style="color:#888;font-size:12px;margin-top:24px">Notificación automática de ' . e(setting('store_name', 'Sofi Love & Faith')) . '</p></div>';
    return send_mail($to, '[Sofi] ' . $subject, $wrapped, $replyTo);
}
