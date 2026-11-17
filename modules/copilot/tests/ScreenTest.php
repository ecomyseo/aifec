<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La pantalla del copiloto: sin JavaScript (formularios que envían, 303 y vuelven a la propuesta), con JavaScript (JSON con la zona
 * nueva), la ficha de formulario, los textos escapados y traducidos, el menú, los ajustes y que lo que enseña la pantalla es lo que
 * hay en la base de datos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la pantalla se abre con el permiso: la caja de la orden, los ejemplos en castellano y un historial vacío', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $r = $web->get('/admin/copilot');
    same(200, $r->status);
    contains('name="text"', $r->body);
    contains('¿Qué quieres hacer?', $r->body);
    contains('data-cp-example="Marca el pedido 12 como enviado con el seguimiento 4455"', $r->body);
    contains('data-cp-example="Pon el precio de la silla roja a 59,90"', $r->body);
    contains('Todavía nada', $r->body);
    contains('data-af-ajax data-af-target="#cp-stage"', $r->body, 'los formularios piden JSON si hay JavaScript');
    contains('id="cp-stage"', $r->body);
    notContains('>Apply<', $r->body, 'los textos salen en castellano');
});

test('los ejemplos cambian con los módulos activos: sin pedidos ni catálogo solo queda el de los ajustes', function (): void {
    $app = testApp(['modules' => ['copilot']]);
    $web = adminClient($app);

    $page = $web->get('/admin/copilot')->body;
    contains('data-cp-example="Cambia el nombre de la tienda a Muebles Ejemplo"', $page);
    notContains('data-cp-example="Marca el pedido', $page);
    notContains('data-cp-example="Pon el precio', $page);
});

test('sin JavaScript: escribir la orden envía un formulario, contesta con un 303 a la propuesta y esa pantalla la enseña con «antes → después» y sus botones', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);

    $r = copilotPost($web, '/admin/copilot/ask', ['text' => 'Pon el precio de SILLA-R a 64,90']);
    same(303, $r->status);
    $page = $web->follow()->body;
    contains('Poner el precio de Silla roja en ' . money(6490), $page);
    contains('<th>Antes</th>', $page);
    contains('<th>Después</th>', $page);
    contains(money(5990), $page);
    contains(money(6490), $page);
    contains('Lista para aplicar', $page);
    contains('>Aplicar</button>', $page);
    contains('>Descartar</button>', $page);
    contains('name="_token"', $page, 'cada formulario lleva su ficha');
    contains('Escribiste: «Pon el precio de SILLA-R a 64,90»', $page);
    same(5990, copilotProduct($app, $ids['roja'], 'price'));

    $r = copilotPost($web, '/admin/copilot/' . (int) $app->db()->value('SELECT MAX(id) FROM {copilot_proposals}') . '/apply');
    same(303, $r->status, 'aplicar también contesta con 303');
    $page = $web->follow()->body;
    contains('Aplicada', $page);
    contains('>Deshacer</button>', $page);
    same(6490, copilotProduct($app, $ids['roja'], 'price'));
});

test('con JavaScript: los mismos formularios piden JSON y reciben la zona nueva ya pintada, sin recargar', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);

    $r = copilotPost($web, '/admin/copilot/ask', ['text' => 'Pon el precio de SILLA-R a 64,90'], true);
    same(200, $r->status);
    $json = json_decode($r->body, true);
    same(true, $json['ok']);
    same('proposed', $json['status']);
    check($json['id'] > 0, 'dice qué propuesta es');
    contains('id="cp-proposal"', $json['html']);
    contains(money(6490), $json['html']);
    contains('/admin/copilot/' . $json['id'] . '/apply', $json['html']);
    notContains('<html', $json['html'], 'solo la zona, no la página entera');

    $r = copilotPost($web, '/admin/copilot/' . $json['id'] . '/apply', [], true);
    $applied = json_decode($r->body, true);
    same([true, 'applied'], [$applied['ok'], $applied['status']]);
    contains('1 precio cambiado.', $applied['message']);
    contains('Aplicada', $applied['html']);
    contains('/undo', $applied['html']);
    same(6490, copilotProduct($app, $ids['roja'], 'price'));

    $r = copilotPost($web, '/admin/copilot/' . $json['id'] . '/undo', [], true);
    same(true, json_decode($r->body, true)['ok']);
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
});

test('una orden vacía: sin JavaScript vuelve a la pantalla con un aviso; con JavaScript, 422 con el mensaje', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $r = copilotPost($web, '/admin/copilot/ask', ['text' => '   ']);
    same(303, $r->status);
    contains('Escribe lo que quieres hacer', $web->follow()->body);
    $r = copilotPost($web, '/admin/copilot/ask', ['text' => ''], true);
    same(422, $r->status);
    contains('Escribe lo que quieres hacer', json_decode($r->body, true)['message']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {copilot_proposals}'), 'una orden vacía no deja ninguna fila');
});

