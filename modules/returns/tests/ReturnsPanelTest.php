<?php
/**
 * AIFEC — pruebas del panel de devoluciones: permisos, listado, ficha, cada cambio de estado con su correo (y sin correo si se desmarca),
 * el rechazo sin motivo, devolver el dinero llamando a Orders::refund() con las líneas, la reposición de existencias solo de las
 * líneas marcadas, el importe propuesto y sus topes, la pasarela que rechaza y la tarjeta de la ficha del pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

if (!class_exists('ReturnsFlakyPayment')) {
    /** Pasarela que devuelve bien la primera vez y rechaza la segunda (para cortar una devolución de dinero a medias). */
    final class ReturnsFlakyPayment implements PaymentMethod
    {
        /** @var int */
        public $calls = 0;

        /** @var int la llamada (1, 2…) que lanza; 0 = ninguna */
        public $failOn = 0;

        public function code(): string
        {
            return 'gw';
        }

        public function info(): array
        {
            return ['title' => 'Pasarela inestable', 'description' => '', 'icon' => ''];
        }

        public function available(array $context): bool
        {
            return true;
        }

        public function fee(array $context): int
        {
            return 0;
        }

        public function isTest(): bool
        {
            return false;
        }

        public function start(array $order): PaymentAction
        {
            return PaymentAction::redirect('https://pasarela.test/pagar/' . (string) $order['number']);
        }

        public function supportsRefund(): bool
        {
            return true;
        }

        public function refund(array $order, int $amount, string $reason): string
        {
            ++$this->calls;
            if ($this->failOn > 0 && $this->calls === $this->failOn) {
                throw new \RuntimeException('The gateway refused the refund');
            }

            return 'FLK-' . $this->calls;
        }
    }
}

if (!function_exists('returnsPanelRole')) {
    /** Da de alta un papel con esos permisos y devuelve un navegador de mentira con sesión de una persona de ese papel. */
    function returnsPanelRole(App $app, string $code, array $caps): TestClient
    {
        $app->db()->insert('roles', ['code' => $code, 'name' => ucfirst($code), 'caps' => json_encode($caps), 'system' => 0]);

        return adminClient($app, $code);
    }

    /**
     * Un pedido con dos líneas (A: 2 × 20,00 €, B: 1 × 10,00 €) y una devolución de todo, ya aceptada.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int} pedido, devolución, producto A, producto B y número de la devolución
     */
    function returnsPanelReceived(App $app, bool $receive = true, string $reason = 'not_fit'): array
    {
        $a = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
        $b = aifecProduct($app, ['name' => 'Gorra', 'price' => 1000, 'stock' => 50]);
        $order = returnsOrder($app, [[$a, 0, 2], [$b, 0, 1]]);
        $ask = returnsAsk($app, $order, [0 => 2, 1 => 1], $reason);
        check(!empty($ask['ok']), 'la devolución se pide: ' . (string) $ask['error']);
        $app->service('returns')->approve((int) $ask['id'], '', false);
        if ($receive) {
            $app->service('returns')->receive((int) $ask['id'], [], false);
        }

        return [$order, (int) $ask['id'], $a, $b, 0];
    }

    /** Los id de return_items por posición. @return array<int,int> */
    function returnsPanelLines(App $app, int $return): array
    {
        return array_map('intval', $app->db()->column('SELECT id FROM {return_items} WHERE return_id = ? ORDER BY id', [$return]));
    }
}

