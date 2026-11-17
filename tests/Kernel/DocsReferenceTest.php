<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Cli\DocsReference;

/**
 * La referencia generada: órdenes, ajustes, rutas, permisos, ganchos y tablas salen de lo que el sistema declara.
 */

/**
 * @param array<int,array<string,mixed>> $rows
 *
 * @return array<string,mixed>|null
 */
function docsRow(array $rows, string $field, string $value): ?array
{
    foreach ($rows as $row) {
        if ($row[$field] === $value) {
            return $row;
        }
    }

    return null;
}

/** @return string[] */
function docsNames(array $rows, string $field): array
{
    return array_map(static function (array $row) use ($field): string {
        return (string) $row[$field];
    }, $rows);
}

/** Cuenta las barras verticales que separan columnas (las escapadas con barra invertida no cuentan). */
function docsColumns(string $line): int
{
    return (int) preg_match_all('/(?<!\\\\)\|/', $line) - 1;
}

test('commands: salen las órdenes del núcleo con su ayuda y ordenadas', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::commands($app);
    foreach (['migrate', 'setting:set', 'i18n:check'] as $name) {
        $row = docsRow($rows, 'name', $name);
        check($row !== null, 'falta ' . $name);
        check($row['help'] !== '', 'sin ayuda: ' . $name);
    }
    contains('setting:set <key> <value>', (string) docsRow($rows, 'name', 'setting:set')['help']);
    $names = docsNames($rows, 'name');
    $sorted = $names;
    sort($sorted, SORT_STRING);
    same($sorted, $names, 'ordenadas por nombre');
    check(docsRow($rows, 'name', 'version') !== null, 'las órdenes que Console atiende por su cuenta también salen');
});

test('commands: cada orden dice quién la declara y con un módulo cargado no desaparece ninguna del núcleo', function (): void {
    $base = testApp();
    $base->boot();
    $before = docsNames(DocsReference::commands($base), 'name');
    $app = testApp(['modules' => ['tax']]);
    $app->boot();
    $rows = DocsReference::commands($app);
    $after = docsNames($rows, 'name');
    same([], array_values(array_diff($before, $after)), 'no desaparece ninguna orden del núcleo');
    same('core', docsRow($rows, 'name', 'migrate')['module']);
    same('core', docsRow($rows, 'name', 'i18n:check')['module']);
    same('core', docsRow($rows, 'name', 'docs:reference')['module'], 'la orden de esta referencia también está documentada');
    foreach ($rows as $row) {
        check($row['module'] !== '', 'sin dueño: ' . $row['name']);
    }
});

test('settings: store.name sale con su módulo y su pestaña', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::settings($app);
    $row = docsRow($rows, 'key', 'store.name');
    check($row !== null, 'falta store.name');
    same('core', $row['module']);
    same('Identity', $row['tab']);
    same('text', $row['type']);
    same('"AIFEC"', $row['default']);
    same('Shop name', $row['label']);
    same(1, count(array_filter($rows, static function (array $r): bool {
        return $r['key'] === 'store.name';
    })), 'cada ajuste una sola vez');
});

test('settings: un secreto no enseña su valor, y los campos que no guardan nada no salen', function (): void {
    $rows = DocsReference::settingRows([
        'prueba' => ['tabs' => [
            'general' => ['label' => 'General', 'fields' => [
                'prueba.clave' => ['type' => 'secret', 'label' => 'Key', 'default' => 'clave-de-mentira'],
                'prueba.otra' => ['type' => 'password', 'label' => 'Other', 'default' => 'clave-de-mentira'],
                'prueba.titulo' => ['type' => 'heading', 'label' => 'A title'],
                'prueba.nota' => ['type' => 'html', 'html' => '<p>Nota</p>'],
                'prueba.volatil' => ['type' => 'text', 'label' => 'Not stored', 'store' => false],
                'prueba.largo' => ['type' => 'tags', 'label' => 'Long', 'default' => array_fill(0, 40, 'ñandú & cía')],
                'prueba.sin' => ['type' => 'number', 'label' => 'No default'],
                'prueba.vacio' => ['type' => 'toggle', 'label' => 'Off', 'default' => false],
            ]],
        ]],
        'aaa' => ['tabs' => ['uno' => ['fields' => ['aaa.zeta' => ['type' => 'text'], 'aaa.alfa' => ['type' => 'text']]]]],
    ]);
    same(['aaa.alfa', 'aaa.zeta', 'prueba.clave'], array_slice(docsNames($rows, 'key'), 0, 3), 'ordenadas por módulo y por clave');
    $rows = array_values(array_filter($rows, static function (array $r): bool {
        return $r['module'] === 'prueba';
    }));
    $keys = docsNames($rows, 'key');
    same(['prueba.clave', 'prueba.largo', 'prueba.otra', 'prueba.sin', 'prueba.vacio'], $keys, 'ni heading, ni html, ni store => false');
    same('(secret)', docsRow($rows, 'key', 'prueba.clave')['default']);
    same('(secret)', docsRow($rows, 'key', 'prueba.otra')['default']);
    notContains('clave-de-mentira', json_encode($rows, JSON_UNESCAPED_UNICODE));
    same('', docsRow($rows, 'key', 'prueba.sin')['default']);
    same('false', docsRow($rows, 'key', 'prueba.vacio')['default']);
    $long = (string) docsRow($rows, 'key', 'prueba.largo')['default'];
    same(58, mb_strlen($long), 'un valor largo se acorta');
    check(mb_substr($long, -1) === '…', 'y lo dice');
});