test('lo que escribe la persona y lo que hay en la tienda sale escapado: nada de HTML ni de JavaScript en la pantalla', function (): void {
    $app = copilotApp();
    aifecProduct($app, ['name' => 'Taza <b>x</b> & "co"', 'sku' => 'TAZA-1', 'price' => 1000]);
    $web = copilotAdmin($app);

    $lost = copilotAsk($web, 'dime <script>alert(1)</script> & más');
    $page = $web->get('/admin/copilot/' . $lost['id'])->body;
    contains('Escribiste: «dime &lt;script&gt;alert(1)&lt;/script&gt; &amp; más»', $page);
    notContains('<script>alert(1)', $page);

    $row = copilotAsk($web, 'Pon el precio de TAZA-1 a 12');
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('Taza &lt;b&gt;x&lt;/b&gt; &amp; &quot;co&quot;', $page);
    notContains('<b>x</b>', $page);
    $json = json_decode(copilotPost($web, '/admin/copilot/ask', ['text' => 'Pon el precio de TAZA-1 a 13'], true)->body, true);
    notContains('<b>x</b>', $json['html'], 'tampoco en la zona que llega por JSON');
    contains('Taza &lt;b&gt;x&lt;/b&gt;', $json['html']);
});

test('todo lo que enseña la pantalla está traducido: etiquetas, datos y ejemplos de cada acción', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);
    $web->get('/admin/copilot');
    $missing = [];
    foreach (copilotService($app)->actions()->all() as $id => $def) {
        $strings = [$def['label']];
        foreach ($def['params'] as $param) {
            if ((string) $param['description'] !== '') {
                $strings[] = $param['description'];
            }
        }
        foreach ($def['examples'] as $example) {
            $strings[] = $example;
        }
        foreach ($strings as $string) {
            if (t($string) === $string) {
                $missing[] = $id . ' → ' . $string;
            }
        }
    }
    same([], $missing, 'cadenas sin traducir');
});

test('el menú tiene «Copiloto» arriba del todo, justo detrás del inicio y antes de las ventas', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $body = $web->get('/admin')->body;
    $copilot = strpos($body, '/admin/copilot"');
    check($copilot !== false, 'el menú lleva al copiloto');
    contains('Copiloto', $body);
    $dashboard = strpos($body, 'href="/admin"');
    $sales = strpos($body, '/admin/orders"');
    check($dashboard !== false && $sales !== false && $dashboard < $copilot && $copilot < $sales, 'va entre el inicio y los pedidos');
});

test('los ajustes del copiloto: se abren, tienen su valor de serie y se guardan en la base de datos', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $r = $web->get('/admin/settings/copilot');
    same(200, $r->status);
    contains('name="f[copilot.max_rows]"', $r->body);
    contains('name="f[copilot.history_days]"', $r->body);
    contains('name="f[copilot.ai]"', $r->body);
    same([true, 500, 90], [$app->settings()->bool('copilot.ai', true), $app->settings()->int('copilot.max_rows', 500), $app->settings()->int('copilot.history_days', 90)]);
    contains('data-af-active="cplgeneral"', $r->body, 'la pestaña lleva las iniciales del módulo');

    $r = copilotPost($web, '/admin/settings/copilot', ['f' => ['copilot.ai' => '0', 'copilot.max_rows' => '300', 'copilot.history_days' => '30'], '_tab' => 'cplgeneral']);
    same(302, $r->status);
    same(false, $app->settings()->bool('copilot.ai', true));
    same(300, $app->settings()->int('copilot.max_rows'));
    same(30, $app->settings()->int('copilot.history_days'));
});

test('el tope de la pantalla sale del ajuste: con 1 registro, un cambio de dos productos no se deja aplicar', function (): void {
    $app = copilotApp(['settings' => ['copilot.max_rows' => 1]]);
    $ids = copilotShop($app);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Sube un 5 % los precios de la categoría Sillas');
    contains('más de 1 registros', $row['preview']['errors'][0]);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    notContains('/apply', $page);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
});

test('corregir los datos de la propuesta desde la pantalla vuelve a calcular la vista previa; un dato en blanco lo quita', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('name="p[price]"', $page);
    contains('value="64,90"', $page);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['product' => 'SILLA-A', 'price' => '80']]);
    $row = copilotRow($app, (int) $row['id']);
    same(['product' => 'SILLA-A', 'price' => 8000], $row['args']);
    same(money(8000), $row['preview']['changes'][0]['to']);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['price' => '']]);
    $row = copilotRow($app, (int) $row['id']);
    same('incomplete', $row['status'], 'sin el dato vuelve a faltar');
    same(['product' => 'SILLA-A'], $row['args']);
});

test('el historial enseña las órdenes de esta persona con su estado y el botón de deshacer solo donde se puede', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $web = copilotAdmin($app);
    $done = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');
    copilotPost($web, '/admin/copilot/' . $done['id'] . '/apply');
    $pending = copilotAsk($web, 'Pon el precio de SILLA-A a 74,90');
    $lost = copilotAsk($web, 'cuéntame un chiste');

    $page = $web->get('/admin/copilot')->body;
    contains('Pon el precio de SILLA-R a 64,90', $page);
    contains('Pon el precio de SILLA-A a 74,90', $page);
    contains('cuéntame un chiste', $page);
    contains('Aplicada', $page);
    contains('Lista para aplicar', $page);
    contains('No hecha', $page);
    same(1, substr_count($page, '/' . $done['id'] . '/undo'), 'deshacer solo en la aplicada');
    same(0, substr_count($page, '/' . $pending['id'] . '/undo'));
    contains('No lo he entendido', $web->get('/admin/copilot/' . $lost['id'])->body);
});
