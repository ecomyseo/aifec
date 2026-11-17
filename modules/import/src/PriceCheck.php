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

/**
 * Lo que cobraba el origen con impuestos por cada producto y cada variante, y lo que cobra esta tienda: sin muestras, uno por uno.
 *
 * Mientras se escribe, remember() apunta por cada producto escrito el importe con impuestos que pagaba el cliente en el origen (calculado
 * desde el registro del origen y el tipo que el propio origen daba: millonésimas si las trae, con UN solo redondeo, sobre el importe con
 * impuestos). Se guarda en import_map con el tipo «_gross» (la pareja no es un registro de aquí: su «local_id» es el importe en céntimos), así
 * no engorda el cursor de la migración y sigue ahí cuando se vuelve a comprobar (import:verify). compare() lo pone al lado de lo que cuesta
 * ahora aquí, con el tipo de aquí y según cómo se escriben los precios: la diferencia permitida es de cero céntimos.
 */
final class PriceCheck
{
    /** Tipo de import_map con el que se apuntan los importes del origen. */
    public const TYPE = '_gross';

    /** Cuántos desacuerdos se enseñan con su producto (el recuento es de todos). */
    public const MAX_DETAILS = 30;

    /** Filas de import_map que se leen de una vez. */
    private const PAGE = 500;

    /** @var App */
    private $app;

    /** @var array<string,int|null> tipos de aquí ya calculados, por clase */
    private $rates = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ mientras se escribe

