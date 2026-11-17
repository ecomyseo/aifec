<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Cache;
use Aifec\Kernel\I18n;

/** Escribe un catálogo de idioma (un array de PHP) como lo escribe el panel: de forma atómica. */
function i18nSitePut(string $file, array $catalog): void
{
    check(Cache::export($file, $catalog), 'se pudo escribir ' . $file);
}

/** Una carpeta del código, la del sitio y la de la caché. @return array{0:string,1:string,2:string,3:string} */
function i18nSiteDirs(): array
{
    $base = tmpDir('aifec-i18n-site');
    i18nSitePut($base . '/codigo/es.php', ['Cart' => 'Carrito', 'Checkout' => 'Pagar', '{n} item|{n} items' => '{n} artículo|{n} artículos']);
    i18nSitePut($base . '/tema/es.php', ['Cart' => 'Carrito del tema']);

    return [$base . '/codigo', $base . '/tema', $base . '/sitio', $base . '/cache'];
}

test('una traducción del sitio gana a la del código y a la del tema, aunque el tema se registre después', function (): void {
    [$code, $theme, $site] = i18nSiteDirs();
    i18nSitePut($site . '/es.php', ['Cart' => 'Cesta & más']);
    $i18n = new I18n('es');
    $i18n->addDir($code);
    $i18n->setSiteDir($site);
    $i18n->addDir($theme);
    same('Cesta & más', $i18n->t('Cart'));
    same('Pagar', $i18n->t('Checkout'), 'lo que el sitio no trae sale del código');
    same($site, $i18n->siteDir());
});

test('un idioma sin catálogo en el código sale traducido con el del sitio, con variables y plurales', function (): void {
    [$code, , $site] = i18nSiteDirs();
    i18nSitePut($site . '/fr.php', ['Cart' => 'Panier', 'Hello {name}' => 'Bonjour {name}', '{n} item|{n} items' => '{n} article|{n} articles']);
    $i18n = new I18n('fr');
    $i18n->addDir($code);
    $i18n->setSiteDir($site);
    same('Panier', $i18n->t('Cart'));
    same('Bonjour Zoé & Cía', $i18n->t('Hello {name}', ['name' => 'Zoé & Cía']));
    same('3 articles', $i18n->tn('{n} item', '{n} items', 3));
    same('1 article', $i18n->tn('{n} item', '{n} items', 1));
    same('Checkout', $i18n->t('Checkout'), 'lo que falta sigue en inglés');
});

test('al borrar la traducción del sitio vuelve el texto de serie', function (): void {
    [$code, , $site] = i18nSiteDirs();
    i18nSitePut($site . '/es.php', ['Cart' => 'Cesta']);
    $i18n = new I18n('es');
    $i18n->addDir($code);
    $i18n->setSiteDir($site);
    same('Cesta', $i18n->t('Cart'));
    i18nSitePut($site . '/es.php', []);
    $i18n->forget('es');
    same('Carrito', $i18n->t('Cart'));
});

test('el catálogo fundido incluye lo del sitio y se rehace cuando cambia ese fichero', function (): void {
    [$code, , $site, $cache] = i18nSiteDirs();
    i18nSitePut($site . '/es.php', ['Cart' => 'Cesta']);
    $first = new I18n('es');
    $first->addDir($code);
    $first->setSiteDir($site);
    $first->useCompiled($cache, 'b1');
    same('Cesta', $first->t('Cart'));
    $copy = Cache::import($cache . '/lang-es.php');
    check(is_array($copy) && $copy['catalog']['Cart'] === 'Cesta', 'el fundido lleva la del sitio');

    // Otro fichero del sitio (otro tamaño) con la misma construcción: el fundido viejo ya no vale.
    i18nSitePut($site . '/es.php', ['Cart' => 'Cesta de la compra']);
    $second = new I18n('es');
    $second->addDir($code);
    $second->setSiteDir($site);
    $second->useCompiled($cache, 'b1');
    same('Cesta de la compra', $second->t('Cart'));

    // forget() borra el fundido de ese idioma.
    check(is_file($cache . '/lang-es.php'), 'hay fundido');
    $second->forget('es');
    check(!is_file($cache . '/lang-es.php'), 'forget lo borra');
    same('Cesta de la compra', $second->t('Cart'), 'y se rehace al pedirlo');
    check(is_file($cache . '/lang-es.php'), 'rehecho');
});

test('sin carpeta del sitio el fundido es el de siempre, y con ella pero sin fichero no se lee nada más', function (): void {
    [$code, , $site, $cache] = i18nSiteDirs();
    $plain = new I18n('es');
    $plain->addDir($code);
    $plain->useCompiled($cache, 'b');
    same('Carrito', $plain->t('Cart'));
    $withDir = new I18n('es');
    $withDir->addDir($code);
    $withDir->setSiteDir($site);
    $withDir->useCompiled($cache, 'b');
    same('Carrito', $withDir->t('Cart'), 'el sitio sin fichero no cambia nada');
});

test('un valor vacío o que no es texto en el catálogo del sitio no cuenta, y un idioma raro no lee ficheros', function (): void {
    [$code, , $site] = i18nSiteDirs();
    i18nSitePut($site . '/es.php', ['Cart' => '', 'Checkout' => ['no' => 'vale'], '404' => 'Cuatrocientos cuatro']);
    $i18n = new I18n('es');
    $i18n->addDir($code);
    $i18n->setSiteDir($site);
    same('Carrito', $i18n->t('Cart'));
    same('Pagar', $i18n->t('Checkout'));
    same('Cuatrocientos cuatro', $i18n->t('404'), 'una clave que PHP guarda como número también vale');
    same('Cart', $i18n->t('Cart', [], '..'), 'un idioma con puntos no abre nada');
});

test('forget() sin idioma borra todos los fundidos y no falla sin caché', function (): void {
    [$code, , $site, $cache] = i18nSiteDirs();
    $i18n = new I18n('es');
    $i18n->addDir($code);
    $i18n->setSiteDir($site);
    $i18n->forget();
    $i18n->useCompiled($cache, 'b');
    $i18n->t('Cart');
    $i18n->t('Cart', [], 'fr');
    check(is_file($cache . '/lang-es.php') && is_file($cache . '/lang-fr.php'), 'dos fundidos');
    $i18n->forget();
    check(!is_file($cache . '/lang-es.php') && !is_file($cache . '/lang-fr.php'), 'ninguno');
});
