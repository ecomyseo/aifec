<?php
/**
 * AIFEC — pruebas del registro: lectura desde el final sin cargar el fichero, filtros, escapado, limpieza y descarga.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tools\LogReader;

require_once __DIR__ . '/helpers.php';

/** Un fichero de $n líneas; la primera lleva una marca que solo aparece si se lee el fichero entero. */
function toolsBigLog(App $app, int $n = 5000): string
{
    $lines = [];
    for ($i = 1; $i <= $n; ++$i) {
        $level = $i % 10 === 0 ? 'error' : ($i % 3 === 0 ? 'warning' : 'info');
        $lines[] = toolsLogLine($level, ($i === 1 ? 'PRIMERA-LINEA-MARCA ' : '') . 'línea ' . $i . ' ' . str_repeat('x', 150), ['n' => $i, 'password' => 'secreta']);
    }

    return toolsLogFile($app, 'app-2026-11-12.log', $lines);
}

test('el registro enseña las últimas líneas de un fichero de 5.000 sin leerlo entero', function (): void {
    $app = toolsApp();
    $path = toolsBigLog($app);
    $size = (int) filesize($path);
    check($size > 1000000, 'el fichero de prueba es grande');

    $reader = new LogReader($app->site()->path('logs'));
    $tail = $reader->tail($path, 50);
    same(50, count($tail['entries']));
    contains('línea 5000 ', $tail['entries'][0]['message'], 'la más reciente va primero');
    contains('línea 4951 ', $tail['entries'][49]['message']);
    check($tail['bytes_read'] < $size / 4, 'se han leído ' . $tail['bytes_read'] . ' de ' . $size . ' bytes');
    same(false, $tail['complete'], 'no se llegó al principio');
    foreach ($tail['entries'] as $entry) {
        notContains('PRIMERA-LINEA-MARCA', $entry['message']);
    }
});

test('con un filtro se sigue leyendo hacia atrás hasta tener las líneas pedidas', function (): void {
    $app = toolsApp();
    $path = toolsBigLog($app);
    $reader = new LogReader($app->site()->path('logs'));

    $errors = $reader->tail($path, 20, 'error');
    same(20, count($errors['entries']));
    foreach ($errors['entries'] as $entry) {
        same('error', $entry['level']);
    }
    contains('línea 5000 ', $errors['entries'][0]['message']);
    contains('línea 4810 ', $errors['entries'][19]['message']);

    $text = $reader->tail($path, 5, '', 'NEA 123 ');
    same(1, count($text['entries']), 'el texto no distingue mayúsculas');
    contains('línea 123 ', $text['entries'][0]['message']);

    $first = $reader->tail($path, 5, '', 'PRIMERA-LINEA-MARCA');
    same(1, count($first['entries']), 'lo más antiguo también se encuentra');
    same(true, $first['complete']);

    $none = $reader->tail($path, 5, '', 'no está en ninguna línea');
    same([], $none['entries']);
    same(true, $none['complete']);
});

test('una línea más larga que un bloque de lectura se lee entera y una que no es JSON sale como desconocida', function (): void {
    $app = toolsApp();
    $big = str_repeat('Ñ', 30000);
    $path = toolsLogFile($app, 'app-2026-11-12.log', [
        toolsLogLine('info', 'ANTES ' . $big, [], '2026-11-12 09:00:00'),
        'esto no es JSON { roto',
        toolsLogLine('error', 'DESPUES ' . $big, ['k' => 'v'], '2026-11-12 11:00:00'),
    ]);
    $tail = (new LogReader($app->site()->path('logs')))->tail($path, 10);
    same(3, count($tail['entries']));
    same('error', $tail['entries'][0]['level']);
    same('2026-11-12 11:00:00', $tail['entries'][0]['time']);
    check(strpos($tail['entries'][0]['message'], 'DESPUES ') === 0, 'la línea larga empieza por donde debe');
    same('unknown', $tail['entries'][1]['level']);
    same('esto no es JSON { roto', $tail['entries'][1]['message']);
    check(strpos($tail['entries'][2]['message'], 'ANTES ') === 0);
    same(['k' => 'v'], $tail['entries'][0]['context']);
    same(true, $tail['complete']);
});

test('un fichero que no existe o vacío no da líneas ni errores', function (): void {
    $app = toolsApp();
    $reader = new LogReader($app->site()->path('logs'));
    same([], $reader->tail('/no/existe.log', 10)['entries']);
    $path = toolsLogFile($app, 'app-2026-11-11.log', []);
    file_put_contents($path, '');
    same([], $reader->tail($path, 10)['entries']);
    same([], (new LogReader($app->site()->path('logs') . '/nada'))->files());
});