test('los permisos mandan: sin «returns.view» no se ve nada, con solo «view» se ve pero no se tramita, y sin ficha no se envía nada', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $ask = returnsAsk($app, $order, [0 => 1], 'defective');
    $id = (int) $ask['id'];

    $admin = adminClient($app);
    same(200, $admin->get('/admin/returns')->status);
    same(200, $admin->get('/admin/returns/' . $id)->status);
    $token = aifecToken($admin);

    $none = returnsPanelRole($app, 'almacen', ['dashboard.view', 'orders.view']);
    same(403, $none->get('/admin/returns')->status);
    same(403, $none->get('/admin/returns/' . $id)->status);
    same(403, $none->get('/admin/returns/' . $id . '/photo/0')->status);
    $none->get('/admin');
    same(403, $none->post('/admin/returns/' . $id . '/approve', ['_token' => aifecToken($none)])->status);

    $view = returnsPanelRole($app, 'lectura', ['dashboard.view', 'returns.view']);
    same(200, $view->get('/admin/returns')->status);
    $show = $view->get('/admin/returns/' . $id);
    same(200, $show->status);
    notContains('data-af-return-action="approve"', $show->body, 'quien solo ve no tiene las acciones');
    same(403, $view->post('/admin/returns/' . $id . '/approve', ['_token' => aifecToken($view)])->status);
    same('requested', $app->service('returns')->find($id)['status']);

    // Sin la ficha del formulario, ni el administrador pasa; y sin sesión, ni la lista ni la foto.
    $noToken = $admin->post('/admin/returns/' . $id . '/approve', []);
    check(in_array($noToken->status, [302, 403, 419], true), 'sin ficha no se acepta: ' . $noToken->status);
    same('requested', $app->service('returns')->find($id)['status']);
    $anon = new TestClient($app);
    check($anon->get('/admin/returns')->status !== 200, 'sin sesión no hay lista');
    check($anon->get('/admin/returns/' . $id . '/photo/0')->status !== 200, 'sin sesión no hay foto');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'return.approve'"), 'ninguna acción dejó huella');
});

test('el listado filtra por estado y busca por número, pedido o correo', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]], ['caja' => ['email' => 'ana@cliente.test']]);
    $b = returnsOrder($app, [[$p, 0, 1]], ['caja' => ['email' => 'luis@cliente.test']]);
    $c = returnsOrder($app, [[$p, 0, 1]], ['caja' => ['email' => 'marta@cliente.test']]);
    $ra = returnsAsk($app, $a, [0 => 1]);
    $rb = returnsAsk($app, $b, [0 => 1]);
    $rc = returnsAsk($app, $c, [0 => 1], 'withdrawal');
    $app->service('returns')->approve((int) $rb['id'], '', false);
    $app->service('returns')->reject((int) $rc['id'], 'Usado', false);
    $web = adminClient($app);

    $all = $web->get('/admin/returns')->body;
    foreach ([$ra, $rb, $rc] as $r) {
        contains((string) $r['number'], $all);
    }
    contains('Solicitada', $all);
    contains('Aceptada', $all);
    contains('Rechazada', $all);

    $approved = $web->get('/admin/returns?f[status]=approved')->body;
    contains((string) $rb['number'], $approved);
    notContains((string) $ra['number'], $approved);
    notContains((string) $rc['number'], $approved);

    $byNumber = $web->get('/admin/returns?q=' . $rc['number'])->body;
    contains((string) $rc['number'], $byNumber);
    notContains((string) $ra['number'], $byNumber);

    $byMail = $web->get('/admin/returns?q=luis@cliente')->body;
    contains((string) $rb['number'], $byMail);
    notContains((string) $ra['number'], $byMail);

    $orderNumber = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$a]);
    $byOrder = $web->get('/admin/returns?q=' . $orderNumber)->body;
    contains((string) $ra['number'], $byOrder);
    notContains((string) $rb['number'], $byOrder);

    $withdrawals = $web->get('/admin/returns?f[kind]=withdrawal')->body;
    contains((string) $rc['number'], $withdrawals);
    notContains((string) $ra['number'], $withdrawals);
});

