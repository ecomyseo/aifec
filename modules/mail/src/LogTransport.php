<?php
/**
 * AIFEC — transporte «log»: escribe cada mensaje como un fichero .eml y lo da por enviado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mail;

use Aifec\Kernel\Clock;
use Aifec\Mail\MailException;
use Aifec\Mail\MimeMessage;
use Aifec\Mail\Transport;

/** Lo usan las demos y el entorno local: no sale nada de la máquina, los mensajes quedan en una carpeta privada del sitio. */
final class LogTransport implements Transport
{
    private string $dir;

    private Clock $clock;

    public function __construct(string $dir, Clock $clock)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
        $this->clock = $clock;
    }

    public function send(MimeMessage $message): void
    {
        $raw = $message->toString();
        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            throw new MailException('The folder for saved email is not writable');
        }
        $name = gmdate('Ymd-His', $this->clock->now()) . '-' . bin2hex(random_bytes(4)) . '.eml';
        if (file_put_contents($this->dir . '/' . $name, $raw) === false) {
            throw new MailException('The message could not be saved to a file');
        }
    }
}
