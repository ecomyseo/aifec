<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Kernel\App;

/**
 * La casilla «Mandar el correo de confirmación al cliente» desmarcada: mientras se crea ESE pedido, el servicio «mailer» es este
 * portero, que deja pasar todo salvo el correo de confirmación (pagado o en espera de pago) dirigido al cliente del pedido.
 * El aviso a la tienda, los de los plugins y todo lo demás salen igual. Fuera de run() no existe: el servicio vuelve a ser el
 * de siempre aunque el pedido falle.
 */
final class ManualOrderMailGate
{
    /** Las plantillas que manda Orders::place() al cliente al nacer el pedido. */
    public const HELD = ['order_confirmed', 'order_on_hold'];

    /** @var object el servicio de correo de verdad */
    private $mailer;

    private string $email;

    private int $held = 0;

    /**
     * @param object $mailer
     */
    public function __construct($mailer, string $email)
    {
        $this->mailer = $mailer;
        $this->email = strtolower(trim($email));
    }

    /**
     * Ejecuta $fn con el portero puesto (si $send es false) y lo quita pase lo que pase.
     *
     * @return mixed lo que devuelva $fn
     */
    public static function run(App $app, string $email, bool $send, callable $fn)
    {
        if ($send || !$app->has('mailer')) {
            return $fn();
        }
        $real = $app->service('mailer');
        $gate = new self($real, $email);
        $app->bind('mailer', static function () use ($gate) {
            return $gate;
        });
        try {
            return $fn();
        } finally {
            $app->bind('mailer', static function () use ($real) {
                return $real;
            });
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     */
    public function queue(string $template, string $toEmail, array $data = [], array $options = []): int
    {
        if (in_array($template, self::HELD, true) && strtolower(trim($toEmail)) === $this->email) {
            ++$this->held;

            return 0;
        }

        return (int) $this->mailer->queue($template, $toEmail, $data, $options);
    }

    /** Cuántos correos ha retenido. */
    public function held(): int
    {
        return $this->held;
    }

    /**
     * Lo demás va al servicio de verdad.
     *
     * @param array<int,mixed> $args
     *
     * @return mixed
     */
    public function __call(string $name, array $args)
    {
        return $this->mailer->{$name}(...$args);
    }
}