test('la ficha enseña las líneas, el motivo, el historial y las fotos, con los textos de la persona escapados; la foto solo la sirve el panel', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $path = returnsPath($app, $order);
    $r = returnsUpload($app, new TestClient($app), $path, [
        'lines' => [returnsItemId($app, $order) => '1'], 'reason' => 'defective', 'comment' => 'A & B <script>alert(1)</script>',
    ], ['photo_1' => returnsFile(returnsImage('jpg', 3000), 'rota.jpg'), 'photo_2' => returnsFile(returnsImage('png', 2500), 'rota2.png')]);
    same(303, $r->status);
    $web = adminClient($app);
    $page = $web->get('/admin/returns/1');
    same(200, $page->status);
    contains('Devolución D-2026-000001', $page->body);
    contains('Camiseta', $page->body);
    contains('Defectuoso o dañado', $page->body);
    contains('A &amp; B &lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
    notContains('<script>alert(1)', $page->body);
    contains('Historial', $page->body);
    contains('Solicitada', $page->body);
    contains('/admin/returns/1/photo/0', $page->body);
    contains('/admin/returns/1/photo/1', $page->body);
    contains('/admin/orders/' . $order, $page->body);

    $photo = $web->get('/admin/returns/1/photo/0');
    same(200, $photo->status);
    same('image/jpeg', $photo->contentType());
    same('nosniff', (string) $photo->headers['X-Content-Type-Options']);
    contains('no-store', (string) $photo->headers['Cache-Control']);
    same(404, $web->get('/admin/returns/1/photo/5')->status, 'una foto que no existe');
    same(404, $web->get('/admin/returns/99/photo/0')->status, 'una devolución que no existe');
    same(404, $web->get('/admin/returns/99')->status);
});

test('aceptar: pasa a «approved» con las instrucciones (las propias o las de serie), avisa por correo y deja huella; una ya aceptada no se acepta otra vez', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $ra = (int) returnsAsk($app, $a, [0 => 1])['id'];
    $rb = (int) returnsAsk($app, $b, [0 => 1])['id'];
    $web = returnsAdmin($app, $ra);

    $r = $web->post('/admin/returns/' . $ra . '/approve', ['_token' => aifecToken($web), 'instructions' => 'Envía a Calle Falsa 1 & ciudad', 'notify' => '1']);
    same(302, $r->status);
    contains('La devolución está aceptada.', $web->follow()->body);
    $return = $app->service('returns')->find($ra);
    same('approved', $return['status']);
    same('Envía a Calle Falsa 1 & ciudad', $return['instructions']);
    check($return['approved_at'] !== null, 'fecha de aceptación');
    $mails = aifecOrderMails($app, 'return_approved');
    same(1, count($mails));
    contains('Calle Falsa 1 &amp; ciudad', (string) $mails[0]['html']);
    contains('D-2026-000001', (string) $mails[0]['subject']);
    same(true, $return['events'][1]['notified']);
    same('staff', $return['events'][1]['actor']);
    check($return['events'][1]['user_id'] !== null, 'quién lo hizo');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'return.approve'"));

    // Sin instrucciones propias salen las de serie; con «avisar» desmarcado no sale correo.
    $web->get('/admin/returns/' . $rb);
    $web->post('/admin/returns/' . $rb . '/approve', ['_token' => aifecToken($web), 'instructions' => '', 'notify' => '0']);
    $second = $app->service('returns')->find($rb);
    same('approved', $second['status']);
    contains('Embala los productos', $second['instructions']);
    contains('D-2026-000002', $second['instructions']);
    same(1, count(aifecOrderMails($app, 'return_approved')), 'sin correo');
    same(false, $second['events'][1]['notified']);

    // Una ya aceptada no se acepta otra vez: el aviso lo dice y no cambia nada.
    $web->get('/admin/returns/' . $ra);
    $again = $web->post('/admin/returns/' . $ra . '/approve', ['_token' => aifecToken($web), 'instructions' => 'Otra', 'notify' => '1']);
    same(302, $again->status);
    contains('La devolución no está en un estado que lo permita', $web->follow()->body);
    same('Envía a Calle Falsa 1 & ciudad', $app->service('returns')->find($ra)['instructions']);
    same(1, count(aifecOrderMails($app, 'return_approved')));
});

