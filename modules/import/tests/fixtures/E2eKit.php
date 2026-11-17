<?php
/**
 * AIFEC — apoyo de las pruebas de punta a punta de la migración: un sitio con TODOS los módulos que la migración toca y los
 * escritores de verdad (nada de escritores de mentira), el correo en modo real con un transporte en memoria que lo vería todo, y dos
 * tiendas de origen de mentira ya completas (PrestaShop y WooCommerce, copias de la base de datos en SQLite) con contraseñas que sí se
 * pueden comprobar.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Mail\ArrayTransport;
use Aifec\Mail\Transport;
use Aifec\Modules\Import\ImportService;

require_once __DIR__ . '/../Readers/PrestaShop/fixtures/PsTestKit.php';
require_once __DIR__ . '/../Readers/WooCommerce/fixtures/WooTestKit.php';
require_once __DIR__ . '/../Writers/WritersKit2.php';

/**
 * Las bases de datos de origen que se han creado en esta pasada, vivas hasta el final. Las pruebas de los lectores de PrestaShop guardan una caché
 * de columnas por «spl_object_id» del PDO (fixtures/db/data.php): si una base mía se libera, la siguiente que nazca hereda su número y esa caché,
 * y una tienda 1.7 acaba insertando con las columnas de una 8.2. Mientras estén vivas, sus números no se reutilizan.
 */
final class E2eAlive
{
    /** @var PDO[] */
    public static $pdos = [];
}

/** La clave de cookies de la tienda PrestaShop de mentira (la que usó para guardar las contraseñas antiguas). */
const E2E_COOKIE_KEY = 'CLAVE-DE-COOKIES-DE-MENTIRA-0123456789';

/** Una prueba de punta a punta: como pwTest(), y además comprueba al final que ningún sitio mandó un solo mensaje. */
function e2eTest(string $name, callable $fn): void
{
    pwTest($name, $fn);
}

/**
 * Un sitio con todos los módulos que toca la migración, SIN los escritores de mentira: el registro encuentra los de verdad.
 *
 * @param array<string,mixed> $settings
 * @param array<string,mixed> $testOptions más opciones de testApp(), por ejemplo http
 *
 * @return array{0:App,1:ArrayTransport}
 */
function e2eApp(array $settings = [], array $testOptions = []): array
{
    $modules = ['import', 'jobs', 'mail', 'customers', 'coupons', 'orders', 'invoices', 'reviews', 'cms', 'media', 'catalog', 'tax', 'shipping', 'seo', 'search', 'semantic',
        'payments-transfer', 'payments-cod', 'payments-redsys', 'payments-paypal'];
    $options = $testOptions + ['modules' => $modules, 'settings' => $settings + ['modules.active' => $modules, 'mail.mode' => 'live', 'mail.from_email' => 'tienda@tienda.test']];
    $app = testApp($options);
    $app->boot();
    $transport = new ArrayTransport();
    $app->bind('mail.transport', static function () use ($transport): Transport {
        return $transport;
    });
    PwSites::$sites[] = [$app, $transport];

    return [$app, $transport];
}

/**
 * Una copia de la base de datos de PrestaShop de la que se cae el origen a mitad de la lectura: a partir de la consulta número «armAfter» que
 * lleve «armOn» lanza una excepción de verdad (no un error del servicio: el proceso del lector muere con ella).
 */
final class E2eFlakyPdo extends PDO
{
    /** @var string trozo de SQL que arma el fallo (vacío = no falla nunca) */
    public $armOn = '';

    /** @var int cuántas consultas con ese trozo salen bien antes de caerse */
    public $armAfter = 0;

    /** @var int */
    private $seen = 0;

