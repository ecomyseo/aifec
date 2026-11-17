<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El panel del asistente: conversaciones, ficha, preguntas sin respuesta, ajustes y la tarea que borra las antiguas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\AssistantTasks;
use Aifec\Modules\Assistant\Conversations;

require_once __DIR__ . '/helpers.php';

/**
 * Una conversación vieja ya hecha: su última actividad hace $days días.
 */
function assistantOldConversation(App $app, int $days, string $message = 'Hola'): int
{
    $conversations = new Conversations($app);
    $created = $conversations->create('es');
    $id = $created['id'];
    $conversations->addMessage($id, 'user', $message);
    $conversations->addMessage($id, 'assistant', 'Respuesta');
    $old = $app->clock()->sql($app->clock()->now() - $days * 86400);
    $app->db()->update('conversations', ['started_at' => $old, 'last_at' => $old], ['id' => $id]);

    return $id;
}

test('el panel declara sus permisos y su entrada en el grupo IA; la bandeja se abre con permiso y recibe un 403 sin él', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = adminClient($app);

    $home = $web->get('/admin');
    contains('/admin/assistant', $home->body, 'el menú lleva al listado');
    // Desde P102, las preguntas sin respuesta y los ajustes son pestañas de «Asistente»: se ven en la barra de su pantalla.
    $tabs = $web->get('/admin/assistant')->body;
    contains('role="tab" href="/admin/assistant/unanswered"', $tabs);
    contains('role="tab" href="/admin/settings/assistant"', $tabs);
    $caps = \Aifec\Admin\Admin::capabilities($app);
    check(isset($caps['assistant.view']) && isset($caps['assistant.edit']), 'los permisos están declarados');

    $list = $web->get('/admin/assistant');
    same(200, $list->status);
    contains('Conversaciones', $list->body);
    contains('Preguntas sin respuesta', $list->body);
    contains('Todavía no hay conversaciones', $list->body, 'vacío, lo dice');
    // Una sola barra de pestañas, la del panel: la pantalla llevaba además la suya y salían dos (visto en vivo el 13/11/2026).
    foreach (['/admin/assistant', '/admin/assistant/unanswered'] as $path) {
        same(1, preg_match_all('/class="af-tabs\b/', $web->get($path)->body), 'una sola barra de pestañas en ' . $path);
    }

    // Atención al cliente lee las conversaciones y las preguntas sin contestar, pero no cambia los ajustes; el editor no ve nada.
    $support = adminClient($app, 'support');
    same(200, $support->get('/admin/assistant/unanswered')->status, 'atención al cliente ve lo que no se supo contestar');
    same(403, $support->get('/admin/settings/assistant')->status, 'pero no los ajustes');
    $editor = adminClient($app, 'editor');
    foreach (['/admin/assistant', '/admin/assistant/unanswered', '/admin/assistant/1', '/admin/settings/assistant'] as $path) {
        same(403, $editor->get($path)->status, $path);
    }
    check((new TestClient($app))->get('/admin/assistant')->status !== 200, 'sin sesión no se ve');
});

test('el listado enseña fecha, mensajes, modo y hasta dónde llegó cada conversación, sin ningún dato personal', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $one = new TestClient($app);
    assistantSay($one, 'Hola, soy Ana Ruiz, mi correo es ana@cliente.test y mi teléfono 999345678');
    assistantSay($one, 'Busco una silla roja');
    $two = new TestClient($app);
    assistantSay($two, 'Busco una silla roja');
    assistantSay($two, 'Añade el primero');
    $three = new TestClient($app);
    assistantSay($three, 'Hola');
    aifecOrderPlace($app, $one, [[$ids['mesa'], 0, 1]]);
    $web = adminClient($app);

    $list = $web->get('/admin/assistant');

    same(200, $list->status);
    foreach (['Ana Ruiz', 'ana@cliente.test', '999345678', 'silla roja', 'Busco'] as $private) {
        notContains($private, $list->body, 'el listado no enseña «' . $private . '»');
    }
    foreach (assistantRows($app, 'conversations') as $conversation) {
        contains('/admin/assistant/' . $conversation['id'], $list->body);
    }
    contains('>Pedido<', $list->body, 'la primera acabó en un pedido');
    contains('>Carrito<', $list->body, 'la segunda llegó al carrito');
    contains('Solo charla', $list->body, 'la tercera no pasó de hablar');
    contains('Modo sencillo', $list->body);

    $orders = $web->get('/admin/assistant?f[outcome]=order')->body;
    contains('/admin/assistant/' . assistantRows($app, 'conversations')[0]['id'], $orders);
    notContains('/admin/assistant/' . assistantRows($app, 'conversations')[2]['id'], $orders);
    $cart = $web->get('/admin/assistant?f[outcome]=cart')->body;
    contains('/admin/assistant/' . assistantRows($app, 'conversations')[1]['id'], $cart);
    notContains('/admin/assistant/' . assistantRows($app, 'conversations')[0]['id'], $cart);
    $mode = $web->get('/admin/assistant?f[mode]=ai')->body;
    foreach (assistantRows($app, 'conversations') as $conversation) {
        notContains('/admin/assistant/' . $conversation['id'] . '"', $mode, 'ninguna fue con IA');
    }
});