test('rechazar sin motivo da error y no cambia nada; con motivo cierra la devolución, se lo manda al cliente y libera el pedido', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $id = (int) returnsAsk($app, $order, [0 => 1])['id'];
    $web = returnsAdmin($app, $id);

    $web->post('/admin/returns/' . $id . '/reject', ['_token' => aifecToken($web), 'reason' => '   ', 'notify' => '1']);
    contains('Hace falta un motivo para rechazar una devolución', $web->follow()->body);
    same('requested', $app->service('returns')->find($id)['status']);
    same(0, count(aifecOrderMails($app, 'return_rejected')));

    $web->post('/admin/returns/' . $id . '/reject', ['_token' => aifecToken($web), 'reason' => 'El producto está usado & sin etiqueta', 'notify' => '1']);
    contains('La devolución está rechazada.', $web->follow()->body);
    $return = $app->service('returns')->find($id);
    same('rejected', $return['status']);
    same('El producto está usado & sin etiqueta', $return['reject_reason']);
    same(null, $return['open_key']);
    check($return['closed_at'] !== null, 'fecha de cierre');
    $mail = aifecOrderMails($app, 'return_rejected')[0];
    contains('El producto está usado &amp; sin etiqueta', (string) $mail['html']);
    contains('no aceptada', (string) $mail['subject']);

    // Una rechazada ya no se acepta, y el pedido admite una devolución nueva.
    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/approve', ['_token' => aifecToken($web)]);
    contains('no está en un estado que lo permita', $web->follow()->body);
    same(true, returnsAsk($app, $order, [0 => 1])['ok']);

    // Sin «avisar» el rechazo no manda correo.
    $other = returnsOrder($app, [[$p, 0, 1]]);
    $quiet = (int) returnsAsk($app, $other, [0 => 1])['id'];
    $web->get('/admin/returns/' . $quiet);
    $web->post('/admin/returns/' . $quiet . '/reject', ['_token' => aifecToken($web), 'reason' => 'Fuera de plazo', 'notify' => '0']);
    same('rejected', $app->service('returns')->find($quiet)['status']);
    same(1, count(aifecOrderMails($app, 'return_rejected')));
});

test('marcar como recibida: solo desde «aceptada», con la reposición decidida línea a línea y su correo (o sin él si se desmarca)', function (): void {
    $app = returnsApp();
    list($order, $id) = returnsPanelReceived($app, false);
    $web = returnsAdmin($app, $id);
    $lines = returnsPanelLines($app, $id);
    $page = $web->get('/admin/returns/' . $id)->body;
    contains('name="restock[' . $lines[0] . ']"', $page);
    contains('name="restock[' . $lines[1] . ']"', $page);

    $web->post('/admin/returns/' . $id . '/receive', ['_token' => aifecToken($web), 'restock' => [$lines[0] => '1'], 'notify' => '1']);
    contains('La devolución está marcada como recibida.', $web->follow()->body);
    $return = $app->service('returns')->find($id);
    same('received', $return['status']);
    same([true, false], [$return['items'][0]['restock'], $return['items'][1]['restock']]);
    same(1, count(aifecOrderMails($app, 'return_received')));

    // Desde «recibida» no se vuelve a recibir; desde «pedida» tampoco se salta la aceptación.
    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/receive', ['_token' => aifecToken($web), 'notify' => '1']);
    contains('no está en un estado que lo permita', $web->follow()->body);
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $o2 = returnsOrder($app, [[$p, 0, 1]]);
    $r2 = (int) returnsAsk($app, $o2, [0 => 1])['id'];
    $web->get('/admin/returns/' . $r2);
    $web->post('/admin/returns/' . $r2 . '/receive', ['_token' => aifecToken($web)]);
    same('requested', $app->service('returns')->find($r2)['status']);

    // Sin «avisar», sin correo.
    $app->service('returns')->approve($r2, '', false);
    $web->get('/admin/returns/' . $r2);
    $web->post('/admin/returns/' . $r2 . '/receive', ['_token' => aifecToken($web), 'notify' => '0']);
    same('received', $app->service('returns')->find($r2)['status']);
    same(1, count(aifecOrderMails($app, 'return_received')));
});

