<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La compra entera por API, sobre el carrito de la ficha X-Cart-Token. Seis de las ocho rutas son las herramientas de la caja del
 * asistente tal cual (la misma lógica, la misma caja de la web); «review» y «place» las atiende Checkout, que añade la ficha de
 * confirmación, la declaración de la persona, la clave con ámbito y los topes.
 */
final class CheckoutController
{
    /** Ruta => herramienta del servicio «tools». */
    private const TOOLS = [
        'api.checkout.status' => 'checkout_status',
        'api.checkout.customer' => 'checkout_set_customer',
        'api.checkout.shipping_options' => 'checkout_shipping_options',
        'api.checkout.shipping' => 'checkout_set_shipping',
        'api.checkout.payment_options' => 'checkout_payment_options',
        'api.checkout.payment' => 'checkout_set_payment',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function step(Request $request, array $params): Response
    {
        Carts::open($this->app, $request);
        $route = (string) $request->attr('route', '');
        $tool = isset(self::TOOLS[$route]) ? self::TOOLS[$route] : '';

        return Agent::json(Agent::tool($this->app, $tool, Agent::body($request)));
    }

    /**
     * @param array<string,string> $params
     */
    public function review(Request $request, array $params): Response
    {
        Carts::open($this->app, $request);
        $key = $request->attr('api.key');

        return Agent::json(Checkout::review($this->app, is_array($key) ? $key : null));
    }

    /**
     * @param array<string,string> $params
     */
    public function place(Request $request, array $params): Response
    {
        Carts::open($this->app, $request);
        $key = $request->attr('api.key');

        return Agent::json(Checkout::place($this->app, is_array($key) ? $key : null, Agent::body($request), CartController::SOURCE), 201);
    }
}