test('la ficha de una conversación enseña los mensajes escapados, las herramientas usadas y el aviso de lo que no se contestó', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $client = new TestClient($app);
    assistantSay($client, '<script>alert(1)</script> & "comillas" <b>negrita</b>');
    assistantSay($client, 'Busco una silla roja');
    $id = (int) assistantRows($app, 'conversations')[0]['id'];
    $web = adminClient($app);

    $show = $web->get('/admin/assistant/' . $id);

    same(200, $show->status);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;comillas&quot; &lt;b&gt;negrita&lt;/b&gt;', $show->body, 'lo que escribió el cliente sale escapado');
    notContains('<script>alert(1)', $show->body);
    notContains('<b>negrita</b>', $show->body);
    contains('search_products', $show->body, 'la herramienta usada');
    check(preg_match('#<span class="af-small af-muted" data-assistant-query>«[^<]*silla[^<]*»</span>#u', $show->body) === 1, 'y con qué palabras buscó');
    contains('Sin respuesta', $show->body, 'la primera quedó sin respuesta');
    contains('Cliente', $show->body);
    contains('Asistente', $show->body);
    contains('Modo sencillo', $show->body);
    same(404, $web->get('/admin/assistant/99999')->status);
});

test('las preguntas sin respuesta salen agrupadas por lo que se preguntó, con su número, sin correos ni números largos y escapadas', function (): void {
    $app = assistantApp();
    assistantShop($app);
    foreach (range(1, 3) as $i) {
        $web = new TestClient($app);
        assistantSay($web, '¿Qué tiempo hará mañana?');
    }
    $web = new TestClient($app);
    assistantSay($web, '¿Qué opináis de <i>mi</i> tele? ana@cliente.test 999345678');
    assistantSay($web, 'Busco xyzzy plugh');
    assistantSay($web, 'Hola');
    $admin = adminClient($app);

    $page = $admin->get('/admin/assistant/unanswered');

    same(200, $page->status);
    check(preg_match('#<td>¿qué tiempo hará mañana\?</td>\s*<td class="af-num">3</td>#', $page->body) === 1, 'tres veces la misma pregunta, una sola fila');
    contains('busco xyzzy plugh', $page->body, 'una búsqueda sin resultados también cuenta: es contenido que falta');
    contains('&lt;i&gt;mi&lt;/i&gt; tele', $page->body, 'escapada');
    notContains('<i>mi</i>', $page->body);
    notContains('ana@cliente.test', $page->body);
    notContains('999345678', $page->body);
    notContains('<td>hola</td>', $page->body, 'lo que sí se contestó no sale');
    $linked = 0;
    foreach (assistantRows($app, 'conversations') as $conversation) {
        if ((int) $conversation['unanswered'] > 0 && strpos($page->body, '/admin/assistant/' . $conversation['id'] . '"') !== false) {
            ++$linked;
        }
    }
    check($linked >= 2, 'cada fila lleva a una conversación (' . $linked . ' enlaces)');
    // El número de preguntas sin respuesta se avisa en el listado de conversaciones, con su enlace (la barra de pestañas es la del panel).
    $list = $admin->get('/admin/assistant')->body;
    check(preg_match('#<p data-assistant-pending><a href="/admin/assistant/unanswered">Preguntas sin respuesta</a>.*?>5<#s', $list) === 1, 'el listado avisa de las cinco preguntas sin respuesta');
    notContains('data-assistant-pending', $page->body, 'en su propia pantalla no hace falta el aviso');

    $quiet = assistantApp();
    $empty = adminClient($quiet)->get('/admin/assistant/unanswered');
    contains('Nada sin contestar', $empty->body);
});

