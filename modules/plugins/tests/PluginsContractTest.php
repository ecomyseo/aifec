<?php
/**
 * AIFEC — pruebas del validador del Contrato AIFEC: un plugin de prueba por regla que la incumple y solo esa, lo que no
 * debe saltar (palabras en comentarios y cadenas) y las comprobaciones dinámicas (C1 medido, C3, C7, C9 y C10).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/PluginsKit.php';

/**
 * Escribe un plugin que incumple UNA regla, pasa las comprobaciones estáticas y comprueba que falla solo esa, con su
 * fichero y su línea, y que todas las demás reglas salen en verde.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed> el informe
 */
function pluginsRuleCase(string $id, array $over, string $rule, string $file, bool $withLine = true): array
{
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, $id, $over);
    $report = pluginsStatic($app, $root, $id);
    same([$rule], pluginsFailed($report), "debería fallar solo $rule:\n" . pluginsMessages($report));
    same(false, $report['ok']);
    $checks = pluginsChecks($report, $rule);
    same($file, $checks[0]['file']);
    if ($withLine) {
        check($checks[0]['line'] > 0, 'trae la línea');
    }
    foreach ($report['checks'] as $check) {
        if ($check['rule'] !== $rule) {
            check($check['ok'], 'la regla ' . $check['rule'] . ' tendría que salir en verde: ' . $check['message']);
        }
    }

    return $report;
}

