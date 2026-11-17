<?php
/**
 * AIFEC — «Diseño → Portada» enseña cada sección con su nombre, no con su identificador. Antes salía «catalog.featured»,
 * «catalog.new» y «catalog.sale» en una pantalla que usa el dueño de la tienda (lo encontró quien escribía el manual, 13/11).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/ThemesKit.php';

themesTest('cada sección de la portada lleva su nombre: el que da el módulo, o el primer encabezado de lo que pinta, o su identificador', function (): void {
    $app = themesApp(themesRoot());
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'mod.con-nombre', 'order' => 10, 'title' => '  Contenido de la portada ', 'html' => '<div><h2>Otro título</h2></div>'];
        $sections[] = ['id' => 'mod.con-encabezado', 'order' => 20, 'html' => '<section><h2 id="x">Productos <em>destacados</em> &amp; novedades</h2><p>…</p></section>'];
        $sections[] = ['id' => 'mod.sin-nada', 'order' => 30, 'html' => '<p>Solo un párrafo</p>'];
        $sections[] = ['id' => 'mod.nombre-raro', 'order' => 40, 'title' => ['no', 'es', 'texto'], 'html' => '<h3>Del encabezado</h3>'];

        return $sections;
    });

    $names = [];
    foreach (themesSvc($app)->homeSections() as $section) {
        $names[$section['id']] = $section['title'];
    }

    same('Contenido de la portada', $names['mod.con-nombre'], 'manda el nombre que da el módulo');
    same('Productos destacados & novedades', $names['mod.con-encabezado'], 'sin nombre, el primer encabezado, sin etiquetas ni entidades');
    same('mod.sin-nada', $names['mod.sin-nada'], 'sin nombre ni encabezado, el identificador');
    same('Del encabezado', $names['mod.nombre-raro'], 'un nombre que no es texto no cuenta');
});

themesTest('personalizar un tema que no es el activo enseña sus opciones traducidas (su catálogo no estaba cargado)', function (): void {
    // Sobre la instalación real: el tema activo es «base» y se personaliza «atelier», que trae opciones propias.
    $app = testApp(['modules' => ['themes']]);
    $web = adminClient($app);
    same('base', themesSvc($app)->activeSlug());

    $page = $web->get('/admin/themes/customise/atelier');

    same(200, $page->status);
    $es = require $app->root() . '/themes/atelier/lang/es.php';
    foreach (['Show the notice strip', 'Product gallery', 'Campaign image', 'Fine serif'] as $label) {
        check(isset($es[$label]), 'el tema traduce «' . $label . '»');
        contains(e($es[$label]), $page->body, 'la etiqueta sale en castellano');
        notContains('>' . $label . '<', $page->body, 'y no en inglés: ' . $label);
    }
    // Lo que hereda del tema base sigue traducido.
    contains('Cabecera', $page->body);
});

themesTest('la pantalla de la portada enseña el nombre de la sección y, en pequeño, su identificador', function (): void {
    $app = themesApp(themesRoot());
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'mod.ofertas', 'order' => 10, 'html' => '<section><h2>Ofertas de la semana <script>x</script></h2></section>'];

        return $sections;
    });

    $page = adminClient($app)->get('/admin/themes/home');

    same(200, $page->status);
    contains('Ofertas de la semana', $page->body);
    contains('<span class="af-mono af-muted">mod.ofertas</span>', $page->body, 'el identificador sigue a la vista para quien lo necesite');
    notContains('<script>x</script>', $page->body, 'el nombre sale como texto');
    contains('name="order[]" value="mod.ofertas"', $page->body, 'lo que se guarda sigue siendo el identificador');
});
