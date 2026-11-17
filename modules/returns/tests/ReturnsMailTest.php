<?php
/**
 * AIFEC — pruebas de los correos (idioma del pedido, plantillas, sin «mod=», todo traducido), de lo que el módulo pone en las páginas
 * de otros (página del pedido, zona de cliente, asistente), de los datos personales y de que activarlo no manda nada por pedidos
 * antiguos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\I18nCheck;
use Aifec\Modules\Returns\ReturnsPrivacy;
use Aifec\Modules\Returns\ReturnTools;

require_once __DIR__ . '/helpers.php';

test('los correos salen con el asunto y el cuerpo en el idioma del pedido; el de la tienda, en el principal', function (): void {
    $app = returnsApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $english = returnsOrder($app, [[$p, 0, 1]]);
    $spanish = returnsOrder($app, [[$p, 0, 1]]);
    $app->db()->update('orders', ['lang' => 'en'], ['id' => $english]);
    $app->db()->update('orders', ['lang' => 'es'], ['id' => $spanish]);
    returnsAsk($app, $english, [0 => 1]);
    returnsAsk($app, $spanish, [0 => 1]);

    $mails = aifecOrderMails($app, 'return_requested');
    same(2, count($mails));
    same('en', $mails[0]['locale']);
    same('es', $mails[1]['locale']);
    contains('We have received your return request D-2026-000001', (string) $mails[0]['subject']);
    contains('Hello', (string) $mails[0]['html']);
    contains('How to send the products back', (string) $mails[0]['html']);
    contains('Pack the products in their original packaging', (string) $mails[0]['html']);
    contains('Hemos recibido tu solicitud de devolución D-2026-000002', (string) $mails[1]['subject']);
    contains('Hola', (string) $mails[1]['html']);
    contains('Cómo enviar los productos de vuelta', (string) $mails[1]['html']);
    notContains('Hello', (string) $mails[1]['html']);
    notContains('Hola', (string) $mails[0]['html']);

    foreach (aifecOrderMails($app, 'return_requested_shop') as $shop) {
        same('es', $shop['locale'], 'el aviso a la tienda va en el idioma principal');
        contains('Nueva solicitud de devolución', (string) $shop['subject']);
    }
    // El cambio de estado también sale en el idioma del pedido.
    $app->service('returns')->approve(1, 'Send it to Calle Falsa 1', true);
    $approved = aifecOrderMails($app, 'return_approved')[0];
    same('en', $approved['locale']);
    contains('accepted: how to send it back', (string) $approved['subject']);
});

test('las siete plantillas de correo se componen en castellano y en inglés sin dejar marcas sin sustituir ni textos sin traducir', function (): void {
    $app = returnsApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $mailer = $app->service('mailer');
    $templates = $mailer->templates();
    $keys = ['return_requested', 'return_requested_shop', 'return_approved', 'return_rejected', 'return_received', 'return_refunded', 'return_cancelled'];
    foreach ($keys as $key) {
        check(isset($templates[$key]), 'la plantilla ' . $key . ' está declarada');
        $es = $mailer->render($key, $templates[$key]['sample'], 'es');
        $en = $mailer->render($key, $templates[$key]['sample'], 'en');
        check($es['subject'] !== '' && strpos($es['subject'], '{') === false, 'asunto es de ' . $key . ': ' . $es['subject']);
        check($en['subject'] !== '' && strpos($en['subject'], '{') === false, 'asunto en de ' . $key . ': ' . $en['subject']);
        check($es['subject'] !== $en['subject'], 'el asunto cambia con el idioma: ' . $key);
        notContains('{', strip_tags($es['html']), 'sin marcas sin sustituir (es) en ' . $key);
        notContains('{', strip_tags($en['html']), 'sin marcas sin sustituir (en) en ' . $key);
        notContains('Hello', $es['html'], 'texto sin traducir en ' . $key);
        notContains('Hola', $en['html'], 'texto de otro idioma en ' . $key);
        contains('D-2026-000001', $es['html']);
        $reason = $templates[$key]['description'];
        check($reason !== '' && $app->i18n()->t($reason, [], 'es') !== $reason, 'la descripción de ' . $key . ' está traducida');
        check($app->i18n()->t($templates[$key]['subject'], [], 'es') !== $templates[$key]['subject'], 'el asunto de ' . $key . ' está traducido');
    }
});

test('el módulo está completo en castellano: ninguna cadena sin traducir, ningún «mod=» y las excepciones y los motivos de serie también traducidos', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 3));
    $result = I18nCheck::run($root, 'modules/returns', 'es');
    same([], array_keys($result['missing']), 'cadenas sin traducir');
    $dir = $root . '/modules/returns';
    $catalog = require $dir . '/lang/es.php';
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    $files = 0;
    foreach ($it as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false) {
            continue;
        }
        ++$files;
        $source = (string) file_get_contents($path);
        notContains('mod=', $source, 'sistema de traducción antiguo en ' . basename($path));
        check(strpos($source, "<?php\n/**\n * AIFEC") === 0, 'la cabecera exacta en ' . basename($path));
        contains('declare(strict_types=1);', $source, basename($path));
        notContains("\r", $source, 'saltos de línea LF en ' . basename($path));
        if (preg_match_all("/throw new \\\\(?:InvalidArgumentException|RuntimeException)\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $found) > 0) {
            foreach ($found[1] as $message) {
                $message = str_replace("\\'", "'", $message);
                check(isset($catalog[$message]) && $catalog[$message] !== '', 'la excepción «' . $message . '» (' . basename($path) . ') tiene traducción');
            }
        }
    }
    check($files >= 20, 'se han mirado los ficheros del módulo: ' . $files);
    $app = returnsApp();
    foreach ($app->service('returns')->policy()->reasons() as $code => $label) {
        check($app->i18n()->t($label, [], 'es') !== $label, 'el motivo de serie «' . $code . '» está traducido');
    }
    check($app->i18n()->t('Withdrawal', [], 'es') === 'Desistimiento', 'el desistimiento');
});

test('activar el módulo con 50 pedidos antiguos no encola ningún correo ni crea ninguna devolución: no hay barridos ni tareas', function (): void {
    $app = returnsApp();
    $now = $app->clock()->now();
    for ($i = 1; $i <= 50; ++$i) {
        $when = $app->clock()->sql($now - (100 + $i) * 86400);
        $app->db()->insert('orders', [
            'number' => 'VIEJO-' . $i, 'email' => 'viejo' . $i . '@cliente.test', 'status' => 'completed', 'payment_status' => 'paid', 'fulfillment_status' => 'fulfilled',
            'total' => 1000, 'paid_total' => 1000, 'placed_at' => $when, 'paid_at' => $when, 'shipped_at' => $when, 'completed_at' => $when, 'created_at' => $when, 'updated_at' => $when,
        ]);
    }
    same(50, aifecOrderCount($app, 'orders'));
    $before = aifecOrderCount($app, 'mail_messages');
    // «Activarlo»: migrar y arrancar de nuevo, y que pase lo que pasaría al entrar cualquiera a la tienda o al panel.
    $app->migrate(true);
    $app->boot();
    $web = new TestClient($app);
    $web->get('/');
    $admin = adminClient($app);
    $admin->get('/admin/returns');
    $admin->get('/admin');
    same($before, aifecOrderCount($app, 'mail_messages'), 'ni un correo');
    same(0, aifecOrderCount($app, 'returns'));
    same(0, aifecOrderCount($app, 'return_events'));
    $dir = dirname(__DIR__);
    check(!is_file($dir . '/jobs.php') && !is_file($dir . '/cli.php'), 'el módulo no declara tareas periódicas ni órdenes de consola');
    same([], $app->modules()->definition('returns', 'jobs'));
    foreach (['order.paid', 'order.placed', 'order.status', 'order.refunded', 'order.cancelled'] as $hook) {
        check(!in_array('returns', $app->hooks()->modulesOn($hook), true), 'el módulo no escucha «' . $hook . '»');
    }
});

test('la página del pedido enseña el botón de devolver mientras se puede y, después, el estado de cada devolución', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $unsent = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $web = new TestClient($app);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$order]);
    $secret = $app->service('orders')->viewTokenOf($number);

    $page = $web->get('/pedido/' . $number . '/' . $secret);
    same(200, $page->status);
    contains('data-af-returns-card', $page->body);
    contains('Devolver productos', $page->body);
    contains('/devolucion/' . $number . '/' . $secret, $page->body);

    $ask = returnsAsk($app, $order, [0 => 1], 'not_fit');
    $after = $web->get('/pedido/' . $number . '/' . $secret)->body;
    contains($ask['number'], $after);
    contains('Solicitada', $after);
    contains('Tenemos tu solicitud y la estamos revisando.', $after);
    contains('Ver mis devoluciones', $after);
    notContains('>Devolver productos<', $after, 'con una abierta no se ofrece otra');

    $app->service('returns')->approve((int) $ask['id'], 'Envíalo a la tienda & guarda el justificante', false);
    $approved = $web->get('/pedido/' . $number . '/' . $secret)->body;
    contains('Aceptada', $approved);
    contains('/devolucion/' . $number . '/' . $secret, $approved);
    $returnPage = $web->get('/devolucion/' . $number . '/' . $secret)->body;
    contains('Envíalo a la tienda &amp; guarda el justificante', $returnPage);
    contains('Cancelar esta devolución', $returnPage);

    // Un pedido pagado y sin enviar no se devuelve todavía, pero sí se puede desistir de él: la tarjeta lo ofrece como tal.
    $numberUnsent = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$unsent]);
    $unsentPage = $web->get('/pedido/' . $numberUnsent . '/' . $app->service('orders')->viewTokenOf($numberUnsent));
    same(200, $unsentPage->status);
    contains('data-af-returns-card', $unsentPage->body);
    contains('Desistir de la compra', $unsentPage->body);
    notContains('>Devolver productos<', $unsentPage->body);
});

test('la zona de cliente enseña sus devoluciones y los pedidos de los que puede pedir una; sin nada que enseñar no pinta la tarjeta', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    aifecCartCustomer($app, 'ana@cliente.test');
    aifecCartCustomer($app, 'luis@cliente.test', ['first_name' => 'Luis']);
    $mine = returnsOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$mine]);
    $web = new TestClient($app);
    check(frontLogin($web) !== null, 'ana entra');
    $dashboard = $web->get('/mi-cuenta');
    same(200, $dashboard->status);
    contains('data-af-returns-card', $dashboard->body);
    contains('Devolver productos del pedido ' . $number, $dashboard->body);
    contains('/mi-cuenta/devoluciones/' . $number, $dashboard->body);

    $ask = returnsAsk($app, $mine, [0 => 1]);
    $after = $web->get('/mi-cuenta')->body;
    contains($ask['number'], $after);
    contains('Solicitada', $after);
    notContains('Devolver productos del pedido ' . $number, $after, 'con una abierta no se ofrece otra');

    $other = new TestClient($app);
    check(frontLogin($other, 'luis@cliente.test') !== null, 'luis entra');
    notContains('data-af-returns-card', $other->get('/mi-cuenta')->body);
});

test('el asistente: la herramienta «return_options» dice qué se puede devolver y el enlace al formulario, y no confirma qué pedidos existen', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]], ['days' => 3]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$order]);
    $secret = $app->service('orders')->viewTokenOf($number);

    $tools = $app->hooks()->apply('assistant.tools', ['otra' => ['description' => 'x']], $app);
    check(isset($tools['return_options']) && isset($tools['otra']), 'la herramienta se añade sin quitar las demás');
    same(false, $tools['return_options']['writes']);
    check(is_callable($tools['return_options']['handler']), 'tiene función');
    same(['number', 'email'], $tools['return_options']['schema']['required']);

    $out = $tools['return_options']['handler'](['number' => $number, 'email' => 'ANA@cliente.test']);
    same(true, $out['can_return']);
    contains('/desistimiento?number=' . $number, $out['link']);
    notContains($secret, json_encode($out), 'la ficha secreta no sale nunca');
    same(substr((string) $app->service('returns')->eligibility($order)['return_until'], 0, 10), $out['return_until']);
    same(true, $out['withdrawal_open']);
    same('Camiseta', $out['lines'][0]['name']);
    same(2, $out['lines'][0]['available']);

    $wrong = ReturnTools::options($app, ['number' => $number, 'email' => 'otra@cliente.test']);
    $unknown = ReturnTools::options($app, ['number' => '2026-999999', 'email' => 'ana@cliente.test']);
    same($wrong, $unknown, 'el mismo aviso para un correo que no coincide y un pedido que no existe');
    same(['error'], array_keys($wrong));

    // Con una devolución abierta o fuera de plazo lo dice.
    $ask = returnsAsk($app, $order, [0 => 1]);
    $open = ReturnTools::options($app, ['number' => $number, 'email' => 'ana@cliente.test']);
    same(false, $open['can_return']);
    same($ask['number'], $open['open_return']);
    $old = returnsOrder($app, [[$p, 0, 1]], ['days' => 40]);
    $oldNumber = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$old]);
    $late = ReturnTools::options($app, ['number' => $oldNumber, 'email' => 'ana@cliente.test']);
    same(false, $late['can_return']);
    contains('plazo', $late['reason']);
});

test('los datos personales: al olvidar a un cliente se borran el comentario, las fotos y el correo de sus devoluciones, y salen en la copia de sus datos', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $ana = aifecCartCustomer($app, 'ana@cliente.test');
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $web = new TestClient($app);
    $r = returnsUpload($app, $web, returnsPath($app, $order), [
        'lines' => [returnsItemId($app, $order) => '1'], 'reason' => 'defective', 'comment' => 'Mi teléfono es 999345678',
    ], ['photo_1' => returnsFile(returnsImage('jpg'), 'rota.jpg')]);
    same(303, $r->status);
    $return = $app->service('returns')->find(1);
    same($ana, $return['customer_id']);
    $photo = $app->service('files')->path((string) $return['photos'][0]['path']);
    check($photo !== null && is_file($photo), 'la foto está en el disco');

    $export = $app->hooks()->apply('customer.export', ['customer' => ['id' => $ana]], $ana);
    same(1, count($export['returns']));
    same('D-2026-000001', $export['returns'][0]['number']);
    same('Mi teléfono es 999345678', $export['returns'][0]['comment']);
    same(1, $export['returns'][0]['photos']);

    $app->hooks()->fire('customer.anonymised', $ana, 'ana@cliente.test');
    $after = $app->service('returns')->find(1);
    same('', $after['comment']);
    same([], $after['photos']);
    same('deleted-1@anonymous.invalid', $after['email']);
    check(!is_file((string) $photo), 'la foto ya no está en el disco');
    same('D-2026-000001', $after['number'], 'la devolución, como asiento, se conserva');
    same(['returns' => 0, 'photos' => 0], ReturnsPrivacy::forget($app, 0, ''));
});

test('el servicio «returns» está publicado con la firma que pide el asistente y la API: eligibility(int) y request(...)', function (): void {
    $app = returnsApp();
    check($app->has('returns'), 'el servicio existe');
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $order = returnsOrder($app, [[$p, 0, 2]]);
    $service = $app->service('returns');
    $e = $service->eligibility($order);
    foreach (['ok', 'code', 'message', 'order_id', 'number', 'return_until', 'withdrawal_until', 'lines', 'open_return'] as $key) {
        check(array_key_exists($key, $e), 'eligibility() trae «' . $key . '»');
    }
    $r = $service->request($order, [returnsItemId($app, $order) => 2], 'not_fit', 'Desde la API', ['source' => 'api', 'actor' => 'system']);
    same(true, $r['ok'], (string) $r['error']);
    same(['ok', 'error', 'errors', 'id', 'number'], array_keys($r));
    $return = $service->find((int) $r['id']);
    same('api', $return['source']);
    same('system', $return['events'][0]['actor']);
    same($return['number'], $service->findByNumber($r['number'])['number']);
    same(1, count($service->forOrder($order)));
    same($return['id'], $service->openFor($order)['id']);
});
