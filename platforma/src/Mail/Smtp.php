<?php
declare(strict_types=1);

namespace App\Mail;

use App\Config;

/** Minimalny klient SMTP (SSL/STARTTLS, AUTH LOGIN/PLAIN) bez zależności zewnętrznych. */
final class Smtp
{
    /** @var resource|null */
    private $fp = null;

    public static function send(string $to, string $subject, string $text, ?string $html, string $from, string $fromName): void
    {
        $transport = (string)Config::get('mail.transport', 'smtp');
        $msg = self::build($to, $subject, $text, $html, $from, $fromName);
        if ($transport === 'log') {
            $dir = Config::storage('logs');
            @mkdir($dir, 0750, true);
            file_put_contents($dir . '/mail.log', "=== " . date('c') . " ===\n" . $msg . "\n", FILE_APPEND | LOCK_EX);
            return;
        }
        if ($transport === 'mail') {
            // awaryjnie: funkcja mail() serwera (niezalecane, brak DKIM)
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, "From: $fromName <$from>\r\nContent-Type: text/plain; charset=UTF-8");
            if (!$ok) {
                throw new \RuntimeException('mail() zwróciło błąd');
            }
            return;
        }
        (new self())->deliver($to, $from, $msg);
    }

    public static function build(string $to, string $subject, string $text, ?string $html, string $from, string $fromName): string
    {
        $enc = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $h = [
            'Date: ' . date('r'),
            'From: ' . (preg_match('/[^\x20-\x7E]/', $fromName) ? $enc($fromName) : ('"' . addcslashes($fromName, '"\\') . '"')) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        $q = fn(string $s) => str_replace("\r\n", "\n", quoted_printable_encode($s));
        if ($html) {
            $b = 'b' . bin2hex(random_bytes(8));
            $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
            $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $q($text)
                . "\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $q($html) . "\r\n--$b--\r\n";
        } else {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: quoted-printable';
            $body = $q($text);
        }
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    private function deliver(string $to, string $from, string $msg): void
    {
        $c = Config::get('mail', []);
        $host = (string)($c['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('Brak konfiguracji SMTP (mail.host).');
        }
        $port = (int)($c['port'] ?? 587);
        $enc = (string)($c['encryption'] ?? 'tls'); // ssl | tls | none
        $timeout = (int)($c['timeout'] ?? 15);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $c['verify_peer'] ?? true, 'verify_peer_name' => $c['verify_peer'] ?? true, 'peer_name' => $host]]);
        $this->fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) {
            throw new \RuntimeException("SMTP: nie można połączyć ($errstr)");
        }
        stream_set_timeout($this->fp, $timeout);
        try {
            $this->expect(220);
            $ehlo = $this->cmd('EHLO ' . (parse_url(abs_url('/'), PHP_URL_HOST) ?: 'localhost'), 250);
            if ($enc === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP: nie udało się włączyć TLS');
                }
                $ehlo = $this->cmd('EHLO ' . (parse_url(abs_url('/'), PHP_URL_HOST) ?: 'localhost'), 250);
            }
            if (!empty($c['user'])) {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false && stripos($ehlo, 'LOGIN') === false) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . ($c['pass'] ?? '')), 235);
                } else {
                    $this->cmd('AUTH LOGIN', 334);
                    $this->cmd(base64_encode((string)$c['user']), 334);
                    $this->cmd(base64_encode((string)($c['pass'] ?? '')), 235);
                }
            }
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', 354);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg));
            fwrite($this->fp, $data . "\r\n.\r\n");
            $this->expect(250);
            try {
                $this->cmd('QUIT', 221); // wiadomość jest już przyjęta; błąd QUIT nie może jej cofnąć
            } catch (\Throwable) {
            }
        } finally {
            if (is_resource($this->fp)) {
                fclose($this->fp);
            }
        }
    }

    private function cmd(string $line, int|array $ok): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($ok);
    }

    private function expect(int|array $ok): string
    {
        $ok = (array)$ok;
        $resp = '';
        while (($l = fgets($this->fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException('SMTP: ' . trim(preg_replace('/\s+/', ' ', $resp)));
        }
        return $resp;
    }
}
