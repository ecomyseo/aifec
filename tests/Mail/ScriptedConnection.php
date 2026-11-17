<?php
/**
 * AIFEC — conexión SMTP de guion para las pruebas: contesta lo que se le programa y apunta lo que recibe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Tests\Mail;

use Aifec\Mail\MailException;
use Aifec\Mail\SmtpConnection;

/**
 * El guion es una lista de respuestas: la primera es el saludo (se entrega al abrir) y cada escritura
 * posterior consume la siguiente. Una respuesta es una cadena o una lista de líneas (respuesta multilínea).
 */
final class ScriptedConnection implements SmtpConnection
{
    /** @var array<int,string|string[]> */
    private array $script;

    private int $step = 0;

    /** @var string[] */
    private array $buffer = [];

    /** @var string[] lo escrito, tal cual */
    public array $writes = [];

    /** @var string[] sucesos en orden: open, cmd, data, starttls, close */
    public array $events = [];

    public bool $closed = false;

    /** @param array<int,string|string[]> $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function open(string $host, int $port, bool $implicitTls, float $timeout): void
    {
        $this->events[] = 'open ' . $host . ':' . $port . ($implicitTls ? ' tls' : '');
        $this->closed = false;
        $this->deliver();
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
        $isData = substr($data, -5) === "\r\n.\r\n";
        $this->events[] = $isData ? 'data' : 'cmd ' . rtrim($data, "\r\n");
        $this->deliver();
    }

    public function readLine(): string
    {
        if ($this->buffer === []) {
            throw new MailException('script exhausted: the code read a reply nobody programmed');
        }

        return (string) array_shift($this->buffer);
    }

    public function startTls(): void
    {
        $this->events[] = 'starttls';
    }

    public function close(): void
    {
        $this->closed = true;
        $this->events[] = 'close';
    }

    /** Las órdenes escritas, sin CRLF; el cuerpo del mensaje aparece como «<data>». @return string[] */
    public function commands(): array
    {
        $out = [];
        foreach ($this->writes as $w) {
            $out[] = substr($w, -5) === "\r\n.\r\n" ? '<data>' : rtrim($w, "\r\n");
        }

        return $out;
    }

    /** El cuerpo del mensaje tal como llegó al servidor (con el relleno de puntos y el terminador). */
    public function dataPayload(): string
    {
        foreach ($this->writes as $w) {
            if (substr($w, -5) === "\r\n.\r\n") {
                return $w;
            }
        }

        return '';
    }

    private function deliver(): void
    {
        if (!array_key_exists($this->step, $this->script)) {
            ++$this->step;

            return;
        }
        $reply = $this->script[$this->step++];
        foreach (is_array($reply) ? $reply : [$reply] as $line) {
            $this->buffer[] = $line;
        }
    }
}
