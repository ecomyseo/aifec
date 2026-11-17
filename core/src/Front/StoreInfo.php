<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Front;

use Aifec\Commerce\Totals;
use Aifec\Kernel\App;

/**
 * Lo que una tienda de las de siempre enseña en el pie y junto al botón de compra, sacado SIEMPRE de la propia tienda:
 * categorías principales, datos de contacto de «Ajustes → General», formas de pago encendidas, envío (desde cuánto, gratis
 * a partir de cuánto y plazo) y devoluciones. Si un dato no existe, no sale: aquí no hay ningún texto de relleno.
 *
 * Solo pregunta a los servicios que estén cargados («catalog», «payments», «shipping», «tax», «returns», el módulo «cms»)
 * y un servicio que falla no tumba la página: esa línea, simplemente, no sale.
 *
 * Lo que devuelve son datos (textos ya traducidos e importes ya formateados); la pieza del tema los escapa al pintarlos.
 * Las plantillas lo piden con data(): una sola entrada de la caché del sitio por idioma y moneda, con las etiquetas TAGS, así
 * el pie de cada página no cuesta consultas. La ficha, que va a la caché de página, lleva además la etiqueta «shipping» (la
 * vacía cualquier cambio de zonas, transportistas o tarifas); los ajustes vacían «settings» y todas las páginas al guardarse.
 */
final class StoreInfo
{
    /** Categorías que salen en el pie como mucho. */
    public const CATEGORIES = 6;

    /** Código de la página legal de envíos y devoluciones en el módulo de contenidos y su dirección de las tiendas de muestra. */
    private const SHIPPING_PAGE = 'shipping-returns';

    private const SHIPPING_SLUG = 'envios-y-devoluciones';

    /**
     * La entrada de data() no caduca por tiempo: la vacían sus etiquetas (guardar ajustes, zonas, transportistas o tarifas,
     * contenidos o catálogo; «setting:set» vacía la caché entera). Nada de lo que guarda depende de la hora.
     */
    private const TTL = 0;

    /** Lo que vacía la entrada de data(): ajustes (tienda, pagos, devoluciones), envíos, contenidos y catálogo. */
    public const TAGS = ['settings', 'shipping', 'cms', 'catalog'];

    /**
     * Todo lo que piden el pie, la ficha, la portada y el carrito, en UNA entrada de la caché del sitio por idioma y moneda:
     * con la caché hecha no cuesta ninguna consulta. Las plantillas usan esto; las funciones sueltas de abajo calculan.
     *
     * @return array{contact:array<string,string>,categories:array<int,array<string,mixed>>,main:array<string,string>|null,payments:string[],trust:array<int,array<string,string>>}
     */
    public static function data(App $app, bool $trust = false): array
    {
        $base = static function () use ($app): array {
            return [
                'contact' => self::contact($app),
                'categories' => self::categories($app),
                'main' => self::mainCategory($app),
                'payments' => self::payments($app),
            ];
        };
        // Una vista previa (ajustes cambiados solo en memoria, como el personalizador o la comprobación en vivo de un tema)
        // calcula sin tocar la caché: ni lee lo de la tienda de verdad ni deja atrás lo suyo.
        if ($app->settings()->overridden()) {
            return $base() + ['trust' => $trust ? self::trust($app) : []];
        }
        $currency = strtoupper($app->settings()->str('shop.currency', 'EUR'));
        $key = 'store-info:' . $app->lang() . ':' . (preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'EUR');
        $cache = $app->cache();
        $hit = $cache->get($key);
        if (is_array($hit) && (!$trust || (isset($hit['trust']) && is_array($hit['trust'])))) {
            return $hit + ['trust' => []];
        }
        // Las líneas de confianza (envíos, devoluciones) solo se calculan cuando una página las pide —la ficha, el carrito, la
        // portada—: el pie de las demás no paga sus consultas ni la primera vez. Se guardan en la misma entrada.
        $data = is_array($hit) ? $hit : $base();
        if ($trust) {
            $data['trust'] = self::trust($app);
        }
        $cache->set($key, $data, self::TTL, self::TAGS);

        return $data + ['trust' => []];
    }

