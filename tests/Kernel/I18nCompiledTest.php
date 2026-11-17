<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\I18n;

require_once __DIR__ . '/../Perf/helpers.php';

/** Dos carpetas lang/ de mentira y la carpeta de la caché. @return array{0:string,1:string,2:string} */
function i18nDirs(): array
{
    $base = tmpDir('aifec-i18n');
    perfPut($base . '/uno/es.php', "<?php\nreturn ['Cart' => 'Carrito', 'Hello {name}' => 'Hola {name}', '{n} item|{n} items' => '{n} artículo|{n} artículos'];\n");
    perfPut($base . '/dos/es.php', "<?php\nreturn ['Cart' => 'Cesta & más'];\n");
    perfPut($base . '/dos/en.php', "<?php\nreturn ['Cart' => 'Basket'];\n");

    return [$base . '/uno', $base . '/dos', $base . '/cache'];
}

test('la misma traducción con el catálogo fundido y sin él (la última carpeta manda, variables y plurales)', function (): void {
    [$uno, $dos, $cache] = i18nDirs();
    $plain = new I18n('es');
    $plain->addDir($uno);
    $plain->addDir($dos);
    $fused = new I18n('es');
    $fused->addDir($uno);
    $fused->addDir($dos);
    $fused->useCompiled($cache, 'build-1');
    foreach ([['Cart', []], ['Hello {name}', ['name' => 'Ñandú & "Cía"']], ['Sin traducir', []]] as $case) {
        same($plain->t($case[0], $case[1]), $fused->t($case[0], $case[1]), $case[0]);
    }
    same($plain->tn('{n} item', '{n} items', 3), $fused->tn('{n} item', '{n} items', 3));
    same('Basket', $fused->t('Cart', [], 'en'), 'otro idioma, su propio fichero');
    check(is_file($cache . '/lang-es.php') && is_file($cache . '/lang-en.php'), 'un fichero fundido por idioma');
});

test('con el catálogo fundido, t() no abre los ficheros de cada carpeta', function (): void {
    [$uno, $dos, $cache] = i18nDirs();
    $first = new I18n('es');
    $first->addDir($uno);
    $first->addDir($dos);
    $first->useCompiled($cache, 'build-1');
    same('Cesta & más', $first->t('Cart'));
    // Se cambia el fichero de origen SIN cambiar la construcción: si t() lo abriera, se vería el cambio.
    perfPut($dos . '/es.php', "<?php\nreturn ['Cart' => 'CAMBIADO'];\n");
    $second = new I18n('es');
    $second->addDir($uno);
    $second->addDir($dos);
    $second->useCompiled($cache, 'build-1');
    same('Cesta & más', $second->t('Cart'), 'sale del fundido, sin leer las carpetas');
    // Una construcción nueva (cambió algún catálogo) lo rehace.
    $third = new I18n('es');
    $third->addDir($uno);
    $third->addDir($dos);
    $third->useCompiled($cache, 'build-2');
    same('CAMBIADO', $third->t('Cart'));
});

test('una carpeta añadida después del fundido se lee como siempre (sin quedarse con lo viejo)', function (): void {
    [$uno, $dos, $cache] = i18nDirs();
    $i18n = new I18n('es');
    $i18n->addDir($uno);
    $i18n->useCompiled($cache, 'b');
    same('Carrito', $i18n->t('Cart'));
    $i18n->addDir($dos);
    same('Cesta & más', $i18n->t('Cart'), 'la carpeta nueva manda');
    same($uno, $i18n->dirs()[0]);
});

test('un catálogo fundido dañado se rehace, y en una vista previa no se escribe', function (): void {
    [$uno, $dos, $cache] = i18nDirs();
    perfPut($cache . '/lang-es.php', '<?php return [');
    $i18n = new I18n('es');
    $i18n->addDir($uno);
    $i18n->addDir($dos);
    $i18n->useCompiled($cache, 'b');
    same('Cesta & más', $i18n->t('Cart'));
    same('Cesta & más', \Aifec\Kernel\Cache::import($cache . '/lang-es.php')['catalog']['Cart'], 'rehecho');
    $preview = new I18n('es');
    $preview->addDir($uno);
    $preview->addDir($dos);
    $preview->useCompiled($cache . '-vista', 'b', false);
    same('Cesta & más', $preview->t('Cart'));
    check(!is_file($cache . '-vista/lang-es.php'), 'la vista previa no escribe');
});

test('en el sitio, la segunda petición traduce con el catálogo fundido lo mismo que la primera', function (): void {
    $app = testApp(['root' => perfRoot()]);
    $first = perfGet($app, '/alfa/3');
    same('ALFA 3 Carrito verde', $first->body);
    $cache = $app->site()->path('cache');
    check(is_file($cache . '/lang-es.php'), 'existe el catálogo fundido');
    $next = perfNext($app);
    same($first->body, perfGet($next, '/alfa/3')->body);
    same('Hola, tema', $next->i18n()->t('Hello'), 'el tema manda sobre el núcleo');
    same('Ñandú y compañía', $next->i18n()->t('Ñandú & cía'));
});