test('routes: el panel y la portada salen con su área y su intermediario', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::routes($app);
    $profile = docsRow($rows, 'path', '/admin/profile');
    check($profile !== null, 'falta /admin/profile');
    same('admin', $profile['area']);
    check(in_array('admin', $profile['middleware'], true), 'intermediario admin');
    check(in_array('GET', $profile['methods'], true), 'método GET');
    same('admin.profile', $profile['name']);
    $home = docsRow($rows, 'path', '/');
    same('front', $home['area']);
    same('home', $home['name']);
    same([], $home['middleware']);
});

test('routes: el área sale del primer tramo de la ruta y salen ordenadas por área y ruta', function (): void {
    $app = testApp();
    $app->boot();
    $app->router()->get('/api/ping', static function (): void {
    });
    $app->router()->get('/administrador', static function (): void {
    });
    $rows = DocsReference::routes($app);
    same('api', docsRow($rows, 'path', '/api/ping')['area']);
    same('front', docsRow($rows, 'path', '/administrador')['area'], '«/administrador» no es del panel');
    $prev = null;
    foreach ($rows as $row) {
        $key = $row['area'] . "\0" . $row['path'];
        check($prev === null || strcmp($prev, $key) <= 0, 'desordenada en ' . $row['path']);
        $prev = $key;
    }
    $areas = array_values(array_unique(docsNames($rows, 'area')));
    same(['admin', 'api', 'front'], $areas);
});

test('capabilities: settings.edit existe y dice dónde se pide', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::capabilities($app);
    $row = docsRow($rows, 'capability', 'settings.edit');
    check($row !== null, 'falta settings.edit');
    same('settings', $row['module']);
    check(in_array('declared in core', $row['used_by'], true), 'declarado en el admin.php del núcleo');
    check(in_array('settings core', $row['used_by'], true), 'lo pide la pantalla de ajustes');
    check(in_array('menu core', $row['used_by'], true), 'lo pide el menú');
    $users = docsRow($rows, 'capability', 'team.edit');
    check($users !== null && in_array('resource users', $users['used_by'], true), 'el recurso users pide team.edit');
    $names = docsNames($rows, 'capability');
    $sorted = $names;
    sort($sorted, SORT_STRING);
    same($sorted, $names, 'ordenados');
});

test('capabilities: cada permiso de los papeles sembrados sale una sola vez', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::capabilities($app);
    $names = docsNames($rows, 'capability');
    $seeded = [];
    foreach ($app->db()->select('SELECT code, caps FROM {roles} WHERE ' . $app->db()->ident('system') . ' = 1') as $role) {
        foreach (json_decode((string) $role['caps'], true) as $cap) {
            $seeded[$cap] = true;
        }
    }
    check(count($seeded) > 10, 'hay papeles sembrados');
    foreach (array_keys($seeded) as $cap) {
        same(1, count(array_keys($names, (string) $cap, true)), 'una sola vez: ' . $cap);
    }
    $all = docsRow($rows, 'capability', '*');
    check(in_array('role admin', $all['used_by'], true), 'el papel admin lo tiene todo');
    same(count($names), count(array_unique($names)), 'ninguno repetido');
});