test('devolver el dinero llama a Orders::refund con las líneas: repone las existencias solo de las marcadas, avisa y la factura rectificativa sale sola', function (): void {
    $app = returnsApp();
    list($order, $id, $a, $b) = returnsPanelReceived($app, false);
    $lines = returnsPanelLines($app, $id);
    $app->service('returns')->receive($id, [$lines[0] => true], false);
    same(48, aifecOrderStock($app, $a));
    same(49, aifecOrderStock($app, $b));
    $web = returnsAdmin($app, $id);
    $page = $web->get('/admin/returns/' . $id)->body;
    contains('value="50.00"', $page, 'el importe propuesto: 40,00 + 10,00');
    contains('Devolver el dinero', $page);

    $r = $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '50,00', 'gateway' => '1', 'notify' => '1']);
    same(302, $r->status);
    contains('Reembolso de', $web->follow()->body);

    $return = $app->service('returns')->find($id);
    same('refunded', $return['status']);
    same(5000, $return['refunded_amount']);
    same(null, $return['open_key']);
    check($return['refunded_at'] !== null, 'fecha del reembolso');
    same([true, true], [$return['items'][0]['refunded'], $return['items'][1]['refunded']]);

    // Dos devoluciones del pedido (lo que no se repone primero): cada una con sus líneas y su reposición.
    $refunds = $app->db()->select('SELECT amount, items, restock FROM {order_refunds} WHERE order_id = ? ORDER BY id', [$order]);
    same(2, count($refunds));
    same(1000, (int) $refunds[0]['amount']);
    same(0, (int) $refunds[0]['restock']);
    same([returnsItemId($app, $order, 1) => 1], array_map('intval', json_decode((string) $refunds[0]['items'], true)));
    same(4000, (int) $refunds[1]['amount']);
    same(1, (int) $refunds[1]['restock']);
    same([returnsItemId($app, $order, 0) => 2], array_map('intval', json_decode((string) $refunds[1]['items'], true)));

    same(50, aifecOrderStock($app, $a), 'la marcada vuelve al almacén');
    same(49, aifecOrderStock($app, $b), 'la no marcada no');
    same(5000, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]));
    $items = $app->db()->select("SELECT refunded_qty FROM {order_items} WHERE order_id = ? AND kind = 'product' ORDER BY position, id", [$order]);
    same([2, 1], [(int) $items[0]['refunded_qty'], (int) $items[1]['refunded_qty']]);
    same(2, count(aifecOrderPayment($app)->refunds), 'la pasarela devolvió las dos veces');
    same(1, count(aifecOrderMails($app, 'return_refunded')));
    same(2, count(aifecOrderMails($app, 'order_refunded')), 'el pedido avisa de cada devolución de dinero');
    contains('hemos reembolsado', mb_strtolower((string) aifecOrderMails($app, 'return_refunded')[0]['html']));
    $credits = array_filter($app->service('invoices')->forOrder($order), static function (array $invoice): bool {
        return (string) $invoice['type'] === 'credit';
    });
    same(2, count($credits), 'una rectificativa por cada devolución de dinero');
    same(2, count(array_filter($return['events'], static function (array $e): bool {
        return (string) $e['kind'] === 'refund';
    })));
});

