<?php
declare(strict_types=1);

namespace Havoc\Services;

/**
 * Minimal SMTP client for the panel's notifications: implicit TLS (465), STARTTLS (587) or plain (local relays),
 * AUTH PLAIN / LOGIN, UTF-8 plain-text messages. Certificates are always verified.
 */
final class Mailer
{
    /** @var resource|null */
    private $s = null;

    public function __construct(private array $cfg) {}

    /** @param string[] $to */
    public function send(array $to, string $subject, string $body): void
    {
        $host = (string)$this->cfg['host'];
        $port = (int)$this->cfg['port'];
        $sec = (string)$this->cfg['secure'];                        // ssl | tls | none
        $from = (string)$this->cfg['from'];
        if ($host === '' || !$to || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('SMTP is not configured');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $this->s = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) {
            throw new \RuntimeException("Cannot connect to $host:$port ($es)");
        }
        stream_set_timeout($this->s, 20);
        try {
            $this->expect(220);
            $ehlo = $this->ehlo();
            if ($sec === 'tls') {
                if (!str_contains($ehlo, 'STARTTLS')) {
                    throw new \RuntimeException('The server does not offer STARTTLS');
                }
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('TLS handshake failed (certificate not valid for ' . $host . '?)');
                }
                $ehlo = $this->ehlo();
            }
            $user = (string)($this->cfg['user'] ?? '');
            if ($user !== '') {
                if ($sec === 'none' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                    throw new \RuntimeException('Refusing to send the password without encryption; choose TLS or SSL');
                }
                $pass = (string)($this->cfg['pass'] ?? '');
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), 235);
                } else {
                    $this->cmd('AUTH LOGIN', 334);
                    $this->cmd(base64_encode($user), 334);
                    $this->cmd(base64_encode($pass), 235);
                }
            }
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            foreach ($to as $r) {
                $this->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $this->cmd('DATA', 354);
            $name = (string)($this->cfg['from_name'] ?? 'Havoc WEB Panel');
            $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
            $msg = 'Date: ' . date('r') . "\r\n"
                . 'From: =?UTF-8?B?' . base64_encode($name) . "?= <$from>\r\n"
                . 'To: ' . implode(', ', $to) . "\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
                . "Auto-Submitted: auto-generated\r\n\r\n"
                . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.";
            $this->cmd($msg, 250);
            @fwrite($this->s, "QUIT\r\n");
        } finally {
            @fclose($this->s);
            $this->s = null;
        }
    }

    private function ehlo(): string
    {
        $host = gethostname() ?: 'localhost';
        return $this->cmd('EHLO ' . (preg_match('/^[A-Za-z0-9.-]+$/', $host) ? $host : 'localhost'), 250);
    }

    private function cmd(string $line, int|array $ok): string
    {
        fwrite($this->s, $line . "\r\n");
        return $this->expect($ok);
    }

    private function expect(int|array $ok): string
    {
        $all = '';
        while (($l = fgets($this->s, 2048)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($all, 0, 3);
        if (!in_array($code, (array)$ok, true)) {
            throw new \RuntimeException('SMTP error: ' . ($all === '' ? 'no answer' : mb_substr(trim($all), 0, 200)));
        }
        return $all;
    }
}