test('hooks: un análisis con token_get_all de unos ficheros de pega', function (): void {
    $dir = tmpDir();
    mkdir($dir . '/src', 0775, true);
    file_put_contents($dir . '/src/a.php', <<<'PHP'
<?php
$app->hooks()->fire('shop.opened', 1);
$app->hooks()->apply('shop.price', $price);
$app->hooks()->on('shop.opened', $listener);
$app->hooks()->filter('shop.price', $listener);
$app->hooks()->seal('shop.price', 'pricing');
$app->hooks()->fire($name);
$app->hooks()->fire('shop.' . $suffix);
// $app->hooks()->fire('in.comment');
/* $app->hooks()->on('in.block'); */
$text = "->fire('in.double')";
$text = '$app->hooks()->on(\'in.single\')';
$app->hooks()->slot('only.heard', $listener);
$app->hooks()
    ->fire(
        'multi.line'
    );
$this->apply($notAHook, 1);
$app->view()->render('core::admin/layout', []);
PHP
    );
    file_put_contents($dir . '/src/b.php', "<?php\necho hook('page.top');\nfunction hook(string \$n) {}\n");
    $rows = DocsReference::hooks($dir, ['src']);

    $opened = docsRow($rows, 'hook', 'shop.opened');
    same('action', $opened['kind']);
    same(['src/a.php:2'], $opened['fired_in']);
    same(['src/a.php:4'], $opened['listened_in']);
    same('', $opened['sealed']);

    $price = docsRow($rows, 'hook', 'shop.price');
    same('filter', $price['kind']);
    same(['src/a.php:3'], $price['fired_in']);
    same(['src/a.php:5'], $price['listened_in']);
    same('pricing', $price['sealed']);

    same('unknown', docsRow($rows, 'hook', 'only.heard')['kind'], 'solo se escucha: no se sabe');
    same(['src/a.php:13'], docsRow($rows, 'hook', 'only.heard')['listened_in']);
    same('action', docsRow($rows, 'hook', 'multi.line')['kind']);
    same(['src/a.php:15'], docsRow($rows, 'hook', 'multi.line')['fired_in'], 'la línea es la de la llamada');
    same('slot', docsRow($rows, 'hook', 'page.top')['kind'], 'el ayudante hook() de las plantillas pinta un hueco');
    same(['src/b.php:2'], docsRow($rows, 'hook', 'page.top')['fired_in']);

    $dynamic = docsRow($rows, 'hook', 'dynamic');
    check($dynamic !== null, 'falta la fila dynamic');
    same(['src/a.php:7', 'src/a.php:8'], $dynamic['fired_in']);
    same([], $dynamic['listened_in']);
    same('dynamic', $dynamic['kind']);
    same('dynamic', end($rows)['hook'], 'la fila dynamic va al final');

    foreach (['in.comment', 'in.block', 'in.double', 'in.single', 'core::admin/layout'] as $ghost) {
        same(null, docsRow($rows, 'hook', $ghost), 'no cuenta: ' . $ghost);
    }
    same(null, docsRow($rows, 'hook', 'shop.'), 'un nombre a medio montar no es un literal');
});

test('hooks: nunca recorre tests, storage ni sites, y sin llamadas dinámicas no hay fila dynamic', function (): void {
    $dir = tmpDir();
    foreach (['src', 'src/tests', 'src/storage', 'src/sites', 'src/real'] as $sub) {
        mkdir($dir . '/' . $sub, 0775, true);
    }
    foreach (['tests' => 'skip.tests', 'storage' => 'skip.storage', 'sites' => 'skip.sites', 'real' => 'keep.real'] as $sub => $hook) {
        file_put_contents($dir . '/src/' . $sub . '/x.php', "<?php\n\$app->hooks()->fire('" . $hook . "');\n");
    }
    file_put_contents($dir . '/src/notes.txt', "->fire('skip.text')");
    $rows = DocsReference::hooks($dir, ['src', 'no-existe']);
    same(['keep.real'], docsNames($rows, 'hook'));
    same(['src/real/x.php:2'], $rows[0]['fired_in']);
});