    /**
     * @param string $query
     * @param array  $options
     *
     * @return PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->armOn !== '' && strpos((string) $query, $this->armOn) !== false) {
            ++$this->seen;
            if ($this->seen > $this->armAfter) {
                throw new \RuntimeException('el origen se cayó a mitad de la lectura');
            }
        }

        return parent::prepare($query, $options);
    }
}

/** Copia todas las tablas y filas de una base SQLite a otra. */
function e2eCopyPdo(PDO $from, PDO $to): void
{
    E2eAlive::$pdos[] = $to;
    $to->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($from->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $to->exec((string) $table['sql']);
        $rows = $from->query('SELECT * FROM "' . $table['name'] . '"')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $names = array_map(static function (string $name): string {
                return '"' . $name . '"';
            }, array_keys($row));
            $to->prepare('INSERT INTO "' . $table['name'] . '" (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    }
}

/**
 * Ejecuta una orden de consola de la migración.
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string} código de salida y lo escrito
 */
function e2eCli(App $app, string $command, array $args = []): array
{
    $io = new \Aifec\Cli\Console(false);
    $commands = $io->commands($app);
    check(isset($commands[$command]), 'la orden ' . $command . ' existe');
    $code = $commands[$command]['run']($app, $args, $io);

    return [(int) $code, $io->output];
}

function e2eService(App $app): ImportService
{
    /** @var ImportService $service */
    $service = $app->service('import');

    return $service;
}

// ---------------------------------------------------------------------------------------------------------------- PrestaShop

/**
 * Una copia de la base de datos de una tienda PrestaShop completa. Parte de la de las pruebas de los lectores (6 productos con una silla de
 * cuatro combinaciones, una rebaja con impuestos, dos idiomas, grupos, direcciones, transportista con tarifas por peso, cupones, páginas,
 * imágenes) y le pone lo que hace falta para comprobar de verdad: contraseñas con resúmenes reales (bcrypt, y la antigua de MD5 con la clave de
 * cookies) y hasta 12 pedidos en varios estados con sus líneas e impuestos.
 */
function e2ePsPdo(): PDO
{
    $pdo = PsTestKit::pdo();
    E2eAlive::$pdos[] = $pdo;
    // Contraseñas de verdad: Ana entra con bcrypt, Luis con la antigua de MD5 + clave de cookies, una tercera con MD5 de otra clave.
    $pdo->prepare('UPDATE `ps_customer` SET passwd = ? WHERE id_customer = 1')->execute([password_hash('secreto-123', PASSWORD_BCRYPT, ['cost' => 4])]);
    $pdo->prepare('UPDATE `ps_customer` SET passwd = ? WHERE id_customer = 2')->execute([md5(E2E_COOKIE_KEY . 'clave-vieja-9')]);
    // Ocho pedidos más (del 5 al 12), de los clientes 1 y 2, en todos los estados, con sus líneas, sus impuestos y su historial.
    $states = [5 => 2, 6 => 3, 7 => 4, 8 => 5, 9 => 5, 10 => 6, 11 => 7, 12 => 8];
    $customers = [5 => 1, 6 => 2, 7 => 1, 8 => 2, 9 => 1, 10 => 2, 11 => 1, 12 => 2];
    $addresses = [1 => 1, 2 => 4];
    $order = [
        'id_shop_group' => 1, 'id_shop' => 1, 'id_lang' => 1, 'id_currency' => 1, 'conversion_rate' => '1.000000', 'recyclable' => 0, 'gift' => 0, 'gift_message' => '', 'total_discounts' => '0.000000',
        'total_discounts_tax_incl' => '0.000000', 'total_discounts_tax_excl' => '0.000000', 'total_shipping' => '5.900000', 'total_shipping_tax_incl' => '5.900000', 'total_shipping_tax_excl' => '4.880000',
        'carrier_tax_rate' => '21.000', 'total_wrapping' => '0.000000', 'total_wrapping_tax_incl' => '0.000000', 'total_wrapping_tax_excl' => '0.000000', 'round_mode' => 2, 'round_type' => 1,
        'invoice_number' => 0, 'delivery_number' => 0, 'valid' => 1, 'note' => '', 'module' => 'ps_wirepayment', 'payment' => 'Transferencia bancaria', 'id_carrier' => 3,
    ];
    $nextDetail = 7;
    foreach ($states as $id => $state) {
        $date = sprintf('2025-%02d-12 10:00:00', $id);
        $netProducts = 20 + $id * 3;
        $taxProducts = round($netProducts * 0.21, 6);
        $grossProducts = $netProducts + $taxProducts;
        $totalNet = $netProducts + 4.88;
        $totalGross = $grossProducts + 5.9;
        psInsert($pdo, 'orders', [
            'id_order' => $id, 'reference' => 'REF' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'id_customer' => $customers[$id], 'id_cart' => 100 + $id,
            'id_address_delivery' => $addresses[$customers[$id]], 'id_address_invoice' => $addresses[$customers[$id]], 'current_state' => $state,
            'total_paid' => sprintf('%.6f', $totalGross), 'total_paid_tax_incl' => sprintf('%.6f', $totalGross), 'total_paid_tax_excl' => sprintf('%.6f', $totalNet),
            'total_paid_real' => in_array($state, [2, 3, 4, 5], true) ? sprintf('%.6f', $totalGross) : '0.000000',
            'total_products' => sprintf('%.6f', $netProducts), 'total_products_wt' => sprintf('%.6f', $grossProducts), 'date_add' => $date, 'date_upd' => $date,
        ] + $order);
        psInsert($pdo, 'order_detail', [
            'id_order_detail' => $nextDetail, 'id_order' => $id, 'product_id' => 2, 'product_attribute_id' => 2, 'product_name' => 'Silla nórdica - Tamaño: Pequeña - Color: Nogal',
            'product_quantity' => 1, 'product_reference' => 'SILLA-P-NOG', 'total_price_tax_excl' => sprintf('%.6f', $netProducts), 'total_price_tax_incl' => sprintf('%.6f', $grossProducts),
            'unit_price_tax_excl' => sprintf('%.6f', $netProducts), 'unit_price_tax_incl' => sprintf('%.6f', $grossProducts), 'product_weight' => '4.500000', 'id_tax_rules_group' => 1,
            'tax_rate' => '21.000', 'tax_name' => 'IVA 21 %', 'id_order_invoice' => 0, 'id_warehouse' => 0, 'id_shop' => 1, 'id_customization' => 0, 'product_quantity_refunded' => 0,
            'product_quantity_reinjected' => 0, 'reduction_percent' => '0.00', 'reduction_amount' => '0.000000', 'product_ean13' => '', 'tax_computation_method' => 0, 'download_hash' => '',
            'total_refunded_tax_excl' => '0.000000', 'total_refunded_tax_incl' => '0.000000',
        ]);
        psInsert($pdo, 'order_detail_tax', ['id_order_detail' => $nextDetail, 'id_tax' => 1, 'unit_amount' => '0', 'total_amount' => '0']);
        psInsert($pdo, 'order_history', ['id_order_history' => 20 + $id, 'id_employee' => 0, 'id_order' => $id, 'id_order_state' => $state, 'date_add' => $date]);
        psInsert($pdo, 'cart', ['id_cart' => 100 + $id, 'id_shop' => 1, 'id_customer' => $customers[$id], 'id_currency' => 1, 'id_lang' => 1]);
        ++$nextDetail;
    }

    return $pdo;
}

/**
 * Crea una migración en borrador contra una copia de PrestaShop (modo «db») y le da la copia por la fábrica de conexiones.
 *
 * @param array<string,mixed> $options
 * @param array<string,mixed> $extra
 * @param array<string,mixed> $config
 */
function e2ePsDraft(App $app, PDO $pdo, array $options = [], array $extra = [], array $config = []): int
{
    $service = e2eService($app);
    $service->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });

    return $service->createDraft('prestashop', 'db', $config + ['prefix' => 'ps_', 'base_url' => 'https://vieja.test', 'cookie_key' => E2E_COOKIE_KEY, 'now' => '2026-11-01T00:00:00Z'], [
        'host' => 'bd.test', 'port' => 3306, 'name' => 'tienda_vieja', 'user' => 'usuario', 'password' => 'clave-de-bd-de-mentira',
    ], $options, $extra);
}

// ---------------------------------------------------------------------------------------------------------------- WooCommerce

/**
 * Una copia de la base de datos de una tienda WooCommerce completa: productos simples y variables, precios sin impuestos, clientes con las tres
 * clases de contraseña de WordPress (phpass, bcrypt y el formato nuevo «$wp») con resúmenes reales, pedidos con reembolso, cupones, páginas, entradas y menús.
 */
function e2eWooPdo(bool $hpos = true, bool $parentManagesStock = false): PDO
{
    $pdo = wooMemoryDb($hpos);
    E2eAlive::$pdos[] = $pdo;
    if ($parentManagesStock) {
        // La silla variable lleva el control de existencias en el producto (y no solo en sus variaciones).
        $pdo->exec("UPDATE wp_postmeta SET meta_value = 'yes' WHERE post_id = 102 AND meta_key = '_manage_stock'");
    }
    $update = $pdo->prepare('UPDATE wp_users SET user_pass = ? WHERE ID = ?');
    $update->execute([pwPhpass('clave-ana-1'), 77]);
    $update->execute([password_hash('clave-luis-2', PASSWORD_BCRYPT, ['cost' => 4]), 78]);
    $update->execute([pwWpModern('clave-marta-3'), 79]);

    return $pdo;
}

/**
 * Crea una migración en borrador contra una copia de WooCommerce (modo «db»).
 *
 * @param array<string,mixed> $options
 * @param array<string,mixed> $extra
 * @param array<string,mixed> $config
 */
function e2eWooDraft(App $app, PDO $pdo, array $options = [], array $extra = [], array $config = []): int
{
    $service = e2eService($app);
    $service->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });

    return $service->createDraft('woocommerce', 'db', $config + ['prefix' => 'wp_', 'base_url' => 'https://vieja.test'], [
        'host' => 'bd.test', 'port' => 3306, 'name' => 'tienda_woo', 'user' => 'usuario', 'password' => 'clave-de-bd-de-mentira',
    ], $options, $extra);
}