/**
 * Informe estático de un plugin de prueba que tiene que salir limpio.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function pluginsCleanCase(string $id, array $over): array
{
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, $id, $over);
    $report = pluginsStatic($app, $root, $id);
    same([], pluginsFailed($report), pluginsMessages($report));
    same([], pluginsFailed($report, 'warning'), pluginsMessages($report));
    same(true, $report['ok']);

    return $report;
}

pluginsTest('C1: escribir en una tabla del comercio que no es suya falla', function (): void {
    $report = pluginsRuleCase('c1-escribe', ['files' => ['hooks.php' => pluginsHooks("    \$app->db()->update('products', ['price' => 1], ['id' => 1]);")]], 'C1', 'hooks.php');
    contains('products', pluginsMessages($report));
    pluginsRuleCase('c1-carriers', ['files' => ['hooks.php' => pluginsHooks("    \$app->db()->delete('carriers', ['id' => 1]);")]], 'C1', 'hooks.php');
});

pluginsTest('C1: una sentencia SQL que escribe en una tabla del comercio también falla', function (): void {
    pluginsRuleCase('c1-sql', ['files' => ['hooks.php' => pluginsHooks("    \$app->db()->run('UPDATE {tax_rates} SET rate = 0');")]], 'C1', 'hooks.php');
    pluginsRuleCase('c1-sql-insert', ['files' => ['hooks.php' => pluginsHooks("    \$app->db()->run(\"INSERT INTO {coupons} (code) VALUES ('X')\");")]], 'C1', 'hooks.php');
});

pluginsTest('C1: escribir en su propia tabla declarada no falla', function (): void {
    pluginsCleanCase('c1-propia', [
        'manifest' => ['contract' => ['tables' => ['c1_propia_data']]],
        'files' => [
            'hooks.php' => pluginsHooks("    \$app->db()->insert('c1_propia_data', ['name' => 'x']);\n    \$app->db()->run('DELETE FROM {c1_propia_data} WHERE id = 1');"),
            'migrations/0001_data.php' => pluginsMigration('c1_propia_data'),
        ],
    ]);
});

pluginsTest('C1: enganchar un filtro sellado sin declarar la capacidad falla, y declarándola no', function (): void {
    $hook = "    \$app->hooks()->filter('product.price', static function (\$block) {\n        return \$block;\n    });";
    pluginsRuleCase('c1-sellado', ['files' => ['hooks.php' => pluginsHooks($hook)]], 'C1', 'hooks.php');
    pluginsCleanCase('c1-con-capacidad', ['manifest' => ['contract' => ['capabilities' => ['pricing']]], 'files' => ['hooks.php' => pluginsHooks($hook)]]);
});

pluginsTest('C2: lo que se añade al producto sin etiqueta, tipo y público falla; declarado entero, no', function (): void {
    $bad = "    \$app->hooks()->filter('product.extra', static function (\$extra) {\n        \$extra['origen'] = ['value' => 'ES'];\n\n        return \$extra;\n    });";
    $report = pluginsRuleCase('c2-mal', ['files' => ['hooks.php' => pluginsHooks($bad)]], 'C2', 'hooks.php');
    contains('label', pluginsMessages($report));
    $good = "    \$app->hooks()->filter('product.extra', static function (\$extra) {\n        \$extra['origen'] = ['label' => 'Origin', 'value' => 'ES', 'type' => 'text', 'public' => true];\n\n        return \$extra;\n    });";
    pluginsCleanCase('c2-bien', ['files' => ['hooks.php' => pluginsHooks($good)], 'lang' => ['Origin' => 'Origen']]);
});

pluginsTest('C3: un script de otro dominio en una vista falla', function (): void {
    pluginsRuleCase('c3-externo', ['files' => ['views/front/caja.php' => "<?php\n\ndeclare(strict_types=1);\n\n?>\n<script src=\"https://cdn.proveedor.test/lib.js\"></script>\n"]], 'C3', 'views/front/caja.php');
    pluginsRuleCase('c3-enlace', ['files' => ['views/front/hoja.php' => "<?php\n\n?>\n<link rel=\"stylesheet\" href=\"//cdn.proveedor.test/x.css\">\n"]], 'C3', 'views/front/hoja.php');
});

pluginsTest('C3: un @import o una dirección externa en una hoja de estilo falla', function (): void {
    pluginsRuleCase('c3-import', ['files' => ['assets/hoja.css' => "@import url('https://fuentes.proveedor.test/a.css');\nbody { color: red; }\n"]], 'C3', 'assets/hoja.css');
});

pluginsTest('C3: los recursos que pesan más de 200 KB fallan y los de más de 60 KB avisan', function (): void {
    $report = pluginsRuleCase('c3-peso', ['files' => ['assets/grande.js' => str_repeat('a', 210 * 1024)]], 'C3', 'assets', false);
    contains('210', pluginsMessages($report));
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'c3-aviso', ['files' => ['assets/medio.js' => str_repeat('a', 70 * 1024)]]);
    $report = pluginsStatic($app, $root, 'c3-aviso');
    same(true, $report['ok']);
    same([], pluginsFailed($report));
    same(['C3'], pluginsFailed($report, 'warning'));
    same(70 * 1024, $report['measured']['assets_bytes']);
});

pluginsTest('C4: echo y print fuera de las vistas fallan', function (): void {
    pluginsRuleCase('c4-echo', ['files' => ['hooks.php' => pluginsHooks("    echo 'hola';")]], 'C4', 'hooks.php');
    pluginsRuleCase('c4-print', ['files' => ['hooks.php' => pluginsHooks("    print('hola');")]], 'C4', 'hooks.php');
});

pluginsTest('C4: header(), exit y die fallan en cualquier sitio', function (): void {
    pluginsRuleCase('c4-header', ['files' => ['hooks.php' => pluginsHooks("    header('X-Mio: 1');")]], 'C4', 'hooks.php');
    pluginsRuleCase('c4-exit', ['files' => ['hooks.php' => pluginsHooks('    exit;')]], 'C4', 'hooks.php');
});

pluginsTest('C4: en una vista, un dato que no pasa por e() avisa y escapado no', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'c4-vista', ['files' => ['views/front/caja.php' => "<?php\n\n?>\n<p><?= \$nombre ?></p>\n<p><?= e(\$nombre) ?></p>\n<p><?= e(t('Test Plugin')) ?></p>\n<p><?= (int) \$cuenta ?></p>\n"]]);
    $report = pluginsStatic($app, $root, 'c4-vista');
    same(true, $report['ok']);
    same(['C4'], pluginsFailed($report, 'warning'));
    $checks = pluginsChecks($report, 'C4');
    same(1, count($checks));
    same('views/front/caja.php', $checks[0]['file']);
    same(4, $checks[0]['line']);
});

pluginsTest('C5: abrir conexiones con curl_init falla', function (): void {
    pluginsRuleCase('c5-curl', ['files' => ['hooks.php' => pluginsHooks("    \$ch = curl_init('https://api.proveedor.test/x');\n    curl_close(\$ch);")]], 'C5', 'hooks.php');
});

pluginsTest('C5: un dominio escrito en el código que no está declarado falla, y declarado no', function (): void {
    $code = "    \$app->http()->get('https://api.proveedor.test/v1/ping');";
    $report = pluginsRuleCase('c5-dominio', ['files' => ['hooks.php' => pluginsHooks($code)]], 'C5', 'hooks.php');
    contains('api.proveedor.test', pluginsMessages($report));
    pluginsCleanCase('c5-declarado', ['manifest' => ['contract' => ['hosts' => ['api.proveedor.test']]], 'files' => ['hooks.php' => pluginsHooks($code)]]);
    pluginsCleanCase('c5-subdominio', ['manifest' => ['contract' => ['hosts' => ['proveedor.test']]], 'files' => ['hooks.php' => pluginsHooks($code)]]);
});

pluginsTest('C5: leer clientes, direcciones o pedidos sin declarar datos personales falla', function (): void {
    $code = "    \$app->db()->select('SELECT id FROM {customers} WHERE id = ?', [1]);";
    pluginsRuleCase('c5-personal', ['files' => ['hooks.php' => pluginsHooks($code)]], 'C5', 'hooks.php');
    pluginsCleanCase('c5-declara', ['manifest' => ['contract' => ['personal_data' => ['email']]], 'files' => ['hooks.php' => pluginsHooks($code)]]);
});

pluginsTest('C6: enviar correo con mail() falla', function (): void {
    pluginsRuleCase('c6-mail', ['files' => ['hooks.php' => pluginsHooks("    mail('ana@cliente.test', 'Hola', 'Texto');")]], 'C6', 'hooks.php');
});

pluginsTest('C6: dormir la petición, quitar el límite de tiempo y llamar a una IA directamente fallan', function (): void {
    pluginsRuleCase('c6-sleep', ['files' => ['hooks.php' => pluginsHooks('    sleep(1);')]], 'C6', 'hooks.php');
    pluginsRuleCase('c6-tiempo', ['files' => ['hooks.php' => pluginsHooks('    set_time_limit(0);')]], 'C6', 'hooks.php');
    pluginsRuleCase('c6-ia', ['manifest' => ['contract' => ['hosts' => ['api.openai.com']]], 'files' => ['hooks.php' => pluginsHooks("    \$app->http()->post('https://api.openai.com/v1/chat/completions', []);")]], 'C6', 'hooks.php');
});

pluginsTest('C6: una tarea que recorre todo el histórico de pedidos avisa y con una ventana de fechas no', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $jobs = static function (string $sql): string {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ['cron' => ['demo.tarea' => ['every' => 'hourly', 'run' => static function (\$app) {\n    \$app->db()->select('" . $sql . "');\n}]]];\n";
    };
    pluginsMake($root, 'c6-historico', ['manifest' => ['contract' => ['personal_data' => ['email']]], 'files' => ['jobs.php' => $jobs('SELECT id FROM {orders}')]]);
    $report = pluginsStatic($app, $root, 'c6-historico');
    same(true, $report['ok']);
    same(['C6'], pluginsFailed($report, 'warning'));
    same('jobs.php', pluginsChecks($report, 'C6')[0]['file']);
    pluginsMake($root, 'c6-ventana', ['manifest' => ['contract' => ['personal_data' => ['email']]], 'files' => ['jobs.php' => $jobs('SELECT id FROM {orders} WHERE created_at >= ?')]]);
    $ok = pluginsStatic($app, $root, 'c6-ventana');
    same([], pluginsFailed($ok, 'warning'));
});

pluginsTest('C8: una cadena que no está en su lang/es.php falla', function (): void {
    $report = pluginsRuleCase('c8-falta', ['files' => ['hooks.php' => pluginsHooks("    \$texto = t('A sentence without translation');")]], 'C8', 'hooks.php', false);
    contains('A sentence without translation', pluginsMessages($report));
    pluginsCleanCase('c8-bien', ['files' => ['hooks.php' => pluginsHooks("    \$texto = t('A sentence with translation');")], 'lang' => ['A sentence with translation' => 'Una frase con traducción']]);
});

pluginsTest('prohibidos: shell_exec falla en rojo con su fichero y su línea', function (): void {
    $report = pluginsRuleCase('p1-shell', ['files' => ['hooks.php' => pluginsHooks("    shell_exec('ls');")]], 'P1', 'hooks.php');
    same(6, pluginsChecks($report, 'P1')[0]['line']);
});

pluginsTest('prohibidos: eval, assert con cadena, create_function, comillas invertidas, base64 con eval, include de la petición y escribir fuera', function (): void {
    $cases = [
        'p1-eval' => "    eval('return 1;');",
        'p1-assert' => "    assert('true');",
        'p1-create' => "    \$f = create_function('', 'return 1;');",
        'p1-backtick' => "    \$x = `ls`;",
        'p1-base64' => "    \$x = base64_decode('cmV0dXJuIDE7');\n    eval(\$x);",
        'p1-include' => "    include \$_GET['f'];",
        'p1-fuera' => "    file_put_contents(__DIR__ . '/../../x.php', 'x');",
        'p1-absoluta' => "    file_put_contents('/tmp/x.txt', 'x');",
        'p1-system' => "    system('ls');",
        'p1-proc' => "    proc_open('ls', [], \$pipes);",
        'p1-barra' => "    \\exec('ls');",
    ];
    foreach ($cases as $id => $code) {
        pluginsRuleCase($id, ['files' => ['hooks.php' => pluginsHooks($code)]], 'P1', 'hooks.php');
    }
});

pluginsTest('prohibidos: una palabra prohibida dentro de un comentario, de una cadena de ayuda o de un método no salta', function (): void {
    $code = "    // shell_exec('x'); eval('1'); mail('a', 'b', 'c'); sleep(1); header('X: 1');\n"
        . "    /* exec('x'); system('x'); echo 1; curl_init(); */\n"
        . "    \$ayuda = 'No uses shell_exec() ni eval() ni mail() ni `comillas` ni echo ni header() ni sleep(1)';\n"
        . "    \$otra = \"curl_init() y exec() y exit en una frase\";\n"
        . "    \$app->hooks()->on('x', static function () use (\$app): void {\n        \$app->log()->info('ok');\n    });\n"
        . "    \$app->log()->exec();\n"
        . "    Aifec\\Db\\Algo::system();\n";
    pluginsCleanCase('p1-comentarios', ['files' => ['hooks.php' => pluginsHooks($code)]]);
});

