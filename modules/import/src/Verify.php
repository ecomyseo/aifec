<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Kernel\Slugs;

/**
 * La comprobación final de una migración: pone lo que había en el origen al lado de lo que hay aquí, con cifras y con muestras, y
 * lo que no cuadra sale en rojo con el registro concreto.
 *
 * Dos tiempos. Mientras se escribe, collect() apunta en el cursor de la migración lo que ve del origen (nunca el registro entero: las
 * cifras, veinte productos al azar con su precio, diez pedidos, diez direcciones antiguas). Al acabar, run() lo compara con las tablas
 * de aquí. El origen no se vuelve a leer: cuando la migración termina sus datos de conexión ya se borraron, y una comprobación
 * que necesitara la tienda vieja viva no se podría repetir.
 *
 * «Al azar» es un resumen: cada muestra se elige por el valor de crc32(semilla|tipo|identificador), y se quedan las de menor valor.
 * Es reproducible (dos pasadas de la misma migración eligen lo mismo), no depende del orden en que llegan los registros y no guarda
 * estado entre tandas más que la propia lista.
 *
 * El precio «final» de un producto es el que paga el cliente con impuestos. Se calcula DESDE el registro del origen y el tipo de
 * impuesto que el propio origen daba (sus tipos de impuesto, también vistos al pasar), y se compara con lo que sale de la fila de
 * aquí por el tipo de aquí: si la conversión de impuestos pierde un céntimo, sale en rojo.
 *
 * Solo comprueba lo que escriben los escritores de este módulo: un escritor de otro (un plugin) puede guardar donde quiera.
 */
final class Verify
{
    /** Productos que se miran uno a uno. */
    public const SAMPLE_PRODUCTS = 20;

    /** Pedidos que se miran uno a uno. */
    public const SAMPLE_ORDERS = 10;

    /** Direcciones antiguas que se piden por su ruta. */
    public const SAMPLE_PATHS = 50;

    /** Variantes de cada producto de la muestra que también se miran. */
    private const VARIANTS = 2;

    /** El estado de pedido de aquí por el significado del estado del origen (el mismo cuadro que usa el escritor de pedidos). */
    private const MEANING = [
        'pending' => 'on_hold', 'on_hold' => 'on_hold', 'paid' => 'processing', 'processing' => 'processing', 'shipped' => 'shipped',
        'delivered' => 'completed', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'failed',
    ];

    /** Nombre de entidad de las direcciones amigables por tipo de migración. */
    private const ENTITY = ['products' => 'product', 'categories' => 'category', 'pages' => 'page', 'posts' => 'post'];

    /** Tipos cuyo recuento se compara. */
    private const COUNTED = ['products', 'categories', 'brands', 'customers', 'orders', 'coupons', 'reviews', 'pages', 'posts'];

    /** @var App */
    private $app;

    /** @var Runs */
    private $runs;

    /** @var Registry */
    private $registry;

    /** @var PriceCheck */
    private $prices;

    public function __construct(App $app, Runs $runs, Registry $registry)
    {
        $this->app = $app;
        $this->runs = $runs;
        $this->registry = $registry;
        $this->prices = new PriceCheck($app);
    }

    // ------------------------------------------------------------------ lo que se apunta mientras se escribe

    /**
     * Las pruebas que se llevan en el cursor al empezar.
     *
     * @return array<string,mixed>
     */
    public static function blank(): array
    {
        return [
            'variants' => 0, 'rates' => [], 'default_class' => null, 'country' => null,
            'products' => [], 'orders' => [], 'paths' => [], 'states' => [], 'years' => [],
        ];
    }

