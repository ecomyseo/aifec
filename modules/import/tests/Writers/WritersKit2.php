<?php
/**
 * AIFEC — apoyo de las pruebas de los escritores de personas, pedidos y contenidos: un sitio con el correo en modo real y un
 * transporte en memoria, contraseñas de cada origen generadas con su algoritmo, un buscador de secretos por todas las tablas, las
 * tareas programadas y el catálogo que necesita un pedido.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once. Usa WritersKit.php sin tocarlo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Mail\ArrayTransport;
use Aifec\Mail\Transport;
use Aifec\Modules\Import\Writers\CustomersWriter;
use Aifec\Modules\Import\Writers\ProductsWriter;

require_once __DIR__ . '/WritersKit.php';
require_once __DIR__ . '/../../../catalog/tests/helpers.php';

if (!class_exists('PwSites')) {
    /**
     * Los sitios que ha creado la prueba en curso (con su transporte de correo): al terminar, pwTest() comprueba que ninguno mandó nada.
     */
    final class PwSites
    {
        /** @var array<int,array{0:App,1:ArrayTransport}> */
        public static $sites = [];

        /** @var array<int,bool> transportes a los que la prueba ha pedido a propósito un correo (control) */
        public static $allowed = [];
    }
}

if (!function_exists('pwApp')) {
    /**
     * Una prueba de este paquete: corre y, al terminar, comprueba que en ninguno de sus sitios ha salido un mensaje, ni queda una fila en el
     * registro de correo ni un trabajo de correo en la cola. Solo se salta la que pidió un correo a propósito con pwAllowMail().
     */
    function pwTest(string $name, callable $fn): void
    {
        test($name, static function () use ($fn): void {
            PwSites::$sites = [];
            PwSites::$allowed = [];
            $fn();
            foreach (PwSites::$sites as $i => $site) {
                if (!isset(PwSites::$allowed[spl_object_id($site[1])])) {
                    pwSilent($site[0], $site[1], 'al terminar la prueba (sitio ' . ($i + 1) . ')');
                }
            }
        });
    }

    /** La prueba va a pedir un correo a propósito fuera de la migración (un control): no se exige el silencio de ese sitio al terminar. */
    function pwAllowMail(ArrayTransport $transport): void
    {
        PwSites::$allowed[spl_object_id($transport)] = true;
    }

    /**
     * Un sitio con los módulos de personas, pedidos y contenidos y el correo EN MODO REAL con un transporte en memoria: si algún
     * escritor intentara mandar algo, saldría por ese transporte y la prueba lo vería.
     *
     * @param array<string,mixed> $settings
     * @param string[]            $extraModules
     *
     * @return array{0:App,1:ArrayTransport}
     */
    function pwApp(array $settings = [], array $extraModules = []): array
    {
        $modules = array_merge(['import', 'customers', 'coupons', 'orders', 'reviews', 'cms', 'media', 'catalog', 'tax', 'shipping', 'mail', 'jobs'], $extraModules);
        $app = importApp($modules, $settings + ['mail.mode' => 'live', 'mail.from_email' => 'tienda@tienda.test']);
        $transport = new ArrayTransport();
        $app->bind('mail.transport', static function () use ($transport): Transport {
            return $transport;
        });
        PwSites::$sites[] = [$app, $transport];

        return [$app, $transport];
    }

    /**
     * Comprueba el silencio: ni un mensaje en el transporte ni una fila en el registro de correo.
     */
    function pwSilent(App $app, ArrayTransport $transport, string $what): void
    {
        same(0, count($transport->sent), $what . ': no salió ningún mensaje');
        same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'), $what . ': no hay ninguna fila en el registro de correo');
        same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type LIKE 'mail%'"), $what . ': no hay ningún trabajo de correo en la cola');
    }

    /**
     * Ejecuta todas las tareas programadas de pedidos, clientes, opiniones y correo, y trabaja la cola.
     *
     * @return string[] las tareas que corrieron
     */
    function pwTasks(App $app): array
    {
        $jobs = $app->service('jobs');
        $ran = [];
        foreach ($jobs->tasks() as $task) {
            if (preg_match('/^(orders|customers|reviews|mail)\./', (string) $task['task']) === 1) {
                $jobs->runTask((string) $task['task']);
                $ran[] = (string) $task['task'];
            }
        }
        $jobs->work(60, 500);

        return $ran;
    }

    /**
     * Las tablas (salvo las que se piden) con una celda que contiene el texto.
     *
     * @param string[] $except nombres de tabla sin prefijo que se saltan
     *
     * @return string[]
     */
    function pwLeaks(App $app, string $needle, array $except = []): array
    {
        $db = $app->db();
        $found = [];
        $tables = $db->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (is_array($tables) ? $tables : [] as $name) {
            $short = (string) preg_replace('/^' . preg_quote($db->prefix(), '/') . '/', '', (string) $name);
            if (in_array($short, $except, true)) {
                continue;
            }
            $stmt = $db->pdo()->query('SELECT * FROM "' . $name . '"');
            foreach ($stmt !== false ? $stmt->fetchAll(\PDO::FETCH_NUM) : [] as $row) {
                foreach ($row as $cell) {
                    if (is_string($cell) && strpos($cell, $needle) !== false) {
                        $found[$short] = $short;
                    }
                }
            }
        }

        return array_values($found);
    }

    /** phpass portable de WordPress («$P$») con coste 7: el algoritmo del origen, escrito aquí para generar un resumen conocido. */
    function pwPhpass(string $password, string $salt = 'abcdefgh'): string
    {
        $itoa = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $log2 = 7;
        $hash = md5($salt . $password, true);
        $count = 1 << $log2;
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);
        $out = '';
        $i = 0;
        $n = 16;
        do {
            $value = ord($hash[$i++]);
            $out .= $itoa[$value & 0x3f];
            if ($i < $n) {
                $value |= ord($hash[$i]) << 8;
            }
            $out .= $itoa[($value >> 6) & 0x3f];
            if ($i++ >= $n) {
                break;
            }
            if ($i < $n) {
                $value |= ord($hash[$i]) << 16;
            }
            $out .= $itoa[($value >> 12) & 0x3f];
            if ($i++ >= $n) {
                break;
            }
            $out .= $itoa[($value >> 18) & 0x3f];
        } while ($i < $n);

        return '$P$' . $itoa[$log2] . $salt . $out;
    }

    /** El formato moderno de WordPress: «$wp» + bcrypt del HMAC-SHA384 de la contraseña. */
    function pwWpModern(string $password): string
    {
        $pre = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

        return '$wp' . password_hash($pre, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * Un cliente neutro: el de ejemplo sin direcciones antiguas ni resumen de contraseña salvo que se pidan, con otro correo si se quiere.
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function pwCustomer(array $over = []): array
    {
        return wr('customers', $over + ['password_hash' => null, 'password_scheme' => 'unknown']);
    }

    /** Un resumen bcrypt de coste mínimo (para que la prueba sea rápida). */
    function pwBcrypt(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * Un pedido neutro: el de ejemplo con cambios.
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function pwOrder(array $over = []): array
    {
        return wr('orders', $over);
    }

    /**
     * Un pedido distinto al de ejemplo (otro identificador, otro número) con cambios.
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function pwOrderN(int $n, array $over = []): array
    {
        return pwOrder($over + ['id' => '90' . $n, 'number' => 'P00104' . $n]);
    }

    /**
     * Un registro neutro SIN comprobar (para probar lo que un lector bien hecho nunca entregaría, como un país vacío).
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function pwRaw(string $type, array $over = []): array
    {
        return array_merge(neutralSample($type), $over);
    }

    /**
     * Un sitio con lo que un pedido necesita (el catálogo de ejemplo, el producto «101» con sus dos variantes y el cliente «77» con su
     * contraseña conocida) y su contexto de escritura.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $options
     * @param string[]            $modules más módulos que cargar (por ejemplo «invoices»)
     *
     * @return array{0:App,1:ArrayTransport,2:WritersContext,3:int,4:int}
     */
    function pwOrderSite(array $settings = [], array $options = [], array $modules = []): array
    {
        list($app, $transport) = pwApp($settings, $modules);
        $ctx = writersContext($app, $options);
        wrCatalogOrigin($app, $ctx);
        $product = (int) (new ProductsWriter($app))->write(wr('products', ['old_paths' => []]), $ctx)['id'];
        $customer = (int) (new CustomersWriter($app))->write(pwCustomer(['password_hash' => password_hash('clave-larga-1', PASSWORD_BCRYPT, ['cost' => 4]), 'password_scheme' => 'bcrypt']), $ctx)['id'];
        $ctx->notes = [];
        $ctx->unmapped = [];

        return [$app, $transport, $ctx, $product, $customer];
    }

    /**
     * El stock de cada variante del producto y el total que enseña el catálogo (para comprobar que un pedido importado no lo toca).
     *
     * @return array<string,mixed>
     */
    function pwStock(App $app, int $productId): array
    {
        $p = aifecCatalog($app)->product($productId, 'es');
        $out = [];
        foreach (is_array($p) ? $p['variants'] : [] as $variant) {
            $out[(string) $variant['sku']] = $variant['availability']['qty'];
        }
        $out['_product'] = is_array($p) ? $p['availability']['qty'] : null;

        return $out;
    }
}