test('si todas las líneas se reponen (o ninguna) es una sola devolución de dinero, con o sin reposición', function (): void {
    $app = returnsApp();
    list($order, $id, $a, $b) = returnsPanelReceived($app, false);
    $lines = returnsPanelLines($app, $id);
    $app->service('returns')->receive($id, [$lines[0] => true, $lines[1] => true], false);
    $app->service('returns')->refund($id, 5000, false, false);
    $refunds = $app->db()->select('SELECT amount, restock FROM {order_refunds} WHERE order_id = ?', [$order]);
    same(1, count($refunds));
    same([5000, 1], [(int) $refunds[0]['amount'], (int) $refunds[0]['restock']]);
    same([50, 50], [aifecOrderStock($app, $a), aifecOrderStock($app, $b)]);

    $app2 = returnsApp();
    list($order2, $id2, $a2, $b2) = returnsPanelReceived($app2, true);
    $app2->service('returns')->refund($id2, 5000, false, false);
    $one = $app2->db()->select('SELECT amount, restock FROM {order_refunds} WHERE order_id = ?', [$order2]);
    same(1, count($one));
    same([5000, 0], [(int) $one[0]['amount'], (int) $one[0]['restock']]);
    same([48, 49], [aifecOrderStock($app2, $a2), aifecOrderStock($app2, $b2)], 'sin marcar, ninguna vuelve al almacén');
    same(0, count(aifecOrderPayment($app2)->refunds), 'sin pasarela solo se apunta');
});

test('el importe se puede bajar pero no subir: uno menor se reparte en proporción, uno mayor que lo propuesto o que lo que queda da error', function (): void {
    $app = returnsApp();
    list($order, $id, $a, $b) = returnsPanelReceived($app, false);
    $lines = returnsPanelLines($app, $id);
    $app->service('returns')->receive($id, [$lines[0] => true], false);
    $web = returnsAdmin($app, $id);

    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '50,01', 'notify' => '0']);
    contains('supera el importe propuesto', $web->follow()->body);
    same('received', $app->service('returns')->find($id)['status']);
    same(0, aifecOrderCount($app, 'order_refunds'));
    same(0, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]));

    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => 'abc', 'notify' => '0']);
    contains('Escribe un importe válido', $web->follow()->body);
    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '0', 'notify' => '0']);
    contains('Escribe un importe válido', $web->follow()->body);
    same('received', $app->service('returns')->find($id)['status']);

    // Bajarlo sí: 30,00 en vez de 50,00 se reparte 60/40 entre lo que no se repone (10,00) y lo que sí (40,00).
    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '30', 'notify' => '0']);
    $return = $app->service('returns')->find($id);
    same('refunded', $return['status']);
    same(3000, $return['refunded_amount']);
    $refunds = $app->db()->select('SELECT amount, restock FROM {order_refunds} WHERE order_id = ? ORDER BY id', [$order]);
    same([600, 0], [(int) $refunds[0]['amount'], (int) $refunds[0]['restock']]);
    same([2400, 1], [(int) $refunds[1]['amount'], (int) $refunds[1]['restock']]);
    same(3000, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]));
});

test('un importe mayor que lo que queda por devolver del pedido da error y no toca nada', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 1]]);
    $id = (int) returnsAsk($app, $order, [0 => 1])['id'];
    $app->service('returns')->approve($id, '', false);
    $app->service('returns')->receive($id, [], false);
    $paid = (int) $app->service('orders')->find($order)['paid_total'];
    $app->service('orders')->refund($order, $paid - 1000, [], 'Parte ya devuelta', false, false);
    $left = $paid - ($paid - 1000);

    $returns = $app->service('returns');
    $proposal = $returns->proposal($returns->find($id));
    same($left, $proposal['remaining']);
    same($left, $proposal['total'], 'lo propuesto nunca pasa de lo que queda');
    throws(function () use ($returns, $id, $left): void {
        $returns->refund($id, $left + 1, false, false);
    }, \InvalidArgumentException::class);
    same('received', $returns->find($id)['status']);
    same(1, aifecOrderCount($app, 'order_refunds'), 'solo la de antes');

    $web = returnsAdmin($app, $id);
    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '999', 'notify' => '0']);
    contains('supera lo que queda por reembolsar del pedido', $web->follow()->body);
    same('received', $returns->find($id)['status']);

    // Lo que queda sí se puede devolver.
    $done = $returns->refund($id, $left, false, false);
    same('refunded', $done['status']);
    same($paid, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]));
});

