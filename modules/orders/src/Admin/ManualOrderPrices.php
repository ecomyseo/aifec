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

use Aifec\Commerce\Totals;
use Aifec\Kernel\App;

/**
 * «Precio de esta venta» del pedido a mano, por la vía que el carrito reserva a las reglas de precio: el filtro sellado
 * cart.adjustments. La línea de producto conserva su precio de catálogo y, al lado, sale una línea de ajuste con nombre
 * («Precio de esta venta: Camiseta (M)») y origen «admin» por la diferencia. Así la cuenta la hace Totals, como siempre, el
 * pedido lleva escrito qué se cambió y nunca hay un precio cambiado a escondidas.
 *
 * El filtro se engancha una sola vez por aplicación (al crear el servicio «orders.manual_prices») y solo actúa sobre el carrito
 * del pedido a mano que la pantalla tiene armado en ESTA petición; cualquier otro carrito pasa sin tocar.
 *
 * Los cupones y el descuento del grupo se calculan sobre el precio de catálogo; el precio de esta venta se suma después.
 */
final class ManualOrderPrices
{
    public const SERVICE = 'orders.manual_prices';

    private App $app;

    private int $cartId = 0;

    /** @var array<string,array{price:int,reason:string}> clave de línea => precio unitario con impuestos y motivo */
    private array $prices = [];

    /** @var object|null la petición en la que está armado */
    private $request;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** El de esta aplicación, con el filtro ya enganchado (una sola vez). */
    public static function forApp(App $app): self
    {
        if (!$app->has(self::SERVICE)) {
            $app->bind(self::SERVICE, static function (App $app): ManualOrderPrices {
                $prices = new ManualOrderPrices($app);
                $app->hooks()->filter('cart.adjustments', [$prices, 'adjust']);

                return $prices;
            });
        }
        $service = $app->service(self::SERVICE);
        if (!$service instanceof self) {
            throw new \LogicException('The manual order prices are not available');
        }

        return $service;
    }

    /**
     * @param array<string,array{price:int,reason:string}> $prices
     */
    public function arm(int $cartId, array $prices): void
    {
        $this->cartId = $cartId;
        $this->prices = $prices;
        $this->request = $this->app->request();
    }

    public function disarm(): void
    {
        $this->cartId = 0;
        $this->prices = [];
        $this->request = null;
    }

    /**
     * Filtro cart.adjustments: añade una línea por cada precio de esta venta del carrito armado.
     *
     * @param mixed $list
     * @param mixed $summary
     *
     * @return mixed
     */
    public function adjust($list, $summary)
    {
        if ($this->cartId === 0 || $this->prices === [] || $this->request !== $this->app->request() || !is_array($summary)
            || !isset($summary['id']) || (int) $summary['id'] !== $this->cartId) {
            return $list;
        }
        $out = is_array($list) ? $list : [];
        $inclusive = isset($summary['totals']['prices_include_tax']) && !empty($summary['totals']['prices_include_tax']);
        $lines = isset($summary['lines']) && is_array($summary['lines']) ? $summary['lines'] : [];
        foreach ($this->prices as $key => $price) {
            if (!isset($lines[$key]) || empty($lines[$key]['available'])) {
                continue;
            }
            $line = $lines[$key];
            $amount = self::difference((int) $price['price'], (int) $line['qty'], (int) $line['unit_price'], (int) $line['tax_rate'], $inclusive);
            if ($amount === 0) {
                continue;
            }
            $out[] = [
                'label' => t('Price of this sale: {product}', ['product' => self::lineName($line)]),
                'amount' => $amount,
                'source' => 'admin',
                'tax_rate' => (int) $line['tax_rate'],
                'tax_name' => (string) $line['tax_name'],
            ];
        }

        return $out;
    }

    /**
     * Lo que hay que sumar (o restar) a una línea para que cada unidad cueste $price con impuestos, en la forma en que se escriben
     * los precios de la tienda: con los impuestos dentro, la diferencia tal cual; sin ellos, la diferencia de las bases.
     */
    public static function difference(int $price, int $qty, int $unit, int $rate, bool $inclusive): int
    {
        $target = $price * $qty;
        if (!$inclusive) {
            $target = (int) Totals::tax($target, $rate, true, false)['net'];
        }

        return $target - $unit * $qty;
    }

    /**
     * El nombre de una línea con su variante: «Camiseta (M)».
     *
     * @param array<string,mixed> $line
     */
    public static function lineName(array $line): string
    {
        $name = isset($line['name']) ? (string) $line['name'] : '';
        $variant = isset($line['variant_label']) ? trim((string) $line['variant_label']) : '';

        return $variant !== '' ? $name . ' (' . $variant . ')' : $name;
    }
}