pluginsTest('M1: un contrato sin todas sus listas falla', function (): void {
    $manifest = ['name' => 'Test Plugin', 'version' => '1.0.0', 'description' => 'A plugin for tests', 'contract' => ['tables' => [], 'personal_data' => [], 'capabilities' => []]];
    $report = pluginsRuleCase('m1-sin-hosts', ['raw_manifest' => (string) json_encode($manifest)], 'M1', 'plugin.json', false);
    contains('hosts', pluginsMessages($report));
});

pluginsTest('M1: una tabla declarada que es del sistema, o un espacio de nombres del sistema, fallan', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    $app = pluginsApp($root);
    pluginsMake($root, 'm1-tabla', ['manifest' => ['contract' => ['tables' => ['products']]]]);
    same(['M1'], pluginsFailed(pluginsStatic($app, $root, 'm1-tabla')));
    pluginsMake($root, 'm1-espacio', ['manifest' => ['namespace' => 'Aifec\\Modules\\Falso']]);
    same(['M1'], pluginsFailed(pluginsStatic($app, $root, 'm1-espacio')));
});

pluginsTest('C9 estático: una migración que crea una tabla sin declararla falla', function (): void {
    pluginsRuleCase('c9-estatico', ['files' => ['migrations/0001_x.php' => pluginsMigration('c9_declarada', 'c9_suelta')], 'manifest' => ['contract' => ['tables' => ['c9_declarada']]]], 'C9', 'migrations/0001_x.php');
});