test('el importe propuesto reparte cada línea con sus impuestos y su descuento por unidades, sin perder ni sobrar un céntimo', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Taza', 'price' => 1000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 3]]);
    $item = returnsItemId($app, $order);
    // Una línea de 3 unidades que ya lleva su descuento repartido: 10,00 € con impuestos en total (3 unidades a 3,33 €).
    $app->db()->update('order_items', ['line_net' => 826, 'line_tax' => 174, 'line_total' => 1000], ['id' => $item]);
    $returns = $app->service('returns');
    $sums = [];
    foreach ([0, 1, 2] as $before) {
        $app->db()->update('order_items', ['refunded_qty' => $before], ['id' => $item]);
        $ask = returnsAsk($app, $order, [0 => 1]);
        $sums[] = $returns->proposal($returns->find((int) $ask['id']))['total'];
        $returns->cancel((int) $ask['id']);
    }
    same([333, 334, 333], $sums, 'una a una: 3,33 + 3,34 + 3,33');
    same(1000, array_sum($sums));

    // Dos unidades de tres de una línea con un 10 % ya descontado (30,00 € → 27,00 €): 18,00 €.
    $app->db()->update('order_items', ['line_net' => 2231, 'line_tax' => 469, 'line_total' => 2700, 'refunded_qty' => 0], ['id' => $item]);
    $two = returnsAsk($app, $order, [0 => 2]);
    same(1800, $returns->proposal($returns->find((int) $two['id']))['total']);
});

test('si la pasarela rechaza el reembolso no cambia nada y se puede repetir; si se corta a medias, al repetir solo se devuelve lo que falta', function (): void {
    $app = returnsApp();
    list($order, $id, $a, $b) = returnsPanelReceived($app, false);
    $lines = returnsPanelLines($app, $id);
    $app->service('returns')->receive($id, [$lines[0] => true], false);
    $gateway = aifecOrderPayment($app);
    $gateway->refundThrows = true;
    $web = returnsAdmin($app, $id);

    $web->post('/admin/returns/' . $id . '/refund', ['_token' => aifecToken($web), 'amount' => '50,00', 'gateway' => '1', 'notify' => '1']);
    contains('El reembolso ha fallado', $web->follow()->body);
    $return = $app->service('returns')->find($id);
    same('received', $return['status']);
    same(0, $return['refunded_amount']);
    same([false, false], [$return['items'][0]['refunded'], $return['items'][1]['refunded']]);
    same(0, aifecOrderCount($app, 'order_refunds'));
    same(0, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]), 'la reserva del importe se deshizo');
    same(0, count(aifecOrderMails($app, 'return_refunded')));

    // Corte a medias: la primera llamada a la pasarela sale bien y la segunda no.
    $flaky = new ReturnsFlakyPayment();
    $flaky->failOn = 2;
    $app->service('payments')->register($flaky);
    throws(function () use ($app, $id): void {
        $app->service('returns')->refund($id, 5000, true, false);
    }, \RuntimeException::class);
    $half = $app->service('returns')->find($id);
    same('received', $half['status']);
    same(1000, $half['refunded_amount'], 'lo que ya salió queda apuntado');
    same([false, true], [$half['items'][0]['refunded'], $half['items'][1]['refunded']]);
    same(1, aifecOrderCount($app, 'order_refunds'));
    $left = $app->service('returns')->proposal($half);
    same(4000, $left['total'], 'al repetir solo queda la línea que no salió');

    // Repetirlo ya con la pasarela buena: se devuelve lo que faltaba y nada de lo anterior se repite.
    $flaky->failOn = 0;
    $done = $app->service('returns')->refund($id, 4000, true, false);
    same('refunded', $done['status']);
    same(5000, $done['refunded_amount']);
    same(2, aifecOrderCount($app, 'order_refunds'));
    same(5000, (int) $app->db()->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$order]));
});

