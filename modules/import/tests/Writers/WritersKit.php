<?php
/**
 * AIFEC — apoyo de las pruebas de los escritores de configuración y de catálogo: un contexto de pruebas que implementa WriteContext sobre
 * las tablas reales import_map e import_log, un sitio con los módulos que hacen falta y ayudas para armar registros neutros.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * Los registros salen de neutralSample() y de variaciones suyas: no hay lectores. El contexto delega en el Context real (parejas, imágenes,
 * direcciones antiguas, notas) y además apunta en memoria lo que el escritor cuenta con note() y unmapped(), para poder comprobarlo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\Context;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\WriteContext;
use Aifec\Modules\Import\Writers\AttributesWriter;
use Aifec\Modules\Import\Writers\BrandsWriter;
use Aifec\Modules\Import\Writers\CarriersWriter;
use Aifec\Modules\Import\Writers\CategoriesWriter;
use Aifec\Modules\Import\Writers\ShippingZonesWriter;
use Aifec\Modules\Import\Writers\TaxClassesWriter;
use Aifec\Modules\Import\Writers\TaxRatesWriter;

require_once __DIR__ . '/../helpers.php';

if (!class_exists('WritersContext')) {
    /**
     * El contexto de los escritores en las pruebas. Opciones, equivalencias y simulacro se cambian entre llamadas.
     */
    final class WritersContext implements WriteContext
    {
        /** @var array<int,array{0:string,1:string,2:string,3:string}> tipo, identificador, resultado, motivo */
        public $notes = [];

        /** @var array<string,array{kind:string,name:string,count:int,note:string}> */
        public $unmapped = [];

        /** @var App */
        private $app;

        /** @var string */
        private $filesDir;

        /** @var array<string,mixed> */
        private $options = [];

        /** @var array<string,array<string,string>> */
        private $equivalences = [];

        /** @var bool */
        private $dry = false;

        /** @var Context|null */
        private $inner;

        /** @var string[]|null */
        private $languages;

        public function __construct(App $app, string $filesDir = '')
        {
            $this->app = $app;
            $this->filesDir = $filesDir;
        }

        /** @param array<string,mixed> $options */
        public function options(array $options): self
        {
            $this->options = $options + $this->options;
            $this->inner = null;

            return $this;
        }

        /** @param array<string,array<string,string>> $equivalences */
        public function equivalences(array $equivalences): self
        {
            $this->equivalences = $equivalences;
            $this->inner = null;

            return $this;
        }

        public function dry(bool $on): self
        {
            $this->dry = $on;
            $this->inner = null;

            return $this;
        }

        /** @param string[]|null $languages */
        public function languagesAre(?array $languages): self
        {
            $this->languages = $languages;

            return $this;
        }

        public function app(): App
        {
            return $this->app;
        }

        public function dryRun(): bool
        {
            return $this->dry;
        }

        public function source(): string
        {
            return 'woocommerce';
        }

        public function option(string $key, $default = null)
        {
            return $this->inner()->option($key, $default);
        }

        public function localId(string $type, string $sourceId): ?int
        {
            return $this->inner()->localId($type, $sourceId);
        }

        public function mapped(string $type, string $sourceId): ?array
        {
            return $this->inner()->mapped($type, $sourceId);
        }

        public function remember(string $type, string $sourceId, int $localId, string $sourceHash): void
        {
            $this->inner()->remember($type, $sourceId, $localId, $sourceHash);
        }

        public function equivalent(string $kind, string $sourceValue): ?string
        {
            return $this->inner()->equivalent($kind, $sourceValue);
        }

        public function note(string $type, string $sourceId, string $result, string $reason): void
        {
            $this->notes[] = [$type, $sourceId, $result, $reason];
            $this->inner()->note($type, $sourceId, $result, $reason);
        }

        public function unmapped(string $kind, string $name, int $count = 1, string $note = ''): void
        {
            $key = $kind . "\n" . $name;
            if (!isset($this->unmapped[$key])) {
                $this->unmapped[$key] = ['kind' => $kind, 'name' => $name, 'count' => 0, 'note' => $note];
            }
            $this->unmapped[$key]['count'] += $count;
            $this->inner()->unmapped($kind, $name, $count, $note);
        }

        public function languages(): array
        {
            return $this->languages !== null ? $this->languages : $this->app->languages();
        }

        public function image(array $image, string $ownerType, string $ownerId): ?int
        {
            return $this->inner()->image($image, $ownerType, $ownerId);
        }

        public function oldPaths(string $entity, int $localId, array $paths, string $lang = ''): void
        {
            $this->inner()->oldPaths($entity, $localId, $paths, $lang);
        }

        /** @return string[] los textos de las notas de un tipo */
        public function noteTexts(string $type = ''): array
        {
            $out = [];
            foreach ($this->notes as $note) {
                if ($type === '' || $note[0] === $type) {
                    $out[] = $note[3];
                }
            }

            return $out;
        }

        /** ¿Hay un «sin equivalente» de ese tipo y nombre? Devuelve su cuenta. */
        public function unmappedCount(string $kind, string $name): int
        {
            $key = $kind . "\n" . $name;

            return isset($this->unmapped[$key]) ? $this->unmapped[$key]['count'] : 0;
        }

        private function inner(): Context
        {
            if ($this->inner === null) {
                $run = ['id' => 7, 'source' => 'woocommerce', 'source_key' => 'origen.test', 'dry_run' => $this->dry ? 1 : 0, 'options' => $this->options, 'equivalences' => $this->equivalences];
                $this->inner = new Context($this->app, importService($this->app)->runs(), $run, ['clave-de-mentira'], $this->filesDir);
            }

            return $this->inner;
        }
    }
}

