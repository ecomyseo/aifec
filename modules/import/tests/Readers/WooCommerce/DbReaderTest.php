<?php
/**
 * AIFEC — el lector de WooCommerce de una copia de la base de datos (SQLite creada en la prueba con las tablas y columnas reales).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\DbReader;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/WooTestKit.php';

/** Una conexión que apunta cada consulta que prepara: así se comprueba que el lector solo lee. */
class WooRecordingPdo extends PDO
{
    /** @var string[] */
    public $statements = [];

    /** @var bool */
    public $deny = false;

    /**
     * @param string $query
     * @param array  $options
     *
     * @return PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->deny) {
            $error = new PDOException('SELECT command denied to user secreto-de-mentira');
            $error->errorInfo = ['42000', 1142, 'SELECT command denied to user secreto-de-mentira'];
            throw $error;
        }
        $this->statements[] = (string) $query;

        return parent::prepare($query, $options);
    }
}

/** Una clase con un método «despertar»: si algún dato serializado la instanciara, quedaría apuntado. */
class WooBoom
{
    /** @var bool */
    public static $woke = false;

    /** @var int */
    public $x = 1;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}

/** @return DbReader */
function wooDb(bool $hpos = false, array $config = [], ?PDO $pdo = null)
{
    return WooTestKit::db($hpos, $config, $pdo);
}

test('copia de la base de datos: cada registro de cada tipo pasa Neutral::check y hay alguno de cada tipo', function (): void {
    $reader = wooDb();
    $result = WooTestKit::checkAll($reader, 3, ['redirects']);
    same([], $result['problems']);
    same([], $result['empty']);
    same([
        'settings' => 1, 'tax_classes' => 2, 'tax_rates' => 4, 'shipping_zones' => 2, 'carriers' => 4, 'payment_methods' => 2, 'brands' => 1, 'categories' => 4,
        'attributes' => 2, 'products' => 6, 'customers' => 3, 'coupons' => 2, 'orders' => 4, 'reviews' => 2, 'media' => 6, 'pages' => 3, 'post_categories' => 2,
        'posts' => 2, 'menus' => 1, 'redirects' => 2, 'team' => 2,
    ], $result['counts']);
    same('woocommerce', $reader->source());
    same('db', $reader->mode());
    same('sqlite:wp_', $reader->key());
});

test('copia de la base de datos con HPOS: los mismos tipos y los mismos recuentos', function (): void {
    $result = WooTestKit::checkAll(wooDb(true), 3, ['redirects']);
    same([], $result['problems']);
    same([], $result['empty']);
    same(4, $result['counts']['orders']);
    same(6, $result['counts']['products']);
});

test('HPOS activo y no activo dan los mismos pedidos; con la opción activa y la tabla vacía se lee de las entradas', function (): void {
    $classic = WooTestKit::drain(wooDb(false), 'orders')['records'];
    $hpos = WooTestKit::drain(wooDb(true), 'orders')['records'];
    same(4, count($classic));
    same(4, count($hpos));
    foreach ($classic as $i => $order) {
        same(Neutral::hash($order), Neutral::hash($hpos[$i]), 'pedido ' . $order['id']);
    }
    same(['9001', '9002', '9003', '9004'], array_map(static function (array $o): string {
        return $o['id'];
    }, $hpos));
    same(9559, WooTestKit::byId($hpos, '9002')['refunded_total']);
    same(2, count(WooTestKit::byId($hpos, '9001')['history']));

    $pdo = wooMemoryDb(false);
    $pdo->exec("UPDATE wp_options SET option_value = 'yes' WHERE option_name = 'woocommerce_custom_orders_table_enabled'");
    same(4, count(WooTestKit::drain(wooDb(false, [], $pdo), 'orders')['records']));
    same(4, wooDb(false, [], $pdo)->count('orders'));
});

test('paginación por clave: dos tandas no se solapan ni saltan filas aunque haya huecos en los identificadores', function (): void {
    $reader = wooDb();
    $first = $reader->read('products', '', 2);
    same(['101', '102'], array_map(static function (array $p): string {
        return $p['id'];
    }, $first['records']));
    same('102', $first['next']);
    $second = $reader->read('products', '102', 2);
    same(['103', '104'], array_map(static function (array $p): string {
        return $p['id'];
    }, $second['records']));
    $third = $reader->read('products', '104', 2);
    same(['105', '106'], array_map(static function (array $p): string {
        return $p['id'];
    }, $third['records']));
    same(null, $third['next']);
    same(6, $first['read'] + $second['read'] + $third['read']);

    $pdo = wooMemoryDb(false);
    $pdo->exec('DELETE FROM wp_posts WHERE ID = 103');
    $gaps = WooTestKit::drain(wooDb(false, [], $pdo), 'products', 2);
    same(['101', '102', '104', '105', '106'], array_map(static function (array $p): string {
        return $p['id'];
    }, $gaps['records']));
    same(3, $gaps['batches']);

    $customers = WooTestKit::drain(wooDb(), 'customers', 1);
    same(3, $customers['batches']);
    same(['77', '78', '79'], array_map(static function (array $c): string {
        return $c['id'];
    }, $customers['records']));
    same('77', wooDb()->read('customers', '', 1)['next']);
    same(null, wooDb()->read('customers', '78', 5)['next']);
    throws(function (): void {
        wooDb()->read('products', 'x; DROP TABLE wp_posts', 2);
    }, SourceError::class);
});

test('redirecciones de Redirection y de Rank Math: las exactas pasan, las de patrón se descartan y read cuenta también los descartados', function (): void {
    $reader = wooDb();
    $drained = WooTestKit::drain($reader, 'redirects', 2);
    same(2, count($drained['records']));
    same(['r1', 'm1-0'], array_map(static function (array $r): string {
        return $r['id'];
    }, $drained['records']));
    same(['source' => '/mesas-antiguas/', 'target' => '/mesas/', 'code' => 301, 'active' => true], array_intersect_key($drained['records'][0], array_flip(['source', 'target', 'code', 'active'])));
    same('/oferta', $drained['records'][1]['source']);
    same('/ofertas/', $drained['records'][1]['target']);
    same(['r2', 'r3', 'm2-0'], array_map(static function (array $s): string {
        return $s['id'];
    }, $drained['skipped']));
    same(5, $drained['read']);
    same(5, count($drained['records']) + count($drained['skipped']));
    $first = $reader->read('redirects', '', 2);
    same('r:2', $first['next']);
    $pdo = wooMemoryDb(false);
    $pdo->exec('DROP TABLE wp_redirection_items');
    $pdo->exec('DROP TABLE wp_rank_math_redirections');
    same([], WooTestKit::drain(wooDb(false, [], $pdo), 'redirects')['records']);
});

test('el lector solo ejecuta SELECT', function (): void {
    $pdo = wooMemoryDb(false, 'wp_', '', 'WooRecordingPdo');
    $pdo->statements = [];
    $reader = wooDb(false, [], $pdo);
    foreach ($reader->types() as $type) {
        WooTestKit::drain($reader, $type, 2);
    }
    $reader->probe();
    $reader->checksums();
    $reader->unmapped();
    foreach (['products', 'orders', 'categories', 'customers', 'pages', 'reviews'] as $type) {
        $reader->count($type);
    }
    check(count($pdo->statements) > 80, 'hizo muchas consultas');
    foreach ($pdo->statements as $statement) {
        check(stripos(ltrim($statement), 'SELECT') === 0, 'no es un SELECT: ' . $statement);
        check(strpos($statement, 'wp_') !== false, 'sin tablas del prefijo: ' . $statement);
    }
    $hpos = wooMemoryDb(true, 'wp_', '', 'WooRecordingPdo');
    $hpos->statements = [];
    foreach (['orders', 'settings'] as $type) {
        WooTestKit::drain(wooDb(true, [], $hpos), $type, 2);
    }
    foreach ($hpos->statements as $statement) {
        check(stripos(ltrim($statement), 'SELECT') === 0, 'no es un SELECT: ' . $statement);
    }
});

test('una opción serializada con un objeto dentro no instancia nada', function (): void {
    $pdo = wooMemoryDb(false);
    $statement = $pdo->prepare('UPDATE wp_options SET option_value = ? WHERE option_name = ?');
    $statement->execute([serialize(['enabled' => 'yes', 'title' => 'Transferencia', 'boom' => new WooBoom(), 'nested' => ['x' => new WooBoom()]]), 'woocommerce_bacs_settings']);
    WooBoom::$woke = false;
    $gateways = WooTestKit::drain(wooDb(false, [], $pdo), 'payment_methods')['records'];
    same(false, WooBoom::$woke, 'ningún objeto se despertó');
    same(['es' => 'Transferencia'], WooTestKit::byId($gateways, 'bacs')['title']);
    same(null, WooTestKit::byId($gateways, 'bacs')['settings']['boom']);
    same(['x' => null], WooTestKit::byId($gateways, 'bacs')['settings']['nested']);
    same([], Neutral::check('payment_methods', WooTestKit::byId($gateways, 'bacs')));
    $statement->execute(['a:1:{s:1:"x";O:7:"Inexist":0:{}}', 'woocommerce_redsys_settings']);
    $again = WooTestKit::drain(wooDb(false, [], $pdo), 'payment_methods')['records'];
    same(2, count($again));
    $statement->execute(['esto no está serializado', 'woocommerce_redsys_settings']);
    same(2, count(WooTestKit::drain(wooDb(false, [], $pdo), 'payment_methods')['records']));
});

test('contraseñas: sale el resumen con su esquema y Neutral::redact lo tapa', function (): void {
    $customers = WooTestKit::drain(wooDb(), 'customers')['records'];
    same('$P$B5xK0e8nOcRyGm1WjQh4T0Z9R2vQ1a0', $customers[0]['password_hash']);
    same(['wordpress', 'bcrypt', 'wordpress'], array_map(static function (array $c): string {
        return $c['password_scheme'];
    }, $customers));
    same('$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S', $customers[1]['password_hash']);
    foreach ($customers as $customer) {
        same([], Neutral::check('customers', $customer));
        same('***', Neutral::redact('customers', $customer)['password_hash']);
        notContains($customer['password_hash'], json_encode(Neutral::redact('customers', $customer)));
    }
    same('ana', $customers[0]['extra']['username']);
    $payments = WooTestKit::byId(WooTestKit::drain(wooDb(), 'payment_methods')['records'], 'redsys');
    same('clave-de-mentira', $payments['settings']['secret_key']);
    notContains('clave-de-mentira', json_encode(Neutral::redact('payment_methods', $payments)));
});

test('imágenes: el fichero de la copia si existe (path) y la dirección si no (url con base_url)', function (): void {
    $dir = tmpDir('woo-files');
    mkdir($dir . '/wp-content/uploads/2025/02', 0777, true);
    file_put_contents($dir . '/wp-content/uploads/2025/02/mesa-1.jpg', 'imagen de mentira');
    $reader = wooDb(false, ['files' => $dir]);
    $mesa = WooTestKit::byId(WooTestKit::drain($reader, 'products')['records'], '101');
    same($dir . '/wp-content/uploads/2025/02/mesa-1.jpg', $mesa['images'][0]['path']);
    check(!array_key_exists('url', $mesa['images'][0]), 'con fichero no lleva url');
    same(['url' => 'https://tienda.test/wp-content/uploads/2025/02/mesa-2.jpg', 'position' => 1, 'cover' => false], $mesa['images'][1]);
    same([], Neutral::check('products', $mesa));
    $media = WooTestKit::byId(WooTestKit::drain($reader, 'media')['records'], '401');
    same($dir . '/wp-content/uploads/2025/02/mesa-1.jpg', $media['path']);
    same('https://tienda.test/wp-content/uploads/2025/02/mesa-1.jpg', $media['url']);
    same(['/wp-content/uploads/2025/02/mesa-1.jpg'], $media['old_paths']);
    check(!array_key_exists('path', WooTestKit::byId(WooTestKit::drain($reader, 'media')['records'], '402')));
    $other = wooDb(false, ['base_url' => 'https://vieja.test']);
    same('https://vieja.test/wp-content/uploads/2025/02/mesa-1.jpg', WooTestKit::byId(WooTestKit::drain($other, 'products')['records'], '101')['images'][0]['url']);

    $pdo = wooMemoryDb(false);
    $pdo->exec("DELETE FROM wp_options WHERE option_name = 'siteurl'");
    $nothing = WooTestKit::byId(WooTestKit::drain(wooDb(false, ['base_url' => ''], $pdo), 'products')['records'], '101');
    same([], $nothing['images']);
    same([], Neutral::check('products', $nothing));
});

test('zona horaria del origen: con la fecha de GMT vale esa y sin ella se pasa la local a UTC', function (): void {
    $products = WooTestKit::drain(wooDb(), 'products')['records'];
    same('2025-02-12T10:00:00Z', WooTestKit::byId($products, '101')['created_at']);
    $pdo = wooMemoryDb(false);
    $pdo->exec("UPDATE wp_posts SET post_date_gmt = '0000-00-00 00:00:00', post_date = '2025-07-12 12:00:00' WHERE ID = 101");
    $fixed = WooTestKit::byId(WooTestKit::drain(wooDb(false, [], $pdo), 'products')['records'], '101');
    same('2025-07-12T10:00:00Z', $fixed['created_at']);
    same('2025-07-12T10:00:00Z', $fixed['published_at']);
    $override = WooTestKit::byId(WooTestKit::drain(wooDb(false, ['timezone' => 'UTC'], $pdo), 'products')['records'], '101');
    same('2025-07-12T12:00:00Z', $override['created_at']);
    $orders = WooTestKit::drain(wooDb(), 'orders')['records'];
    same('2026-10-01T08:15:00Z', WooTestKit::byId($orders, '9001')['placed_at']);
    same('2026-10-01T08:16:00Z', WooTestKit::byId($orders, '9001')['paid_at']);
    $settings = wooDb()->read('settings', '', 1)['records'][0];
    same('Europe/Madrid', $settings['timezone']);
});

test('multisitio: el prefijo se ajusta al sitio (wp_2_) y los usuarios siguen en el prefijo base', function (): void {
    $pdo = wooMemoryDb(false, 'wp_2_', 'wp_');
    $reader = new DbReader($pdo, ['prefix' => 'wp_', 'shop' => '2', 'base_url' => 'https://tienda.test']);
    same(6, $reader->count('products'));
    same(3, $reader->count('customers'));
    $drained = WooTestKit::drain($reader, 'customers');
    same(['77', '78', '79'], array_map(static function (array $c): string {
        return $c['id'];
    }, $drained['records']));
    same(4, count(WooTestKit::drain($reader, 'orders')['records']));
    same(2, count(WooTestKit::drain($reader, 'team')['records']));
    same('sqlite:wp_2_', $reader->key());
    $wrong = new DbReader($pdo, ['prefix' => 'wp_', 'base_url' => 'https://tienda.test']);
    throws(function () use ($wrong): void {
        $wrong->read('products', '', 10);
    }, SourceError::class);
    check(isset(DbReader::describe()['fields']['shop']), 'la ficha pide el sitio');
    throws(function (): void {
        new DbReader(wooMemoryDb(false), ['prefix' => 'wp_; DROP TABLE x']);
    }, InvalidArgumentException::class);
    throws(function (): void {
        new DbReader('no soy un PDO', []);
    }, InvalidArgumentException::class);
});

test('probe: con productos va bien; tienda vacía, sin tablas o con WPML se dice', function (): void {
    $ok = wooDb()->probe();
    same(true, $ok['ok']);
    same('Connected to the database', $ok['message']);
    same('WooCommerce 10.8.1, HPOS off', $ok['version']);
    same('EUR', $ok['currency']);
    same(['es'], $ok['languages']);
    same(['products' => 6, 'orders' => 4, 'customers' => 3], $ok['counts']);
    same([], $ok['warnings']);
    same('WooCommerce 10.8.1, HPOS on', wooDb(true)->probe()['version']);

    $empty = wooDb(false, [], wooMemoryDb(false, 'wp_', '', 'PDO', true))->probe();
    same(false, $empty['ok']);
    contains('no products', $empty['message']);

    $missing = wooDb(false, [], new PDO('sqlite::memory:'))->probe();
    same(false, $missing['ok']);
    contains('check the table prefix', $missing['message']);

    $pdo = wooMemoryDb(false);
    $statement = $pdo->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = 'active_plugins'");
    $statement->execute([serialize(['woocommerce/woocommerce.php', 'polylang/polylang.php'])]);
    $multilingual = wooDb(false, [], $pdo)->probe();
    same(true, $multilingual['ok']);
    same(['WPML or Polylang found: only the main language is brought and the other languages are not'], $multilingual['warnings']);

    $blogs = wooMemoryDb(false);
    $blogs->exec('CREATE TABLE wp_blogs (blog_id INTEGER PRIMARY KEY, domain TEXT, path TEXT)');
    $blogs->exec("INSERT INTO wp_blogs VALUES (1, 'tienda.test', '/'), (2, 'tienda.test', '/en/')");
    same([['id' => '1', 'name' => 'tienda.test/'], ['id' => '2', 'name' => 'tienda.test/en/']], wooDb(false, [], $blogs)->probe()['shops']);
});

test('permiso denegado en la base de datos es SourceDenied y no enseña el usuario', function (): void {
    $pdo = wooMemoryDb(false, 'wp_', '', 'WooRecordingPdo');
    $reader = wooDb(false, [], $pdo);
    $pdo->deny = true;
    try {
        $reader->read('products', '', 10);
        check(false, 'tenía que lanzar');
    } catch (SourceDenied $e) {
        notContains('secreto-de-mentira', $e->getMessage());
    }
    $probe = $reader->probe();
    same(false, $probe['ok']);
    notContains('secreto-de-mentira', json_encode($probe));
    throws(function () use ($reader): void {
        $reader->read('customer_groups', '', 10);
    }, InvalidArgumentException::class);
});

test('sin traer: los plugins con el nombre de su cabecera, los papeles propios y nada de WooCommerce ni Yoast', function (): void {
    $dir = tmpDir('woo-files');
    mkdir($dir . '/wp-content/plugins/gravityforms', 0777, true);
    file_put_contents($dir . '/wp-content/plugins/gravityforms/gravityforms.php', "<?php\n/*\nPlugin Name: Gravity Forms\nVersion: 2.9\n*/\n");
    $unmapped = wooDb(false, ['files' => $dir])->unmapped();
    $names = array_map(static function (array $u): string {
        return $u['name'];
    }, $unmapped);
    same(['Woocommerce Subscriptions', 'Mailpoet', 'Gravity Forms', 'mayorista'], $names);
    same(['plugin', 'plugin', 'plugin', 'role'], array_map(static function (array $u): string {
        return $u['kind'];
    }, $unmapped));
    same(0, $unmapped[3]['count']);
    $sinFicheros = array_map(static function (array $u): string {
        return $u['name'];
    }, wooDb()->unmapped());
    check(in_array('Gravityforms', $sinFicheros, true), 'sin la carpeta de ficheros, la carpeta del plugin con otro aspecto');
});

test('sumas de control del origen: hechas a mano', function (): void {
    foreach ([false, true] as $hpos) {
        $sums = wooDb($hpos)->checksums();
        same(6, $sums['products_count'], 'productos');
        same(3, $sums['variants_count'], 'variantes');
        same(4, $sums['categories_count'], 'categorías');
        same(3, $sums['customers_count'], 'clientes');
        same(4, $sums['orders_count'], 'pedidos');
        same(7, $sums['order_items_count'], 'líneas de pedido');
        same(13614 + 19832 + 18150 + 7974, $sums['orders_total'], 'importe de los pedidos');
        same(14 + 3 + 0 + 9 + 5 + 2, $sums['stock_total'], 'existencias');
        same(4, $sums['tax_rates_count'], 'tipos de impuesto');
        same(2, $sums['coupons_count'], 'cupones');
        same(3, $sums['pages_count'], 'páginas');
        same(2, $sums['posts_count'], 'entradas');
    }
    $reader = wooDb();
    same(6, $reader->count('products'));
    same(2, $reader->count('post_categories'));
    same(6, $reader->count('media'));
    same(2, $reader->count('reviews'));
    same(null, $reader->count('menus'));
});

test('producto: variaciones con su precio, existencias y atributos; categoría con su ruta y marca con su logotipo', function (): void {
    $reader = wooDb();
    $products = WooTestKit::drain($reader, 'products')['records'];
    $silla = WooTestKit::byId($products, '102');
    same(3, count($silla['variants']));
    same(['21', '22', '23'], array_map(static function (array $v): string {
        return $v['values'][0]['value_id'];
    }, $silla['variants']));
    same([9, 5, 2], array_map(static function (array $v): int {
        return $v['stock'];
    }, $silla['variants']));
    same([5000, 6000, 6300], array_map(static function (array $v): int {
        return $v['price'];
    }, $silla['variants']));
    same(7000, $silla['variants'][2]['compare_price']);
    same('digital', WooTestKit::byId($products, '103')['kind']);
    same('draft', WooTestKit::byId($products, '106')['status']);
    same('outofstock', WooTestKit::byId($products, '105')['extra']['stock_status']);
    same(['/producto/mesa-auxiliar-roble/'], WooTestKit::byId($products, '101')['old_paths']);
    $categories = WooTestKit::drain($reader, 'categories')['records'];
    same(['/categoria/muebles/mesas/'], WooTestKit::byId($categories, '12')['old_paths']);
    same('2', WooTestKit::byId($categories, '12')['parent_id']);
    same(1, WooTestKit::byId($categories, '12')['position']);
    $brand = WooTestKit::drain($reader, 'brands')['records'][0];
    same('Nordika', $brand['name']);
    same(['url' => 'https://tienda.test/wp-content/uploads/2026/01/logo.png', 'alt' => ['es' => 'Logotipo']], $brand['logo']);
    $attributes = WooTestKit::drain($reader, 'attributes')['records'];
    same(['tamano', 'acabado'], array_map(static function (array $a): string {
        return $a['code'];
    }, $attributes));
    same(['es' => 'Tamaño'], $attributes[0]['name']);
    same(3, count($attributes[0]['values']));
});

test('pedido con una variación: la etiqueta sale del atributo y de su valor', function (): void {
    $orders = WooTestKit::drain(wooDb(), 'orders')['records'];
    $guest = WooTestKit::byId($orders, '9004');
    same('Tamaño: Mediana', $guest['items'][0]['variant_label']);
    same('202', $guest['items'][0]['variant_id']);
    same('102', $guest['items'][0]['product_id']);
    same('SILLA-NOGAL-M', $guest['items'][0]['sku']);
    check(!array_key_exists('customer_id', $guest), 'invitado');
    same('on_hold', $guest['state_meaning']);
    same('cancelled', WooTestKit::byId($orders, '9003')['state_meaning']);
    $pdo = wooMemoryDb(false);
    $pdo->exec("UPDATE wp_postmeta SET meta_value = '' WHERE post_id = 9003 AND meta_key = '_billing_email'");
    $drained = WooTestKit::drain(wooDb(false, [], $pdo), 'orders');
    same(3, count($drained['records']));
    same([['id' => '9003', 'reason' => 'order without an email']], $drained['skipped']);
    same(4, $drained['read']);
});

test('páginas, entradas y menú: ruta antigua, SEO de Yoast, páginas legales, bloques y árbol con el título de lo enlazado', function (): void {
    $reader = wooDb();
    $pages = WooTestKit::drain($reader, 'pages')['records'];
    same(['/politica-de-privacidad/'], WooTestKit::byId($pages, '7')['old_paths']);
    same('privacy', WooTestKit::byId($pages, '7')['legal']);
    same('terms', WooTestKit::byId($pages, '8')['legal']);
    same(['es' => 'Quiénes somos en Ñandú'], WooTestKit::byId($pages, '9')['meta_title']);
    same(['es' => 'Conoce el taller.'], WooTestKit::byId($pages, '9')['meta_description']);
    same('gutenberg', WooTestKit::byId($pages, '9')['content_format']);
    same('html', WooTestKit::byId($pages, '7')['content_format']);
    $posts = WooTestKit::drain($reader, 'posts')['records'];
    $cuidados = WooTestKit::byId($posts, '230');
    same('gutenberg', $cuidados['content_format']);
    same(['/como-cuidar-una-mesa-de-roble/'], $cuidados['old_paths']);
    same(['4'], $cuidados['category_ids']);
    same(['es' => ['roble']], $cuidados['tags']);
    same('Gestor de tienda', $cuidados['author']);
    same(['url' => 'https://tienda.test/wp-content/uploads/2026/03/taller.jpg'], $cuidados['image']);
    same('2026-03-04T07:30:00Z', $cuidados['published_at']);
    $postCategories = WooTestKit::drain($reader, 'post_categories')['records'];
    same(['/category/cuidados/novedades/'], WooTestKit::byId($postCategories, '5')['old_paths']);
    same('4', WooTestKit::byId($postCategories, '5')['parent_id']);
    $menu = WooTestKit::drain($reader, 'menus')['records'][0];
    same(['50', 'primary', 'Principal'], [$menu['id'], $menu['location'], $menu['name']]);
    same(['es' => 'Tienda'], $menu['items'][0]['label']);
    same(['es' => 'Mesas'], $menu['items'][0]['children'][0]['label']);
    same(['category', '12'], [$menu['items'][0]['children'][0]['type'], $menu['items'][0]['children'][0]['ref']]);
    same(['es' => 'Política de privacidad'], $menu['items'][1]['label']);
    same(['page', '7'], [$menu['items'][1]['type'], $menu['items'][1]['ref']]);
    same('home', $menu['items'][2]['type']);
    $plain = wooMemoryDb(false);
    $plain->exec("UPDATE wp_options SET option_value = '' WHERE option_name = 'permalink_structure'");
    $without = WooTestKit::drain(wooDb(false, [], $plain), 'pages')['records'];
    check(!array_key_exists('old_paths', WooTestKit::byId($without, '7')), 'sin estructura de enlaces no hay ruta que conservar');
});

test('configuración: ajustes, clases de impuesto (con alternativa a la opción antigua), tipos con códigos postales, zonas y pasarelas', function (): void {
    $reader = wooDb();
    $settings = $reader->read('settings', '', 1)['records'][0];
    same('Muebles Ñandú & Cía', $settings['shop_name']);
    same(['kg', 'cm'], [$settings['weight_unit'], $settings['dimension_unit']]);
    same(false, $settings['prices_include_tax']);
    same(true, $settings['display_prices_with_tax']);
    same(['url' => 'https://tienda.test/wp-content/uploads/2026/01/logo.png'], $settings['logo']);
    same(9005, $settings['order_number_next']);
    same(['terms' => '8', 'privacy' => '7'], $settings['legal_pages']);
    same('SE', $settings['address']['region']);
    $rates = WooTestKit::drain($reader, 'tax_rates')['records'];
    same([2100, 700, 400, 1000], array_map(static function (array $r): int {
        return $r['rate'];
    }, $rates));
    same(['', '35,38', '51', ''], array_map(static function (array $r): string {
        return $r['postcodes'];
    }, $rates));
    same(['standard', 'standard', 'standard', 'reduced-rate'], array_map(static function (array $r): string {
        return $r['class_id'];
    }, $rates));
    $zones = WooTestKit::drain($reader, 'shipping_zones')['records'];
    same([['country' => 'ES', 'region' => 'TF', 'postcodes' => '35,38'], ['country' => 'ES', 'region' => 'GC', 'postcodes' => '35,38']], $zones[1]['locations']);
    $carriers = WooTestKit::drain($reader, 'carriers')['records'];
    same(['11', '12', '13', '21'], array_map(static function (array $c): string {
        return $c['id'];
    }, $carriers));
    same([590, 1200], [$carriers[0]['rates'][0]['price'], $carriers[3]['rates'][0]['price']]);
    same(6000, $carriers[1]['free_from']);
    $gateways = WooTestKit::drain($reader, 'payment_methods')['records'];
    same([true, false], [$gateways[0]['enabled'], $gateways[1]['enabled']]);
    same(true, $gateways[1]['sandbox']);

    $old = wooMemoryDb(false);
    $old->exec('DROP TABLE wp_wc_tax_rate_classes');
    $old->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_tax_classes', 'Tipo Reducido\nSúper reducido', 'yes')");
    $classes = WooTestKit::drain(wooDb(false, [], $old), 'tax_classes')['records'];
    same(['standard', 'tipo-reducido', 'super-reducido'], array_map(static function (array $c): string {
        return $c['id'];
    }, $classes));
    same('Súper reducido', $classes[2]['name']);
});

test('cupones, opiniones y equipo: lo que no cabe a unsupported, solo las opiniones que son opiniones y los papeles', function (): void {
    $reader = wooDb();
    $coupons = WooTestKit::drain($reader, 'coupons')['records'];
    same(['percent', 'free_shipping'], [$coupons[0]['kind'], $coupons[1]['kind']]);
    same(5000, $coupons[0]['min_subtotal']);
    same('2026-12-21T00:00:00Z', $coupons[0]['ends_at']);
    same(['12'], $coupons[0]['category_ids']);
    same(['excluded products: 105', 'restricted to emails: ana@cliente.test'], $coupons[1]['unsupported']);
    $reviews = WooTestKit::drain($reader, 'reviews')['records'];
    same(['15', '16'], array_map(static function (array $r): string {
        return $r['id'];
    }, $reviews));
    same(['approved', 'pending'], [$reviews[0]['status'], $reviews[1]['status']]);
    same([5, 3], [$reviews[0]['rating'], $reviews[1]['rating']]);
    same(true, $reviews[0]['verified']);
    same('101', $reviews[0]['product_id']);
    $team = WooTestKit::drain($reader, 'team')['records'];
    same(['admin', 'manager'], [$team[0]['role_meaning'], $team[1]['role_meaning']]);
    same(['admin@tienda.test', 'gestor@tienda.test'], [$team[0]['email'], $team[1]['email']]);
});

test('un método de envío apagado en el origen sale inactivo, y uno de otro plugin se descarta con su motivo', function (): void {
    $pdo = wooMemoryDb(false);
    $pdo->exec('UPDATE wp_woocommerce_shipping_zone_methods SET is_enabled = 0 WHERE instance_id = 13');
    $pdo->exec("INSERT INTO wp_woocommerce_shipping_zone_methods (zone_id, instance_id, method_id, method_order, is_enabled) VALUES (2, 22, 'table_rate', 2, 1)");
    $drained = WooTestKit::drain(wooDb(false, [], $pdo), 'carriers');
    same(['11', '12', '13', '21'], array_map(static function (array $c): string {
        return $c['id'];
    }, $drained['records']));
    same([true, true, false, true], array_map(static function (array $c): bool {
        return $c['active'];
    }, $drained['records']));
    same([['id' => '22', 'reason' => 'unsupported shipping method table_rate']], $drained['skipped']);
    same(5, $drained['read']);
});