pluginsTest('un plugin limpio pasa todas las reglas estáticas con 100', function (): void {
    $report = pluginsCleanCase('limpio', []);
    same(100, $report['score']);
    same(false, $report['dynamic']);
    same(['M1', 'P1', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C8', 'C9'], array_map(static function (array $c): string {
        return $c['rule'];
    }, $report['checks']));
});

pluginsTest('C9 dinámico: una tabla que el plugin crea y no declara se detecta y se ve que queda al desinstalar', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'suelta', ['files' => ['migrations/0001_x.php' => pluginsMigration('suelta_data', 'suelta_extra')], 'manifest' => ['contract' => ['tables' => ['suelta_data']]]]);
    $report = pluginsSvc($app)->validate('suelta');
    same(['C9'], pluginsFailed($report));
    same(true, $report['dynamic']);
    $text = pluginsMessages($report);
    contains('suelta_extra', $text);
    contains('aparece al activar el plugin', $text);
    contains('Deja la tabla «suelta_extra»', $text);
});

pluginsTest('C9 dinámico: un plugin que declara todo y se desinstala limpio deja el mismo esquema y los mismos ajustes', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'limpia', ['files' => ['migrations/0001_x.php' => pluginsMigration('limpia_data')], 'manifest' => ['contract' => ['tables' => ['limpia_data']]]]);
    $report = pluginsSvc($app)->validate('limpia');
    same([], pluginsFailed($report), pluginsMessages($report));
    same(true, $report['ok']);
    same(100, $report['score']);
    check($report['measured']['dynamic']['schema']['tables'] > 5, 'comparó el esquema entero');
    check(!$app->db()->tableExists('limpia_data'), 'y el sitio de verdad no se ha tocado');
    check(is_dir($root . '/plugins/limpia'), 'el plugin sigue instalado en el sitio de verdad');
    same(null, $app->settings()->get('modules.active'));
});

