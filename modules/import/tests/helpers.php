<?php
/**
 * AIFEC — apoyo de las pruebas de la base de la migración: un lector y unos escritores de mentira, programables.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * El lector entrega registros neutros de ejemplo con registros inválidos y fallos programables; los escritores apuntan lo
 * que reciben y escriben una fila en «redirects» cuando NO es un simulacro (así un simulacro que escribiera se nota).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\ImportService;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\SourceReader;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\WriteContext;

require_once __DIR__ . '/NeutralSamples.php';

if (!class_exists('ImportFakeReader')) {
    /**
     * Lector de mentira de «WooCommerce (público)». Todo es estático: la prueba lo programa antes de empezar.
     */
    final class ImportFakeReader implements SourceReader
    {
        /** @var array<string,array<int,array<string,mixed>>> tipo => registros */
        public static $data = [];

        /** @var array<int,array{0:string,1:string,2:int}> las llamadas a read(): tipo, cursor, límite */
        public static $calls = [];

        /** @var array<int,\Throwable|null> cola de fallos: cada read() saca uno (null = lee con normalidad) */
        public static $failures = [];

        /** @var int|null cuántos registros entrega como mucho cada read() */
        public static $pageCap = null;

        /** @var array<string,int> */
        public static $checksums = [];

        /** @var array<int,array<string,mixed>> */
        public static $unmapped = [];

        /** @var array<string,mixed> */
        public static $probe = ['ok' => true, 'message' => 'It works', 'version' => '9.1', 'shops' => [], 'languages' => ['es', 'fr'], 'currency' => 'EUR', 'warnings' => []];

        /** @var string[]|null */
        public static $types = null;

        /** @var mixed lo último que recibió el constructor */
        public static $connection;

        /** @var array<string,mixed> */
        public static $config = [];

        /** @var array<int,array<string,mixed>>|null estados de pedido para orderStates() */
        public static $orderStates = null;

        /** @var bool un lector estropeado: devuelve el mismo cursor sin consumir nada */
        public static $stuck = false;

        /**
         * @param mixed               $connection
         * @param array<string,mixed> $config
         */
        public function __construct($connection, array $config)
        {
            self::$connection = $connection;
            self::$config = $config;
        }

        public static function reset(): void
        {
            self::$data = [];
            self::$calls = [];
            self::$failures = [];
            self::$pageCap = null;
            self::$checksums = [];
            self::$unmapped = [];
            self::$types = null;
            self::$connection = null;
            self::$config = [];
            self::$orderStates = null;
            self::$stuck = false;
            self::$probe = ['ok' => true, 'message' => 'It works', 'version' => '9.1', 'shops' => [], 'languages' => ['es', 'fr'], 'currency' => 'EUR', 'warnings' => []];
        }

        public static function describe(): array
        {
            return [
                'source' => 'woocommerce', 'mode' => 'public', 'label' => 'Fake shop', 'help' => 'A shop made up for the tests.',
                'fields' => [
                    'url' => ['type' => 'url', 'label' => 'Shop address', 'required' => true],
                    'key' => ['type' => 'secret', 'label' => 'Key', 'required' => false],
                ],
            ];
        }

        public function source(): string
        {
            return 'woocommerce';
        }

        public function mode(): string
        {
            return 'public';
        }

        public function key(): string
        {
            return 'origen.test';
        }

        public function probe(): array
        {
            return self::$probe;
        }

        public function types(): array
        {
            return self::$types !== null ? self::$types : array_keys(self::$data);
        }

        public function count(string $type): ?int
        {
            return isset(self::$data[$type]) ? count(self::$data[$type]) : 0;
        }

        public function read(string $type, string $cursor, int $limit): array
        {
            self::$calls[] = [$type, $cursor, $limit];
            if (self::$failures !== []) {
                $failure = array_shift(self::$failures);
                if ($failure instanceof \Throwable) {
                    throw $failure;
                }
            }
            if (self::$stuck) {
                return ['records' => [], 'next' => $cursor, 'read' => 0, 'skipped' => []];
            }
            $items = isset(self::$data[$type]) ? self::$data[$type] : [];
            $offset = $cursor === '' ? 0 : (int) $cursor;
            $take = self::$pageCap !== null ? min($limit, self::$pageCap) : $limit;
            $slice = array_slice($items, $offset, $take);
            $records = [];
            $skipped = [];
            foreach ($slice as $item) {
                if (isset($item['__skip'])) {
                    $skipped[] = ['id' => (string) $item['id'], 'reason' => 'Could not be converted'];
                } else {
                    $records[] = $item;
                }
            }
            $next = $offset + count($slice);

            return ['records' => $records, 'next' => $next >= count($items) ? null : (string) $next, 'read' => count($slice), 'skipped' => $skipped];
        }

        public function checksums(): array
        {
            return self::$checksums;
        }

        public function unmapped(): array
        {
            return self::$unmapped;
        }

        /**
         * @return array<int,array<string,mixed>>
         */
        public function orderStates(): array
        {
            return self::$orderStates !== null ? self::$orderStates : [];
        }
    }

    /**
     * Escritor de mentira. Cada tipo tiene el suyo (el registro crea una instancia por tipo con new $clase($app)).
     */
    abstract class ImportFakeWriter implements TypeWriter
    {
        /** @var array<string,string[]> tipo => identificadores recibidos en write() */
        public static $written = [];

        /** @var array<string,string[]> tipo => identificadores recibidos en link() */
        public static $linked = [];

        /** @var array<string,array<string,string>> «tipo:id» => [result, reason] programado */
        public static $results = [];

        /** @var array<string,bool> «tipo:id» => lanza al escribir */
        public static $throwOn = [];

        /** @var bool intenta encolar un correo en cada registro */
        public static $mail = false;

        /** @var int[] lo que devolvió Mailer::queue() */
        public static $mailLog = [];

        /** @var bool[] App::silenced() visto desde write() */
        public static $silenced = [];

        /** @var bool devuelve el registro entero (con sus secretos) en el motivo de un descarte */
        public static $leak = false;

        /** @var bool apunta una nota y un «sin equivalente» en cada registro */
        public static $notes = false;

        /** @var array<string,array<string,int>> tipo => cifras del destino */
        public static $sums = [];

        /** @var int */
        public static $next = 1000;

        /** @var App */
        protected $app;

        public function __construct(App $app)
        {
            $this->app = $app;
        }

        public static function reset(): void
        {
            self::$written = [];
            self::$linked = [];
            self::$results = [];
            self::$throwOn = [];
            self::$mail = false;
            self::$mailLog = [];
            self::$silenced = [];
            self::$leak = false;
            self::$notes = false;
            self::$sums = [];
            self::$next = 1000;
        }

        public function write(array $record, WriteContext $ctx): array
        {
            $type = $this->type();
            $id = (string) $record['id'];
            $key = $type . ':' . $id;
            self::$written[$type][] = $id;
            self::$silenced[] = $ctx->app()->silenced();
            if (self::$mail) {
                self::$mailLog[] = $ctx->app()->service('mailer')->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
            }
            if (isset(self::$throwOn[$key])) {
                throw new \RuntimeException('boom ' . $key);
            }
            if (self::$notes) {
                $ctx->note($type, $id, 'note', 'a note about ' . $id);
                $ctx->unmapped('feature', 'Gift wrap', 1, 'no equivalent');
            }
            if (isset(self::$results[$key])) {
                return ['result' => self::$results[$key][0], 'id' => null, 'reason' => self::$results[$key][1]];
            }
            if (self::$leak) {
                return ['result' => self::SKIPPED, 'id' => null, 'reason' => 'bad record ' . json_encode($record)];
            }
            $hash = Neutral::hash($record);
            $mapped = $ctx->mapped($type, $id);
            if ($mapped === null) {
                $local = ++self::$next;
                $this->row($ctx, $type, $id);
                $ctx->remember($type, $id, $local, $hash);

                return ['result' => self::CREATED, 'id' => $local, 'reason' => ''];
            }
            if ($mapped['source_hash'] === $hash) {
                return ['result' => self::UNCHANGED, 'id' => $mapped['local_id'], 'reason' => ''];
            }
            $ctx->remember($type, $id, $mapped['local_id'], $hash);

            return ['result' => self::UPDATED, 'id' => $mapped['local_id'], 'reason' => ''];
        }

        public function link(array $record, WriteContext $ctx): void
        {
            self::$linked[$this->type()][] = (string) $record['id'];
        }

        public function checksums(WriteContext $ctx): array
        {
            return isset(self::$sums[$this->type()]) ? self::$sums[$this->type()] : [];
        }

        /** Lo que un escritor de verdad haría fuera de import_*: aquí, una fila en «redirects». En simulacro, nada. */
        private function row(WriteContext $ctx, string $type, string $id): void
        {
            if ($ctx->dryRun()) {
                return;
            }
            $ctx->app()->db()->insert('redirects', [
                'source' => $type . '/' . $id, 'target' => '/x', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $ctx->app()->clock()->sql(),
            ]);
        }
    }

    final class ImportFakeSettingsWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'settings';
        }
    }

    final class ImportFakeTaxClassesWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'tax_classes';
        }
    }

    final class ImportFakeCategoriesWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'categories';
        }
    }

    final class ImportFakeProductsWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'products';
        }
    }

    final class ImportFakeCustomersWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'customers';
        }
    }

    final class ImportFakeRedirectsWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'redirects';
        }
    }

    final class ImportFakeTeamWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'team';
        }
    }

    final class ImportFakePaymentMethodsWriter extends ImportFakeWriter
    {
        public function type(): string
        {
            return 'payment_methods';
        }
    }
}

