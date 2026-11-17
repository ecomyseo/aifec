<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Mail;

/** Envía con la función mail() de PHP, para alojamientos que solo ofrecen eso. */
final class MailFunctionTransport implements Transport
{
    private \Closure $mailer;

    /**
     * @param callable|null $mailer sustituto de mail() con su misma firma (to, subject, message, headers); solo para pruebas
     */
    public function __construct(?callable $mailer = null)
    {
        $this->mailer = \Closure::fromCallable($mailer !== null ? $mailer : 'mail');
    }

    public function send(MimeMessage $message): void
    {
        if ($message->recipients() === []) {
            throw new MailException('The message has no recipients');
        }
        // mail() añade él mismo To y Subject; Bcc sí se deja en las cabeceras porque sendmail -t lo usa y lo quita.
        $parts = $message->parts(true);
        $to = '';
        $subject = '';
        $headerLines = [];
        foreach ($parts['headers'] as $h) {
            $name = strtolower($h[0]);
            if ($name === 'to') {
                $to = str_replace("\r\n ", ' ', $h[1]);
            } elseif ($name === 'subject') {
                $subject = str_replace("\r\n ", ' ', $h[1]);
            } else {
                $headerLines[] = $h[0] . ': ' . $h[1];
            }
        }
        if ($to === '') {
            $to = 'undisclosed-recipients:;';
        }

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $ok = ($this->mailer)($to, $subject, $parts['body'], implode("\r\n", $headerLines));
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            throw new MailException('The mail() function could not hand the message over');
        }
    }
}