    /**
     * Apunta lo que este registro del origen aporta a la comprobación. Se llama con registros ya comprobados contra el formato
     * neutro, escritos o no (también los que se saltan por «since» cuentan: son del origen).
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $ev
     */
    public static function collect(string $type, array $record, array &$ev, int $seed): void
    {
        $ev = $ev + self::blank();
        $id = isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '';
        if ($id === '') {
            return;
        }
        if ($type === 'settings') {
            $country = isset($record['address']['country']) && is_string($record['address']['country']) ? strtoupper($record['address']['country']) : '';
            if ($country !== '') {
                $ev['country'] = $country;
            }
        } elseif ($type === 'tax_classes') {
            if (!empty($record['is_default'])) {
                $ev['default_class'] = $id;
            }
        } elseif ($type === 'tax_rates') {
            self::collectRate($record, $ev);
        } elseif ($type === 'products') {
            self::collectProduct($record, $id, $ev, $seed);
        } elseif ($type === 'orders') {
            self::collectOrder($record, $id, $ev, $seed);
        }
        // Lo que en el origen no estaba publicado tampoco respondía en su dirección antigua: no es una dirección que tenga que seguir funcionando.
        $published = !isset($record['status']) || !is_string($record['status']) || $record['status'] === 'published';
        if (isset(self::ENTITY[$type]) && $published) {
            foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
                if (is_string($path) && $path !== '' && strlen($path) <= 191) {
                    self::keep($ev['paths'], [self::hash($seed, 'paths', $id . '|' . $path), $type, $id, $path], self::SAMPLE_PATHS);
                }
            }
        }
    }

    /**
     * El tipo de impuesto general de un país para una clase: el primero sin región ni código postal y activo.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $ev
     */
    private static function collectRate(array $record, array &$ev): void
    {
        $country = isset($record['country']) && is_string($record['country']) ? strtoupper($record['country']) : '';
        $class = isset($record['class_id']) && is_scalar($record['class_id']) ? (string) $record['class_id'] : '';
        $general = (!isset($record['region']) || $record['region'] === '') && (!isset($record['postcodes']) || $record['postcodes'] === '');
        $active = !isset($record['active']) || $record['active'] !== false;
        if ($country === '' || $class === '' || !$general || !$active || !isset($record['rate']) || !is_int($record['rate'])) {
            return;
        }
        if (!isset($ev['rates'][$country][$class])) {
            $ev['rates'][$country][$class] = $record['rate'];
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $ev
     */
    private static function collectProduct(array $record, string $id, array &$ev, int $seed): void
    {
        $variants = isset($record['variants']) && is_array($record['variants']) ? $record['variants'] : [];
        $ev['variants'] = (int) $ev['variants'] + count($variants);
        $class = isset($record['tax_class_id']) && is_scalar($record['tax_class_id']) ? (string) $record['tax_class_id'] : null;
        $sale = isset($record['sale_price']) && is_int($record['sale_price']) && !isset($record['sale_reduction']) ? $record['sale_price'] : null;
        $item = [
            self::hash($seed, 'products', $id), $id, isset($record['sku']) && is_string($record['sku']) ? $record['sku'] : '',
            (int) $record['price'], self::micros($record, 'price_micros', (int) $record['price']), !empty($record['price_includes_tax']), $class,
            $sale, $sale !== null ? self::micros($record, 'sale_price_micros', $sale) : null, [],
        ];
        foreach (array_slice($variants, 0, self::VARIANTS) as $variant) {
            if (is_array($variant) && isset($variant['id']) && isset($variant['price']) && is_int($variant['price'])) {
                $item[9][] = [
                    (string) $variant['id'], isset($variant['sku']) && is_string($variant['sku']) ? $variant['sku'] : '', $variant['price'],
                    self::micros($variant, 'price_micros', $variant['price']),
                ];
            }
        }
        self::keep($ev['products'], $item, self::SAMPLE_PRODUCTS);
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $ev
     */
    private static function collectOrder(array $record, string $id, array &$ev, int $seed): void
    {
        $state = isset($record['state']) && is_scalar($record['state']) ? (string) $record['state'] : '';
        $meaning = isset($record['state_meaning']) && is_string($record['state_meaning']) ? $record['state_meaning'] : 'on_hold';
        if (!isset($ev['states'][$state])) {
            $ev['states'][$state] = [$meaning, 0];
        }
        ++$ev['states'][$state][1];
        $year = isset($record['placed_at']) && is_string($record['placed_at']) ? substr($record['placed_at'], 0, 4) : '';
        if ($year !== '') {
            if (!isset($ev['years'][$year])) {
                $ev['years'][$year] = [0, 0];
            }
            ++$ev['years'][$year][0];
            $ev['years'][$year][1] += (int) $record['total'];
        }
        self::keep($ev['orders'], [
            self::hash($seed, 'orders', $id), $id, isset($record['number']) && is_scalar($record['number']) ? (string) $record['number'] : '',
            (int) $record['total'], (int) $record['total_tax'],
        ], self::SAMPLE_ORDERS);
    }

    /**
     * Las millonésimas de un importe, si el registro las trae y son de fiar (redondean al céntimo que las acompaña).
     *
     * @param array<string,mixed> $record
     */
    public static function micros(array $record, string $key, int $cents): ?int
    {
        if (!isset($record[$key]) || !is_int($record[$key]) || $record[$key] < 0 || $cents < 0 || Money::divRound($record[$key], Money::SCALE) !== $cents) {
            return null;
        }

        return $record[$key];
    }

    private static function hash(int $seed, string $kind, string $id): int
    {
        return (int) sprintf('%u', crc32($seed . '|' . $kind . '|' . $id));
    }

    /**
     * Mete una muestra en una lista que se queda con las K de menor valor (el primer elemento de cada una).
     *
     * @param array<int,array<int,mixed>> $list
     * @param array<int,mixed>            $item
     */
    private static function keep(array &$list, array $item, int $max): void
    {
        foreach ($list as $known) {
            if ($known === $item) {
                return;
            }
        }
        $list[] = $item;
        usort($list, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        if (count($list) > $max) {
            $list = array_slice($list, 0, $max);
        }
    }

    // ------------------------------------------------------------------ la comparación final

    /**
     * Compara el origen (lo apuntado) con lo que hay aquí.
     *
     * @param array<string,mixed> $run      fila de import_runs
     * @param array<string,mixed> $ev       lo que apuntó collect()
     * @param array<string,mixed> $counters contadores por tipo de la migración
     * @param string[]            $plan     tipos que se trajeron
     *
     * @return array<string,mixed> ['at', 'ok', 'checked', 'wrong', 'rows' => [...]]; cada fila: section, label, vars, kind (count|cents|text),
     *                             source, here, ok, notes
     */
    public function run(array $run, array $ev, array $counters, WriteContext $ctx, array $plan): array
    {
        $ev = $ev + self::blank();
        $rows = [];
        $supported = array_values(array_filter($plan, function ($type): bool {
            return $this->supports((string) $type);
        }));
        $this->counts($run, $ev, $counters, $supported, $rows);
        if (in_array('orders', $supported, true)) {
            $this->orderTotals($run, $ev, $ctx, $rows);
            $this->orderSamples($ev, $ctx, $rows);
        }
        if (in_array('products', $supported, true)) {
            $this->productSamples($ev, $ctx, $rows);
            $this->allPrices($run, $ctx, $rows);
        }
        $this->addresses($ev, $ctx, $supported, $rows);
        $this->cutTexts($run, $rows);
        $wrong = 0;
        foreach ($rows as $row) {
            if (!$row['ok']) {
                ++$wrong;
            }
        }

        return ['at' => $this->app->clock()->sql(), 'ok' => $wrong === 0, 'checked' => count($rows), 'wrong' => $wrong, 'rows' => $rows];
    }

    /** ¿Lo que escribe este tipo está donde esta comprobación lo busca? Solo si lo escribe un escritor de este módulo. */
    public function supports(string $type): bool
    {
        $class = $this->registry->writerClass($type);

        return $class !== null && strpos($class, __NAMESPACE__ . '\\Writers\\') === 0;
    }

    /**
     * Número de productos, variantes, categorías, clientes, pedidos…: lo leído contra lo que hay aquí.
     *
     * @param array<string,mixed>            $run
     * @param array<string,mixed>            $ev
     * @param array<string,mixed>            $counters
     * @param string[]                       $supported
     * @param array<int,array<string,mixed>> $rows
     */
    private function counts(array $run, array $ev, array $counters, array $supported, array &$rows): void
    {
        $labels = Types::labels();
        foreach (self::COUNTED as $type) {
            if (!in_array($type, $supported, true)) {
                continue;
            }
            $read = isset($counters[$type]['read']) ? (int) $counters[$type]['read'] : 0;
            $rows[] = $this->row('counts', $labels[$type], [], 'count', $read, $this->mapped($run, $type), $this->problems($run, $type));
            if ($type === 'products') {
                $rows[] = $this->row('counts', 'Variants', [], 'count', (int) $ev['variants'], $this->mapped($run, 'variants'), $this->problems($run, 'products'));
            }
        }
    }

    /**
     * Pedidos por estado y suma de importes por año.
     *
     * @param array<string,mixed>            $run
     * @param array<string,mixed>            $ev
     * @param array<int,array<string,mixed>> $rows
     */
    private function orderTotals(array $run, array $ev, WriteContext $ctx, array &$rows): void
    {
        $db = $this->app->db();
        $expected = [];
        foreach ($ev['states'] as $state => $pair) {
            $code = $this->statusFor((string) $state, (string) $pair[0], $ctx);
            $expected[$code] = (isset($expected[$code]) ? $expected[$code] : 0) + (int) $pair[1];
        }
        ksort($expected);
        $here = [];
        foreach ($db->select(
            'SELECT o.status AS k, COUNT(*) AS n FROM {import_map} m JOIN {orders} o ON o.id = m.local_id WHERE m.source = ? AND m.source_key = ? AND m.type = ? GROUP BY o.status',
            [(string) $run['source'], (string) $run['source_key'], 'orders']
        ) as $row) {
            $here[(string) $row['k']] = (int) $row['n'];
        }
        $codes = array_keys($expected + $here);
        sort($codes);
        foreach ($codes as $code) {
            $rows[] = $this->row('states', 'Orders in state «{state}»', ['state' => (string) $code], 'count', isset($expected[$code]) ? $expected[$code] : 0, isset($here[$code]) ? $here[$code] : 0, []);
        }

        $years = [];
        foreach ($db->select(
            'SELECT SUBSTR(o.placed_at, 1, 4) AS y, COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS t FROM {import_map} m JOIN {orders} o ON o.id = m.local_id '
            . 'WHERE m.source = ? AND m.source_key = ? AND m.type = ? GROUP BY SUBSTR(o.placed_at, 1, 4)',
            [(string) $run['source'], (string) $run['source_key'], 'orders']
        ) as $row) {
            $years[(string) $row['y']] = [(int) $row['n'], (int) $row['t']];
        }
        $names = array_keys($ev['years'] + $years);
        sort($names);
        foreach ($names as $year) {
            $source = isset($ev['years'][$year]) ? $ev['years'][$year] : [0, 0];
            $dest = isset($years[$year]) ? $years[$year] : [0, 0];
            $rows[] = $this->row('years', 'Orders placed in {year}', ['year' => (string) $year], 'count', (int) $source[0], $dest[0], []);
            $rows[] = $this->row('years', 'Amount of the orders placed in {year}', ['year' => (string) $year], 'cents', (int) $source[1], $dest[1], []);
        }
    }

    /**
     * Diez pedidos al azar con su total (y sus impuestos).
     *
     * @param array<string,mixed>            $ev
     * @param array<int,array<string,mixed>> $rows
     */
    private function orderSamples(array $ev, WriteContext $ctx, array &$rows): void
    {
        $db = $this->app->db();
        foreach ($ev['orders'] as $item) {
            $local = $ctx->localId('orders', (string) $item[1]);
            $order = $local !== null ? $db->row('SELECT number, total, total_tax FROM {orders} WHERE id = ?', [$local]) : null;
            $vars = ['number' => (string) $item[2] !== '' ? (string) $item[2] : (string) $item[1], 'id' => (string) $item[1]];
            if ($order === null) {
                $rows[] = $this->row('orders', 'Order {number} (#{id}): total', $vars, 'cents', (int) $item[3], null, [], 'orders#' . $item[1]);
                continue;
            }
            $rows[] = $this->row('orders', 'Order {number} (#{id}): total', $vars, 'cents', (int) $item[3], (int) $order['total'], [], 'orders#' . $item[1]);
            $rows[] = $this->row('orders', 'Order {number} (#{id}): tax', $vars, 'cents', (int) $item[4], (int) $order['total_tax'], [], 'orders#' . $item[1]);
        }
    }

    /**
     * Veinte productos al azar con su precio final con impuestos, al céntimo (y el de oferta y el de sus primeras variantes).
     *
     * @param array<string,mixed>            $ev
     * @param array<int,array<string,mixed>> $rows
     */
    private function productSamples(array $ev, WriteContext $ctx, array &$rows): void
    {
        $db = $this->app->db();
        $country = is_string($ev['country']) && $ev['country'] !== '' ? $ev['country'] : null;
        foreach ($ev['products'] as $item) {
            list(, $id, $sku, $price, $micros, $includes, $class, $sale, $saleMicros, $variants) = $item;
            $label = $sku !== '' ? $sku : '#' . $id;
            $vars = ['product' => $label, 'id' => (string) $id];
            $record = 'products#' . $id;
            $local = $ctx->localId('products', (string) $id);
            $product = $local !== null ? $db->row('SELECT price, sale_price, tax_class_id FROM {products} WHERE id = ?', [$local]) : null;
            $rate = $this->prices->sourceRate($ev, $country, $class);
            $note = $rate === null && !$includes ? ['The tax rate of the source is not known: the one of this shop was used'] : [];
            $rateHere = $this->prices->hereRate($product !== null && $product['tax_class_id'] !== null ? (int) $product['tax_class_id'] : null);
            $useRate = $rate !== null ? $rate : $rateHere;
            $rows[] = $this->row('products', 'Product {product} (#{id}): price with tax', $vars, 'cents', $this->prices->gross($price, $micros, $includes, $useRate),
                $product !== null ? $this->prices->hereGross((int) $product['price'], $rateHere) : null, $note, $record);
            if ($sale !== null && $sale > 0) {
                $rows[] = $this->row('products', 'Product {product} (#{id}): sale price with tax', $vars, 'cents', $this->prices->gross($sale, $saleMicros, $includes, $useRate),
                    $product !== null && $product['sale_price'] !== null ? $this->prices->hereGross((int) $product['sale_price'], $rateHere) : null, $note, $record);
            }
            foreach ($variants as $variant) {
                $vvars = $vars + ['variant' => (string) $variant[1] !== '' ? (string) $variant[1] : '#' . $variant[0]];
                $vLocal = $ctx->localId('variants', (string) $variant[0]);
                $vRow = $vLocal !== null ? $db->row('SELECT price FROM {variants} WHERE id = ?', [$vLocal]) : null;
                $written = $vRow !== null ? ($vRow['price'] !== null ? (int) $vRow['price'] : ($product !== null ? (int) $product['price'] : null)) : null;
                $rows[] = $this->row('products', 'Product {product} (#{id}), variant {variant}: price with tax', $vvars, 'cents', $this->prices->gross((int) $variant[2], $variant[3], $includes, $useRate),
                    $written !== null ? $this->prices->hereGross($written, $rateHere) : null, $note, $record);
            }
        }
    }

    /**
     * TODOS los precios con impuestos, producto a producto y variante a variante: lo que pagaba el cliente en el origen contra lo que paga
     * ahora aquí. Una fila resume («n de n») y cada desacuerdo sale con su producto (los que ya salieron en la muestra no se repiten).
     * La diferencia permitida es de cero céntimos.
     *
     * @param array<string,mixed>            $run
     * @param array<int,array<string,mixed>> $rows
     */
    private function allPrices(array $run, WriteContext $ctx, array &$rows): void
    {
        $result = $this->prices->compare($run, $ctx);
        if ($result['checked'] === 0) {
            return;
        }
        $shown = [];
        foreach ($rows as $row) {
            if (!$row['ok']) {
                $shown[$row['label'] . '|' . json_encode($row['vars']) . '|' . $row['record']] = true;
            }
        }
        $rows[] = $this->row('prices', 'Prices with tax identical', [], 'count', $result['checked'], $result['checked'] - $result['wrong'], []);
        foreach ($result['details'] as $item) {
            $label = $item['sku'] !== '' ? (string) $item['sku'] : '#' . $item['product'];
            $vars = ['product' => $label, 'id' => (string) $item['product']];
            if ($item['kind'] === 'v') {
                $text = 'Product {product} (#{id}), variant {variant}: price with tax';
                $vars['variant'] = (string) $item['variant'];
            } else {
                $text = $item['kind'] === 's' ? 'Product {product} (#{id}): sale price with tax' : 'Product {product} (#{id}): price with tax';
            }
            $record = 'products#' . $item['product'];
            if (isset($shown[$text . '|' . json_encode($vars) . '|' . $record])) {
                continue;
            }
            $rows[] = $this->row('prices', $text, $vars, 'cents', (int) $item['source'], (int) $item['gross'], [], $record);
        }
    }

    /**
     * Cuántos textos más largos que su campo se recortaron: no es un fallo (un nombre de 500 letras no cabe en 191), pero se enseña, con el
     * enlace a las notas del informe donde está cada registro.
     *
     * @param array<string,mixed>            $run
     * @param array<int,array<string,mixed>> $rows
     */
    private function cutTexts(array $run, array &$rows): void
    {
        $count = 0;
        foreach ($this->app->db()->column(
            "SELECT reason FROM {import_log} WHERE run_id = ? AND result = 'note' AND reason LIKE ?",
            [(int) $run['id'], 'Some texts were longer than their field and were cut:%']
        ) as $reason) {
            $count += max(1, substr_count((string) $reason, ' → '));
        }
        if ($count === 0) {
            return;
        }
        $row = $this->row('counts', 'Texts cut to fit their field', [], 'count', null, $count, []);
        $row['ok'] = true;
        $row['link'] = 'notes';
        $rows[] = $row;
    }

    /**
     * Diez direcciones antiguas: ¿siguen funcionando? Una vale si responde ella misma (se conservó) o redirige con un 301 a la
     * dirección nueva de lo mismo.
     *
     * @param array<string,mixed>            $ev
     * @param string[]                       $supported
     * @param array<int,array<string,mixed>> $rows
     */
    private function addresses(array $ev, WriteContext $ctx, array $supported, array &$rows): void
    {
        $tested = 0;
        $works = 0;
        $start = count($rows);
        foreach ($ev['paths'] as $item) {
            $type = (string) $item[1];
            if (!in_array($type, $supported, true) || !isset(self::ENTITY[$type])) {
                continue;
            }
            $local = $ctx->localId($type, (string) $item[2]);
            list($lang, $clean) = $this->split((string) $item[3]);
            $vars = ['path' => '/' . $clean];
            $answer = $local !== null ? $this->answer(self::ENTITY[$type], $local, $lang, $clean) : null;
            ++$tested;
            $works += $answer !== null ? 1 : 0;
            $rows[] = [
                'section' => 'addresses', 'label' => 'Old address {path}', 'vars' => $vars, 'kind' => 'text', 'source' => 'Works',
                'here' => $answer !== null ? $answer[0] : 'It does not answer', 'here_vars' => $answer !== null ? $answer[1] : [], 'ok' => $answer !== null,
                'notes' => [], 'record' => $type . '#' . $item[2],
            ];
        }
        if ($tested > 0) {
            // La suma va antes que el detalle: «n de n» es lo primero que se lee.
            $summary = $this->row('addresses', 'Old addresses that work or redirect', [], 'count', $tested, $works, []);
            array_splice($rows, $start, 0, [$summary]);
        }
    }

    /**
     * Qué contestaría la tienda a esa dirección antigua: el texto (y sus valores) de lo que contesta, o null si no lleva a lo que debe.
     *
     * @return array{0:string,1:array<string,string>}|null
     */
    private function answer(string $entity, int $local, string $lang, string $path): ?array
    {
        $db = $this->app->db();
        $hit = $this->app->slugs()->resolve($lang, $path);
        if ($hit !== null && $hit['type'] === $entity && $hit['entity_id'] === $local) {
            if ($hit['canonical'] && $hit['path'] === $path) {
                return ['Works at the same address', []];
            }

            return ['301 to {path}', ['path' => '/' . $hit['path']]];
        }
        foreach ([$path, '/' . $path] as $source) {
            $row = $db->row('SELECT target, code FROM {redirects} WHERE source = ? AND active = 1', [$source]);
            if ($row === null) {
                continue;
            }
            $target = (string) parse_url((string) $row['target'], PHP_URL_PATH);
            $base = trim($this->app->site()->basePath, '/');
            $target = Slugs::clean($base !== '' && strpos(trim($target, '/'), $base . '/') === 0 ? substr(trim($target, '/'), strlen($base) + 1) : $target);
            // La dirección nueva está en el idioma de quien la pide o, si el contenido no existe en él, en el principal.
            foreach (array_unique([$lang, $this->app->defaultLang()]) as $where) {
                $to = $target !== '' ? $this->app->slugs()->resolve($where, $target) : null;
                if ((int) $row['code'] === 301 && $to !== null && $to['type'] === $entity && $to['entity_id'] === $local && $to['canonical']) {
                    return ['301 to {path}', ['path' => '/' . $to['path']]];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * La ruta sin lo que va detrás de «?» y «#» y su idioma, como la entiende la tienda al recibirla: el idioma principal no lleva
     * prefijo y, en los demás, el primer tramo de la ruta es el idioma (App::dispatch).
     *
     * @return array{0:string,1:string}
     */
    private function split(string $raw): array
    {
        $cut = strcspn($raw, '?#');
        $path = Slugs::clean(substr($raw, 0, $cut));
        $lang = $this->app->defaultLang();
        if (preg_match('#^([a-z]{2})(/.*)?$#', $path, $m) === 1 && $m[1] !== $lang && in_array($m[1], $this->app->languages(), true)) {
            $lang = $m[1];
            $path = Slugs::clean(isset($m[2]) ? $m[2] : '');
        }

        return [$lang, $path];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El estado de aquí que le toca a un estado del origen: la equivalencia elegida si ese estado existe, y si no, el del significado.
     */
    private function statusFor(string $state, string $meaning, WriteContext $ctx): string
    {
        $code = $ctx->equivalent('order_state', $state);
        if ($code !== null && $this->app->db()->value('SELECT code FROM {order_states} WHERE code = ?', [$code]) === null) {
            $code = null;
        }
        if ($code === null) {
            $code = isset(self::MEANING[$meaning]) ? self::MEANING[$meaning] : 'on_hold';
        }

        return $code === 'pending_payment' ? 'on_hold' : $code;
    }

    /**
     * Cuántos registros de un tipo hay aquí que vienen de este origen.
     *
     * @param array<string,mixed> $run
     */
    private function mapped(array $run, string $type): int
    {
        return (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM {import_map} WHERE source = ? AND source_key = ? AND type = ?',
            [(string) $run['source'], (string) $run['source_key'], $type]
        );
    }

    /**
     * Los registros de un tipo que no llegaron (descartados o chocados), para ponerlos al lado de la cifra que no cuadra.
     *
     * @param array<string,mixed> $run
     *
     * @return array<int,array{id:string,reason:string}>
     */
    private function problems(array $run, string $type): array
    {
        $out = [];
        foreach (['skipped', 'conflict'] as $result) {
            foreach ($this->runs->logs((int) $run['id'], $type, $result, 5) as $line) {
                $out[] = ['id' => (string) $line['source_id'], 'reason' => (string) $line['reason']];
            }
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Una fila de la comprobación.
     *
     * @param array<string,string>           $vars
     * @param int|string|null                $source
     * @param int|string|null                $here
     * @param array<int,string|array<string,string>> $notes
     *
     * @return array<string,mixed>
     */
    private function row(string $section, string $label, array $vars, string $kind, $source, $here, array $notes, string $record = ''): array
    {
        return [
            'section' => $section, 'label' => $label, 'vars' => $vars, 'kind' => $kind, 'source' => $source, 'here' => $here,
            'ok' => $here !== null && $source === $here, 'notes' => $notes, 'record' => $record,
        ];
    }
}