test('la pantalla de ajustes tiene sus pestañas y guarda lo que se cambia', function (): void {
    $app = assistantApp();
    $web = adminClient($app);

    $settings = $web->get('/admin/settings/assistant');
    same(200, $settings->status);
    contains('asgeneral', $settings->body);
    contains('aslimits', $settings->body);
    contains('Enseñar el asistente en la tienda', $settings->body);

    $web->post('/admin/settings/assistant', ['_token' => aifecToken($web), 'f' => [
        'assistant.enabled' => '0', 'assistant.tone' => 'professional', 'assistant.greeting' => 'Envío en 24 h & más', 'assistant.hours' => 'L-V 9-18',
        'assistant.returns_text' => '30 días', 'assistant.max_messages' => '12', 'assistant.retention_days' => '7',
    ]]);
    $app->settings()->reload();
    same(false, $app->settings()->bool('assistant.enabled', true));
    same('professional', $app->settings()->str('assistant.tone'));
    same('Envío en 24 h & más', $app->settings()->str('assistant.greeting'), 'sin &amp; en la base de datos');
    same(12, $app->settings()->int('assistant.max_messages'));
    same(7, $app->settings()->int('assistant.retention_days'));
    same(404, (new TestClient($app))->postJson('/asistente/mensaje', ['message' => 'Hola'])->status, 'apagado desde el panel, la ruta deja de contestar');
});

test('la tarea assistant.purge borra las conversaciones que pasan de los días de los ajustes, con sus mensajes, y deja las recientes', function (): void {
    $app = assistantApp();
    $old = assistantOldConversation($app, 31, 'Hace un mes');
    $mid = assistantOldConversation($app, 10, 'Hace diez días');
    $new = assistantOldConversation($app, 1, 'Ayer');
    $tasks = array_column($app->service('jobs')->tasks(), 'task');
    check(in_array('assistant.purge', $tasks, true), 'la tarea está declarada en el módulo de tareas');

    same('1 conversations, 2 messages', $app->service('jobs')->runTask('assistant.purge'));

    same([$mid, $new], array_map('intval', array_column(assistantRows($app, 'conversations'), 'id')));
    same(0, count(assistantRows($app, 'conversation_messages', 'conversation_id = ?', [$old])), 'sus mensajes también');
    same(4, count(assistantRows($app, 'conversation_messages')));
    same(null, AssistantTasks::purge($app), 'la segunda pasada no encuentra nada');

    $app->settings()->set('assistant.retention_days', 5);
    same('1 conversations, 2 messages', AssistantTasks::purge($app), 'con 5 días se va también la de hace diez');
    same([$new], array_map('intval', array_column(assistantRows($app, 'conversations'), 'id')));
});

test('con 0 días no se borra nada y una conversación con actividad reciente no se borra aunque empezara hace mucho', function (): void {
    $app = assistantApp();
    $id = assistantOldConversation($app, 90);
    $app->settings()->set('assistant.retention_days', 0);
    same(null, AssistantTasks::purge($app));
    same(1, count(assistantRows($app, 'conversations')), '0 días es no borrar');
    same(['conversations' => 0, 'messages' => 0], (new Conversations($app))->purge(-3));

    $app->settings()->set('assistant.retention_days', 30);
    $conversations = new Conversations($app);
    $conversations->addMessage($id, 'user', 'Sigo aquí');
    same(null, AssistantTasks::purge($app), 'un mensaje nuevo renueva la fecha de la conversación');
    same(1, count(assistantRows($app, 'conversations')));
});

test('la fecha de la última actividad y el contador de mensajes los lleva el servicio, no la ruta', function (): void {
    $app = assistantApp();
    $conversations = new Conversations($app);
    $created = $conversations->create('es');
    $id = $created['id'];
    $start = assistantRows($app, 'conversations')[0]['last_at'];

    $app->clock()->advance(120);
    $conversations->addMessage($id, 'user', 'Uno');
    $conversations->addMessage($id, 'assistant', 'Respuesta');
    $conversations->addMessage($id, 'user', 'Dos');
    $row = assistantRows($app, 'conversations')[0];
    same(2, (int) $row['messages'], 'cuenta solo los mensajes del cliente');
    check($row['last_at'] > $start, 'la actividad se actualiza');
    same(['Respuesta', 'Dos'], array_column($conversations->history($id, 2), 'content'), 'el historial pide los últimos y los da en orden');
    same(32, strlen($created['token']));
    same($id, (int) $conversations->findByToken($created['token'])['id']);
    same(null, $conversations->findByToken(str_repeat('a', 32)));
    same(null, $conversations->findByToken('corta'));
    same(null, $conversations->find(999999));
});

test('Conversations::phrase normaliza para agrupar: minúsculas, sin correos ni números largos, espacios recogidos y a 160 caracteres', function (): void {
    same('¿qué tal?', Conversations::phrase("  ¿QUÉ   TAL? \n"));
    same('mi correo es … y mi móvil …', Conversations::phrase('Mi correo es Ana.Ruiz+x@Cliente.TEST y mi móvil 999 345 678'));
    same('mi pedido …', Conversations::phrase('mi pedido 2026-000123'));
    same('quiero 28001 y 3 sillas', Conversations::phrase('quiero 28001 y 3 sillas'), 'un código postal o una cantidad no se tapan');
    same(160, mb_strlen(Conversations::phrase(str_repeat('á', 400)), 'UTF-8'));
    same('', Conversations::phrase("   \t "));
});