test('la ficha del pedido enseña la tarjeta «Devoluciones» con enlace a cada una; quien no puede ver devoluciones no la ve', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $with = returnsOrder($app, [[$p, 0, 2]]);
    $without = returnsOrder($app, [[$p, 0, 1]]);
    $id = (int) returnsAsk($app, $with, [0 => 1])['id'];
    $web = adminClient($app);

    $card = $web->get('/admin/orders/' . $with)->body;
    contains('data-af-returns', $card);
    contains('D-2026-000001', $card);
    contains('/admin/returns/' . $id, $card);
    contains('Solicitada', $card);
    $empty = $web->get('/admin/orders/' . $without)->body;
    contains('Este pedido no tiene devoluciones.', $empty);

    $warehouse = returnsPanelRole($app, 'almacen', ['dashboard.view', 'orders.view']);
    $hidden = $warehouse->get('/admin/orders/' . $with);
    same(200, $hidden->status);
    notContains('data-af-returns', $hidden->body);
    $reader = returnsPanelRole($app, 'lectura', ['dashboard.view', 'orders.view', 'returns.view']);
    contains('data-af-returns', $reader->get('/admin/orders/' . $with)->body);
});

test('una nota interna queda en el historial con quién la escribió; vacía da error y quien no tramita no puede', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $id = (int) returnsAsk($app, $order, [0 => 1])['id'];
    $web = returnsAdmin($app, $id);
    $web->post('/admin/returns/' . $id . '/note', ['_token' => aifecToken($web), 'note' => 'Llamar al cliente & confirmar']);
    contains('La nota está guardada.', $web->follow()->body);
    $events = $app->service('returns')->find($id)['events'];
    same('note', $events[1]['kind']);
    same('Llamar al cliente & confirmar', $events[1]['note']);
    same('staff', $events[1]['actor']);
    check($events[1]['user_id'] !== null, 'quién la escribió');
    contains('Llamar al cliente &amp; confirmar', $web->get('/admin/returns/' . $id)->body);

    $web->post('/admin/returns/' . $id . '/note', ['_token' => aifecToken($web), 'note' => '  ']);
    contains('La nota no puede estar vacía', $web->follow()->body);
    same(2, count($app->service('returns')->find($id)['events']));

    $view = returnsPanelRole($app, 'lectura', ['dashboard.view', 'returns.view']);
    $view->get('/admin');
    same(403, $view->post('/admin/returns/' . $id . '/note', ['_token' => aifecToken($view), 'note' => 'x'])->status);
});

test('cancelar desde el panel: solo mientras está pedida o aceptada, con correo solo si se marca, y una reembolsada no se cancela', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $ra = (int) returnsAsk($app, $a, [0 => 1])['id'];
    $rb = (int) returnsAsk($app, $b, [0 => 1])['id'];
    $web = returnsAdmin($app, $ra);
    $web->post('/admin/returns/' . $ra . '/cancel', ['_token' => aifecToken($web), 'notify' => '0']);
    contains('La devolución está cancelada.', $web->follow()->body);
    same('cancelled', $app->service('returns')->find($ra)['status']);
    same(0, count(aifecOrderMails($app, 'return_cancelled')));

    $web->get('/admin/returns/' . $rb);
    $web->post('/admin/returns/' . $rb . '/cancel', ['_token' => aifecToken($web), 'notify' => '1', 'reason' => 'Lo pidió por teléfono']);
    same('cancelled', $app->service('returns')->find($rb)['status']);
    same(1, count(aifecOrderMails($app, 'return_cancelled')));

    list($order, $id) = returnsPanelReceived($app, true);
    $app->service('returns')->refund($id, 5000, false, false);
    $web->get('/admin/returns/' . $id);
    $web->post('/admin/returns/' . $id . '/cancel', ['_token' => aifecToken($web), 'notify' => '0']);
    contains('no está en un estado que lo permita', $web->follow()->body);
    same('refunded', $app->service('returns')->find($id)['status']);
});
