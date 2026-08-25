<?php

declare(strict_types=1);

namespace ExpressPHP\Mail;

use DateTimeImmutable;
use Throwable;

final class SMTPMailer
{
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function defaultMailer(): string
    {
        return (string)(self::$config['default'] ?? 'smtp');
    }

    public function send(
        string  $mailer,
        string  $recipient,
        string  $subject,
        string  $body,
        bool    $html = true,
        ?string $replyTo = null,
    ): void {
        $config = $this->configuration($mailer);
        $this->assertEmail($recipient, 'recipient');
        if ($replyTo !== null) {
            $this->assertEmail($replyTo, 'reply-to address');
        }

        $socket = $this->connect($config);
        try {
            $this->expect($socket, [220]);
            $hostname = gethostname() ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hostname, [250]);

            if ($config['encryption'] === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new MailException('Unable to start TLS encryption.');
                }
                $this->command($socket, 'EHLO ' . $hostname, [250]);
            }

            if ($config['username'] !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($config['username']), [334]);
                $this->command($socket, base64_encode($config['password']), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $config['from_address'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $message = $this->message($config, $recipient, $subject, $body, $html, $replyTo);
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } catch (Throwable $exception) {
            throw $exception instanceof MailException
                ? $exception
                : new MailException('SMTP delivery failed.', 0, $exception);
        } finally {
            fclose($socket);
        }
    }

    private function configuration(string $mailer): array
    {
        if (!(bool)(self::$config['enabled'] ?? false)) {
            throw new MailException('Email delivery is disabled.');
        }

        $config = self::$config['mailers'][$mailer] ?? null;
        if (!is_array($config)) {
            throw new MailException("Mailer [{$mailer}] is not configured.");
        }

        $config = [
            'host' => trim((string)($config['host'] ?? '')),
            'port' => (int)($config['port'] ?? 587),
            'encryption' => strtolower((string)($config['encryption'] ?? 'tls')),
            'username' => (string)($config['username'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
            'timeout' => max(1, (int)(self::$config['timeout'] ?? 10)),
            'from_address' => trim((string)(self::$config['from_address'] ?? '')),
            'from_name' => trim((string)(self::$config['from_name'] ?? 'ExpressPHP')),
        ];

        if ($config['host'] === '' || $config['from_address'] === '') {
            throw new MailException("Mailer [{$mailer}] is incomplete.");
        }
        if (!in_array($config['encryption'], ['none', 'tls', 'ssl'], true)) {
            throw new MailException('Mail encryption must be none, tls, or ssl.');
        }
        $this->assertEmail($config['from_address'], 'from address');
        return $config;
    }

    private function connect(array $config): mixed
    {
        $scheme = $config['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            $scheme . '://' . $config['host'] . ':' . $config['port'],
            $errorCode,
            $errorMessage,
            $config['timeout'],
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new MailException("Could not connect to SMTP server: {$errorMessage} ({$errorCode}).");
        }
        stream_set_timeout($socket, $config['timeout']);
        return $socket;
    }

    private function command(mixed $socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    private function expect(mixed $socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 8192);
            if ($line === false) {
                $metadata = stream_get_meta_data($socket);
                throw new MailException(($metadata['timed_out'] ?? false)
                    ? 'SMTP server timed out.'
                    : 'SMTP server closed the connection.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new MailException('SMTP server rejected the request: ' . trim($response));
        }
        return $response;
    }

    private function message(
        array   $config,
        string  $recipient,
        string  $subject,
        string  $body,
        bool    $html,
        ?string $replyTo,
    ): string {
        $headers = [
            'Date: ' . (new DateTimeImmutable())->format(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'localhost') . '>',
            'From: ' . $this->mailbox($config['from_address'], $config['from_name']),
            'To: <' . $recipient . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }

    private function mailbox(string $address, string $name): string
    {
        return $name === '' ? '<' . $address . '>' : $this->encodeHeader($name) . ' <' . $address . '>';
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function assertEmail(string $email, string $label): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, "\n")) {
            throw new MailException("The {$label} is invalid.");
        }
    }
}