    /**
     * Apunta lo que cobraba el origen con impuestos por un producto ya escrito, y por cada una de sus variantes.
     *
     * @param array<string,mixed> $record  el producto neutro
     * @param array<string,mixed> $ev      lo que apuntó Verify::collect (tipos de impuesto del origen)
     * @param int|null            $localId el producto de aquí
     */
    public function remember(array $record, array $ev, WriteContext $ctx, ?int $localId): void
    {
        $id = isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '';
        if ($localId === null || $id === '' || strlen($id) > 60 || $ctx->dryRun()) {
            return;
        }
        $ev = $ev + Verify::blank();
        $class = $this->app->db()->value('SELECT tax_class_id FROM {products} WHERE id = ?', [$localId]);
        $rateHere = $this->hereRate($class !== null ? (int) $class : null);
        $classKey = isset($record['tax_class_id']) && is_scalar($record['tax_class_id']) ? (string) $record['tax_class_id'] : null;
        $rate = $this->sourceRate($ev, is_string($ev['country']) && $ev['country'] !== '' ? $ev['country'] : null, $classKey);
        $use = $rate !== null ? $rate : $rateHere;
        $includes = !empty($record['price_includes_tax']);

        if (isset($record['price']) && is_int($record['price'])) {
            $ctx->remember(self::TYPE, 'p:' . $id, $this->gross($record['price'], Verify::micros($record, 'price_micros', $record['price']), $includes, $use), '');
        }
        if (isset($record['sale_price']) && is_int($record['sale_price']) && $record['sale_price'] > 0 && !isset($record['sale_reduction'])) {
            $ctx->remember(self::TYPE, 's:' . $id, $this->gross($record['sale_price'], Verify::micros($record, 'sale_price_micros', $record['sale_price']), $includes, $use), '');
        }
        foreach (isset($record['variants']) && is_array($record['variants']) ? $record['variants'] : [] as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || !is_scalar($variant['id']) || !isset($variant['price']) || !is_int($variant['price'])) {
                continue;
            }
            // La clave lleva también el producto (al enseñar un desacuerdo hace falta): «v:<variante>|<producto>».
            $key = 'v:' . $variant['id'] . '|' . $id;
            if (strlen($key) <= 64) {
                $ctx->remember(self::TYPE, $key, $this->gross($variant['price'], Verify::micros($variant, 'price_micros', $variant['price']), $includes, $use), '');
            }
        }
    }

    // ------------------------------------------------------------------ la comparación

    /**
     * Compara todo lo apuntado en esta migración con lo que cuesta ahora aquí.
     *
     * @param array<string,mixed> $run fila de import_runs
     *
     * @return array{checked:int,wrong:int,details:array<int,array<string,mixed>>} cada desacuerdo: kind (p, s o v), product (id de origen), sku, variant, source, here
     */
    public function compare(array $run, WriteContext $ctx): array
    {
        $db = $this->app->db();
        $checked = 0;
        $wrong = 0;
        $details = [];
        $last = 0;
        while (true) {
            $page = $db->select(
                'SELECT id, source_id, local_id FROM {import_map} WHERE source = ? AND source_key = ? AND type = ? AND run_id = ? AND id > ? ORDER BY id LIMIT ' . self::PAGE,
                [(string) $run['source'], (string) $run['source_key'], self::TYPE, (int) $run['id'], $last]
            );
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $last = (int) $row['id'];
                $found = $this->here($ctx, (string) $row['source_id']);
                if ($found === null) {
                    continue;
                }
                ++$checked;
                if ($found['gross'] !== (int) $row['local_id']) {
                    ++$wrong;
                    if (count($details) < self::MAX_DETAILS) {
                        $details[] = $found + ['source' => (int) $row['local_id']];
                    }
                }
            }
        }

        return ['checked' => $checked, 'wrong' => $wrong, 'details' => $details];
    }

    /**
     * Lo que cuesta con impuestos aquí lo apuntado con esa clave («p:12», «s:12» o «v:30»); null si ya no está aquí.
     *
     * @return array{gross:int,kind:string,product:string,sku:string,variant:string}|null
     */
    private function here(WriteContext $ctx, string $key): ?array
    {
        $db = $this->app->db();
        $kind = substr($key, 0, 1);
        $ref = substr($key, 2);
        if ($kind === 'v') {
            $cut = (int) strrpos($ref, '|');
            $variantSource = substr($ref, 0, $cut);
            $local = $ctx->localId('variants', $variantSource);
            $variant = $local !== null
                ? $db->row('SELECT v.price AS vprice, v.sku AS vsku, p.price, p.tax_class_id, p.sku FROM {variants} v JOIN {products} p ON p.id = v.product_id WHERE v.id = ?', [$local])
                : null;
            if ($variant === null) {
                return null;
            }
            $written = $variant['vprice'] !== null ? (int) $variant['vprice'] : (int) $variant['price'];

            return [
                'gross' => $this->hereGross($written, $this->hereRate($variant['tax_class_id'] !== null ? (int) $variant['tax_class_id'] : null)),
                'kind' => 'v', 'product' => substr($ref, $cut + 1), 'sku' => (string) $variant['sku'],
                'variant' => $variant['vsku'] !== null && $variant['vsku'] !== '' ? (string) $variant['vsku'] : '#' . $variantSource,
            ];
        }
        $local = $ctx->localId('products', $ref);
        $product = $local !== null ? $db->row('SELECT price, sale_price, tax_class_id, sku FROM {products} WHERE id = ?', [$local]) : null;
        if ($product === null || ($kind === 's' && $product['sale_price'] === null)) {
            return null;
        }
        $written = $kind === 's' ? (int) $product['sale_price'] : (int) $product['price'];

        return [
            'gross' => $this->hereGross($written, $this->hereRate($product['tax_class_id'] !== null ? (int) $product['tax_class_id'] : null)),
            'kind' => $kind === 's' ? 's' : 'p', 'product' => $ref, 'sku' => (string) $product['sku'], 'variant' => '',
        ];
    }

    // ------------------------------------------------------------------ piezas (también las usa Verify)

    /**
     * Lo que cuesta con impuestos un precio del origen: tal cual si ya los llevaba; si no, el tipo del origen sobre las millonésimas
     * (o sobre el céntimo si no las hay).
     */
    public function gross(int $cents, ?int $micros, bool $includes, ?int $rate): int
    {
        if ($includes || $rate === null || $rate <= 0) {
            return $cents;
        }
        if ($micros !== null) {
            return Money::divRound($micros * (Money::SCALE + $rate), 100000000);
        }

        return Money::add($cents, $rate)[1];
    }

    /** Lo que cuesta con impuestos una fila de aquí: el precio como se escribe, con el tipo de aquí si se escribe sin impuestos. */
    public function hereGross(int $written, ?int $rate): int
    {
        if ($this->app->has('tax') && !$this->app->service('tax')->pricesIncludeTax()) {
            return Money::add($written, (int) $rate)[1];
        }

        return $written;
    }

    /** El tipo de impuesto de aquí de una clase para el país de la tienda. */
    public function hereRate(?int $classId): ?int
    {
        if (!$this->app->has('tax')) {
            return null;
        }
        $key = $classId === null ? 'none' : (string) $classId;
        if (!array_key_exists($key, $this->rates)) {
            $tax = $this->app->service('tax');
            $rate = $tax->rate($classId, $tax->baseDestination());
            $this->rates[$key] = max(0, (int) $rate['rate']);
        }

        return $this->rates[$key];
    }

    /**
     * El tipo que cobraba el origen para ese producto: el de su clase (o el de la clase por defecto) en el país de su tienda.
     *
     * @param array<string,mixed> $ev
     */
    public function sourceRate(array $ev, ?string $country, ?string $class): ?int
    {
        if ($country === null && $this->app->has('tax')) {
            $base = $this->app->service('tax')->baseDestination();
            $country = isset($base['country']) ? strtoupper((string) $base['country']) : null;
        }
        $key = $class !== null ? $class : (is_string($ev['default_class']) ? $ev['default_class'] : null);
        if ($country === null || $key === null || !isset($ev['rates'][$country][$key])) {
            return null;
        }

        return (int) $ev['rates'][$country][$key];
    }
}
