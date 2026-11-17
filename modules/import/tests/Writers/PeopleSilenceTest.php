<?php
/**
 * AIFEC — silencio de los escritores de personas, pedidos y contenidos: con el correo en modo real y un transporte en memoria, tras traer
 * clientes, pedidos pagados y enviados, opiniones, equipo, suscritos y contenidos, y tras ejecutar todas las tareas programadas de pedidos,
 * clientes, opiniones y correo, no ha salido ni un mensaje, no hay ni una fila en el registro de correo ni un trabajo de aviso en la cola.
 * También de punta a punta, con la migración entera (lector de mentira) corriendo por su ejecutor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Writers\CouponsWriter;
use Aifec\Modules\Import\Writers\CustomersWriter;
use Aifec\Modules\Import\Writers\MenusWriter;
use Aifec\Modules\Import\Writers\OrdersWriter;
use Aifec\Modules\Import\Writers\PagesWriter;
use Aifec\Modules\Import\Writers\PostCategoriesWriter;
use Aifec\Modules\Import\Writers\PostsWriter;
use Aifec\Modules\Import\Writers\ReviewsWriter;
use Aifec\Modules\Import\Writers\SubscribersWriter;
use Aifec\Modules\Import\Writers\TeamWriter;

require_once __DIR__ . '/WritersKit2.php';

pwTest('tras traer clientes, pedidos pagados y enviados, opiniones, equipo, suscritos y contenidos, y tras todas las tareas programadas: cero mensajes', function (): void {
    [$app, $transport, $ctx] = pwOrderSite();
    $db = $app->db();
    $guard = [
        'customers' => $db->value('SELECT COUNT(*) FROM {customers}'),
    ];
    $customers = new CustomersWriter($app);
    $customers->write(pwCustomer(['id' => '78', 'email' => 'luis@cliente.test', 'password_hash' => pwBcrypt('luis-1234'), 'password_scheme' => 'bcrypt']), $ctx);
    $customers->write(pwCustomer(['id' => '79', 'email' => 'eva@cliente.test', 'marketing' => true, 'password_hash' => md5('X' . 'eva'), 'password_scheme' => 'prestashop-md5']), $ctx);
    (new CouponsWriter($app))->write(wr('coupons'), $ctx);
    $orders = new OrdersWriter($app);
    $orders->write(pwOrder(['coupons' => [['code' => 'OTOÑO10', 'amount' => 500]]]), $ctx);
    $orders->write(pwOrderN(3, ['state_meaning' => 'paid', 'state' => '3', 'shipped_at' => null]), $ctx);
    $orders->write(pwOrderN(4, ['state_meaning' => 'delivered', 'state' => '6']), $ctx);
    $orders->write(pwOrderN(5, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0, 'placed_at' => '2026-11-12T11:00:00Z']), $ctx);
    $orders->write(pwOrderN(6, ['state_meaning' => 'refunded', 'state' => '8', 'refunded_total' => 13614, 'customer_id' => null, 'email' => 'invitado@cliente.test']), $ctx);
    (new ReviewsWriter($app))->write(wr('reviews'), $ctx);
    (new TeamWriter($app))->write(wr('team'), $ctx);
    (new SubscribersWriter($app))->write(wr('subscribers'), $ctx);
    (new SubscribersWriter($app))->write(wr('subscribers', ['id' => '41', 'email' => 'sin-fecha@cliente.test', 'granted_at' => null]), $ctx);
    $category = new PostCategoriesWriter($app);
    $category->write(wr('post_categories', ['old_paths' => []]), $ctx);
    (new PagesWriter($app))->write(wr('pages', ['old_paths' => []]), $ctx);
    (new PostsWriter($app))->write(wr('posts', ['old_paths' => [], 'image' => null]), $ctx);
    (new MenusWriter($app))->write(wr('menus'), $ctx);
    check((int) $guard['customers'] === 1, 'partíamos de un cliente');
    same(5, wrCount($app, 'orders'));
    pwSilent($app, $transport, 'tras traer todo');

    $ran = pwTasks($app);
    check(in_array('orders.expire', $ran, true) && in_array('reviews.ask', $ran, true) && in_array('mail.send', $ran, true), 'corrieron las tareas de pedidos, opiniones y correo: ' . implode(', ', $ran));
    pwSilent($app, $transport, 'tras las tareas programadas');
    same(0, wrCount($app, 'review_requests'));
    same(0, wrCount($app, 'tokens'), 'ni fichas de verificación ni de acceso');
    same(0, wrCount($app, 'order_downloads'));

    // El control: el correo sí funciona fuera de la migración, así que el silencio de arriba es de verdad.
    pwAllowMail($transport);
    $app->service('customers')->requestReset('luis@cliente.test');
    same(1, count($transport->sent), 'fuera de la migración, un correo de restablecer sale');
});

pwTest('el registro encuentra los diez escritores por su nombre, sin que nadie los declare: no hay código correcto que no llame nadie', function (): void {
    $app = testApp(['modules' => ['import', 'customers', 'coupons', 'orders', 'reviews', 'cms', 'media', 'catalog', 'tax', 'shipping', 'mail', 'jobs'],
        'settings' => ['modules.active' => ['import', 'customers', 'coupons', 'orders', 'reviews', 'cms', 'media', 'catalog', 'tax', 'shipping', 'mail', 'jobs']]]);
    $app->boot();
    $registry = importService($app)->registry();
    $expected = [
        'customers' => CustomersWriter::class, 'coupons' => CouponsWriter::class, 'orders' => OrdersWriter::class, 'reviews' => ReviewsWriter::class,
        'pages' => PagesWriter::class, 'post_categories' => PostCategoriesWriter::class, 'posts' => PostsWriter::class, 'menus' => MenusWriter::class,
        'team' => TeamWriter::class, 'subscribers' => SubscribersWriter::class,
    ];
    foreach ($expected as $type => $class) {
        $writer = $registry->writer($type);
        check($writer instanceof $class, 'el escritor de «' . $type . '» es ' . $class);
        same($type, $writer->type(), 'y escribe el tipo «' . $type . '»');
        same(null, $registry->unavailable($type), 'y no falta ningún módulo para él');
    }
});

pwTest('de punta a punta: la migración entera de personas y contenidos, corriendo por su ejecutor, no avisa a nadie y cuadra read = created + …', function (): void {
    [$app, $transport] = pwApp();
    $app->hooks()->filter('import.writers', static function ($list): array {
        $list = is_array($list) ? $list : [];
        $mine = [
            'customers' => CustomersWriter::class, 'coupons' => CouponsWriter::class, 'orders' => OrdersWriter::class, 'reviews' => ReviewsWriter::class,
            'pages' => PagesWriter::class, 'post_categories' => PostCategoriesWriter::class, 'posts' => PostsWriter::class, 'menus' => MenusWriter::class,
            'team' => TeamWriter::class, 'subscribers' => SubscribersWriter::class,
        ];

        return $mine + $list;
    });
    $hash = pwBcrypt('clave-larga-1');
    ImportFakeReader::$data = [
        'customers' => [
            pwCustomer(['password_hash' => $hash, 'password_scheme' => 'bcrypt']),
            pwCustomer(['id' => '78', 'email' => 'luis@cliente.test', 'addresses' => [], 'consents' => [], 'password_hash' => pwPhpass('luis-1234'), 'password_scheme' => 'wordpress']),
            pwCustomer(['id' => '79', 'email' => 'no-es-un-correo', 'addresses' => [], 'consents' => []]),
        ],
        'coupons' => [wr('coupons')],
        'orders' => [wr('orders'), pwOrderN(3, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0, 'payments' => []])],
        'reviews' => [wr('reviews')],
        'pages' => [wr('pages')],
        'post_categories' => [wr('post_categories')],
        'posts' => [wr('posts', ['image' => null])],
        'menus' => [wr('menus')],
        'team' => [wr('team')],
        'subscribers' => [wr('subscribers'), wr('subscribers', ['id' => '41', 'email' => 'sin-fecha@cliente.test', 'granted_at' => null])],
    ];
    $types = array_keys(ImportFakeReader::$data);
    $id = importDraft($app, ['types' => $types]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);

    same('done', $run['status'], 'la migración termina: ' . (string) json_encode($run['counters']));
    foreach ($types as $type) {
        same((int) $run['counters'][$type]['read'], importSum($run, $type), 'read = created + updated + unchanged + conflict + skipped en ' . $type);
    }
    same([2, 1], [$run['counters']['customers']['created'], $run['counters']['customers']['skipped']], 'el correo malo se descarta con su motivo');
    same([1, 1], [$run['counters']['subscribers']['created'], $run['counters']['subscribers']['skipped']]);
    same(2, $run['counters']['orders']['created']);
    same(1, $run['counters']['menus']['created']);
    pwSilent($app, $transport, 'tras la migración entera');
    pwTasks($app);
    pwSilent($app, $transport, 'tras las tareas programadas');
    same(['customers'], pwLeaks($app, $hash), 'el resumen de la contraseña solo está en la fila del cliente: ni el informe ni el registro de actividad');
    same(['customers'], pwLeaks($app, pwPhpass('luis-1234')));

    importWork($app);
    $again = importDraft($app, ['types' => $types]);
    importService($app)->runner()->start($again);
    importWork($app);
    $second = importRun($app, $again);
    same('done', $second['status']);
    foreach ($types as $type) {
        same(0, $second['counters'][$type]['created'] + $second['counters'][$type]['updated'], 'la segunda vez no cambia nada en ' . $type);
    }
    pwSilent($app, $transport, 'tras repetir la migración');
});
