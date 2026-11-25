<?php
/**
 * AIFEC — pruebas del panel de la IA: bandeja de propuestas, comparación, corregir, aprobar y rechazar (también en bloque), el
 * botón «Proponer con IA» de la ficha de producto, lotes, gasto, ajustes sin claves, avisos y la pieza del inicio, con permisos
 * (403 sin ellos) y los datos escapados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la bandeja se abre con permiso, filtra por estado, enseña los textos escapados y da 403 a quien no puede', function (): void {
    $app = aiApp([], ['es', 'en']);
    $p = aiProduct($app, ['name' => 'Camiseta & Co', 'description' => '<p>Vieja</p>']);
    $ai = $app->service('ai');
    $a = $ai->propose('product', $p, 'description', 'es', '<p>Nueva <b>con</b> & más</p>', ['model' => 'fake-model']);
    $b = $ai->propose('product', $p, 'meta_title', 'es', 'Título ya hecho');
    $ai->proposals()->reject($b);
    $web = adminClient($app);

    $list = $web->get('/admin/ai/proposals');
    same(200, $list->status);
    contains('Propuestas de la IA', $list->body);
    contains('Camiseta &amp; Co', $list->body);
    contains('/admin/ai/proposals/' . $a, $list->body);
    contains('/admin/ai/proposals/bulk', $list->body, 'las acciones en bloque');

    $pending = $web->get('/admin/ai/proposals?f[status]=pending')->body;
    contains('/admin/ai/proposals/' . $a, $pending);
    notContains('/admin/ai/proposals/' . $b, $pending);
    $rejected = $web->get('/admin/ai/proposals?f[status]=rejected')->body;
    contains('/admin/ai/proposals/' . $b, $rejected);
    $byField = $web->get('/admin/ai/proposals?f[field]=meta_title')->body;
    contains('/admin/ai/proposals/' . $b, $byField);
    notContains('/admin/ai/proposals/' . $a, $byField);
    contains('/admin/ai/proposals/' . $a, $web->get('/admin/ai/proposals?f[entity]=product')->body);
    contains('/admin/ai/proposals/' . $a, $web->get('/admin/ai/proposals?q=camiseta')->body);

    same(403, adminClient(aiApp(), 'support')->get('/admin/ai/proposals')->status, 'soporte no tiene nada de IA');
    same(200, adminClient(aiApp(), 'editor')->get('/admin/ai/proposals')->status, 'el editor puede usarla');
});

test('la ficha de una propuesta enseña lo actual y lo propuesto lado a lado con las diferencias marcadas, escapado', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Mesa', 'description' => '<p>Una mesa de roble macizo</p>']);
    $id = $app->service('ai')->propose('product', $p, 'description', 'es', '<p>Una mesa de pino & roble macizo <script>alert(1)</script></p>');
    $web = adminClient($app);
    $show = $web->get('/admin/ai/proposals/' . $id);
    same(200, $show->status);
    contains('data-ai-current', $show->body);
    contains('data-ai-proposed', $show->body);
    contains('<ins>', $show->body);
    contains('pino &amp;', $show->body);
    notContains('<script>alert', $show->body);
    contains('/admin/ai/proposals/' . $id . '/approve', $show->body);
    contains('/admin/ai/proposals/' . $id . '/reject', $show->body);
    contains('Mesa', $show->body);
    same(404, $web->get('/admin/ai/proposals/99999')->status);
});

test('aprobar desde el panel escribe el texto corregido en la tienda, deja marca y auditoría, y avisa; la «&» no se guarda como «&amp;»', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Mesa', 'meta_title' => 'Vieja']);
    $id = $app->service('ai')->propose('product', $p, 'meta_title', 'es', 'Propuesta de la IA');
    $web = adminClient($app);
    $web->get('/admin/ai/proposals/' . $id);

    $r = $web->post('/admin/ai/proposals/' . $id . '/approve', ['_token' => aifecToken($web), 'text' => 'Mesa & Sillas, corregido']);
    same(302, $r->status);
    same('Mesa & Sillas, corregido', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]));
    same('approved', aiRows($app, 'ai_proposals')[0]['status']);
    same(1, count(aiRows($app, 'ai_marks')));
    check(aiRows($app, 'ai_marks')[0]['user_id'] !== null, 'queda quién lo aprobó');
    same(['approve'], array_column($app->db()->select("SELECT action FROM {audit} WHERE entity = 'ai_proposal'"), 'action'));
    contains('Aprobada y escrita en la tienda.', $web->follow()->body);
});

test('rechazar desde el panel no toca la tienda; guardar solo el texto tampoco publica; y un texto con la forma equivocada se avisa', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Mesa', 'meta_title' => 'Vieja']);
    $ai = $app->service('ai');
    $id = $ai->propose('product', $p, 'meta_title', 'es', 'Propuesta');
    $web = adminClient($app);
    $web->get('/admin/ai/proposals/' . $id);

    $web->post('/admin/ai/proposals/' . $id . '/save', ['_token' => aifecToken($web), 'text' => 'Texto guardado & sin publicar']);
    same('Texto guardado & sin publicar', aiRows($app, 'ai_proposals')[0]['proposed_text']);
    same('Vieja', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]), 'guardar no publica');
    same('pending', aiRows($app, 'ai_proposals')[0]['status']);
    contains('No se publica hasta que lo apruebes', $web->follow()->body);

    $web->post('/admin/ai/proposals/' . $id . '/save', ['_token' => aifecToken($web), 'text' => '   ']);
    same('Texto guardado & sin publicar', aiRows($app, 'ai_proposals')[0]['proposed_text'], 'un texto vacío no se guarda');
    contains('No se puede guardar el texto', $web->follow()->body);

    $r = $web->post('/admin/ai/proposals/' . $id . '/reject', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('rejected', aiRows($app, 'ai_proposals')[0]['status']);
    same('Vieja', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]));
    same([], aiRows($app, 'ai_marks'));
});

test('aprobar desde el panel una propuesta que nadie sabe aplicar la deja pendiente y lo dice', function (): void {
    $app = aiApp();
    $id = $app->service('ai')->propose('page', 3, 'html', 'es', '<p>Para un módulo ausente</p>');
    $web = adminClient($app);
    $web->get('/admin/ai/proposals/' . $id);
    $web->post('/admin/ai/proposals/' . $id . '/approve', ['_token' => aifecToken($web)]);
    same('pending', aiRows($app, 'ai_proposals')[0]['status']);
    contains('Ningún módulo sabe aplicar', $web->follow()->body);
});

test('aprobar y rechazar varias a la vez desde la bandeja', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    $ids = [];
    foreach (['A', 'B', 'C', 'D'] as $name) {
        $p = aiProduct($app, ['name' => 'Producto ' . $name, 'meta_title' => 'Vieja ' . $name]);
        $ids[$name] = [$p, $ai->propose('product', $p, 'meta_title', 'es', 'Nueva ' . $name)];
    }
    $page = $ai->propose('page', 1, 'html', 'es', '<p>Sin dueño</p>');
    $web = adminClient($app);
    $web->get('/admin/ai/proposals');

    $web->post('/admin/ai/proposals/bulk', ['_token' => aifecToken($web), 'action' => 'approve', 'ids' => [$ids['A'][1], $ids['B'][1], $page]]);
    same('Nueva A', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$ids['A'][0]]));
    same('Nueva B', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$ids['B'][0]]));
    same('Vieja C', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$ids['C'][0]]), 'las no marcadas no se tocan');
    same('pending', aiRows($app, 'ai_proposals', 'id = ?', [$page])[0]['status'], 'la que nadie aplica sigue pendiente');
    $flash = $web->follow()->body;
    contains('2 propuestas aprobadas', $flash);
    contains('1 no se pudo aplicar', $flash);

    $web->post('/admin/ai/proposals/bulk', ['_token' => aifecToken($web), 'action' => 'reject', 'ids' => [$ids['C'][1]]]);
    same('rejected', aiRows($app, 'ai_proposals', 'id = ?', [$ids['C'][1]])[0]['status']);
    same('pending', aiRows($app, 'ai_proposals', 'id = ?', [$ids['D'][1]])[0]['status']);
    same('Vieja C', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$ids['C'][0]]));
});

test('sin el permiso «ai.use» ninguna acción de propuestas, lotes ni propuesta de producto pasa, aunque se pueda ver', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Mesa', 'meta_title' => 'Vieja']);
    $id = $app->service('ai')->propose('product', $p, 'meta_title', 'es', 'Nueva');
    $viewer = adminClient($app, 'support');
    // Un papel con solo «ai.view»: el que ve el gasto pero no usa la IA.
    $app->db()->update('roles', ['caps' => json_encode(['dashboard.view', 'ai.view'])], ['code' => 'support']);
    $app->auth()->reset();
    $viewer->get('/admin');
    same(200, $viewer->get('/admin/ai/usage')->status);
    same(200, $viewer->get('/admin/ai/proposals')->status, 'ver la bandeja sí');
    same(403, $viewer->get('/admin/ai/batches')->status);
    // Tras un 403 la última página no lleva ficha: se abre una que sí la lleve antes de cada envío.
    $token = static function () use ($viewer): string {
        $viewer->get('/admin/ai/usage');

        return aifecToken($viewer);
    };
    foreach (['approve', 'reject', 'save'] as $action) {
        same(403, $viewer->post('/admin/ai/proposals/' . $id . '/' . $action, ['_token' => $token(), 'text' => 'x'])->status, $action);
    }
    same(403, $viewer->post('/admin/ai/proposals/bulk', ['_token' => $token(), 'action' => 'approve', 'ids' => [$id]])->status);
    same(403, $viewer->post('/admin/ai/propose', ['_token' => $token(), 'product_id' => $p, 'task' => 'summary'])->status);
    same(403, $viewer->post('/admin/ai/batches/start', ['_token' => $token(), 'task' => 'summary', 'type' => 'no_description'])->status);
    same([], aiRows($app, 'ai_batches'));
    same('pending', aiRows($app, 'ai_proposals')[0]['status']);
    same('Vieja', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]));
});

test('usar la IA no da derecho a escribir en el catálogo: sin «catalog.edit» no se aprueba, ni se edita, ni se propone sobre un producto', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Mesa', 'meta_title' => 'Vieja']);
    $id = $app->service('ai')->propose('product', $p, 'meta_title', 'es', 'Nueva');
    $user = adminClient($app, 'support');
    $app->db()->update('roles', ['caps' => json_encode(['dashboard.view', 'ai.view', 'ai.use'])], ['code' => 'support']);
    $app->auth()->reset();
    $user->get('/admin');
    $token = static function () use ($user): string {
        $user->get('/admin/ai/usage');

        return aifecToken($user);
    };
    foreach (['approve', 'save'] as $action) {
        same(403, $user->post('/admin/ai/proposals/' . $id . '/' . $action, ['_token' => $token(), 'text' => 'Hackeada'])->status, $action);
    }
    $user->post('/admin/ai/proposals/bulk', ['_token' => $token(), 'action' => 'approve', 'ids' => [$id]]);
    same(403, $user->post('/admin/ai/propose', ['_token' => $token(), 'product_id' => $p, 'task' => 'summary'])->status);
    same('pending', aiRows($app, 'ai_proposals')[0]['status'], 'la propuesta sigue pendiente');
    same('Vieja', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]), 'el producto no se tocó');
    // Con «catalog.edit» sí.
    $app->db()->update('roles', ['caps' => json_encode(['dashboard.view', 'ai.view', 'ai.use', 'catalog.edit'])], ['code' => 'support']);
    $app->auth()->reset();
    $user->get('/admin');
    same(302, $user->post('/admin/ai/proposals/' . $id . '/approve', ['_token' => $token()])->status);
    same('Nueva', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$p]));
});

test('la ficha de producto lleva la tarjeta «Proponer con IA» con sus botones; con la IA apagada lo dice y enlaza a los ajustes', function (): void {
    $app = aiApp([], ['es', 'en']);
    $p = aiProduct($app, ['name' => 'Silla']);
    $web = adminClient($app);
    $page = $web->get('/admin/r/products/' . $p);
    same(200, $page->status);
    contains('Proponer con IA', $page->body);
    contains('action="' . $app->url()->plain('/admin/ai/propose') . '"', $page->body);
    foreach (['description', 'summary', 'facts', 'faqs', 'meta_title', 'meta_description', 'alt', 'translate'] as $task) {
        contains('name="task" value="' . $task . '"', $page->body, $task);
    }
    contains('name="product_id" value="' . $p . '"', $page->body);
    $new = $web->get('/admin/r/products/new');
    notContains('Proponer con IA', $new->body, 'un producto nuevo todavía no tiene nada que proponer');

    $off = aiApp(['ai.enabled' => false]);
    $q = aiProduct($off, ['name' => 'Silla']);
    $offPage = adminClient($off)->get('/admin/r/products/' . $q)->body;
    contains('La IA está apagada.', $offPage);
    contains('/admin/settings/ai', $offPage);
    notContains('name="task" value="description"', $offPage);
});

test('«Proponer con IA» crea la propuesta y lleva a la bandeja; si la IA falla, avisa y no crea nada', function (): void {
    $app = aiApp();
    $p = aiProduct($app, ['name' => 'Silla de roble', 'description' => '<p>Una silla de roble</p>']);
    aiFake($app)->script(aiJson(['title' => 'Silla de roble macizo & Co']));
    $web = adminClient($app);
    $web->get('/admin/r/products/' . $p);

    $r = $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'product_id' => $p, 'task' => 'meta_title', 'lang' => 'es']);
    same(302, $r->status);
    $row = aiRows($app, 'ai_proposals')[0];
    same('Silla de roble macizo & Co', $row['proposed_text']);
    same('pending', $row['status']);
    check($row['created_by'] !== null, 'queda quién lo pidió');
    contains('/admin/ai/proposals/' . $row['id'], (string) $r->headers['Location']);
    contains('esperando tu aprobación', $web->follow()->body);

    // La IA contesta algo que no vale.
    aiFake($app)->script(aiJson(['otra' => 'cosa']));
    $web->get('/admin/r/products/' . $p);
    $r = $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'product_id' => $p, 'task' => 'summary']);
    same(302, $r->status);
    same(1, count(aiRows($app, 'ai_proposals')));
    contains('La IA no ha podido redactarlo', $web->follow()->body);

    // Una tarea que no existe.
    $web->get('/admin/r/products/' . $p);
    $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'product_id' => $p, 'task' => 'novela']);
    same(1, count(aiRows($app, 'ai_proposals')));
});

test('«Proponer con IA» para el texto alternativo crea una propuesta por imagen, y sin imágenes lo dice', function (): void {
    $app = aiApp();
    $images = [aifecMedia($app, ''), aifecMedia($app, '')];
    $p = aiProduct($app, ['name' => 'Silla', 'images' => $images]);
    $empty = aiProduct($app, ['name' => 'Sin fotos']);
    aiFake($app)->script(aiJson(['alt' => 'Silla, vista 1']), aiJson(['alt' => 'Silla, vista 2']));
    $web = adminClient($app);
    $web->get('/admin/r/products/' . $p);
    $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'product_id' => $p, 'task' => 'alt']);
    $rows = aiRows($app, 'ai_proposals');
    same(['media', 'media'], array_column($rows, 'entity'));
    same($images, array_map('intval', array_column($rows, 'entity_id')));
    same(['Silla, vista 1', 'Silla, vista 2'], array_column($rows, 'proposed_text'));

    $web->get('/admin/r/products/' . $empty);
    $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'product_id' => $empty, 'task' => 'alt']);
    contains('Este producto no tiene imágenes.', $web->follow()->body);
    same(2, count(aiRows($app, 'ai_proposals')));
});

test('los lotes del panel: estimar sin lanzar, lanzar, pausar, reanudar y cancelar; con 403 sin permiso', function (): void {
    $app = aiApp();
    foreach (['A', 'B', 'C'] as $name) {
        aiProduct($app, ['name' => 'Producto ' . $name, 'description' => '']);
    }
    $web = adminClient($app);
    $page = $web->get('/admin/ai/batches');
    same(200, $page->status);
    contains('Lotes de la IA', $page->body);
    contains('Lanzar un lote', $page->body);
    contains('/admin/ai/batches/estimate', $page->body);

    $r = $web->post('/admin/ai/batches/estimate', ['_token' => aifecToken($web), 'task' => 'summary', 'type' => 'no_description']);
    same(200, $r->status);
    contains('data-ai-estimate', $r->body);
    contains('3 productos', $r->body);
    contains('/admin/ai/batches/start', $r->body);
    same([], aiRows($app, 'ai_batches'), 'estimar no lanza nada');

    $r = $web->post('/admin/ai/batches/start', ['_token' => aifecToken($web), 'task' => 'summary', 'type' => 'no_description']);
    same(302, $r->status);
    $batch = aiRows($app, 'ai_batches')[0];
    same('running', $batch['status']);
    same(3, (int) $batch['total']);
    check($batch['created_by'] !== null);
    $id = (int) $batch['id'];
    $list = $web->follow()->body;
    contains('data-ai-batch="' . $id . '"', $list);
    contains('/admin/ai/batches/' . $id . '/pause', $list);
    contains('/admin/ai/batches/' . $id . '/cancel', $list);

    $web->post('/admin/ai/batches/' . $id . '/pause', ['_token' => aifecToken($web)]);
    same('paused', aiRows($app, 'ai_batches')[0]['status']);
    contains('/admin/ai/batches/' . $id . '/resume', $web->follow()->body);
    $web->post('/admin/ai/batches/' . $id . '/resume', ['_token' => aifecToken($web)]);
    same('running', aiRows($app, 'ai_batches')[0]['status']);
    $web->follow();
    $web->post('/admin/ai/batches/' . $id . '/cancel', ['_token' => aifecToken($web)]);
    same('cancelled', aiRows($app, 'ai_batches')[0]['status']);
    same(['start', 'pause', 'resume', 'cancel'], array_column($app->db()->select("SELECT action FROM {audit} WHERE entity = 'ai_batch' ORDER BY id"), 'action'));

    $web->get('/admin/ai/batches');
    $web->post('/admin/ai/batches/start', ['_token' => aifecToken($web), 'task' => 'summary', 'type' => 'category', 'category_id' => '0']);
    same(1, count(aiRows($app, 'ai_batches')), 'una selección que no vale no lanza nada');
    contains('No se puede lanzar el lote', $web->follow()->body);
    same(404, $web->post('/admin/ai/batches/9999/pause', ['_token' => aifecToken($web)])->status);

    $other = aiApp();
    aiProduct($other, ['name' => 'Producto', 'description' => '']);
    $support = adminClient($other, 'support');
    same(403, $support->get('/admin/ai/batches')->status);
    $support->get('/admin');
    same(403, $support->post('/admin/ai/batches/start', ['_token' => aifecToken($support), 'task' => 'summary', 'type' => 'no_description'])->status);
    same([], aiRows($other, 'ai_batches'));
});

test('la pantalla de gasto enseña la gráfica de servidor, las tablas por día y por tarea, y solo la ve quien tiene «ai.view»', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    aiFake($app)->script(new \Aifec\Ai\AiResult('a', [], null, 10000, 0), new \Aifec\Ai\AiResult('b', [], null, 5000, 1000));
    $ai->ask('product.description', ['prompt' => 'x'], 'economy');
    $app->clock()->advance(86400);
    $ai->ask('product.summary', ['prompt' => 'y'], 'economy');

    $web = adminClient($app);
    $page = $web->get('/admin/ai/usage');
    same(200, $page->status);
    contains('<svg', $page->body);
    contains('Gasto por día', $page->body);
    contains('<td>Descripción de producto</td>', $page->body, 'cada tarea con su nombre, no con su código');
    contains('<td>Resumen de producto</td>', $page->body);
    notContains('<td>product.description</td>', $page->body);
    contains('2026-11-12', $page->body);
    contains('2026-11-13', $page->body);
    contains('0,01', $page->body, 'la primera llamada: un céntimo, en unidades de la moneda');
    contains('0,01', $page->body, 'la segunda: 5.000 × 100 + 1.000 × 400 = 900.000 millonésimas, casi un céntimo');
    notContains('céntimos', $page->body, 'ya no habla de céntimos');
    contains('de 2,00', $page->body, 'el tope de hoy, en unidades');
    contains('2 llamadas', $page->body);

    same(403, adminClient(aiApp(), 'editor')->get('/admin/ai/usage')->status, 'el editor puede usar la IA pero no ve el gasto');
    same(403, adminClient(aiApp(), 'support')->get('/admin/ai/usage')->status);
});

test('sin precios por millón de fichas, la pantalla de gasto avisa de que el coste no se puede estimar y enlaza con los ajustes', function (): void {
    $bare = testApp(['modules' => ['ai'], 'settings' => ['ai.enabled' => true]]);
    $bare->boot();
    $body = adminClient($bare)->get('/admin/ai/usage')->body;
    contains('data-af-ai-prices', $body);
    contains('El coste no se puede estimar', $body);
    contains('href="/admin/settings/ai"', $body);
    contains('El tope diario detiene la IA', $body, 'la ayuda de la pantalla es una frase entera');
    contains('El menú «IA» es tu ayudante de redacción', $body, 'la ayuda dice qué es el grupo y cuál habla con los clientes');
    notContains('El tope diario para la IA', $body);
    notContains('data-af-ai-prices', adminClient(aiApp())->get('/admin/ai/usage')->body, 'con precios puestos no avisa');
});

test('la pieza del inicio enseña el gasto de hoy frente al tope y las propuestas pendientes; el inicio avisa si la IA está encendida sin clave ni precios', function (): void {
    $app = aiApp(['ai.daily_limit_cents' => 5]);
    $ai = $app->service('ai');
    aiFake($app)->script(new \Aifec\Ai\AiResult('a', [], null, 10000, 0));
    $ai->ask('t', ['prompt' => 'x']);
    $p = aiProduct($app, ['name' => 'Mesa']);
    $ai->propose('product', $p, 'meta_title', 'es', 'Uno');
    $ai->propose('product', $p, 'meta_description', 'es', 'Dos');
    $home = adminClient($app)->get('/admin')->body;
    contains('Gasto de IA de hoy', $home);
    contains('0,01', $home, 'un céntimo gastado');
    contains('de 0,05', $home, 'frente a un tope de cinco céntimos');
    contains('2 propuestas pendientes de aprobar', $home);

    // Gemini sin clave y sin precios: dos avisos.
    $bare = testApp(['modules' => ['ai'], 'settings' => ['ai.enabled' => true]]);
    $bare->boot();
    $body = adminClient($bare)->get('/admin')->body;
    contains('falta la clave de la API de', $body);
    contains('Económico', $body);
    contains('no hay ningún precio por millón de fichas', $body);
    // Con el interruptor apagado no avisa de nada.
    $off = testApp(['modules' => ['ai'], 'settings' => ['ai.enabled' => false]]);
    $off->boot();
    notContains('falta la clave de la API', adminClient($off)->get('/admin')->body);
});

test('la pantalla de ajustes no contiene las claves guardadas, y guarda los modelos, el tope y los precios', function (): void {
    $app = aiApp();
    $app->setSecret('ai.economy.key', 'clave-de-mentira-economica');
    $app->setSecret('ai.advanced.key', 'clave-de-mentira-avanzada');
    $web = adminClient($app);
    $page = $web->get('/admin/settings/ai');
    same(200, $page->status);
    notContains('clave-de-mentira-economica', $page->body);
    notContains('clave-de-mentira-avanzada', $page->body);
    contains('Inteligencia artificial', $page->body);
    contains('aigeneral', $page->body);
    contains('aimodels', $page->body);
    contains('aispend', $page->body);
    contains('gemini-flash-latest', $page->body, 'los modelos sugeridos van en la ayuda del campo');
    contains('gemini-3-pro-preview', $page->body);

    $r = $web->post('/admin/settings/ai', ['_token' => aifecToken($web), 'f' => [
        'ai.enabled' => '1', 'ai.show_notice' => '0', 'ai.daily_limit_cents' => '3,50', 'ai.timeout' => '45',
        'ai.economy.provider' => 'gemini', 'ai.economy.model' => 'gemini-flash-lite-latest', 'ai.economy.base_url' => '', 'ai.economy.key' => '',
        'ai.standard.provider' => 'openai', 'ai.standard.model' => 'un-modelo', 'ai.standard.base_url' => 'https://api.ejemplo.test/v1', 'ai.standard.key' => 'clave-de-mentira-nueva',
        'ai.advanced.provider' => 'fake', 'ai.advanced.model' => '', 'ai.advanced.base_url' => '', 'ai.advanced.key' => '',
        'ai.economy.price_in' => '7', 'ai.economy.price_out' => '30', 'ai.standard.price_in' => '0', 'ai.standard.price_out' => '0',
        'ai.advanced.price_in' => '0', 'ai.advanced.price_out' => '0',
    ]]);
    same(302, $r->status);
    $s = $app->settings();
    $s->reload();
    same(350, $s->int('ai.daily_limit_cents'));
    same('gemini-flash-lite-latest', $s->str('ai.economy.model'));
    same('openai', $s->str('ai.standard.provider'));
    same('https://api.ejemplo.test/v1', $s->str('ai.standard.base_url'));
    same(7, $s->int('ai.economy.price_in'));
    same('clave-de-mentira-nueva', $app->secret('ai.standard.key'), 'la clave nueva se guarda cifrada');
    same('clave-de-mentira-economica', $app->secret('ai.economy.key'), 'la vacía no borra la que había');
    $stored = (string) $app->db()->value("SELECT v FROM {settings} WHERE k = 'ai.standard.key'");
    notContains('clave-de-mentira-nueva', $stored, 'en la base de datos va cifrada');
    notContains('clave-de-mentira-nueva', $web->get('/admin/settings/ai')->body);
    same(403, adminClient(aiApp(), 'editor')->get('/admin/settings/ai')->status, 'el editor no cambia los ajustes de la IA');
});
