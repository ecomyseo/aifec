<?php
/**
 * AIFEC — pruebas del registro de búsquedas: suma por consulta normalizada, marca las que no dieron resultado y no guarda
 * nada de quien busca (ni persona, ni IP, ni un correo o un teléfono escrito en el buscador).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('suma por consulta normalizada: mayúsculas, tildes, signos y espacios no la distinguen', function (): void {
    $app = searchApp();
    $log = searchService($app)->log();
    $log->record('Sillas  ROJAS!', 'es', 2, false);
    $log->record('sillas rojas', 'es', 2, false);
    $log->record('  Sillás, rojas ', 'es', 2, false);
    $rows = $app->db()->select('SELECT * FROM {search_log}');
    same(1, count($rows), 'una sola fila');
    same('sillas rojas', $rows[0]['phrase']);
    same(3, (int) $rows[0]['hits']);
    same(substr($app->clock()->sql(), 0, 10), $rows[0]['day']);
    same('es', $rows[0]['lang']);
    $log->record('sillas rojas', 'en', 2, false);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'otro idioma, otra fila');
    $app->clock()->advance(86400);
    $log->record('sillas rojas', 'es', 2, false);
    same(3, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'otro día, otra fila');
});

test('marca las que no dieron resultado y se queda con lo último que pasó', function (): void {
    $app = searchApp();
    $log = searchService($app)->log();
    $log->record('sillon cama', 'es', 0, true);
    $log->record('sillon cama', 'es', 0, true);
    $log->record('mesa', 'es', 4, false);
    $log->record('mesa', 'es', 4, false);
    $log->record('mesa', 'es', 4, false);
    $missed = $log->top(7, true);
    same(1, count($missed));
    same('sillon cama', $missed[0]['phrase']);
    same(2, $missed[0]['hits']);
    same(true, $missed[0]['missed']);
    $all = $log->top(7, false);
    same(['mesa', 'sillon cama'], array_column($all, 'phrase'), 'las más repetidas primero');
    same(3, $all[0]['hits']);
    same(4, $all[0]['found']);
    // Ahora la tienda sí tiene sillones cama: la misma consulta ya no es una «sin resultado».
    $log->record('sillon cama', 'es', 3, false);
    same([], $log->top(7, true), 'lo último que pasó manda');
});

test('las ventanas de 7 y 30 días: lo antiguo sale solo en la de 30', function (): void {
    $app = searchApp();
    $log = searchService($app)->log();
    $log->record('vieja', 'es', 0, true);
    $app->clock()->advance(10 * 86400);
    $log->record('nueva', 'es', 0, true);
    same(['nueva'], array_column($log->top(7, true), 'phrase'));
    same(['nueva', 'vieja'], array_column($log->top(30, true), 'phrase'));
    $app->clock()->advance(95 * 86400);
    same(2, $log->purge(), 'se borra lo de más de 90 días');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'));
});

test('no guarda nada personal: ni quién busca, ni un correo, ni un teléfono con separadores', function (): void {
    $app = searchApp();
    $columns = $app->db()->columns('search_log');
    sort($columns);
    same(['day', 'found', 'hits', 'id', 'lang', 'missed', 'outcome', 'phrase'], $columns, 'la tabla no tiene dónde guardar a nadie: «outcome» solo dice cómo acabó la búsqueda');

    $log = searchService($app)->log();
    $log->record('ana@cliente.test', 'es', 0, true);
    $log->record('mi correo es ana@cliente.test', 'es', 0, true);
    $log->record('999 000 001', 'es', 0, true);
    $log->record('+34 999-000-002', 'es', 0, true);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'nada de eso se apunta');
    $log->record('8412345678905', 'es', 1, false);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'un código de barras sí: es una búsqueda corriente');
    $log->record('', 'es', 0, true);
    $log->record('!!!', 'es', 0, true);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'ni lo vacío');
});

test('una consulta muy larga se recorta y con comillas, «&» o emojis no rompe el registro', function (): void {
    $app = searchApp();
    $log = searchService($app)->log();
    $log->record(str_repeat('a', 400), 'es', 0, true);
    $log->record('o\'brien "&" <b>', 'es', 0, true);
    $log->record('mesa 😀', 'es', 1, false);
    $phrases = array_column($app->db()->select('SELECT phrase FROM {search_log} ORDER BY phrase'), 'phrase');
    same(3, count($phrases));
    check(in_array(str_repeat('a', 120), $phrases, true), 'recortada a 120');
    check(in_array('mesa', $phrases, true), 'el emoji no se guarda');
    check(in_array('o brien b', $phrases, true), 'los signos tampoco: ' . json_encode($phrases));
});

test('la página de resultados apunta la búsqueda una vez, y ni los filtros ni pasar de página la repiten', function (): void {
    $app = searchApp(['catalog.per_page' => 1]);
    searchShop($app);
    $web = new TestClient($app);
    $web->get('/buscar?q=silla');
    $web->get('/buscar?q=silla&page=2');
    $web->get('/buscar?q=silla&sort=price_desc');
    $web->get('/buscar?q=silla&f[color][]=rojo');
    $row = $app->db()->row('SELECT * FROM {search_log} WHERE phrase = ?', ['silla']);
    same(1, (int) $row['hits'], 'solo la primera página sin filtros cuenta');
    same(0, (int) $row['missed']);
    same(3, (int) $row['found']);
    $web->get('/buscar?q=zapatillas');
    $missed = $app->db()->row('SELECT * FROM {search_log} WHERE phrase = ?', ['zapatillas']);
    same(1, (int) $missed['missed'], 'lo que no se encontró queda marcado');
    same(0, (int) $missed['found']);
    $web->get('/buscar?q=mesa+zapatillas');
    same(1, (int) $app->db()->value('SELECT missed FROM {search_log} WHERE phrase = ?', ['mesa zapatillas']), 'también lo que se encontró pero no convence');
    $web->get('/buscar');
    $web->get('/buscar/sugerencias.json?q=silla');
    same(3, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'la página sin palabra y las sugerencias no se apuntan (solo hay «silla», «zapatillas» y «mesa zapatillas»)');
});