pluginsTest('C10: con un oyente que lanza, las páginas de referencia siguen dando 200', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $code = "    \$app->hooks()->filter('home.sections', static function (\$sections) {\n        throw new \\RuntimeException('falla siempre');\n    });";
    pluginsMake($root, 'lanza', ['files' => ['hooks.php' => pluginsHooks($code)]]);
    $report = pluginsSvc($app)->validate('lanza');
    same(true, $report['ok'], pluginsMessages($report));
    same([], pluginsFailed($report));
    same(['C10'], pluginsFailed($report, 'warning'));
    same(200, $report['measured']['dynamic']['pages']['home']['status']);
    same(1, $report['measured']['dynamic']['listeners_forced']);
    contains('falla siempre', pluginsMessages($report));
});

pluginsTest('C10: un plugin que falla al cargarse da error en esa regla', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'no-arranca', ['files' => ['hooks.php' => pluginsHooks("    throw new \\RuntimeException('no arranca nunca');")]]);
    $report = pluginsSvc($app)->validate('no-arranca');
    same(['C10'], pluginsFailed($report));
    contains('no arranca nunca', pluginsMessages($report));
});

pluginsTest('C1 medido: un plugin con la capacidad que baja el total sin una línea de ajuste con nombre falla', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    $app = pluginsApp($root);
    $code = "    \$app->hooks()->filter('product.price', static function (\$block) {\n        if (is_array(\$block) && isset(\$block['amount'])) {\n            \$block['amount'] = (int) \$block['amount'] - 100;\n        }\n\n        return \$block;\n    });";
    pluginsMake($root, 'rebaja', ['manifest' => ['requires' => ['modules' => ['catalog']], 'contract' => ['capabilities' => ['pricing']]], 'files' => ['hooks.php' => pluginsHooks($code)]]);
    $report = pluginsSvc($app)->validate('rebaja');
    same(['C1'], pluginsFailed($report), pluginsMessages($report));
    contains('sin una línea de ajuste con nombre', pluginsMessages($report));
    $cart = $report['measured']['dynamic']['cart'];
    check($cart['with']['lines_total'] < $cart['without']['lines_total'], 'el total bajó');
});

