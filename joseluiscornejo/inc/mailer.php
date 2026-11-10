<?php
// Envío de correo por SMTP (SSL 465 o STARTTLS 587) sin librerías externas.
require_once __DIR__ . '/helpers.php';

class SmtpMailer {
    private $sock;
    public string $log = '';

    private function read(): string {
        $data = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $this->log .= 'S: ' . $data;
        return $data;
    }

    private function cmd(string $c, array $ok, bool $hide = false): string {
        fwrite($this->sock, $c . "\r\n");
        $this->log .= 'C: ' . ($hide ? '****' : $c) . "\n";
        $r = $this->read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r));
        return $r;
    }

    public function send(string $to, string $subject, string $html, ?string $replyTo = null): void {
        $host = s('smtp_host');
        $port = (int)s('smtp_port', '465');
        $secure = s('smtp_secure', 'ssl');
        $user = s('smtp_user');
        $pass = s('smtp_pass');
        $from = $user ?: 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromName = s('smtp_from_name', s('site_name'));

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException("No se pudo conectar a $host:$port ($errstr)");
        stream_set_timeout($this->sock, 20);

        $this->read();
        $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->cmd('EHLO ' . $ehloHost, [250]);
        if ($secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('No se pudo iniciar TLS');
            $this->cmd('EHLO ' . $ehloHost, [250]);
        }
        if ($user !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($user), [334], true);
            $this->cmd(base64_encode($pass), [235], true);
        }
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);

        $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $replyTo . '>';
        $body = chunk_split(base64_encode($html));
        fwrite($this->sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $r = $this->read();
        if ((int)substr($r, 0, 3) !== 250) throw new RuntimeException('SMTP: ' . trim($r));
        $this->cmd('QUIT', [221]);
        fclose($this->sock);
    }
}

function email_template(string $title, string $content): string {
    return '<div style="font-family:Arial,sans-serif;background:#f6f4f1;padding:24px"><div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden">'
        . '<div style="background:#0E0D0C;color:#F2EEE8;padding:20px 24px;font-size:18px;font-weight:bold">' . e(s('site_name')) . '</div>'
        . '<div style="padding:24px;color:#1A1816;font-size:15px;line-height:1.6"><h2 style="margin:0 0 12px;font-size:20px">' . e($title) . '</h2>' . $content . '</div></div></div>';
}

// Envía y devuelve true/false sin romper la página si falla el correo.
function send_mail(string $to, string $subject, string $html, ?string $replyTo = null, ?string &$error = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Correo destino inválido'; return false; }
    try {
        if (s('smtp_host') !== '' && s('smtp_pass') !== '') {
            (new SmtpMailer())->send($to, $subject, $html, $replyTo);
        } else {
            $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $h)) throw new RuntimeException('mail() falló; configura el SMTP en el panel.');
        }
        return true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        error_log('Correo: ' . $error);
        return false;
    }
}

function notify_admin(string $subject, string $html, ?string $replyTo = null): bool {
    return send_mail(s('notify_email'), $subject, email_template($subject, $html), $replyTo);
}