// ---------------------------------------------------------------------------------------------------------------- recorrido

/**
 * Pone en marcha una migración y la trabaja tanda a tanda hasta que se cierra, como la consola. Devuelve la fila final.
 *
 * @return array<string,mixed>
 */
function e2eRun(App $app, int $id, int $maxSteps = 400): array
{
    $runner = e2eService($app)->runner();
    $started = $runner->start($id, false);
    check($started['ok'], 'la migración arranca: ' . $started['reason']);

    return e2eDrive($app, $id, $maxSteps);
}

/**
 * Trabaja una migración ya en marcha hasta que se cierra o se pausa.
 *
 * @return array<string,mixed>
 */
function e2eDrive(App $app, int $id, int $maxSteps = 400): array
{
    $runner = e2eService($app)->runner();
    for ($i = 0; $i < $maxSteps; ++$i) {
        $outcome = $runner->step($id);
        if ($outcome !== 'continue' && $outcome !== 'retry') {
            break;
        }
    }
    $run = e2eService($app)->runs()->find($id);
    check($run !== null, 'la migración existe');

    return $run;
}

/**
 * Las cifras de las tablas que la migración llena: sirven para comprobar que repetir o cortar y seguir no cambia nada.
 *
 * @return array<string,int>
 */
function e2eCounts(App $app): array
{
    $out = [];
    foreach (['products', 'variants', 'categories', 'brands', 'customers', 'addresses', 'orders', 'order_items', 'coupons', 'reviews', 'pages', 'posts', 'slugs', 'redirects', 'import_map',
        'tax_rates', 'tax_classes', 'carriers', 'carrier_rates', 'media', 'consents', 'users'] as $table) {
        if ($app->db()->tableExists($table)) {
            $out[$table] = (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '}');
        }
    }

    return $out;
}

