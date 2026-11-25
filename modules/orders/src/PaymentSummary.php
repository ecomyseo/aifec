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

use Aifec\Kernel\App;

/**
 * Lo que la tienda cuenta de su pago a un agente que todavía no ha empezado a comprar: qué formas de pago tiene encendidas, que
 * el pago lo hace SIEMPRE una persona en la caja de la tienda y cómo se llega a ella. Es una declaración, no una operación:
 * no cobra, no guarda nada y no expone ningún dato de las pasarelas (ni claves, ni IBAN, ni códigos de comercio).
 *
 * La usan el perfil de UCP (payment_handlers), el descubrimiento de ACP y /.well-known/aifec.json: las tres dicen lo mismo.
 */
final class PaymentSummary
{
    /** El nombre del manejador de pago de la tienda en el perfil de UCP (no es un cobro por la API: la persona paga en la caja). */
    public const HANDLER = 'dev.aifec.shop_checkout';

    /**
     * Las formas de pago que se ofrecerían hoy a una compra normal de la tienda, en el orden en que salen en la caja.
     *
     * @return array<int,array{code:string,name:string,test:bool}>
     */
    public static function methods(App $app): array
    {
        if (!$app->modules()->isLoaded('orders')) {
            return [];
        }
        $settings = $app->settings();
        $context = [
            'total' => 5000,
            'currency' => strtoupper($settings->str('shop.currency', 'EUR')),
            'country' => strtoupper($settings->str('store.address_country', '') !== '' ? $settings->str('store.address_country', '') : 'ES'),
            'needs_shipping' => true,
            'customer_id' => 0,
            'lang' => $app->defaultLang(),
        ];
        $out = [];
        try {
            foreach ($app->service('payments')->available($context) as $method) {
                $info = $method->info();
                $out[] = ['code' => $method->code(), 'name' => (string) $info['title'], 'test' => $method->isTest()];
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'payment-summary');
        }

        return $out;
    }

    /**
     * La declaración completa. Con ninguna forma de pago encendida, «methods» va vacío y no se promete nada más.
     *
     * @return array<string,mixed>
     */
    public static function declaration(App $app): array
    {
        $settings = $app->settings();
        $methods = self::methods($app);
        $out = [
            'paid_by' => 'person',
            'agent_pays' => false,
            'completion' => 'shop_checkout',
            'currency' => strtoupper($settings->str('shop.currency', 'EUR')),
            'methods' => $methods,
            'note' => 'The buyer pays on the shop checkout. An agent prepares the cart and hands over a link; it never pays and never handles card data.',
        ];
        if ($methods !== []) {
            $out['how_to_reach_the_checkout'] = [
                'ucp' => 'continue_url in every checkout session',
                'mcp_and_api' => 'the checkout_link tool opens the prepared cart on the shop checkout',
                'buy_with_links' => $app->url()->abs('/buy-with-links', [], ''),
            ];
            foreach ($methods as $method) {
                if ($method['test']) {
                    $out['test_mode'] = true;
                }
            }
        }

        return $out;
    }
}