test('hooks: sobre el proyecto real settings.saved es una acción disparada en el panel y en la consola', function (): void {
    $app = testApp();
    $rows = DocsReference::hooks($app->root());
    $row = docsRow($rows, 'hook', 'settings.saved');
    check($row !== null, 'falta settings.saved');
    same('action', $row['kind']);
    $files = array_map(static function (string $where): string {
        return substr($where, 0, (int) strrpos($where, ':'));
    }, $row['fired_in']);
    check(in_array('core/src/Admin/SettingsController.php', $files, true), 'se dispara en SettingsController');
    check(in_array('core/cli.php', $files, true), 'se dispara en cli.php');
    foreach ($row['fired_in'] as $where) {
        check(preg_match('#^[a-z]+/.+\.php:\d+$#', $where) === 1, 'ruta relativa con línea: ' . $where);
    }
    // Lo sellado con una capacidad y lo que se pinta como hueco de plantilla.
    same('pricing', docsRow($rows, 'hook', 'product.price')['sealed']);
    same('slot', docsRow($rows, 'hook', 'admin.foot')['kind']);
    same('filter', docsRow($rows, 'hook', 'admin.status')['kind']);
    // Este mismo fichero de pruebas no cuenta: tests/ no se recorre.
    foreach ($rows as $r) {
        foreach (array_merge($r['fired_in'], $r['listened_in']) as $where) {
            notContains('/tests/', $where);
        }
    }
});

test('tables: las del núcleo salen con su módulo y sus columnas reales', function (): void {
    $app = testApp();
    $app->boot();
    $rows = DocsReference::tables($app);
    foreach (['settings', 'users', 'migrations'] as $table) {
        $row = docsRow($rows, 'table', $table);
        check($row !== null, 'falta ' . $table);
        same('core', $row['module'], $table);
        check($row['columns'] !== [], 'columnas de ' . $table);
    }
    check(in_array('email', docsRow($rows, 'table', 'users')['columns'], true), 'users.email');
    $names = docsNames($rows, 'table');
    $sorted = $names;
    sort($sorted, SORT_STRING);
    same($sorted, $names, 'ordenadas');
    foreach ($names as $name) {
        notContains('af_', $name, 'sin el prefijo');
    }
});

test('tables: la tabla de un módulo dice qué módulo la crea', function (): void {
    $app = testApp(['modules' => ['tax']]);
    $app->boot();
    $rows = DocsReference::tables($app);
    same('tax', docsRow($rows, 'table', 'tax_rates')['module']);
    same('tax', docsRow($rows, 'table', 'tax_classes')['module']);
    same('core', docsRow($rows, 'table', 'users')['module']);
});

test('markdown: escapa la barra vertical, los saltos de línea y las listas, con las mismas columnas en todas las filas', function (): void {
    $md = DocsReference::markdown('Título & más', "Introducción.\nSegunda línea.", ['a' => 'Uno | dos', 'b' => 'Lista', 'c' => 'Texto'], [
        ['a' => 'x|y', 'b' => ['uno', 'd|os', 'tres'], 'c' => "línea 1\nlínea 2\r\nlínea 3"],
        ['a' => 'ñandú <b> & cía', 'b' => [], 'c' => null],
        ['a' => '{id:\d+}', 'b' => true],
    ]);
    contains("# Título & más\n\nIntroducción.\nSegunda línea.\n\n", $md);
    contains('Generado con `php aifec docs:reference`. No lo edites a mano.', $md);
    contains('x\|y', $md);
    contains('uno<br>d\|os<br>tres', $md);
    contains('línea 1<br>línea 2<br>línea 3', $md);
    contains('ñandú &lt;b&gt; & cía', $md);
    contains('{id:\\\\d+}', $md, 'la barra invertida se escapa');
    contains('| Uno \| dos | Lista | Texto |', $md);
    contains('| --- | --- | --- |', $md);
    $lines = array_values(array_filter(explode("\n", $md), static function (string $l): bool {
        return strncmp($l, '|', 1) === 0;
    }));
    same(5, count($lines), 'cabecera, separador y tres filas');
});

test('markdown: todas las filas tienen las mismas columnas y una tabla vacía sigue siendo una tabla', function (): void {
    $md = DocsReference::markdown('T', '', ['a', 'b'], [['a' => 'solo a'], ['a' => '1|2|3', 'b' => "x\ny"], []]);
    $lines = array_values(array_filter(explode("\n", $md), static function (string $l): bool {
        return strncmp($l, '|', 1) === 0;
    }));
    same(5, count($lines));
    foreach ($lines as $line) {
        same(2, docsColumns($line), 'columnas en: ' . $line);
    }
    $empty = DocsReference::markdown('Vacía', '', ['a' => 'A'], []);
    contains("| A |\n| --- |\n", $empty);
    check(substr($empty, -1) === "\n", 'acaba en salto de línea');
    notContains("\n\n\n", $empty);
});