/**
 * El contenido (no solo el recuento) de lo que importa: nombres, precios, totales y direcciones, ordenado para poder compararlo entre dos sitios.
 *
 * @return array<string,mixed>
 */
function e2eFingerprint(App $app): array
{
    $db = $app->db();

    return [
        'products' => $db->select('SELECT sku, price, sale_price, tax_class_id, stock, status FROM {products} ORDER BY sku, id'),
        'variants' => $db->select('SELECT sku, price, stock FROM {variants} ORDER BY sku, id'),
        'orders' => $db->select('SELECT legacy_id, status, payment_status, total, total_tax, items_total, shipping_total, placed_at FROM {orders} ORDER BY legacy_id'),
        'customers' => $db->select('SELECT email, first_name, last_name, source, legacy_id FROM {customers} ORDER BY email'),
        'slugs' => $db->select('SELECT lang, path, type, canonical FROM {slugs} ORDER BY lang, path'),
        'redirects' => $db->select('SELECT source, target, code FROM {redirects} ORDER BY source'),
        'categories' => $db->select('SELECT parent_id, depth, position, active FROM {categories} ORDER BY id'),
    ];
}

/**
 * Pide una dirección de la tienda por el sitio de pruebas y devuelve la respuesta.
 *
 * @return \Aifec\Kernel\Response
 */