    /**
     * Correo, teléfono (y su enlace tel:) y dirección de «Ajustes → General». Lo que esté vacío, vacío.
     *
     * @return array{email:string,phone:string,phone_href:string,address:string}
     */
    public static function contact(App $app): array
    {
        $settings = $app->settings();
        $phone = trim($settings->str('store.phone', ''));

        return [
            'email' => trim($settings->str('store.email', '')),
            'phone' => $phone,
            'phone_href' => $phone !== '' ? (string) preg_replace('/[^0-9+]/', '', $phone) : '',
            'address' => trim($settings->str('store.address', '')),
        ];
    }

    /**
     * La categoría principal de la tienda (la primera de primer nivel), o null sin catálogo o sin categorías.
     *
     * @return array{name:string,url:string}|null
     */
    public static function mainCategory(App $app): ?array
    {
        $roots = self::tree($app);
        foreach ($roots as $root) {
            if ((string) $root['name'] !== '') {
                return ['name' => (string) $root['name'], 'url' => (string) $root['url']];
            }
        }

        return null;
    }

    /**
     * Las categorías principales: las de primer nivel que están «en el menú» o, si la tienda tiene UNA sola, sus hijas (es lo
     * que el cliente busca).
     *
     * @return array<int,array{id:int,name:string,url:string}>
     */
    public static function categories(App $app, int $limit = self::CATEGORIES): array
    {
        // Las que el comerciante dejó fuera del menú («en el menú» apagado) tampoco salen aquí.
        $inMenu = [];
        if ($app->has('catalog')) {
            foreach ($app->db()->select('SELECT id FROM {categories} WHERE active = 1 AND in_menu = 1') as $row) {
                $inMenu[(int) $row['id']] = true;
            }
        }
        $visible = static function (array $nodes) use ($inMenu): array {
            return array_values(array_filter($nodes, static function ($node) use ($inMenu): bool {
                return is_array($node) && isset($node['id']) && isset($inMenu[(int) $node['id']]);
            }));
        };
        $nodes = $visible(self::tree($app));
        if (count($nodes) === 1 && isset($nodes[0]['children']) && is_array($nodes[0]['children']) && $visible($nodes[0]['children']) !== []) {
            $nodes = $visible($nodes[0]['children']);
        }
        $out = [];
        foreach ($nodes as $node) {
            if (count($out) >= max(0, $limit)) {
                break;
            }
            if (!isset($node['name'], $node['url']) || trim((string) $node['name']) === '') {
                continue;
            }
            $out[] = ['id' => isset($node['id']) ? (int) $node['id'] : 0, 'name' => (string) $node['name'], 'url' => (string) $node['url']];
        }

        return $out;
    }

    /**
     * Los nombres de las formas de pago que la tienda tiene encendidas, por su orden y sin repetir.
     *
     * @return string[]
     */
    public static function payments(App $app): array
    {
        if (!$app->has('payments')) {
            return [];
        }
        $context = [
            'currency' => strtoupper($app->settings()->str('shop.currency', 'EUR')),
            'country' => self::country($app),
            'needs_shipping' => true,
            'customer_id' => 0,
            'lang' => $app->lang(),
        ];
        $names = [];
        try {
            foreach ($app->service('payments')->available($context) as $method) {
                $info = $method->info();
                $title = isset($info['title']) && is_scalar($info['title']) ? trim((string) $info['title']) : '';
                if ($title !== '' && !in_array($title, $names, true)) {
                    $names[] = $title;
                }
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'store-info.payments');

            return [];
        }

        return $names;
    }

    /**
     * El envío a domicilio al país de la tienda sin dirección: el precio más barato (tal como se enseñan los precios),
     * desde qué importe sale gratis y el plazo de esa opción. Null si no hay ninguna opción calculable sin dirección.
     *
     * @return array{from:int,free_from:int|null,min_days:int|null,max_days:int|null,delay:string}|null
     */
    public static function shipping(App $app): ?array
    {
        if (!$app->has('shipping')) {
            return null;
        }
        $shipping = $app->service('shipping');
        $destination = ['country' => self::country($app), 'region' => '', 'postcode' => '', 'city' => ''];
        try {
            $options = $shipping->options(['weight' => 0, 'items_total' => 0, 'qty' => 1, 'needs_shipping' => true], $destination);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'store-info.shipping');

            return null;
        }
        $cheapest = null;
        $freeFrom = null;
        foreach ($options as $option) {
            // La recogida en tienda no es un envío: ni da el «desde» ni el plazo.
            if (!is_array($option) || !isset($option['price']) || (isset($option['needs_address']) && !$option['needs_address'])) {
                continue;
            }
            if ($cheapest === null || (int) $option['price'] < (int) $cheapest['price']) {
                $cheapest = $option;
            }
            $carrier = isset($option['carrier_id']) ? $shipping->carrier((int) $option['carrier_id']) : null;
            if (is_array($carrier) && $carrier['free_from'] !== null && (int) $carrier['free_from'] > 0) {
                $freeFrom = $freeFrom === null ? (int) $carrier['free_from'] : min($freeFrom, (int) $carrier['free_from']);
            }
        }
        if ($cheapest === null) {
            return null;
        }
        $int = static function ($value): ?int {
            return $value !== null && is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
        };

