<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Core\Config;

/**
 * One e-mail over SMTP (STARTTLS + AUTH LOGIN), with no library: the project
 * keeps zero dependencies. Used for the welcome e-mail at the first sign-in
 * (styled HTML, with a plain-text part for clients that cannot show it); a
 * failure never blocks the sign-in (the caller only logs it).
 */
final class Mailer
{
    /** Whether the site can send mail at all (SMTP_HOST set in .env.php). */
    public static function configured(Config $config): bool
    {
        return $config->smtpHost !== '' && $config->smtpUser !== '' && $config->smtpFrom !== '';
    }

    /**
     * Sends one e-mail and returns null on success, or the SMTP error for the log.
     * `$html` is the styled body; `$text` is what clients that refuse HTML show.
     * `$inline` pictures are embedded by Content-ID (src="cid:…"): mail clients
     * accept those where they strip data URIs. Never throws (see welcome mail).
     *
     * @param array<string, array{type: string, data: string}> $inline cid => {type, data}
     */
    public static function send(Config $config, string $to, string $subject, string $text, string $html = '', array $inline = []): ?string
    {
        if (!self::configured($config)) {
            return null; // No SMTP configured: quietly skip.
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'invalid recipient';
        }

        try {
            return self::speak($config, $to, $subject, $text, $html, $inline);
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    /** The SMTP conversation; returns null once the message is accepted. */
    private static function speak(Config $config, string $to, string $subject, string $text, string $html, array $inline): ?string
    {
        $address = self::address($config->smtpFrom);
        $peer = ($config->smtpPort === 465 ? 'ssl://' : '') . $config->smtpHost;
        $socket = @stream_socket_client(
            $peer . ':' . $config->smtpPort,
            $errno,
            $error,
            10,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if ($socket === false) {
            return "connect: {$error} ({$errno})";
        }
        stream_set_timeout($socket, 15);

        try {
            self::expect($socket, '220');
            self::command($socket, 'EHLO ' . self::hostname(), '250');

            if ($config->smtpPort !== 465) {
                self::command($socket, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'STARTTLS failed';
                }
                self::command($socket, 'EHLO ' . self::hostname(), '250');
            }

            self::command($socket, 'AUTH LOGIN', '334');
            self::command($socket, base64_encode($config->smtpUser), '334');
            self::command($socket, base64_encode($config->smtpPass), '235');

            self::command($socket, 'MAIL FROM:<' . $address . '>', '250');
            self::command($socket, 'RCPT TO:<' . self::address($to) . '>', '250');
            self::command($socket, 'DATA', '354');
            self::command($socket, self::message($config->smtpFrom, $to, $subject, $text, $html, $inline) . "\r\n.", '250');
            self::command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }

        return null;
    }

    /**
     * The raw RFC 5322 message: headers, then the body. Plain text alone when
     * there is no HTML; multipart/alternative (text, then HTML) when there is;
     * multipart/related wrapping it when pictures are embedded by cid.
     *
     * @param array<string, array{type: string, data: string}> $inline
     */
    public static function message(string $from, string $to, string $subject, string $text, string $html = '', array $inline = []): string
    {
        $headers = [
            'Date: ' . gmdate('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::hostname() . '>',
            'From: ' . self::name($from, $from),
            'To: ' . $to,
            'Subject: ' . self::header($subject),
            'MIME-Version: 1.0',
        ];

        if ($html === '') {
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
        }

        $alternative = '=_alt_' . bin2hex(random_bytes(6));
        $parts = '--' . $alternative . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $alternative . "\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $alternative . '--';

        if ($inline === []) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alternative . '"';

            return implode("\r\n", $headers) . "\r\n\r\n" . $parts;
        }

        // multipart/related: the alternative body, then each embedded picture
        // (the HTML points at them with <img src="cid:logo">).
        $related = '=_rel_' . bin2hex(random_bytes(6));
        $headers[] = 'Content-Type: multipart/related; boundary="' . $related . '"';
        $body = '--' . $related . "\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $alternative . "\"\r\n\r\n"
            . $parts . "\r\n";
        foreach ($inline as $cid => $file) {
            $safeCid = preg_replace('/[^A-Za-z0-9._-]/', '', $cid) ?: 'img';
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '', $cid) ?: 'img';
            $body .= '--' . $related . "\r\n"
                . 'Content-Type: ' . $file['type'] . "\r\n"
                . 'Content-ID: <' . $safeCid . ">\r\n"
                . 'Content-Disposition: inline; filename="' . $safeName . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($file['data'])) . "\r\n";
        }
        $body .= '--' . $related . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** @param resource $socket */
    private static function command($socket, string $line, string $want): void
    {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new \RuntimeException('write failed');
        }
        self::expect($socket, $want);
    }

    /** Reads one complete SMTP reply (250-… lines) and checks its code. @param resource $socket */
    private static function expect($socket, string $code): void
    {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if ($line === '' || strlen($reply) > 16384) {
                throw new \RuntimeException('SMTP timeout or overflow');
            }
        }
        if ($reply === '' || !str_starts_with($reply, $code)) {
            throw new \RuntimeException('SMTP expected ' . $code . ', got: ' . trim($reply));
        }
    }

    /** A display name and address, quoted when needed. */
    private static function name(string $from, string $address): string
    {
        $label = 'RecupBuild';

        return sprintf('%s <%s>', $label, self::address($address));
    }

    private static function address(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(.*<)?([^<>\s]+@[^<>\s]+)>?$/', $value, $found) === 1) {
            return $found[2];
        }

        return $value;
    }

    /** A header value: RFC 2047 encoded-word when it is not plain ASCII. */
    private static function header(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function hostname(): string
    {
        $host = gethostname();

        return $host !== false && $host !== '' ? $host : 'localhost';
    }
}
