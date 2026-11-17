<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Lo que impide que el copiloto haga daño: los permisos (el de usar el copiloto y el de cada acción), la vista previa caducada,
 * deshacer solo si nada lo tocó, el tope de registros, que un cambio en bloque no avise a ningún cliente, la ficha de formulario,
 * que una orden solo se aplique una vez y que las propuestas sean de quien las escribió.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Copilot\CopilotTasks;

require_once __DIR__ . '/helpers.php';

test('sin el permiso «copilot.use» no se abre nada: la pantalla, preguntar, aplicar y deshacer dan 403', function (): void {
    $app = copilotApp();
    adminOrdersRole($app, 'lector', ['orders.view', 'catalog.view']);
    $other = copilotAdmin($app);
    $row = copilotAsk($other, 'Add 5 units to SILLA-X');
    $web = copilotAdmin($app, 'lector');

    same(403, $web->get('/admin/copilot')->status);
    same(403, $web->get('/admin/copilot/' . $row['id'])->status);
    same(403, copilotPost($web, '/admin/copilot/ask', ['text' => 'Pon el stock de la mesa a 3'])->status);
    foreach (['apply', 'undo', 'discard', 'complete', 'choose'] as $route) {
        same(403, copilotPost($web, '/admin/copilot/' . $row['id'] . '/' . $route)->status, $route);
    }
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {copilot_proposals}'), 'no se ha creado ninguna propuesta');
    $menu = $web->get('/admin')->body;
    notContains('/admin/copilot', $menu, 'y el menú no lo enseña');
});

test('sin el permiso de la acción no se propone aplicar: la propuesta queda sin vista previa y no se puede aplicar', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    adminOrdersRole($app, 'soporte', ['copilot.use', 'orders.view']);
    $web = copilotAdmin($app, 'soporte');

    $row = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado con el seguimiento 4455');
    same('orders.ship', $row['action']);
    same('failed', $row['status']);
    same([], $row['preview']['changes'], 'no se enseña el pedido a quien no puede tocarlo');
    contains('No tienes permiso', $row['result']);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    notContains('/apply', $page);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]));
    same(null, $app->db()->value('SELECT tracking_code FROM {orders} WHERE id = ?', [$order['id']]));
});

test('aunque la propuesta ya esté lista, aplicar vuelve a mirar el permiso de la acción: si lo perdió entre medias, 403 y no cambia nada', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    adminOrdersRole($app, 'soporte', ['copilot.use', 'orders.view']);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado');
    same('proposed', $row['status']);

    $app->db()->update('users', ['role' => 'soporte'], ['email' => 'admin@tienda.test']);
    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(403, $r->status);
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]));
    same('proposed', copilotRow($app, (int) $row['id'])['status']);
});

test('vista previa caducada: si el dato cambia entre ver y aplicar, no se aplica y se enseña la nueva; aplicar otra vez sí', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Sube un 10 % el precio de SILLA-R');
    same(money(6589), $row['preview']['changes'][0]['to']);
    $fingerprint = $row['fingerprint'];

    $app->db()->update('products', ['price' => 8000], ['id' => $ids['roja']]);
    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply', [], true);
    $json = json_decode($r->body, true);
    same(false, $json['ok']);
    contains('Los datos han cambiado', $json['message']);
    same(8000, copilotProduct($app, $ids['roja'], 'price'), 'no se ha aplicado nada');

    $row = copilotRow($app, (int) $row['id']);
    same('proposed', $row['status']);
    check($row['fingerprint'] !== $fingerprint, 'la huella es otra');
    same([['label' => 'Silla roja', 'from' => money(8000), 'to' => money(8800)]], $row['preview']['changes'], 'se enseña la vista previa nueva');

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(8800, copilotProduct($app, $ids['roja'], 'price'), 'con la vista nueva a la vista, ahora sí');
});

test('la vista previa caducada también vale para un conjunto: un producto nuevo en la categoría cambia la huella', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Sube un 5 % los precios de la categoría Sillas');
    same(2, $row['preview']['count']);

    aifecProduct($app, ['name' => 'Silla verde', 'price' => 4000, 'categories' => [$ids['cat']]]);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(5990, copilotProduct($app, $ids['roja'], 'price'), 'no se ha aplicado');
    same(3, copilotRow($app, (int) $row['id'])['preview']['count'], 'y la vista nueva ya cuenta tres');
});

