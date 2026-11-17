<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * La confirmación del pedido en una vuelta de la conversación. Es lo que hace que el pedido NO lo decida el modelo.
 *
 *  - checkout_review «arma» la espera: guarda la ficha de confirmación (el HMAC del pedido exacto) y el total. Se guarda en la
 *    conversación (clave «await» de su estado), nunca se le da al modelo ni al navegador.
 *  - Quien confirma es la PERSONA: el botón «Confirmar pedido» o un mensaje que es una afirmación clara. Solo el motor del
 *    asistente llama a confirm(); ninguna herramienta puede hacerlo.
 *  - checkout_place solo vale si confirmed() es verdadero en esta vuelta. Usada, la espera se consume: un segundo «sí» no
 *    hace un segundo pedido.
 *  - Cualquier otro mensaje, o un cambio en el carrito o en los datos, desarma la espera.
 *
 * Además lleva, de un lado a otro de la vuelta, lo que no debe pasar por el modelo: la tarjeta con el resumen para pintar,
 * lo que dejó el pedido hecho (para los botones de pago) y los datos personales que hay que enmascarar al guardar.
 */
final class CheckoutTurn
{
    /** Lo que dura una espera de confirmación, en segundos: pasado esto hay que volver a revisar el pedido. */
    public const TTL = 1800;

    /** @var array<string,mixed>|null */
    private $await;

    /** @var int */
    private $now;

    /** @var bool */
    private $confirmed = false;

    /** @var string[] valores personales de esta vuelta (para enmascararlos al guardar los mensajes) */
    public $personal = [];

    /** @var array<string,mixed>|null el resumen del pedido para pintar (acción «confirm_order») */
    public $card = null;

    /** @var array<string,mixed>|null lo que devolvió el pedido hecho: id, number, order_url, total y payment */
    public $placed = null;

    /**
     * @param array<string,mixed>|null $await lo que estaba guardado en la conversación
     */
    public function __construct(?array $await, int $now)
    {
        $this->now = $now;
        $this->await = null;
        if (is_array($await) && isset($await['token'], $await['total'], $await['at']) && is_string($await['token'])
            && preg_match('/^[a-f0-9]{64}$/D', $await['token']) === 1 && is_numeric($await['total']) && is_numeric($await['at'])) {
            $this->await = ['token' => $await['token'], 'total' => (int) $await['total'], 'at' => (int) $await['at']];
        }
    }

    /** ¿Hay un pedido revisado esperando que la persona lo confirme (y no ha caducado)? */
    public function armed(): bool
    {
        return $this->await !== null && $this->await['at'] + self::TTL >= $this->now;
    }

    /** Deja el pedido revisado esperando confirmación. */
    public function arm(string $token, int $total): void
    {
        $this->await = ['token' => $token, 'total' => $total, 'at' => $this->now];
        $this->confirmed = false;
    }

    /** Anula la espera y la ficha. */
    public function disarm(): void
    {
        $this->await = null;
        $this->confirmed = false;
    }

    /**
     * La persona ha confirmado: se marca solo si había algo esperando. Lo llama el motor del asistente, nunca una herramienta.
     */
    public function confirm(): bool
    {
        if (!$this->armed()) {
            return false;
        }
        $this->confirmed = true;

        return true;
    }

    public function confirmed(): bool
    {
        return $this->confirmed && $this->armed();
    }

    /** La ficha de confirmación (vacía si no hay espera). */
    public function token(): string
    {
        return $this->await !== null ? $this->await['token'] : '';
    }

    /** El total de céntimos del pedido revisado. */
    public function total(): int
    {
        return $this->await !== null ? $this->await['total'] : 0;
    }

    /** La espera se ha usado: ya no hay nada que confirmar. */
    public function consume(): void
    {
        $this->disarm();
    }

    /**
     * Lo que se guarda en la conversación (null = no hay espera).
     *
     * @return array<string,mixed>|null
     */
    public function await(): ?array
    {
        return $this->await;
    }
}