function e2eGet(App $app, string $path)
{
    return (new TestClient($app))->get($path);
}

/**
 * Migra la tienda de PrestaShop completa en un sitio que ya tiene un producto propio (así los ajustes traídos no cambian cómo se escriben
 * los precios aquí: con impuestos, que es como los cobra PrestaShop al cliente).
 *
 * @param array<string,mixed> $options
 *
 * @return array{0:App,1:array<string,mixed>,2:int,3:PDO}
 */
function e2ePsMigrated(array $options = [], bool $own = true): array
{
    [$app] = e2eApp();
    if ($own) {
        aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    }
    $pdo = e2ePsPdo();
    $id = e2ePsDraft($app, $pdo, $options + ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], 'la migración termina: ' . $run['pause_reason']);

    return [$app, $run, $id, $pdo];
}

/** El precio con impuestos de un producto publicado, tal como lo ve el cliente en la tienda. */
function e2eGross(App $app, string $sku): int
{
    $id = (int) $app->db()->value('SELECT id FROM {products} WHERE sku = ?', [$sku]);
    $product = $id > 0 ? aifecCatalog($app)->product($id, 'es') : null;
    check(is_array($product), 'el producto ' . $sku . ' se ve en la tienda');

    return (int) $product['price']['gross'];
}

/**
 * Las filas en rojo de la comprobación final.
 *
 * @param array<string,mixed> $verify
 *
 * @return array<int,array<string,mixed>>
 */
function e2eRed(array $verify): array
{
    return array_values(array_filter($verify['rows'], static function (array $row): bool {
        return !$row['ok'];
    }));
}

/** Todos los tipos menos las páginas: para lo que espera el verde sin más (import:verify sale con 0 solo si no hay ningún rojo). */
function e2eWithoutPages(): array
{
    return array_values(array_diff(\Aifec\Modules\Import\Types::ORDER, ['pages']));
}

/**
 * Las direcciones antiguas que la comprobación final eligió, pedidas a la tienda como las pediría un visitante (con el sitio de pruebas, sin
 * red): cada una tiene que responder 200, o redirigir con un 301 a algo que responde 200. Devuelve las que no.
 *
 * @param array<string,mixed> $run
 *
 * @return string[]
 */
function e2eOldAddressesFailing(App $app, array $run): array
{
    $web = new TestClient($app);
    $bad = [];
    foreach ($run['cursor']['ev']['paths'] as $item) {
        $r = $web->get('/' . ltrim((string) $item[3], '/'));
        if ($r->status === 301) {
            $r = $web->follow();
        }
        if ($r->status !== 200) {
            $bad[] = $item[3] . ' → ' . $r->status;
        }
    }

    return $bad;
}