test('deshacer tras un cambio ajeno no deshace y lo dice: precio, existencias, estado del pedido, ajuste y tarifa', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $order = copilotOrder($app);
    $gls = shippingAddCarrier($app, 'gls', ['name' => 'GLS']);
    $rate = shippingAddRate($app, $gls, shippingZoneId($app, 'Peninsula'), 'weight', 0, 1000, 590);
    $web = copilotAdmin($app);

    $texts = [
        'price' => 'Pon el precio de SILLA-R a 64,90',
        'stock' => 'Pon el stock de SILLA-R a 20',
        'order' => 'Pon el pedido ' . $order['n'] . ' en espera',
        'setting' => 'Cambia el nombre de la tienda a Otra',
        'rate' => 'Pon la tarifa de GLS a 6,90',
    ];
    $rows = [];
    foreach ($texts as $key => $text) {
        $rows[$key] = copilotAsk($web, $text);
        copilotPost($web, '/admin/copilot/' . $rows[$key]['id'] . '/apply');
        same('applied', copilotRow($app, (int) $rows[$key]['id'])['status'], $key);
    }

    // Alguien más toca lo mismo después.
    $app->db()->update('products', ['price' => 7777, 'stock' => 7], ['id' => $ids['roja']]);
    $app->db()->update('orders', ['status' => 'completed'], ['id' => $order['id']]);
    $app->settings()->set('store.name', 'Cambiada por otra persona');
    $app->db()->update('carrier_rates', ['price' => 999], ['id' => $rate]);

    foreach ($rows as $key => $row) {
        $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo', [], true);
        $json = json_decode($r->body, true);
        same(false, $json['ok'], $key . ' no se deshace');
        contains('desde entonces', $json['message'], $key);
        same('applied', copilotRow($app, (int) $row['id'])['status'], $key . ' sigue aplicada');
    }
    same([7777, 7], [(int) copilotProduct($app, $ids['roja'], 'price'), (int) copilotProduct($app, $ids['roja'], 'stock')], 'lo ajeno queda como está');
    same('completed', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]));
    same('Cambiada por otra persona', $app->settings()->str('store.name'));
    same(999, (int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$rate]));
});

test('un bloque de más de 500 registros no se puede aplicar; el tope sale de los ajustes', function (): void {
    $app = copilotApp();
    $cat = aifecCategory($app, 'Masa');
    for ($i = 1; $i <= 501; ++$i) {
        aifecProduct($app, ['name' => 'Pieza ' . $i, 'price' => 1000 + $i, 'categories' => [$cat], 'stock' => 1]);
    }
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Sube un 5 % los precios de la categoría Masa');
    same(501, $row['preview']['count']);
    same(10, count($row['preview']['changes']), 'la pantalla enseña las diez primeras filas');
    same(491, $row['preview']['more']);
    contains('más de 500 registros', $row['preview']['errors'][0]);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    notContains('/apply', $page, 'no hay botón de aplicar');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(1001, (int) $app->db()->value('SELECT MIN(price) FROM {products}'), 'ningún precio ha cambiado');
    same('proposed', copilotRow($app, (int) $row['id'])['status']);

    $app->settings()->set('copilot.max_rows', 600);
    $again = copilotAsk($web, 'Sube un 5 % los precios de la categoría Masa');
    same([], $again['preview']['errors'], 'con un tope de 600 sí cabe');
});

test('un cambio en bloque no avisa a ningún cliente: no se encola ningún correo ni ninguna tarea', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    copilotOrder($app);
    $web = copilotAdmin($app);
    $mails = copilotMails($app);
    $jobs = (int) $app->db()->value('SELECT COUNT(*) FROM {jobs}');

    foreach (['Sube un 5 % los precios de la categoría Sillas', 'Desactiva los productos de la categoría Sillas', 'Activa los productos de la categoría Sillas'] as $text) {
        $row = copilotAsk($web, $text);
        copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
        same('applied', copilotRow($app, (int) $row['id'])['status'], $text);
    }
    same($mails, copilotMails($app));
    same($jobs, (int) $app->db()->value('SELECT COUNT(*) FROM {jobs}'));
});

