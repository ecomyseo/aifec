<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Modules\Tax\TaxService;

/**
 * El precio de un producto: cuál es el vigente (oferta, variante) y el bloque que se enseña (contrato de comercio § 5).
 *
 * Todo lo que se guarda va «tal como se escribe» (con o sin impuestos según tax.prices_include_tax) y en céntimos.
 * Un precio «efectivo» es el array ['price' => int, 'compare' => ?int, 'sale_until' => ?string] antes de poner impuestos.
 */
final class Pricing
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * ¿Está en marcha la oferta del producto? Hay precio de oferta, es menor que el normal y ahora cae entre
     * sale_from y sale_to (nulos o vacíos = sin límite; los dos extremos entran).
     *
     * @param array<string,mixed> $product fila de products
     * @param string              $now     «Y-m-d H:i:s» en UTC
     */
    public function saleActive(array $product, string $now): bool
    {
        if (!isset($product['sale_price']) || $product['sale_price'] === null || $product['sale_price'] === '') {
            return false;
        }
        if ((int) $product['sale_price'] >= (int) $product['price']) {
            return false;
        }
        if (!empty($product['sale_from']) && (string) $product['sale_from'] > $now) {
            return false;
        }
        if (!empty($product['sale_to']) && (string) $product['sale_to'] < $now) {
            return false;
        }

        return true;
    }

    /**
     * El precio vigente de un producto o de una de sus variantes.
     *
     * Una variante con precio propio usa el suyo (y su compare_price si es mayor); con el precio nulo, el del producto
     * con su oferta. Sin oferta, «compare» es compare_price; con oferta, es el precio normal. Solo si es mayor que el vigente.
     *
     * @param array<string,mixed>      $product fila de products
     * @param array<string,mixed>|null $variant fila de variants
     *
     * @return array{price:int,compare:?int,sale_until:?string}
     */
    public function effective(array $product, ?array $variant, string $now): array
    {
        if ($variant !== null && isset($variant['price']) && $variant['price'] !== null && $variant['price'] !== '') {
            $price = (int) $variant['price'];
            $compare = isset($variant['compare_price']) && $variant['compare_price'] !== null && $variant['compare_price'] !== ''
                ? (int) $variant['compare_price'] : null;

            return ['price' => $price, 'compare' => $compare !== null && $compare > $price ? $compare : null, 'sale_until' => null];
        }
        $base = (int) $product['price'];
        if ($this->saleActive($product, $now)) {
            return [
                'price' => (int) $product['sale_price'],
                'compare' => $base,
                'sale_until' => !empty($product['sale_to']) ? (string) $product['sale_to'] : null,
            ];
        }
        $compare = isset($product['compare_price']) && $product['compare_price'] !== null && $product['compare_price'] !== ''
            ? (int) $product['compare_price'] : null;

        return ['price' => $base, 'compare' => $compare !== null && $compare > $base ? $compare : null, 'sale_until' => null];
    }

    /**
     * El precio que se enseña de un producto: el suyo o, si tiene variantes, el de la más barata (con from = true
     * cuando sus precios no son todos iguales).
     *
     * @param array<string,mixed>            $product  fila de products
     * @param array<int,array<string,mixed>> $variants variantes activas (vacío si el producto no tiene)
     *
     * @return array{eff:array{price:int,compare:?int,sale_until:?string},variant:?array<string,mixed>,from:bool}
     */
    public function cheapest(array $product, array $variants, string $now): array
    {
        if ($variants === []) {
            return ['eff' => $this->effective($product, null, $now), 'variant' => null, 'from' => false];
        }
        $best = null;
        $bestEff = null;
        $min = null;
        $max = null;
        foreach ($variants as $variant) {
            $eff = $this->effective($product, $variant, $now);
            if ($min === null || $eff['price'] < $min) {
                $min = $eff['price'];
                $best = $variant;
                $bestEff = $eff;
            }
            if ($max === null || $eff['price'] > $max) {
                $max = $eff['price'];
            }
        }

        return ['eff' => $bestEff, 'variant' => $best, 'from' => $min !== $max];
    }

    /**
     * El bloque de precio de un producto (con o sin variantes).
     *
     * @param array<string,mixed>            $product
     * @param array<int,array<string,mixed>> $variants variantes activas
     * @param array<string,mixed>            $destination
     * @param array<string,mixed>|null       $customer
     *
     * @return array<string,mixed>
     */
    public function productBlock(array $product, array $variants, string $now, array $destination = [], ?array $customer = null): array
    {
        $pick = $this->cheapest($product, $variants, $now);

        return $this->block($pick['eff'], $this->classOf($product), $destination, $pick['from'], $customer, $this->context($product, $pick['variant'], $pick['eff']));
    }

    /**
     * El bloque de precio de una variante.
     *
     * @param array<string,mixed>      $product
     * @param array<string,mixed>      $variant
     * @param array<string,mixed>      $destination
     * @param array<string,mixed>|null $customer
     *
     * @return array<string,mixed>
     */
    public function variantBlock(array $product, array $variant, string $now, array $destination = [], ?array $customer = null): array
    {
        $eff = $this->effective($product, $variant, $now);

        return $this->block($eff, $this->classOf($product), $destination, false, $customer, $this->context($product, $variant, $eff));
    }

    /**
     * El bloque de precio de lo que devuelve purchasable() (lo que usa el carrito para enseñar un importe).
     *
     * @param array<string,mixed>      $purchasable
     * @param array<string,mixed>|null $destination null = el de la tienda
     * @param array<string,mixed>|null $customer
     *
     * @return array<string,mixed>
     */
    public function fromPurchasable(array $purchasable, ?array $destination = null, ?array $customer = null): array
    {
        $compare = isset($purchasable['compare_price']) && $purchasable['compare_price'] !== null ? (int) $purchasable['compare_price'] : null;
        $eff = [
            'price' => (int) $purchasable['unit_price'],
            'compare' => $compare,
            'sale_until' => isset($purchasable['sale_until']) && $purchasable['sale_until'] !== null ? (string) $purchasable['sale_until'] : null,
        ];
        $class = isset($purchasable['tax_class_id']) && $purchasable['tax_class_id'] !== null ? (int) $purchasable['tax_class_id'] : null;
        $context = [
            'id' => (int) (isset($purchasable['product_id']) ? $purchasable['product_id'] : 0),
            'variant_id' => (int) (isset($purchasable['variant_id']) ? $purchasable['variant_id'] : 0),
            'sku' => isset($purchasable['sku']) ? (string) $purchasable['sku'] : '',
            'kind' => isset($purchasable['kind']) ? (string) $purchasable['kind'] : 'physical',
            'tax_class_id' => $class,
            'price' => $eff['price'],
            'compare_price' => $eff['compare'],
        ];

        return $this->block($eff, $class, $destination !== null ? $destination : [], false, $customer, $context);
    }

    /**
     * Pone los impuestos y arma el bloque de precio. Pasa por el filtro sellado product.price (array $block, array $product).
     *
     * @param array{price:int,compare:?int,sale_until:?string} $eff
     * @param array<string,mixed>                              $destination vacío = el de la tienda
     * @param array<string,mixed>|null                         $customer
     * @param array<string,mixed>                              $context     el segundo argumento del filtro
     *
     * @return array<string,mixed>
     */
    public function block(array $eff, ?int $taxClassId, array $destination, bool $from, ?array $customer, array $context): array
    {
        $tax = $this->tax();
        $rate = $tax->rate($taxClassId, $destination);
        $exempt = $customer !== null && $tax->exempt($customer, $destination !== [] ? $destination : $tax->baseDestination());
        $rateValue = (int) $rate['rate'];
        $include = $tax->pricesIncludeTax();

        $convert = static function (int $written) use ($include, $rateValue): array {
            if ($include) {
                [$net, $taxAmount] = Money::split($written, $rateValue);

                return [$net, $taxAmount, $written];
            }
            [$taxAmount, $gross] = Money::add($written, $rateValue);

            return [$written, $taxAmount, $gross];
        };

        [$net, $taxAmount, $gross] = $convert($eff['price']);
        if ($exempt) {
            // Un cliente exento no paga impuestos: la base sale del precio con el tipo de verdad, y es lo que se le enseña.
            $gross = $net;
            $taxAmount = 0;
        }
        $withTax = $tax->displayWithTax() && !$exempt;
        $amount = $withTax ? $gross : $net;

        $compare = null;
        if ($eff['compare'] !== null) {
            [$compareNet, , $compareGross] = $convert($eff['compare']);
            $shown = $withTax ? $compareGross : $compareNet;
            if ($shown > $amount) {
                $compare = $shown;
            }
        }
        $discount = $compare !== null ? Money::divRound(($compare - $amount) * 100, $compare) : null;

        $block = [
            'amount' => $amount,
            'with_tax' => $withTax,
            'net' => $net,
            'tax' => $taxAmount,
            'gross' => $gross,
            'tax_rate' => $exempt ? 0 : $rateValue,
            'tax_name' => $exempt ? '' : (string) $rate['name'],
            'currency' => $this->app->settings()->str('shop.currency', 'EUR'),
            'compare' => $compare,
            'on_sale' => $compare !== null,
            'discount_percent' => $discount,
            'sale_until' => $compare !== null ? $eff['sale_until'] : null,
            'from' => $from,
        ];
        $filtered = $this->app->hooks()->apply('product.price', $block, $context);

        return is_array($filtered) ? $filtered : $block;
    }

    /**
     * El bloque de un producto cuyo precio no se enseña (disponibilidad con show_price = false): la misma forma, con todos los
     * importes a cero y «hidden» = true. Quien pinta el precio (las plantillas, los datos estructurados, los feeds) mira «hidden»
     * y no enseña nada; quien no lo mire, al menos no filtra el importe verdadero.
     *
     * @param array<string,mixed> $block
     *
     * @return array<string,mixed>
     */
    public static function hide(array $block): array
    {
        foreach (['amount', 'net', 'tax', 'gross'] as $key) {
            if (array_key_exists($key, $block)) {
                $block[$key] = 0;
            }
        }
        foreach (['compare', 'discount_percent', 'sale_until'] as $key) {
            if (array_key_exists($key, $block)) {
                $block[$key] = null;
            }
        }
        $block['on_sale'] = false;
        $block['from'] = false;
        $block['hidden'] = true;

        return $block;
    }

    /**
     * Lo que se pasa al filtro product.price como «producto».
     *
     * @param array<string,mixed>      $product
     * @param array<string,mixed>|null $variant
     * @param array{price:int,compare:?int,sale_until:?string} $eff
     *
     * @return array<string,mixed>
     */
    private function context(array $product, ?array $variant, array $eff): array
    {
        return [
            'id' => (int) $product['id'],
            'variant_id' => $variant !== null ? (int) $variant['id'] : 0,
            'sku' => $variant !== null && isset($variant['sku']) && $variant['sku'] !== null && $variant['sku'] !== ''
                ? (string) $variant['sku'] : (isset($product['sku']) ? (string) $product['sku'] : ''),
            'kind' => isset($product['kind']) ? (string) $product['kind'] : 'physical',
            'tax_class_id' => $this->classOf($product),
            'price' => $eff['price'],
            'compare_price' => $eff['compare'],
        ];
    }

    /**
     * @param array<string,mixed> $product
     */
    private function classOf(array $product): ?int
    {
        return isset($product['tax_class_id']) && $product['tax_class_id'] !== null && $product['tax_class_id'] !== ''
            ? (int) $product['tax_class_id'] : null;
    }

    private function tax(): TaxService
    {
        $service = $this->app->service('tax');
        if (!$service instanceof TaxService) {
            throw new \LogicException('The tax service is not available');
        }

        return $service;
    }
}
