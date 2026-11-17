<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Kernel\App;
use Aifec\Modules\Consent\ConsentService;

/**
 * Los datos de cada página que se pintan en un bloque JSON incrustado («data-af-dl»), que datalayer.js empuja a la capa de datos.
 *
 *  - Ficha, carrito y caja: el JSON es el mismo para todos los visitantes (la ficha se guarda en la caché de página). Lo que es del
 *    visitante —lo que añade o quita del carrito— lo empuja el guion con lo que contestan las acciones del carrito.
 *  - Pedido: la página es privada y nunca se guarda. La compra se empuja una sola vez por pedido (la marca está en datalayer_orders).
 *    El correo y el teléfono resumidos solo salen si la cookie del visitante concede el marketing.
 */
final class Pages
{
    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    /** @var EventBuilder */
    private $builder;

    /** @var OrderMarks */
    private $marks;

    public function __construct(App $app, Config $config, EventBuilder $builder, OrderMarks $marks)
    {
        $this->app = $app;
        $this->config = $config;
        $this->builder = $builder;
        $this->marks = $marks;
    }

    /**
     * @param array<string,mixed> $product ProductData
     */
    public function product(array $product): string
    {
        if (!$this->config->enabled()) {
            return '';
        }

        return $this->block([$this->builder->viewItem($product)]);
    }

    /**
     * @param array<string,mixed> $summary Cart::summary()
     */
    public function cart(array $summary): string
    {
        if (!$this->config->enabled() || empty($summary['lines'])) {
            return '';
        }

        return $this->block([$this->builder->viewCart($summary)]);
    }

    /**
     * @param array<string,mixed> $summary Cart::summary()
     */
    public function checkout(array $summary): string
    {
        if (!$this->config->enabled() || empty($summary['lines'])) {
            return '';
        }

        return $this->block([$this->builder->beginCheckout($summary)]);
    }

    /**
     * La compra, la primera vez que se enseña el pedido con la compra válida.
     *
     * @param array<string,mixed> $order
     */
    public function thanks(array $order): string
    {
        if (!$this->config->enabled()) {
            return '';
        }
        $mark = $this->marks->claimBrowser($order);
        if ($mark === null) {
            return '';
        }

        return $this->block($this->builder->purchase($order, $mark, $this->userData($order)));
    }

    /**
     * Correo y teléfono resumidos, solo si el visitante concede el marketing y las conversiones mejoradas están encendidas.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,string>|null
     */
    private function userData(array $order): ?array
    {
        if (!$this->config->adsEnhanced() || !$this->app->has('consent')) {
            return null;
        }
        /** @var ConsentService $consent */
        $consent = $this->app->service('consent');
        if (!$consent->allows('marketing')) {
            return null;
        }
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $data = [];
        $email = UserData::emailHash((string) $order['email']);
        $phone = UserData::phoneHash(isset($billing['phone']) ? (string) $billing['phone'] : '');
        if ($email !== '') {
            $data['sha256_email_address'] = $email;
        }
        if ($phone !== '') {
            $data['sha256_phone_number'] = $phone;
        }

        return $data !== [] ? $data : null;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function block(array $events): string
    {
        return '<script type="application/json" data-af-dl>' . json_html(['e' => $events]) . '</script>';
    }
}