test('la ficha del formulario es obligatoria: sin ella no se crea ni se aplica nada, y con JavaScript contesta 419', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');

    $web->get('/admin/profile');
    $r = $web->post('/admin/copilot/ask', ['text' => 'Pon el stock de SILLA-R a 3']);
    same(302, $r->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {copilot_proposals}'), 'no se ha creado otra');
    $r = $web->post('/admin/copilot/' . $row['id'] . '/apply');
    same(302, $r->status);
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
    $r = $web->post('/admin/copilot/' . $row['id'] . '/apply', [], ['accept' => 'application/json', 'x-requested-with' => 'fetch']);
    same(419, $r->status);
    $r = $web->post('/admin/copilot/' . $row['id'] . '/apply', ['_token' => 'falsa']);
    same(302, $r->status);
    same(5990, copilotProduct($app, $ids['roja'], 'price'), 'una ficha falsa tampoco vale');
});

test('una orden solo se aplica una vez: pulsar dos veces no repite el cambio', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Add 5 units to SILLA-R');

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(15, (int) copilotProduct($app, $ids['roja'], 'stock'), 'las existencias suben una sola vez');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'copilot.apply'"));
    $json = json_decode(copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply', [], true)->body, true);
    contains('ya estaba aplicado', $json['message']);
});

test('una propuesta es de quien la escribió: otra persona del equipo recibe 404 al abrirla, aplicarla, deshacerla o descartarla', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');

    aifecAdmin($app, 'admin', 'otra@tienda.test', 'Otra Persona');
    $other = new TestClient($app);
    aifecLogin($other, 'otra@tienda.test');
    $other->get('/admin');
    same(404, $other->get('/admin/copilot/' . $row['id'])->status);
    foreach (['apply', 'undo', 'discard', 'complete'] as $route) {
        same(404, copilotPost($other, '/admin/copilot/' . $row['id'] . '/' . $route)->status, $route);
    }
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
    same('proposed', copilotRow($app, (int) $row['id'])['status']);
    notContains('Pon el precio de SILLA-R', $other->get('/admin/copilot')->body, 'y su historial no lo enseña');
});

test('descartar no hace nada: la propuesta queda descartada y aplicarla después no cambia el dato', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/discard');
    same('discarded', copilotRow($app, (int) $row['id'])['status']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
    same('discarded', copilotRow($app, (int) $row['id'])['status']);
});

test('cada aplicar y cada deshacer va a la auditoría con un resumen que se entiende', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado con el seguimiento 4455');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');

    $audit = $app->db()->select("SELECT action, entity, entity_id, summary, user_name FROM {audit} WHERE action LIKE 'copilot.%' ORDER BY id");
    same(['copilot.apply', 'copilot.undo'], array_column($audit, 'action'));
    same('copilot', $audit[0]['entity']);
    same((string) $row['id'], $audit[0]['entity_id']);
    contains('Marcar el pedido ' . $order['number'] . ' como enviado', $audit[0]['summary']);
    same('Admin', $audit[0]['user_name']);
});

test('la tarea diaria borra del historial lo antiguo y nada más: ni propuestas recientes ni datos de la tienda', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $old = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');
    $recent = copilotAsk($web, 'Pon el precio de SILLA-A a 74,90');
    $app->db()->update('copilot_proposals', ['updated_at' => $app->clock()->sql($app->clock()->now() - 100 * 86400)], ['id' => $old['id']]);
    $products = (int) $app->db()->value('SELECT COUNT(*) FROM {products}');

    same('1 proposals', CopilotTasks::purge($app));
    same([(int) $recent['id']], array_map('intval', $app->db()->column('SELECT id FROM {copilot_proposals}')));
    same($products, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    same(null, CopilotTasks::purge($app), 'sin nada que borrar no dice nada');

    $app->settings()->set('copilot.history_days', 1);
    $app->db()->update('copilot_proposals', ['updated_at' => $app->clock()->sql($app->clock()->now() - 3 * 86400)], ['id' => $recent['id']]);
    same('1 proposals', CopilotTasks::purge($app), 'el plazo sale de los ajustes');
    same([['copilot.purge']], array_map(static function (string $id): array {
        return [$id];
    }, array_keys($app->modules()->definition('copilot', 'jobs')['cron'])), 'la tarea está declarada en jobs.php');
});