test('write: crea los siete ficheros, dos pasadas dan los mismos bytes y el índice los enlaza', function (): void {
    $app = testApp();
    $app->boot();
    $dir = tmpDir() . '/sub/referencia';
    $result = DocsReference::write($app, $dir);
    $expected = ['consola.md', 'ajustes.md', 'rutas.md', 'permisos.md', 'ganchos.md', 'tablas.md', 'indice.md'];
    same($expected, array_keys($result['files']));
    same(str_replace('\\', '/', $dir), $result['dir']);
    $hashes = [];
    foreach ($expected as $name) {
        check(is_file($dir . '/' . $name), 'falta ' . $name);
        $hashes[$name] = md5_file($dir . '/' . $name);
    }
    $present = array_values(array_diff((array) scandir($dir), ['.', '..']));
    sort($present);
    $sortedExpected = $expected;
    sort($sortedExpected);
    same($sortedExpected, $present, 'ni un temporal de sobra');

    $again = DocsReference::write($app, $dir);
    same($result['files'], $again['files']);
    foreach ($expected as $name) {
        same($hashes[$name], md5_file($dir . '/' . $name), 'mismos bytes: ' . $name);
    }

    $index = (string) file_get_contents($dir . '/indice.md');
    foreach (array_slice($expected, 0, 6) as $name) {
        contains('[' . $name . '](' . $name . ')', $index);
        contains('| ' . $result['files'][$name] . ' |', $index, 'con su número de filas: ' . $name);
    }
    foreach ($expected as $name) {
        $text = (string) file_get_contents($dir . '/' . $name);
        notContains(date('Y-m-d'), $text, 'sin fecha: ' . $name);
        notContains(date('d/m/Y'), $text, 'sin fecha: ' . $name);
        notContains('2026-11-12', $text, 'sin la hora de las pruebas: ' . $name);
        contains('Generado con `php aifec docs:reference`. No lo edites a mano.', $text);
    }
    contains('| settings.edit |', (string) file_get_contents($dir . '/permisos.md'));
    contains('| store.name |', (string) file_get_contents($dir . '/ajustes.md'));
    contains('| /admin/profile |', (string) file_get_contents($dir . '/rutas.md'));
    contains('| settings.saved |', (string) file_get_contents($dir . '/ganchos.md'));
    contains('| users |', (string) file_get_contents($dir . '/tablas.md'));
    contains('| migrate |', (string) file_get_contents($dir . '/consola.md'));
    notContains('(secret)x', (string) file_get_contents($dir . '/ajustes.md'));
});

test('write: si en lugar de la carpeta hay un fichero, falla con una excepción y no deja nada', function (): void {
    $app = testApp();
    $app->boot();
    $file = tmpDir() . '/es-un-fichero';
    file_put_contents($file, 'x');
    throws(function () use ($app, $file): void {
        DocsReference::write($app, $file);
    }, \RuntimeException::class);
    same('x', file_get_contents($file), 'el fichero no se toca');
    throws(function () use ($app): void {
        DocsReference::write($app, '');
    }, \RuntimeException::class);
});

test('la orden docs:reference escribe en una carpeta temporal y sale con 0', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    check(isset($commands['docs:reference']), 'la orden existe');
    check(strpos($commands['docs:reference']['help'], 'docs:reference') !== false, 'con su ayuda');
    $dir = tmpDir() . '/ref';
    $io = new Console(false);
    same(0, $commands['docs:reference']['run']($app, ['out' => $dir], $io));
    contains('REFERENCE: 7 files', $io->output);
    contains('consola.md  ', $io->output);
    contains('indice.md  6', $io->output);
    foreach (['consola', 'ajustes', 'rutas', 'permisos', 'ganchos', 'tablas', 'indice'] as $name) {
        check(is_file($dir . '/' . $name . '.md'), 'falta ' . $name . '.md');
    }
    check(isset((new Console(false))->commands($app)['docs:reference']), 'y la consola la conoce');
});

test('la orden docs:reference sale con 1 si no puede escribir, y con 2 si --out va sin valor', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    $file = tmpDir() . '/un-fichero';
    file_put_contents($file, 'x');
    $io = new Console(false);
    same(1, $commands['docs:reference']['run']($app, ['out' => $file], $io));
    contains('ERROR:', $io->output);
    notContains('REFERENCE:', $io->output);
    $io = new Console(false);
    same(2, $commands['docs:reference']['run']($app, ['out' => true], $io));
    contains('Usage: docs:reference', $io->output);
});