        return [
            'from' => self::shown($app, (int) $cheapest['price'], $cheapest, $destination),
            'free_from' => $freeFrom,
            'min_days' => $int(isset($cheapest['min_days']) ? $cheapest['min_days'] : null),
            'max_days' => $int(isset($cheapest['max_days']) ? $cheapest['max_days'] : null),
            'delay' => isset($cheapest['delay']) && is_scalar($cheapest['delay']) ? trim((string) $cheapest['delay']) : '',
        ];
    }

    /**
     * Las devoluciones, solo con el módulo «returns» cargado: los días de plazo y, si la tienda lo ha escrito, quién paga la vuelta.
     *
     * @return array{days:int,payer:string}|null
     */
    public static function returns(App $app): ?array
    {
        if (!$app->modules()->isLoaded('returns') || !$app->has('returns')) {
            return null;
        }
        $days = max(0, $app->settings()->int('returns.days', 30));
        if ($days === 0) {
            return null;
        }

        return ['days' => $days, 'payer' => trim($app->settings()->str('returns.return_shipping_payer', ''))];
    }

    /**
     * Las líneas de confianza que van debajo del botón de compra (y en la franja de ventajas de la portada): envío,
     * devolución y pago, cada una solo si la tienda tiene el dato.
     *
     * @return array<int,array{code:string,text:string,detail:string,url:string}>
     */
    public static function trust(App $app): array
    {
        $currency = $app->settings()->str('shop.currency', 'EUR');
        $money = static function (int $cents) use ($app, $currency): string {
            return $app->i18n()->money($cents, $currency);
        };
        $page = self::shippingPageUrl($app);
        $lines = [];

        $ship = self::shipping($app);
        if ($ship !== null) {
            $details = [];
            if ($ship['from'] === 0) {
                $text = t('Free shipping');
            } elseif ($ship['free_from'] !== null) {
                $text = t('Free shipping from {amount}', ['amount' => $money($ship['free_from'])]);
                $details[] = t('Otherwise from {price}', ['price' => $money($ship['from'])]);
            } else {
                $text = t('Shipping from {price}', ['price' => $money($ship['from'])]);
            }
            if ($ship['min_days'] !== null && $ship['max_days'] !== null && $ship['max_days'] > 0) {
                $details[] = $ship['min_days'] === $ship['max_days'] || $ship['min_days'] === 0
                    ? $app->i18n()->tn('Delivery in {n} day', 'Delivery in {n} days', $ship['max_days'])
                    : t('Delivery in {min} to {max} days', ['min' => $ship['min_days'], 'max' => $ship['max_days']]);
            } elseif ($ship['delay'] !== '') {
                $details[] = $ship['delay'];
            }
            $lines[] = ['code' => 'shipping', 'text' => $text, 'detail' => implode(' · ', $details), 'url' => $page];
        } elseif ($page !== '' && $app->has('shipping')) {
            // Sin tarifa calculable sin dirección: la línea lleva a la página que lo explica.
            $lines[] = ['code' => 'shipping', 'text' => t('Shipping costs and delivery times'), 'detail' => '', 'url' => $page];
        }

        $returns = self::returns($app);
        if ($returns !== null) {
            $lines[] = ['code' => 'returns', 'text' => $app->i18n()->tn('Returns within {n} day', 'Returns within {n} days', $returns['days']), 'detail' => $returns['payer'], 'url' => $page];
        }

        $payments = self::payments($app);
        if ($payments !== []) {
            $lines[] = ['code' => 'payment', 'text' => t('Payment: {methods}', ['methods' => implode(', ', $payments)]), 'detail' => '', 'url' => ''];
        }

        return $lines;
    }

    /**
     * Cuánto le falta a un carrito para el envío gratis, con el umbral del transportista (el más bajo de los que se le
     * ofrecen a ese carrito y lo tienen). Se compara con lo mismo que compara el servicio de envíos: el importe de los
     * artículos tal como se escriben los precios. Null si el carrito no se envía o ningún transportista tiene envío gratis.
     *
     * @param array<string,mixed> $cart Cart::summary()
     *
     * @return array{threshold:int,total:int,missing:int,reached:bool,percent:int}|null
     */
    public static function freeShipping(App $app, array $cart): ?array
    {
        if (!$app->has('shipping') || empty($cart['needs_shipping'])) {
            return null;
        }
        $shipping = $app->service('shipping');
        $threshold = null;
        foreach (isset($cart['shipping_options']) && is_array($cart['shipping_options']) ? $cart['shipping_options'] : [] as $option) {
            if (!is_array($option) || !isset($option['carrier_id']) || (isset($option['needs_address']) && !$option['needs_address'])) {
                continue;
            }
            $carrier = $shipping->carrier((int) $option['carrier_id']);
            if (is_array($carrier) && $carrier['free_from'] !== null && (int) $carrier['free_from'] > 0) {
                $threshold = $threshold === null ? (int) $carrier['free_from'] : min($threshold, (int) $carrier['free_from']);
            }
        }
        if ($threshold === null) {
            return null;
        }
        $total = isset($cart['totals']['items_total']) && is_numeric($cart['totals']['items_total']) ? max(0, (int) $cart['totals']['items_total']) : 0;
        $missing = max(0, $threshold - $total);

        return [
            'threshold' => $threshold,
            'total' => $total,
            'missing' => $missing,
            'reached' => $missing === 0,
            'percent' => $missing === 0 ? 100 : (int) min(99, intdiv($total * 100, $threshold)),
        ];
    }

    /**
     * La dirección de la página publicada de envíos y devoluciones: la legal que creó el módulo de contenidos o, en las
     * tiendas de muestra, la de dirección «envios-y-devoluciones». Cadena vacía si no hay ninguna.
     */
    public static function shippingPageUrl(App $app): string
    {
        if (!$app->modules()->isLoaded('cms')) {
            return '';
        }
        $lang = $app->lang();
        $ids = [];
        $map = $app->settings()->arr('cms.legal');
        if (isset($map[self::SHIPPING_PAGE]) && is_numeric($map[self::SHIPPING_PAGE])) {
            $ids[] = (int) $map[self::SHIPPING_PAGE];
        }
        $hit = $app->slugs()->resolve($lang, self::SHIPPING_SLUG);
        if (is_array($hit) && isset($hit['type'], $hit['entity_id']) && $hit['type'] === 'page') {
            $ids[] = (int) $hit['entity_id'];
        }
        foreach ($ids as $id) {
            if ($id > 0 && (string) $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$id]) === 'published') {
                return $app->url()->entity('page', $id, $lang);
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Árbol de categorías activas del catálogo, o vacío sin él.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function tree(App $app): array
    {
        if (!$app->has('catalog')) {
            return [];
        }
        try {
            $tree = $app->service('catalog')->tree();
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'store-info.categories');

            return [];
        }

        return is_array($tree) ? array_values(array_filter($tree, 'is_array')) : [];
    }

    /** País de la tienda: el de partida de los envíos o, sin módulo de envíos, el de los impuestos; «ES» si nada lo dice. */
    private static function country(App $app): string
    {
        if ($app->has('shipping')) {
            return (string) $app->service('shipping')->defaultCountry();
        }
        $country = strtoupper(trim($app->settings()->str('shipping.default_country', 'ES')));

        return preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : 'ES';
    }

    /**
     * Un precio de envío «tal como se escribe» pasado a como se enseñan los precios (con o sin impuestos), igual que el carrito.
     *
     * @param array<string,mixed> $option
     * @param array<string,mixed> $destination
     */
    private static function shown(App $app, int $price, array $option, array $destination): int
    {
        if ($price <= 0 || !$app->has('tax')) {
            return max(0, $price);
        }
        $tax = $app->service('tax');
        if ($tax->pricesIncludeTax() === $tax->displayWithTax()) {
            return $price;
        }
        $classId = isset($option['tax_class_id']) && $option['tax_class_id'] !== null ? (int) $option['tax_class_id'] : null;
        $rate = $classId !== null ? $tax->rate($classId, $destination) : $tax->shippingRate($destination, []);
        $parts = Totals::tax($price, (int) $rate['rate'], $tax->pricesIncludeTax(), false);

        return $tax->displayWithTax() ? (int) $parts['gross'] : (int) $parts['net'];
    }
}
