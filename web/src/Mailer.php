<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Cliente SMTP mínimo (sin dependencias): STARTTLS o SSL + AUTH LOGIN.
 * Se configura desde el panel admin (ajustes SMTP en la tabla settings).
 *
 * Campos: smtp_host, smtp_port, smtp_secure (tls|ssl|none),
 *         smtp_user, smtp_pass, smtp_from_email, smtp_from_name.
 */
final class Mailer
{
    public function __construct(private array $cfg) {}

    /** Construye desde los ajustes; null si no está configurado el mínimo. */
    public static function fromSettings(PDO $db): ?self
    {
        $s = Settings::smtp($db);
        if (empty($s['smtp_host']) || empty($s['smtp_user']) || empty($s['smtp_pass'])) {
            return null;
        }
        return new self($s);
    }

    /** @return array{ok:bool,error?:string} */
    public function send(string $to, string $subject, string $htmlBody): array
    {
        $host   = trim($this->cfg['smtp_host']);
        $port   = (int) ($this->cfg['smtp_port'] ?: 587);
        $secure = strtolower(trim($this->cfg['smtp_secure'] ?: 'tls'));
        $user   = $this->cfg['smtp_user'];
        $pass   = $this->cfg['smtp_pass'];
        $from   = $this->cfg['smtp_from_email'] ?: $user;
        $name   = $this->cfg['smtp_from_name'] ?: 'Ojo al Precio';

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['ok' => false, 'error' => "No se pudo conectar a $host:$port ($errstr)"];
        }
        stream_set_timeout($fp, 20);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break; // última línea de la respuesta
                }
            }
            return $data;
        };
        $put = function (string $c) use ($fp): void { fwrite($fp, $c . "\r\n"); };
        $code = fn(string $r): int => (int) substr($r, 0, 3);

        try {
            if ($code($read()) !== 220) {
                throw new \RuntimeException('El servidor no saludó (220)');
            }
            $put('EHLO ojoalprecio');
            if ($code($read()) !== 250) {
                throw new \RuntimeException('EHLO rechazado');
            }

            if ($secure === 'tls') {
                $put('STARTTLS');
                if ($code($read()) !== 220) {
                    throw new \RuntimeException('STARTTLS rechazado');
                }
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (!stream_socket_enable_crypto($fp, true, $crypto)) {
                    throw new \RuntimeException('No se pudo iniciar TLS');
                }
                $put('EHLO ojoalprecio');
                if ($code($read()) !== 250) {
                    throw new \RuntimeException('EHLO tras TLS rechazado');
                }
            }

            $put('AUTH LOGIN');
            if ($code($read()) !== 334) {
                throw new \RuntimeException('AUTH LOGIN no soportado');
            }
            $put(base64_encode($user));
            if ($code($read()) !== 334) {
                throw new \RuntimeException('Usuario rechazado');
            }
            $put(base64_encode($pass));
            if ($code($read()) !== 235) {
                throw new \RuntimeException('Autenticación fallida (revisá usuario/contraseña; Gmail requiere "contraseña de aplicación")');
            }

            $put("MAIL FROM:<$from>");
            if ($code($read()) !== 250) {
                throw new \RuntimeException('MAIL FROM rechazado');
            }
            $put("RCPT TO:<$to>");
            if (!in_array($code($read()), [250, 251], true)) {
                throw new \RuntimeException('Destinatario rechazado');
            }
            $put('DATA');
            if ($code($read()) !== 354) {
                throw new \RuntimeException('DATA rechazado');
            }

            $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
            $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
            $headers = 'Date: ' . gmdate('r') . "\r\n"
                . "Message-ID: $messageId\r\n"
                . "From: $name <$from>\r\n"
                . "To: <$to>\r\n"
                . "Reply-To: <$from>\r\n"
                . "Subject: $subjectEnc\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n";
            $body = chunk_split(base64_encode($htmlBody));
            $put($headers . "\r\n" . $body . "\r\n.");
            if ($code($read()) !== 250) {
                throw new \RuntimeException('El servidor no aceptó el mensaje');
            }
            $put('QUIT');
            fclose($fp);
            return ['ok' => true];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