pluginsTest('C1 medido: un plugin que añade un ajuste con nombre pasa, y sin nombre falla', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    $app = pluginsApp($root);
    $adjust = static function (string $label): string {
        return "    \$app->hooks()->filter('product.price', static function (\$block) {\n        if (is_array(\$block)) {\n            \$block['adjustments'] = [['label' => '" . $label . "', 'amount' => -100, 'source' => 'regalo']];\n        }\n\n        return \$block;\n    });";
    };
    $manifest = ['requires' => ['modules' => ['catalog']], 'contract' => ['capabilities' => ['pricing']]];
    pluginsMake($root, 'regala', ['manifest' => $manifest, 'files' => ['hooks.php' => pluginsHooks($adjust('Gift'))], 'lang' => ['Gift' => 'Regalo']]);
    $report = pluginsSvc($app)->validate('regala');
    same([], pluginsFailed($report), pluginsMessages($report));
    same(1, count($report['measured']['dynamic']['cart']['with']['adjustments']));
    check($report['measured']['dynamic']['cart']['with']['total'] < $report['measured']['dynamic']['cart']['with']['lines_total'], 'el ajuste se ve en el total');
    pluginsMake($root, 'regala-mudo', ['manifest' => $manifest, 'files' => ['hooks.php' => pluginsHooks($adjust(''))]]);
    $mute = pluginsSvc($app)->validate('regala-mudo');
    same(['C1'], pluginsFailed($mute), pluginsMessages($mute));
});

pluginsTest('C3 medido: una página que crece mucho con el plugin da un aviso, no un error', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $code = "    \$app->hooks()->filter('home.sections', static function (\$sections) {\n        \$sections = is_array(\$sections) ? \$sections : [];\n        \$sections[] = ['id' => 'relleno', 'order' => 1, 'html' => str_repeat('<p>relleno</p>', 1500)];\n\n        return \$sections;\n    });";
    pluginsMake($root, 'pesado', ['files' => ['hooks.php' => pluginsHooks($code)]]);
    $report = pluginsSvc($app)->validate('pesado');
    same(true, $report['ok'], pluginsMessages($report));
    same(['C3'], pluginsFailed($report, 'warning'));
    $home = $report['measured']['dynamic']['pages']['home'];
    check($home['bytes_with'] > $home['bytes'] + 10000, 'la página pesa más');
    contains('crece', pluginsMessages($report));
});

pluginsTest('con código prohibido el validador no ejecuta el plugin: la parte dinámica se salta', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    pluginsMake($root, 'no-se-ejecuta', ['files' => ['hooks.php' => pluginsHooks("    shell_exec('ls');")]]);
    $report = pluginsSvc($app)->validate('no-se-ejecuta');
    same(['P1'], pluginsFailed($report));
    same(false, $report['dynamic']);
    same('skipped', $report['measured']['dynamic']);
    check(!$app->db()->tableExists('no_se_ejecuta'), 'nada se creó');
});

pluginsTest('el informe se guarda con su huella y se reutiliza mientras los ficheros no cambien', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    pluginsMake($root, 'guardado');
    $first = $svc->validate('guardado');
    same($first, $svc->reports()['guardado']);
    $stored = $svc->validate('guardado', true);
    same($first['fingerprint'], $stored['fingerprint']);
    file_put_contents($root . '/plugins/guardado/hooks.php', pluginsHooks("    // un cambio\n"));
    $changed = $svc->validate('guardado', true);
    check($changed['fingerprint'] !== $first['fingerprint'], 'cambió la huella y se validó de nuevo');
    same('1.0.0', $changed['version']);
    same(false, $svc->validate('no-hay')['ok']);
});
