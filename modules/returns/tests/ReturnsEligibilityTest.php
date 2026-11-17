<?php
/**
 * AIFEC — pruebas de la elegibilidad: qué se puede devolver de un pedido y hasta cuándo (plazos con el reloj de pruebas, pedido sin
 * enviar o sin pagar, unidades ya devueltas, productos excluidos del desistimiento).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('dentro de plazo se puede devolver: los plazos cuentan desde la entrega y salen las líneas con lo que queda', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 3]], ['days' => 2]);
    $e = $app->service('returns')->eligibility($id);
    same(true, $e['ok'], (string) $e['message']);
    same('ok', $e['code']);
    same(true, $e['return_open']);
    same(true, $e['withdrawal_open']);
    $delivered = (string) $app->db()->value('SELECT completed_at FROM {orders} WHERE id = ?', [$id]);
    same($app->clock()->sql(strtotime($delivered . ' UTC') + 30 * 86400), $e['return_until']);
    same($app->clock()->sql(strtotime($delivered . ' UTC') + 14 * 86400), $e['withdrawal_until']);
    same(1, count($e['lines']));
    same('Camiseta', $e['lines'][0]['name']);
    same(3, $e['lines'][0]['available']);
    same(null, $e['open_return']);
});

test('fuera del plazo de devolución y del de desistimiento no se puede devolver', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['days' => 40]);
    $e = $app->service('returns')->eligibility($id);
    same(false, $e['ok']);
    same('expired', $e['code']);
    same(false, $e['return_open']);
    same(false, $e['withdrawal_open']);
    contains('plazo', $e['message']);
});

test('pasados los 14 días el desistimiento se cierra pero la devolución corriente sigue abierta hasta los 30', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['days' => 20]);
    $e = $app->service('returns')->eligibility($id);
    same(true, $e['ok']);
    same(true, $e['return_open']);
    same(false, $e['withdrawal_open']);
    same(false, $e['lines'][0]['withdrawal_ok']);
    same(true, $e['lines'][0]['return_ok']);
});

test('el reloj de pruebas manda: un día antes del límite se puede y un segundo después ya no', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['days' => 2]);
    $returns = $app->service('returns');
    $until = Aifec\Kernel\Clock::parse($returns->eligibility($id)['return_until']);
    $app->clock()->set($until - 1);
    same(true, $returns->eligibility($id)['ok'], 'un segundo antes');
    $app->clock()->set($until + 1);
    $e = $returns->eligibility($id);
    same(false, $e['ok'], 'un segundo después');
    same('expired', $e['code']);
});

test('un pedido pagado y sin enviar no se devuelve todavía, pero el desistimiento se puede pedir ya y queda como solicitud', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $returns = $app->service('returns');
    $e = $returns->eligibility($id);
    same(true, $e['ok'], 'el desistimiento existe desde que se hace el pedido');
    same(true, $e['unshipped']);
    same(true, $e['withdrawal_open']);
    same(false, $e['return_open'], 'la devolución de siempre es cuando llegue');
    same(null, $e['withdrawal_until'], 'su plazo aún no ha empezado a correr: no hay fecha');
    same(null, $e['return_until']);

    $item = (int) $e['lines'][0]['id'];
    $bad = $returns->request($id, [$item => 1], 'not_fit', '');
    same(false, $bad['ok'], 'pedir una devolución normal de lo que no ha llegado no vale');
    contains('todavía no se ha enviado', (string) $bad['error']);

    $ok = $returns->request($id, [$item => 1], 'withdrawal', '');
    same(true, $ok['ok'], (string) $ok['error']);
    $row = $returns->findByNumber((string) $ok['number']);
    same('withdrawal', $row['kind']);
    same('requested', $row['status'], 'queda pedida para que la tienda la atienda');
});

test('un pedido sin pagar, uno cancelado y uno que no existe no se pueden devolver', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $returns = $app->service('returns');
    $unpaid = returnsOrder($app, [[$p, 0, 1]], ['state' => 'pending_payment']);
    same('not_paid', $returns->eligibility($unpaid)['code']);

    // Un pedido pagado no se cancela: se reembolsa entero y queda «refunded».
    $paid = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $app->service('orders')->refund($paid, (int) $app->service('orders')->find($paid)['paid_total'], [], 'Ya no lo quiere', false, false);
    $cancelled = $returns->eligibility($paid);
    same(false, $cancelled['ok']);
    same('cancelled', $cancelled['code']);

    $none = $returns->eligibility(99999);
    same(false, $none['ok']);
    same('unknown_order', $none['code']);
});

test('sin fecha de entrega el plazo cuenta desde el envío más los días de tránsito', function (): void {
    $app = returnsApp(['settings' => ['returns.transit_days' => 5]]);
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['days' => 10, 'shipped_only' => true]);
    $e = $app->service('returns')->eligibility($id);
    $shipped = Aifec\Kernel\Clock::parse((string) $app->db()->value('SELECT shipped_at FROM {orders} WHERE id = ?', [$id]));
    same($app->clock()->sql($shipped + 5 * 86400), $e['start']);
    same($app->clock()->sql($shipped + 5 * 86400 + 14 * 86400), $e['withdrawal_until']);
    same(true, $e['ok']);

    // Con los días de tránsito de serie (3) y un envío de hace 20 días, el desistimiento ya cerró pero la devolución sigue.
    $late = returnsOrder($app, [[$p, 0, 1]], ['days' => 20, 'shipped_only' => true]);
    $app->settings()->set('returns.transit_days', 3);
    $l = $app->service('returns')->eligibility($late);
    same(true, $l['return_open']);
    same(false, $l['withdrawal_open']);
});

test('las unidades ya devueltas se descuentan y, cuando no queda nada, no hay nada que devolver', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 3]]);
    $item = returnsItemId($app, $id);
    $app->service('orders')->refund($id, 2000, [$item => 1], 'Una unidad', false, false);
    $e = $app->service('returns')->eligibility($id);
    same(true, $e['ok']);
    same(2, $e['lines'][0]['available']);
    same(1, $e['lines'][0]['returned']);

    $app->service('orders')->refund($id, 4000, [$item => 2], 'El resto', false, false);
    $none = $app->service('returns')->eligibility($id);
    same(false, $none['ok']);
    check(in_array($none['code'], ['cancelled', 'nothing_left'], true), 'sin nada que devolver: ' . $none['code']);
});

test('un producto con la marca «no_withdrawal» o de una categoría excluida no admite desistimiento pero sí devolución corriente', function (): void {
    $app = returnsApp();
    $cat = aifecCategory($app, 'Personalizados');
    $marked = aifecProduct($app, ['name' => 'Taza con nombre', 'price' => 1500, 'stock' => 50, 'extra' => ['no_withdrawal' => ['label' => 'No desistimiento', 'value' => 1, 'type' => 'toggle', 'public' => false]]]);
    $inCat = aifecProduct($app, ['name' => 'Camiseta bordada', 'price' => 2500, 'stock' => 50, 'categories' => [$cat]]);
    $normal = aifecProduct($app, ['name' => 'Libro', 'price' => 1000, 'stock' => 50]);
    $app->settings()->set('returns.excluded_categories', [(string) $cat]);
    $id = returnsOrder($app, [[$marked, 0, 1], [$inCat, 0, 1], [$normal, 0, 1]]);
    $e = $app->service('returns')->eligibility($id);
    same(true, $e['ok']);
    same(true, $e['lines'][0]['withdrawal_excluded']);
    same(true, $e['lines'][1]['withdrawal_excluded']);
    same(false, $e['lines'][2]['withdrawal_excluded']);
    same(false, $e['lines'][0]['withdrawal_ok']);
    same(true, $e['lines'][0]['return_ok'], 'la devolución corriente sí');
    same(true, $e['lines'][2]['withdrawal_ok']);
    contains('excluido', $e['lines'][0]['excluded_reason']);
    same('', $e['lines'][2]['excluded_reason']);
});

test('lo digital no se devuelve: un pedido solo con un producto digital no tiene nada que devolver', function (): void {
    $app = returnsApp();
    $digital = aifecProduct($app, ['name' => 'Guía en PDF', 'kind' => 'digital', 'price' => 900, 'stock' => 50]);
    $id = returnsOrder($app, [[$digital, 0, 1]]);
    $e = $app->service('returns')->eligibility($id);
    same(false, $e['ok']);
    check(in_array($e['code'], ['nothing_left', 'not_shipped'], true), 'sin nada que devolver: ' . $e['code']);
    same([], $e['lines']);
    // 14/11/2026: decía solo «No queda nada por devolver» y la página del pedido no enseñaba nada de devoluciones.
    contains('solo lleva productos digitales', $e['message']);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $page = (new TestClient($app))->get('/pedido/' . $number . '/' . $app->service('orders')->viewTokenOf($number));
    same(200, $page->status);
    contains('data-af-returns-notice', $page->body, 'la página del pedido dice por qué no se puede devolver');
    contains('solo lleva productos digitales', $page->body);
});

test('lo ya devuelto entero lo dice así, no con un «no queda nada» a secas', function (): void {
    $app = returnsApp();
    $chair = aifecProduct($app, ['name' => 'Silla', 'price' => 900, 'stock' => 50]);
    $id = returnsOrder($app, [[$chair, 0, 1]]);
    $app->db()->run("UPDATE {order_items} SET refunded_qty = qty WHERE order_id = ? AND kind = 'product'", [$id]);
    $e = $app->service('returns')->eligibility($id);
    same('nothing_left', $e['code']);
    contains('ya se ha devuelto', $e['message']);
});

test('con una devolución abierta el pedido no admite otra y lo dice con su número', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2]]);
    $ask = returnsAsk($app, $id, [0 => 1]);
    same(true, $ask['ok'], (string) $ask['error']);
    $e = $app->service('returns')->eligibility($id);
    same(false, $e['ok']);
    same('open_return', $e['code']);
    same($ask['number'], $e['open_return']['number']);
    same('requested', $e['open_return']['status']);
});

test('los plazos de los ajustes mandan: con 0 días de devolución solo queda el desistimiento', function (): void {
    $app = returnsApp(['settings' => ['returns.days' => 0, 'returns.withdrawal_days' => 14]]);
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['days' => 3]);
    $e = $app->service('returns')->eligibility($id);
    same(true, $e['ok']);
    same(false, $e['return_open']);
    same(true, $e['withdrawal_open']);
    same(true, $e['lines'][0]['selectable']);
    same(false, $e['lines'][0]['return_ok']);
    $bad = returnsAsk($app, $id, [0 => 1], 'not_fit');
    same(false, $bad['ok']);
    $good = returnsAsk($app, $id, [0 => 1], 'withdrawal');
    same(true, $good['ok'], (string) $good['error']);
});
