<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

/**
 * Servicio «payments»: el registro de las formas de pago. Cada módulo de pago registra la suya en su hooks.php:
 *
 *   $app->service('payments')->register(new MiForma($app));
 *
 * La caja y el carrito solo preguntan aquí: nunca conocen a una pasarela concreta.
 *
 * Orden de las formas que se ofrecen: primero las que nombra el ajuste orders.payment_order (una lista o un texto con
 * los códigos separados por comas) y después el resto, en el orden en que se registraron.
 */
final class Payments
{
    /** @var App */
    private $app;

    /** @var array<string,PaymentMethod> código => forma de pago, en orden de registro */
    private $methods = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Registra una forma de pago. Si ya había una con el mismo código, la nueva ocupa su sitio.
     *
     * @throws \InvalidArgumentException si el código está vacío
     */
    public function register(PaymentMethod $method): void
    {
        $code = self::normalize($method->code());
        if ($code === '') {
            throw new \InvalidArgumentException('A payment method needs a code');
        }
        $this->methods[$code] = $method;
    }

    /**
     * ¿Se puede usar esta forma de pago en este sitio? En una tienda normal, todas. En una demostración solo las que no
     * pueden mover dinero de verdad: el pago simulado y la pasarela que diga que está en su entorno de pruebas
     * (sandboxOnly()). En cuanto esa pasarela se pone en real, la demo deja de ofrecerla.
     */
    private function allowedHere(string $code, PaymentMethod $method): bool
    {
        if (!$this->app->site()->isDemo() || $code === 'simulated') {
            return true;
        }

        return method_exists($method, 'sandboxOnly') && $method->sandboxOnly() === true;
    }

    public function get(string $code): ?PaymentMethod
    {
        $code = self::normalize($code);
        if ($code === FreePayment::CODE) {
            return $this->free();
        }

        if (!isset($this->methods[$code]) || !$this->allowedHere($code, $this->methods[$code])) {
            return null;
        }

        return $this->methods[$code];
    }

    /**
     * Todas las registradas, en orden de registro.
     *
     * @return array<string,PaymentMethod>
     */
    public function all(): array
    {
        return $this->methods;
    }

    /**
     * Las que se ofrecen para esta compra, ya ordenadas. Una forma que falla al decidir no se ofrece y no tumba la caja.
     *
     * @param array<string,mixed> $context total (céntimos), currency, country, needs_shipping, customer_id, lang
     *
     * @return PaymentMethod[] lista (sin claves)
     */
    public function available(array $context): array
    {
        // Con cero que pagar ninguna pasarela sirve (rechazan los importes de cero): solo «sin pago».
        if ($this->free()->available($context)) {
            return [$this->free()];
        }
        $wanted = [];
        foreach ($this->methods as $code => $method) {
            if (!$this->allowedHere((string) $code, $method)) {
                continue;
            }
            try {
                if ($method->available($context)) {
                    $wanted[$code] = $method;
                }
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'payments.available:' . $code);
            }
        }

        $out = [];
        foreach ($this->order() as $code) {
            if (isset($wanted[$code])) {
                $out[] = $wanted[$code];
                unset($wanted[$code]);
            }
        }
        foreach ($wanted as $method) {
            $out[] = $method;
        }

        return $out;
    }

    /**
     * Los códigos del ajuste orders.payment_order, normalizados y sin repetir.
     *
     * @return string[]
     */
    private function order(): array
    {
        $value = $this->app->settings()->get('orders.payment_order', []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        $codes = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $code = is_scalar($item) ? self::normalize((string) $item) : '';
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @var FreePayment|null */
    private $free;

    private function free(): FreePayment
    {
        if ($this->free === null) {
            $this->free = new FreePayment();
        }

        return $this->free;
    }

    private static function normalize(string $code): string
    {
        return strtolower(trim($code));
    }
}
