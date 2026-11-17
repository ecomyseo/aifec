<?php
/**
 * AIFEC — pruebas del servicio «themes»: la lista de temas, activar, las opciones y la portada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Themes\ThemeException;

require_once __DIR__ . '/ThemesKit.php';

themesTest('all() lista el tema base con su vista previa, de quién hereda, si está activo y el resumen del validador', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo');
    $app = themesApp($root);
    $rows = [];
    foreach (themesSvc($app)->all() as $row) {
        $rows[$row['slug']] = $row;
    }
    same(['base', 'hijo'], array_keys($rows));
    same('Base', $rows['base']['name']);
    same(true, $rows['base']['preview'], 'el tema base tiene vista previa');
    same(true, $rows['base']['active']);
    same(true, $rows['base']['valid']);
    same('', $rows['base']['parent']);
    same(true, $rows['base']['validation']['ok']);
    check($rows['base']['validation']['score'] > 80, 'puntuación del tema base: ' . $rows['base']['validation']['score']);
    same('base', $rows['hijo']['parent']);
    same(true, $rows['hijo']['preview'], 'un hijo sin vista previa usa la del padre');
    same(false, $rows['hijo']['active']);
});

themesTest('un theme.json roto no tumba la lista: sale como no válido y una carpeta sin theme.json no sale', function (): void {
    $root = themesRoot();
    mkdir($root . '/themes/roto', 0777, true);
    file_put_contents($root . '/themes/roto/theme.json', '{ esto no es JSON');
    mkdir($root . '/themes/vacio', 0777, true);
    $app = themesApp($root);
    $rows = [];
    foreach (themesSvc($app)->all() as $row) {
        $rows[$row['slug']] = $row;
    }
    same(['base', 'roto'], array_keys($rows));
    same(false, $rows['roto']['valid']);
    same('roto', $rows['roto']['name']);
    same(true, $rows['base']['valid']);
    $report = themesSvc($app)->validate('roto', false);
    same(false, $report['ok']);
    same(['manifest'], themesFailed($report));
});

themesTest('activar un tema hijo cambia theme.active y la portada carga su hoja encima de la del padre', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $themes = themesSvc($app);
    $slug = $themes->generate(['slug' => 'tienda-nueva', 'name' => 'Tienda nueva', 'accent' => '#0b5394']);
    same('tienda-nueva', $slug);
    $themes->activate('tienda-nueva');
    same('tienda-nueva', themesSetting($app, 'theme.active'));
    same('tienda-nueva', $themes->activeSlug());
    $web = new TestClient(themesReboot($app, $root));
    $body = $web->get('/')->body;
    $base = strpos($body, '/t/base/');
    $child = strpos($body, '/t/tienda-nueva/');
    check($base !== false && $child !== false && $base < $child, 'la hoja del hijo va después de la del padre');
    contains('--accent:#0b5394', $body);
});

themesTest('activar un tema con un error bloqueante se rechaza y no cambia nada', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'sin-cabecera', ['templates/layout.php' => str_replace('page_head(', 'otra_cosa(', $layout)]);
    $app = themesApp($root);
    $themes = themesSvc($app);
    try {
        $themes->activate('sin-cabecera');
        check(false, 'tenía que lanzar');
    } catch (ThemeException $e) {
        contains('blocking errors', $e->getMessage());
        same(false, $e->report()['ok']);
        check(in_array('wrapper-head', themesFailed($e->report()), true), 'el informe dice qué falla');
    }
    same(null, themesSetting($app, 'theme.active'));
    same('base', $themes->activeSlug());
    throws(function () use ($themes): void {
        $themes->activate('no-existe');
    }, ThemeException::class);
    throws(function () use ($themes): void {
        $themes->activate('../etc');
    }, ThemeException::class);
});

themesTest('un tema que rompe la portada se rechaza al activar por las comprobaciones en vivo', function (): void {
    $root = themesRoot();
    themesChild($root, 'portada-rota', ['templates/home.php' => "<?php\nthrow new \\RuntimeException('portada rota');\n"]);
    $app = themesApp($root);
    $themes = themesSvc($app);
    $static = $themes->validate('portada-rota', false);
    same(true, $static['ok'], 'por ficheros no se ve');
    $report = $themes->validate('portada-rota', true);
    same(false, $report['ok']);
    check(in_array('live-home', themesFailed($report), true), 'la portada no responde 200');
    throws(function () use ($themes): void {
        $themes->activate('portada-rota');
    }, ThemeException::class);
    same(null, themesSetting($app, 'theme.active'));
});

themesTest('las comprobaciones en vivo no dejan nada atrás: ni ajustes, ni caché de página, ni la aplicación en curso cambiada', function (): void {
    $root = themesRoot();
    themesChild($root, 'vivo');
    $app = themesApp($root);
    $before = $app->db()->value('SELECT COUNT(*) FROM {settings}');
    $report = themesSvc($app)->validate('vivo', true);
    same(true, $report['ok'], json_encode(themesFailed($report)));
    same($app, App::current(), 'la aplicación en curso es la misma');
    same($before, $app->db()->value('SELECT COUNT(*) FROM {settings}'));
    same(null, themesSetting($app, 'theme.active'));
    $pages = glob($app->site()->path('cache') . '/data/*/*.php');
    same(0, count(is_array($pages) ? $pages : []), 'no se ha guardado ninguna página');
});