test('la pantalla del registro escapa el mensaje y el contexto, filtra por nivel y enseña el contexto desplegable', function (): void {
    $app = toolsApp();
    toolsLogFile($app, 'app-2026-11-12.log', [
        toolsLogLine('info', 'todo bien & tranquilo'),
        toolsLogLine('error', '<script>alert(1)</script> falló', ['html' => '<b>negrita</b>', 'password' => '***']),
    ]);
    $web = adminClient($app);

    $r = $web->get('/admin/tools/logs');
    same(200, $r->status);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; falló', $r->body);
    notContains('<script>alert(1)</script>', $r->body);
    contains('&lt;b&gt;negrita&lt;/b&gt;', $r->body);
    notContains('<b>negrita</b>', $r->body);
    contains('todo bien &amp; tranquilo', $r->body);
    contains('<details>', $r->body);

    $r = $web->get('/admin/tools/logs?level=error');
    contains('falló', $r->body);
    notContains('todo bien', $r->body);

    $r = $web->get('/admin/tools/logs?q=TRANQUILO');
    contains('todo bien', $r->body);
    notContains('falló', $r->body);

    same(404, $web->get('/admin/tools/logs?file=../../config.php')->status, 'un nombre que no es de un fichero de registro no vale');
    same(404, $web->get('/admin/tools/logs?file=app-2000-01-01.log')->status);
});

test('sin ficheros de registro la pantalla lo dice y no falla', function (): void {
    $app = toolsApp();
    $web = adminClient($app);
    $r = $web->get('/admin/tools/logs');
    same(200, $r->status);
    contains('Todavía no hay ficheros de registro', $r->body);
});

test('la limpieza borra los ficheros de registro de más de 30 días y solo esos', function (): void {
    $app = toolsApp();
    $old = toolsLogFile($app, 'app-2026-09-01.log', [toolsLogLine('info', 'viejo')]);
    $edge = toolsLogFile($app, 'app-2026-10-13.log', [toolsLogLine('info', 'justo en el límite')]);
    $recent = toolsLogFile($app, 'app-2026-11-10.log', [toolsLogLine('info', 'reciente')]);
    $mail = toolsLogFile($app, 'mail-2026-08-01.log', [toolsLogLine('info', 'otro canal viejo')]);
    $dir = $app->site()->path('logs');
    file_put_contents($dir . '/visits-2026-08-01.tsv', "x\n");
    file_put_contents($dir . '/notas.txt', 'no es del registro');

    $removed = (new LogReader($dir))->purge(30, $app->clock()->now());
    same(2, $removed);
    check(!is_file($old) && !is_file($mail), 'se borran los viejos');
    check(is_file($edge) && is_file($recent), 'se quedan los del límite y los recientes');
    check(is_file($dir . '/visits-2026-08-01.tsv') && is_file($dir . '/notas.txt'), 'lo que no es un fichero de registro no se toca');
});

test('la tarea tools.logs usa los días del ajuste', function (): void {
    $app = toolsApp(['tools', 'jobs'], ['tools.keep_logs_days' => 5]);
    $gone = toolsLogFile($app, 'app-2026-11-01.log', [toolsLogLine('info', 'de hace 11 días')]);
    $kept = toolsLogFile($app, 'app-2026-11-09.log', [toolsLogLine('info', 'de hace 3 días')]);
    $note = $app->service('jobs')->runTask('tools.logs');
    contains('Removed 1 log files', (string) $note);
    check(!is_file($gone) && is_file($kept));
});

test('la descarga del registro pide permiso, solo sirve ficheros de registro y queda auditada', function (): void {
    $app = toolsApp();
    $path = toolsLogFile($app, 'app-2026-11-12.log', [toolsLogLine('info', 'hola')]);
    $web = adminClient($app);

    $r = $web->get('/admin/tools/logs/download?file=app-2026-11-12.log');
    same(200, $r->status);
    same($path, $r->file);
    contains('attachment', $r->headers['Content-Disposition']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.log_download'"));

    same(404, $web->get('/admin/tools/logs/download?file=../db.sqlite')->status);
    same(404, $web->get('/admin/tools/logs/download')->status);

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/tools/logs')->status);
    same(403, $support->get('/admin/tools/logs/download?file=app-2026-11-12.log')->status);
});
