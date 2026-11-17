<?php
/**
 * AIFEC — ayudas de prueba del buscador. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   searchApp(array $settings = [], array $more = []): App
 *       Aplicación de prueba con el buscador, el catálogo y los medios (más los módulos que se pidan), arrancada.
 *   searchService(App $app): Search
 *       El servicio «search» ya comprobado.
 *   searchShop(App $app): array
 *       Una tienda pequeña e inventada, ya indexada; devuelve los identificadores por clave (silla_roja, silla_azul, mesa, sillon, banco…).
 *   searchSynonym(App $app, string $mode, ?string $source, string $terms, string $lang = ''): int
 *       Da de alta un sinónimo y vacía la caché de sinónimos.
 *   searchIds(App $app, string $q, array $options = []): int[]
 *       Los identificadores que da query().
 *   searchDocs(App $app, string $type = 'product'): array
 *       Los documentos indexados (fila de search_docs), por identificador de entidad.
 *   searchCheckTerms(App $app): void
 *       Comprueba que las cuentas de search_terms cuadran con las apariciones guardadas (df = filas de search_postings).
 *   FakeAi
 *       Un servicio «ai» de mentira: apunta lo que se le pide y devuelve lo que se le haya dado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiResult;
use Aifec\Kernel\App;
use Aifec\Modules\Search\Search;

require_once __DIR__ . '/../../catalog/tests/helpers.php';

if (!function_exists('searchApp')) {
    /**
     * @param array<string,mixed> $settings
     * @param string[]            $more módulos de más (por ejemplo 'jobs' o 'cms')
     */
    function searchApp(array $settings = [], array $more = []): App
    {
        return catalogApp($settings, array_merge(['search', 'catalog', 'media'], $more));
    }

    function searchService(App $app): Search
    {
        $service = $app->service('search');
        check($service instanceof Search, 'el servicio search es el del módulo');

        return $service;
    }

    /**
     * Una tienda pequeña. Se indexa entera al final.
     *
     * @return array<string,int>
     */
    function searchShop(App $app): array
    {
        $sillas = aifecCategory($app, 'Sillas');
        $mesas = aifecCategory($app, 'Mesas');
        $exterior = aifecCategory($app, 'Exterior');
        $nordika = aifecBrand($app, 'Nordika');
        aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul']);

        $ids = [];
        $ids['silla_roja'] = aifecProduct($app, [
            'name' => 'Silla roja', 'sku' => 'SIL-001', 'gtin' => '8412345678905', 'summary' => 'Silla de comedor muy cómoda',
            'categories' => [$sillas], 'brand_id' => $nordika, 'attributes' => ['color' => 'rojo'], 'sales_count' => 5, 'images' => 1,
            'price' => 5990,
        ]);
        $ids['silla_azul'] = aifecProduct($app, [
            'name' => 'Silla azul', 'sku' => 'SIL-002', 'categories' => [$sillas], 'brand_id' => $nordika,
            'attributes' => ['color' => 'azul'], 'stock' => 0, 'sales_count' => 50, 'price' => 6490,
        ]);
        $ids['mesa'] = aifecProduct($app, [
            'name' => 'Mesa de roble', 'sku' => 'MES-001', 'categories' => [$mesas], 'stock' => 5,
            'description' => '<p>Mesa robusta de madera maciza para el salón.</p>', 'price' => 29900,
        ]);
        $ids['mesa_comedor'] = aifecProduct($app, [
            'name' => 'Mesa de comedor extensible', 'sku' => 'MES-002', 'categories' => [$mesas], 'price' => 39900,
        ]);
        $ids['sillon'] = aifecProduct($app, [
            'name' => 'Sillón orejero', 'categories' => [$sillas], 'tags' => ['lectura'], 'summary' => 'Sillón relax para leer', 'stock' => 3,
        ]);
        $ids['banco'] = aifecProduct($app, [
            'name' => 'Banco de jardín', 'categories' => [$exterior], 'description' => '<p>Ideal para la terraza y el jardín.</p>',
        ]);
        $ids['lampara'] = aifecProduct($app, [
            'name' => 'Lámpara de pie', 'summary' => 'Luz cálida para el rincón de lectura', 'facts' => [['label' => 'Material', 'value' => 'Latón']],
        ]);
        searchService($app)->reindexNow();

        return $ids;
    }

    /**
     * Un sitio con el buscador, el módulo de tareas y un servicio «ai» de mentira.
     *
     * @param array<string,mixed> $settings
     *
     * @return array{0:App,1:FakeAi}
     */
    function searchAiApp(array $settings = []): array
    {
        $app = searchApp($settings, ['jobs']);
        $fake = new FakeAi();
        $app->bind('ai', static function () use ($fake): FakeAi {
            return $fake;
        });

        return [$app, $fake];
    }

    /** Hace todo lo que haya en la cola de tareas. Devuelve cuántos trabajos acabaron bien. */
    function searchWork(App $app): int
    {
        $done = 0;
        for ($i = 0; $i < 20; ++$i) {
            $step = $app->service('jobs')->work(5);
            $done += $step['done'];
            if ($step['done'] + $step['failed'] + $step['retried'] === 0) {
                break;
            }
        }

        return $done;
    }

    function searchJobCount(App $app, string $type, string $status = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM {jobs} WHERE type = ?';
        $params = [$type];
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        return (int) $app->db()->value($sql, $params);
    }

    function searchSynonym(App $app, string $mode, ?string $source, string $terms, string $lang = ''): int
    {
        $now = $app->clock()->sql();
        $id = $app->db()->insert('synonyms', ['lang' => $lang, 'mode' => $mode, 'source' => $source, 'terms' => $terms, 'created_at' => $now, 'updated_at' => $now]);
        $app->invalidate(['search']);

        return $id;
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return int[]
     */
    function searchIds(App $app, string $q, array $options = []): array
    {
        $result = searchService($app)->query($q, $options);

        return array_map('intval', $result['ids']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function searchDocs(App $app, string $type = 'product'): array
    {
        $out = [];
        foreach ($app->db()->select('SELECT * FROM {search_docs} WHERE type = ? ORDER BY entity_id', [$type]) as $row) {
            $out[(int) $row['entity_id']] = $row;
        }

        return $out;
    }

    /** Las cuentas de search_terms (en cuántos documentos sale cada término) tienen que ser las filas que hay en search_postings. */
    function searchCheckTerms(App $app): void
    {
        $db = $app->db();
        $wrong = $db->select(
            'SELECT t.term, t.df, (SELECT COUNT(*) FROM {search_postings} p WHERE p.term_id = t.id) AS n FROM {search_terms} t'
        );
        foreach ($wrong as $row) {
            same((int) $row['n'], (int) $row['df'], 'el término «' . $row['term'] . '» dice salir en ' . $row['df'] . ' documentos y sale en ' . $row['n']);
            check((int) $row['df'] > 0, 'un término sin documentos tiene que haberse borrado: ' . $row['term']);
        }
        same(0, (int) $db->value('SELECT COUNT(*) FROM {search_postings} WHERE term_id NOT IN (SELECT id FROM {search_terms})'), 'apariciones de un término que no existe');
        same(0, (int) $db->value('SELECT COUNT(*) FROM {search_postings} WHERE doc_id NOT IN (SELECT id FROM {search_docs})'), 'apariciones de un documento que no existe');
    }

    /**
     * Da de alta muchos productos a la vez, directamente en la base (sin pasar por el panel): para las pruebas de tamaño.
     * Los nombres salen de dos listas de palabras, así que se repiten y hay palabras corrientes y raras.
     *
     * @return int[]
     */
    function searchBulkProducts(App $app, int $count): array
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $nouns = ['silla', 'mesa', 'sillon', 'estante', 'lampara', 'banco', 'cama', 'armario', 'escritorio', 'taburete', 'cajonera', 'espejo'];
        $adjectives = ['roja', 'azul', 'grande', 'pequena', 'nordica', 'rustica', 'moderna', 'plegable', 'extensible', 'ligera'];
        $materials = ['roble', 'pino', 'acero', 'cristal', 'bambu', 'nogal', 'lino', 'cuero'];
        $ids = [];
        $db->transaction(static function () use ($db, $now, $count, $nouns, $adjectives, $materials, &$ids): void {
            for ($i = 0; $i < $count; ++$i) {
                $noun = $nouns[$i % count($nouns)];
                $adjective = $adjectives[intdiv($i, 3) % count($adjectives)];
                $material = $materials[intdiv($i, 7) % count($materials)];
                $id = $db->insert('products', [
                    'kind' => 'physical', 'sku' => 'SKU-' . $i, 'gtin' => null, 'mpn' => null, 'brand_id' => null, 'family_id' => null,
                    'price' => 1000 + $i, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null,
                    'manage_stock' => 1, 'stock' => $i % 5 === 0 ? 0 : 10, 'stock_alert' => 0, 'backorders' => 'no', 'weight' => 0, 'length' => 0,
                    'width' => 0, 'height' => 0, 'min_qty' => 1, 'max_qty' => 0, 'delivery_min_days' => null, 'delivery_max_days' => null,
                    'status' => 'published', 'visibility' => 'everywhere', 'featured' => 0, 'position' => 0, 'rating_sum' => 0,
                    'rating_count' => 0, 'sales_count' => $i % 11, 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
                    'has_variants' => 0, 'tax_class_id' => null, 'extra' => null,
                ]);
                $db->insert('product_i18n', [
                    'product_id' => $id, 'lang' => 'es', 'name' => ucfirst($noun) . ' ' . $adjective . ' de ' . $material . ' modelo ' . $i,
                    'summary' => 'Un ' . $noun . ' ' . $adjective . ' para toda la casa', 'facts' => null, 'meta_title' => null, 'meta_description' => null,
                    'description' => '<p>El ' . $noun . ' ' . $adjective . ' esta hecho de ' . $material . ' y se entrega montado. Ideal para el salon, el dormitorio y la oficina. '
                        . 'Resistente, fácil de limpiar y con un diseño pensado para durar muchos años en cualquier habitación de la casa.</p>',
                ]);
                $ids[] = $id;
            }
        });

        return $ids;
    }

    /**
     * Una tienda pensada para probar que el buscador entiende frases: sillas de oficina de varios colores y precios, mesas con
     * medida, un sillón rebajado y una silla agotada. Ya indexada. Devuelve los identificadores por clave y, bajo «cat», «brand»
     * y «attr», los de las categorías, la marca y los valores.
     *
     * @return array<string,mixed>
     */
    function askShop(App $app): array
    {
        $oficina = aifecCategory($app, 'Sillas de oficina');
        $sillas = aifecCategory($app, 'Sillas');
        $mesas = aifecCategory($app, 'Mesas');
        $nordika = aifecBrand($app, 'Nordika');
        aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'rojo' => 'Rojo', 'azul' => 'Azul', 'cafe' => 'Café', 'gris-perla' => 'Gris perla']);
        aifecAttribute($app, 'material', 'Material', ['cuero' => 'Cuero', 'tela' => 'Tela', 'roble' => 'Roble']);
        aifecAttribute($app, 'largo', 'Largo', [], ['type' => 'number', 'unit' => 'cm']);
        aifecAttribute($app, 'peso', 'Peso', [], ['type' => 'number', 'unit' => 'kg']);

        $ids = [];
        $ids['ergo'] = aifecProduct($app, [
            'name' => 'Silla de oficina ergonómica', 'price' => 15999, 'categories' => [$oficina], 'brand_id' => $nordika, 'sales_count' => 9,
            'attributes' => ['color' => 'negro', 'material' => 'tela', 'peso' => 12],
        ]);
        $ids['basica'] = aifecProduct($app, [
            'name' => 'Silla de oficina básica', 'price' => 9900, 'categories' => [$oficina], 'stock' => 5, 'sales_count' => 20,
            'attributes' => ['color' => 'negro', 'material' => 'tela', 'peso' => 8],
        ]);
        $ids['premium'] = aifecProduct($app, [
            'name' => 'Silla de oficina premium', 'price' => 34900, 'categories' => [$oficina], 'brand_id' => $nordika, 'rating_sum' => 50, 'rating_count' => 10,
            'attributes' => ['color' => 'negro', 'material' => 'cuero', 'peso' => 15],
        ]);
        $ids['roja'] = aifecProduct($app, [
            'name' => 'Silla de oficina roja', 'price' => 12000, 'categories' => [$oficina], 'attributes' => ['color' => 'rojo', 'material' => 'tela'],
        ]);
        $ids['agotada'] = aifecProduct($app, [
            'name' => 'Silla de oficina agotada', 'price' => 8000, 'categories' => [$oficina], 'stock' => 0, 'attributes' => ['color' => 'negro'],
        ]);
        $ids['comedor'] = aifecProduct($app, [
            'name' => 'Silla de comedor', 'price' => 6000, 'categories' => [$sillas], 'attributes' => ['color' => 'azul', 'material' => 'roble'],
        ]);
        $ids['mesa'] = aifecProduct($app, [
            'name' => 'Mesa de roble', 'price' => 29900, 'categories' => [$mesas], 'attributes' => ['material' => 'roble', 'largo' => 180],
        ]);
        $ids['mesa_corta'] = aifecProduct($app, [
            'name' => 'Mesa auxiliar', 'price' => 7900, 'categories' => [$mesas], 'attributes' => ['material' => 'roble', 'largo' => 90],
        ]);
        $ids['sillon'] = aifecProduct($app, [
            'name' => 'Sillón relax', 'price' => 10000, 'compare_price' => 15000, 'categories' => [$sillas], 'attributes' => ['color' => 'gris-perla', 'material' => 'cuero'],
        ]);
        $ids['lampara'] = aifecProduct($app, ['name' => 'Lámpara de pie', 'price' => 4500, 'attributes' => ['color' => 'cafe']]);
        searchService($app)->reindexNow();
        $ids['cat'] = ['oficina' => $oficina, 'sillas' => $sillas, 'mesas' => $mesas];
        $ids['brand'] = $nordika;

        return $ids;
    }

    /**
     * Lo entendido de una frase: clave de cada ficha => su texto, más «text» (lo que queda) bajo la clave especial «_text».
     *
     * @return array<string,string>
     */
    function askUnderstood(App $app, string $q, string $lang = 'es'): array
    {
        $plan = searchService($app)->asker()->plan($q, $lang, false);
        $out = [];
        foreach ($plan['filters'] as $filter) {
            $out[(string) $filter['key']] = (string) $filter['label'];
        }
        $out['_text'] = (string) $plan['text'];

        return $out;
    }

    /**
     * @return string[] los nombres de los productos que da ask(), en orden
     */
    function askNames(array $result): array
    {
        return array_map(static function (array $p): string {
            return (string) $p['name'];
        }, $result['products']);
    }

    /**
     * @return string[] los nombres de las tarjetas de una página de resultados
     */
    function askCardNames(string $html): array
    {
        preg_match_all('#<h3 class="af-product-card-name">([^<]*)</h3>#', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    /** La dirección de la ficha con esa clave en una página de resultados. */
    function askChipUrl(string $html, string $key): string
    {
        if (preg_match('#<a [^>]*href="([^"]*)" data-af-search-chip="' . preg_quote($key, '#') . '"#', $html, $m) !== 1) {
            return '';
        }

        return html_entity_decode($m[1]);
    }

    /**
     * @return string[] los nombres de los productos de ask(), ordenados (para comparar conjuntos)
     */
    function askSorted(array $result): array
    {
        $names = askNames($result);
        sort($names);

        return $names;
    }

    /** Un servicio «ai» de mentira: apunta lo que se le pide y devuelve lo que se le haya dado. */
    final class FakeAi
    {
        /** @var bool */
        public $on = true;

        /** @var bool true = el tope de gasto de hoy ya se alcanzó */
        public $limit = false;

        public function limitReached(): bool
        {
            return $this->limit;
        }

        /** @var array<int,array<string,mixed>> */
        public $asked = [];

        /** @var array<int,string[]> */
        public $embedded = [];

        /** @var string lo que contesta ask() */
        public $reply = '{"terms": ["sofa de lectura", "butaca"]}';

        /** @var bool true = embed() y ask() fallan, como un proveedor caído */
        public $fail = false;

        /** @var array<string,string> tarea => lo que contesta ask() a esa tarea (si no hay, $reply) */
        public $replies = [];

        /** @var array<string,array<int,float>> texto => el vector que da embed() (si no hay, uno de mentira) */
        public $vectors = [];

        /** @var int microsegundos que tarda embed() en contestar */
        public $embedDelay = 0;

        public function enabled(): bool
        {
            return $this->on;
        }

        /** Cuántas veces se preguntó por esa tarea. */
        public function askedTask(string $task): int
        {
            $n = 0;
            foreach ($this->asked as $call) {
                if ($call['task'] === $task) {
                    ++$n;
                }
            }

            return $n;
        }

        /**
         * @param array<string,mixed> $request
         */
        public function ask(string $task, array $request, string $tier = 'economy'): AiResult
        {
            $this->asked[] = ['task' => $task, 'request' => $request, 'tier' => $tier];
            if ($this->fail) {
                throw new \RuntimeException('The provider is down');
            }

            return new AiResult(isset($this->replies[$task]) ? $this->replies[$task] : $this->reply);
        }

        /**
         * @param string[] $texts
         *
         * @return array<int,array<int,float>>
         */
        public function embed(array $texts): array
        {
            $this->embedded[] = $texts;
            if ($this->fail) {
                throw new \RuntimeException('The provider is down');
            }
            if ($this->embedDelay > 0) {
                usleep($this->embedDelay);
            }
            $out = [];
            foreach ($texts as $text) {
                // Un vector de mentira que solo depende del texto: las mismas palabras dan casi el mismo vector.
                $out[] = isset($this->vectors[$text]) ? $this->vectors[$text] : \Aifec\Search\Vector::hashEmbedding($text, 16, 'es');
            }

            return $out;
        }
    }
}