if (!function_exists('writersApp')) {
    /**
     * Sitio con el módulo de migración y los que los escritores tocan: impuestos, envíos, catálogo, medios, clientes, pedidos, las formas de pago y los contenidos (que son quienes sirven las redirecciones).
     *
     * @param array<string,mixed> $settings
     */
    function writersApp(array $settings = []): App
    {
        return importApp(
            ['import', 'tax', 'shipping', 'catalog', 'media', 'customers', 'orders', 'payments-transfer', 'payments-cod', 'payments-redsys', 'payments-paypal', 'seo', 'cms'],
            $settings
        );
    }

    /**
     * @param array<string,mixed>                $options
     * @param array<string,array<string,string>> $equivalences
     */
    function writersContext(App $app, array $options = [], array $equivalences = [], string $filesDir = ''): WritersContext
    {
        return (new WritersContext($app, $filesDir))->options($options)->equivalences($equivalences);
    }

    /**
     * Un registro neutro: el de ejemplo con cambios. Se comprueba que sigue siendo válido para que una prueba no pruebe un registro roto.
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function wr(string $type, array $over = []): array
    {
        $record = array_merge(neutralSample($type), $over);
        $problems = Neutral::check($type, $record);
        check($problems === [], 'el registro de prueba de ' . $type . ' es válido: ' . implode('; ', $problems));

        return $record;
    }

    /**
     * Escribe un registro y devuelve solo su resultado («created», «updated»…).
     *
     * @param array<string,mixed> $record
     */
    function wrResult(TypeWriter $writer, array $record, WriteContext $ctx): string
    {
        $out = $writer->write($record, $ctx);

        return $out['result'];
    }

    /** Un valor de la base de datos (primera columna de la primera fila). */
    function wrValue(App $app, string $sql, array $params = [])
    {
        return $app->db()->value($sql, $params);
    }

    /** Cuántas filas hay en una tabla (con un filtro si se quiere). */
    function wrCount(App $app, string $table, string $where = '1 = 1', array $params = []): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where, $params);
    }

    /**
     * Las filas de todas las tablas del sitio salvo el informe (import_log), que un simulacro sí puede escribir.
     *
     * @return array<string,int>
     */
    function wrCounts(App $app): array
    {
        $counts = importCounts($app);
        unset($counts['af_import_log']);

        return $counts;
    }

    /**
     * Una imagen PNG pequeña dentro de una carpeta (el «path» de una imagen neutra es relativo a ella).
     */
    function wrPng(string $dir, string $name, int $seed = 1): string
    {
        $img = imagecreatetruecolor(40, 30);
        $bg = (int) imagecolorallocate($img, ($seed * 53) % 256, ($seed * 97) % 256, ($seed * 191) % 256);
        imagefilledrectangle($img, 0, 0, 40, 30, $bg);
        if (!is_dir(dirname($dir . '/' . $name))) {
            mkdir(dirname($dir . '/' . $name), 0777, true);
        }
        imagepng($img, $dir . '/' . $name);
        imagedestroy($img);

        return $dir . '/' . $name;
    }

    /**
     * Una clase y unos tipos nuevos (no son de serie): IVA 21 %, IGIC 7 % por código postal y Ceuta y Melilla a 0. Devuelve el identificador de la clase.
     */
    function wrTaxOrigin(App $app, WriteContext $ctx): int
    {
        $classes = new TaxClassesWriter($app);
        $rates = new TaxRatesWriter($app);
        $class = $classes->write(wr('tax_classes', ['id' => 'c1', 'code' => 'iva-general', 'name' => 'IVA general', 'is_default' => false]), $ctx);
        $base = ['class_id' => 'c1', 'country' => 'ES', 'region' => '', 'priority' => 0, 'active' => true, 'compound' => false, 'applies_to_shipping' => true];
        $rates->write(wr('tax_rates', ['id' => 'r1', 'postcodes' => '', 'rate' => 2100, 'name' => 'IVA 21 %'] + $base), $ctx);
        $rates->write(wr('tax_rates', ['id' => 'r2', 'postcodes' => '35,38', 'rate' => 700, 'name' => 'IGIC 7 %', 'priority' => 10] + $base), $ctx);
        $rates->write(wr('tax_rates', ['id' => 'r3', 'postcodes' => '51,52', 'rate' => 0, 'name' => 'Ceuta y Melilla', 'priority' => 10] + $base), $ctx);

        return (int) $class['id'];
    }

    /**
     * Dos zonas (la península con Portugal, y Canarias por código postal) y dos transportistas (tarifas por peso con tres tramos y «gratis
     * desde» 60 €; recogida). Los precios del origen no llevan impuestos. Necesita wrTaxOrigin().
     *
     * @return array{zones:int[],carriers:int[]}
     */
    function wrShippingOrigin(App $app, WriteContext $ctx): array
    {
        $zones = new ShippingZonesWriter($app);
        $carriers = new CarriersWriter($app);
        $z1 = $zones->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Península y Portugal', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => ''], ['country' => 'PT']], 'position' => 0, 'active' => true]), $ctx);
        $z2 = $zones->write(wr('shipping_zones', ['id' => 'z2', 'name' => 'Canarias', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => '35,38']], 'position' => 1, 'active' => true]), $ctx);
        $rates = [];
        foreach (['z1' => [590, 990, 1490], 'z2' => [1290, 1890, 2690]] as $zone => $prices) {
            $rates[] = ['zone_id' => $zone, 'basis' => 'weight', 'min' => 0, 'max' => 5000, 'price' => $prices[0]];
            $rates[] = ['zone_id' => $zone, 'basis' => 'weight', 'min' => 5000, 'max' => 15000, 'price' => $prices[1]];
            $rates[] = ['zone_id' => $zone, 'basis' => 'weight', 'min' => 15000, 'max' => null, 'price' => $prices[2]];
        }
        $a = $carriers->write(wr('carriers', [
            'id' => '4', 'name' => 'Mensajeria', 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => false, 'free_from' => 6000, 'max_weight' => 0,
            'handling_fee' => 0, 'rates' => $rates, 'zone_ids' => ['z1', 'z2'], 'position' => 0, 'active' => true,
        ]), $ctx);
        $b = $carriers->write(wr('carriers', [
            'id' => '5', 'name' => 'Recogida', 'kind' => 'pickup', 'tax_class_id' => 'c1', 'price_includes_tax' => false, 'free_from' => null, 'max_weight' => 0,
            'handling_fee' => 0, 'rates' => [['zone_id' => 'z1', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 0]], 'zone_ids' => ['z1'], 'position' => 1, 'active' => true,
        ]), $ctx);

        return ['zones' => [(int) $z1['id'], (int) $z2['id']], 'carriers' => [(int) $a['id'], (int) $b['id']]];
    }

    /**
     * Lo que el producto de ejemplo necesita para que no le falte nada: la clase de impuesto «1» (la de serie «standard»), la marca «9», las
     * categorías «2» (madre) y «12», y los atributos «a1» (tamaño, con dos valores y para variantes), «f3» (material, texto) y «f4» (altura, número).
     */
    function wrCatalogOrigin(App $app, WriteContext $ctx): void
    {
        (new TaxClassesWriter($app))->write(wr('tax_classes'), $ctx);
        (new BrandsWriter($app))->write(wr('brands', ['old_paths' => []]), $ctx);
        $categories = new CategoriesWriter($app);
        $roots = wr('categories', ['id' => '2', 'parent_id' => null, 'name' => ['es' => 'Muebles'], 'slug' => ['es' => 'muebles'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
        $child = wr('categories', ['old_paths' => []]);
        $categories->write($roots, $ctx);
        $categories->write($child, $ctx);
        $categories->link($roots, $ctx);
        $categories->link($child, $ctx);
        $attributes = new AttributesWriter($app);
        $attributes->write(wr('attributes'), $ctx);
        $attributes->write(wr('attributes', ['id' => 'f3', 'code' => 'material', 'name' => ['es' => 'Material'], 'type' => 'text', 'variant' => false, 'values' => []]), $ctx);
        $attributes->write(wr('attributes', ['id' => 'f4', 'code' => 'height', 'name' => ['es' => 'Altura'], 'type' => 'number', 'unit' => 'cm', 'variant' => false, 'values' => []]), $ctx);
    }

    /** El precio de cada una de las opciones de envío, por código: [código => precio]. */
    function wrPrices(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[(string) $option['code']] = (int) $option['price'];
        }
        ksort($out);

        return $out;
    }

    /** Un destino de envío o de impuestos. */
    function wrDest(string $country, string $postcode = '', string $region = ''): array
    {
        return ['country' => $country, 'region' => $region, 'postcode' => $postcode, 'city' => ''];
    }
}