themesTest('guardar un color válido cambia la variable del <head>; uno que no es un color da error en ese campo y no se guarda', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $themes = themesSvc($app);
    same([], $themes->saveOptions('base', ['accent' => '#112233', 'radius' => '14']));
    same(['base' => ['accent' => '#112233', 'radius' => 14]], themesSetting($app, 'theme.options'));
    $body = (new TestClient(themesReboot($app, $root)))->get('/')->body;
    contains('--accent:#112233', $body);
    contains('--radius:14px', $body);

    $errors = $themes->saveOptions('base', ['accent' => 'url(javascript:1)', 'radius' => '999', 'font' => 'serif', 'ink' => '#000000']);
    same(['accent', 'radius'], array_keys($errors));
    same(['base' => ['accent' => '#112233', 'radius' => 14, 'font' => 'serif', 'ink' => '#000000']], themesSetting($app, 'theme.options'), 'lo válido se guarda y lo inválido no toca lo que había');
    $options = $themes->options('base');
    same('#112233', $options['values']['accent']);
    same('serif', $options['values']['font']);
    same('#b94a17', $options['defaults']['accent']);
});

themesTest('un valor igual al de fábrica no se guarda y una clave que el tema no tiene se ignora', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $themes = themesSvc($app);
    same([], $themes->saveOptions('base', ['accent' => '#b94a17', 'no_existe' => 'x', 'radius' => 10]));
    same(null, themesSetting($app, 'theme.options'), 'no queda nada pegado al valor de fábrica');
    $themes->saveOptions('base', ['accent' => '#112233']);
    $themes->saveOptions('base', ['accent' => '#B94A17']);
    same([], themesSetting($app, 'theme.options'), 'volver al valor de fábrica quita la opción');
    throws(function () use ($themes): void {
        $themes->saveOptions('no-existe', ['accent' => '#112233']);
    }, ThemeException::class);
});

themesTest('restablecer vuelve a los valores de fábrica solo de ese tema', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo');
    $app = themesApp($root);
    $themes = themesSvc($app);
    $themes->saveOptions('base', ['accent' => '#112233']);
    $themes->saveOptions('hijo', ['accent' => '#445566']);
    $themes->resetOptions('base');
    same(['hijo' => ['accent' => '#445566']], themesSetting($app, 'theme.options'));
    same('#b94a17', $themes->options('base')['values']['accent']);
    same('#445566', $themes->options('hijo')['values']['accent']);
});

themesTest('las opciones de un hijo heredan los campos del padre y su valor de fábrica manda', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo', [], ['settings' => ['colors' => ['fields' => ['accent' => ['default' => '#0b5394']]]]]);
    $app = themesApp($root);
    $options = themesSvc($app)->options('hijo');
    same('#0b5394', $options['defaults']['accent']);
    same('color', $options['fields']['accent']['type']);
    same('--accent', $options['fields']['accent']['var']);
    same('Colours', $options['groups']['colors']);
    check(isset($options['fields']['header_style']), 'hereda los campos que no cambia');
});

themesTest('portada: ordenar y ocultar las secciones que entregan los módulos', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'a-novedades', 'order' => 10, 'html' => '<p>NOVEDADES</p>'];
        $sections[] = ['id' => 'b-ofertas', 'order' => 20, 'html' => '<p>OFERTAS</p>'];
        $sections[] = ['id' => 'c-blog', 'order' => 30, 'html' => '<p>BLOG</p>'];
        $sections[] = ['id' => 'd-vacia', 'order' => 40, 'html' => '  '];

        return $sections;
    });
    $themes = themesSvc($app);
    same(['a-novedades', 'b-ofertas', 'c-blog'], array_column($themes->homeSections(), 'id'), 'la sección vacía no sale');
    $themes->saveHome(['c-blog', 'a-novedades', 'b-ofertas'], ['c-blog', 'b-ofertas']);
    same(['c-blog', 'a-novedades', 'b-ofertas'], themesSetting($app, 'home.order'));
    same(['a-novedades'], themesSetting($app, 'home.hidden'));
    $sections = $themes->homeSections();
    same(['c-blog', 'a-novedades', 'b-ofertas'], array_column($sections, 'id'));
    same([true, false, true], array_column($sections, 'visible'));
    $body = (new TestClient($app))->get('/')->body;
    check(strpos($body, 'BLOG') < strpos($body, 'OFERTAS'), 'el orden llega a la portada');
    notContains('NOVEDADES', $body, 'la sección oculta no sale');
});

themesTest('guardar la portada ignora identificadores que no existen y añade al final los que faltaban', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'uno', 'order' => 10, 'html' => '<p>UNO</p>'];
        $sections[] = ['id' => 'dos', 'order' => 20, 'html' => '<p>DOS</p>'];

        return $sections;
    });
    $themes = themesSvc($app);
    $themes->saveHome(['dos', 'fantasma', 'dos'], ['dos', 'fantasma', 'uno']);
    same(['dos', 'uno'], themesSetting($app, 'home.order'));
    same([], themesSetting($app, 'home.hidden'));
    $themes->saveHome(['uno', 'dos'], []);
    same(['uno', 'dos'], themesSetting($app, 'home.hidden'), 'sin ninguna marcada se ocultan todas');
});