if (!function_exists('importApp')) {
    /**
     * Sitio de prueba con el módulo de migración, el lector de mentira y los escritores de mentira ya registrados.
     * Por defecto carga lo que hace falta para los cinco tipos de las pruebas (ajustes, clases de impuesto, categorías,
     * productos y redirecciones).
     *
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $testOptions más opciones de testApp(), por ejemplo http
     */
    function importApp(array $modules = ['import', 'media', 'catalog'], array $settings = [], array $testOptions = []): App
    {
        // El módulo de migración no viene encendido de serie: se enciende como lo haría el panel.
        $options = $testOptions + ['modules' => $modules, 'settings' => $settings + ['modules.active' => $modules]];
        $app = testApp($options);
        $app->boot();
        ImportFakeReader::reset();
        ImportFakeWriter::reset();
        $app->hooks()->filter('import.readers', static function ($list): array {
            $list = is_array($list) ? $list : [];
            $list[] = ImportFakeReader::class;

            return $list;
        });
        $app->hooks()->filter('import.writers', static function ($list): array {
            $list = is_array($list) ? $list : [];

            return $list + [
                'settings' => ImportFakeSettingsWriter::class, 'tax_classes' => ImportFakeTaxClassesWriter::class,
                'categories' => ImportFakeCategoriesWriter::class, 'products' => ImportFakeProductsWriter::class,
                'customers' => ImportFakeCustomersWriter::class, 'redirects' => ImportFakeRedirectsWriter::class,
                'team' => ImportFakeTeamWriter::class, 'payment_methods' => ImportFakePaymentMethodsWriter::class,
            ];
        });

        return $app;
    }

    /** Cinco tipos con datos de ejemplo: ajustes, clases de impuesto, categorías, productos y redirecciones. */
    function importFiveTypes(): void
    {
        ImportFakeReader::$data = [
            'settings' => importRecords('settings', 1, ['id' => 'main']),
            'tax_classes' => importRecords('tax_classes', 2),
            'categories' => importRecords('categories', 3),
            'products' => importRecords('products', 4),
            'redirects' => importRecords('redirects', 2),
        ];
    }

    /** @param array<string,mixed> $run */
    function importSum(array $run, string $type): int
    {
        $c = $run['counters'][$type];

        return $c['created'] + $c['updated'] + $c['unchanged'] + $c['conflict'] + $c['skipped'];
    }

    function importService(App $app): ImportService
    {
        /** @var ImportService $service */
        $service = $app->service('import');

        return $service;
    }

    /**
     * N registros neutros de un tipo, con identificadores distintos (los datos de ejemplo del formato neutro).
     *
     * @param array<string,mixed> $override
     *
     * @return array<int,array<string,mixed>>
     */
    function importRecords(string $type, int $n, array $override = []): array
    {
        $out = [];
        for ($i = 1; $i <= $n; ++$i) {
            $record = neutralSample($type);
            $record['id'] = (string) (100 + $i);
            $out[] = $override + $record;
        }

        return $out;
    }

    /**
     * Un registro que no cumple el formato neutro (le falta el nombre obligatorio).
     *
     * @return array<string,mixed>
     */
    function importBroken(string $type, string $id): array
    {
        $record = neutralSample($type);
        $record['id'] = $id;
        unset($record['name'], $record['title'], $record['email'], $record['code'], $record['source'], $record['target']);

        return $record;
    }

    /**
     * Crea una migración en borrador contra el lector de mentira.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $extra
     */
    function importDraft(App $app, array $options = [], array $extra = []): int
    {
        return importService($app)->createDraft('woocommerce', 'public', ['url' => 'https://origen.test', 'key' => 'clave-de-mentira-7777'], [], $options, $extra);
    }

    /**
     * Trabaja la cola de la migración hasta que no queda nada pendiente.
     *
     * @return array{done:int,failed:int,retried:int,reclaimed:int}
     */
    function importWork(App $app): array
    {
        return $app->service('jobs')->work(60, 500, 'import');
    }

    /**
     * Da pasos hasta que la migración deja de pedir más (o se pasa del tope: una migración que no termina falla la
     * prueba en vez de colgarla).
     */
    function importDrain(\Aifec\Modules\Import\Runner $runner, int $id, int $max = 400): string
    {
        $outcome = 'continue';
        for ($i = 0; $i < $max && ($outcome === 'continue'); ++$i) {
            $outcome = $runner->step($id);
        }
        check($outcome !== 'continue', 'la migración no termina: ' . $max . ' pasos y sigue');

        return $outcome;
    }

    /** @return array<string,mixed> */
    function importRun(App $app, int $id): array
    {
        $run = importService($app)->runs()->find($id);
        check($run !== null, 'la migración ' . $id . ' existe');

        return (array) $run;
    }

    /** Cuántas filas hay en cada tabla del sitio (todas, descubiertas con la capa de datos). */
    function importCounts(App $app): array
    {
        $db = $app->db();
        $tables = $db->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach (is_array($tables) ? $tables : [] as $name) {
            $out[(string) $name] = (int) $db->pdo()->query('SELECT COUNT(*) FROM "' . $name . '"')->fetchColumn();
        }
        ksort($out);

        return $out;
    }
}
