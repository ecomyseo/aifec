<?php
/**
 * AIFEC — las direcciones antiguas con el prefijo del idioma: «/es/content/4-aviso-legal» (el idioma principal no lleva prefijo aquí) y
 * «/en/…» (un idioma del sitio) tienen que seguir funcionando, con y sin «conservar las direcciones antiguas», y un contenido que no existe
 * en el idioma de su prefijo manda al idioma principal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\Writers\PagesWriter;

require_once __DIR__ . '/Writers/WritersKit2.php';

/** Un sitio con castellano (principal) e inglés. */
function apSite(): array
{
    [$app] = pwApp(['i18n.languages' => ['es', 'en']]);

    return [$app, new PagesWriter($app)];
}

/**
 * Escribe una página con sus direcciones antiguas y devuelve su identificador.
 *
 * @param string[]            $paths
 * @param array<string,mixed> $options
 * @param array<string,string> $titles
 */
function apPage(array $site, array $paths, array $options = [], array $titles = ['es' => 'Aviso legal', 'en' => 'Legal notice']): int
{
    static $n = 40;
    ++$n;
    list($app, $writer) = $site;
    $slugs = [];
    $content = [];
    foreach ($titles as $lang => $title) {
        $slugs[$lang] = $lang === 'es' ? 'aviso-legal-' . $n : 'legal-notice-' . $n;
        $content[$lang] = '<p>' . $title . '</p>';
    }
    // «legal» no admite null en el formato neutro: una página que no es legal no lo trae.
    $record = wr('pages', ['id' => (string) $n, 'title' => $titles, 'slug' => $slugs, 'content' => $content, 'old_paths' => $paths]);
    unset($record['legal']);
    $out = $writer->write($record, writersContext($app, $options));
    check(in_array($out['result'], ['created', 'updated'], true), 'la página se escribe: ' . $out['result'] . ' ' . (isset($out['reason']) ? $out['reason'] : ''));

    return (int) $out['id'];
}

pwTest('con «conservar»: «/es/…» (el idioma principal no lleva prefijo) se guarda con y sin él y redirige a la buena; «/en/…» responde en su idioma', function (): void {
    $site = apSite();
    $app = $site[0];
    $id = apPage($site, ['/es/content/41-aviso-legal', '/en/content/41-legal-notice']);
    same('content/41-aviso-legal', $app->slugs()->path('page', $id, 'es'), 'la antigua sin prefijo es la dirección en castellano');
    same('content/41-legal-notice', $app->slugs()->path('page', $id, 'en'), 'y la inglesa, sin su prefijo, en inglés');
    $alias = $app->slugs()->resolve('es', 'es/content/41-aviso-legal');
    check($alias !== null && $alias['entity_id'] === $id && $alias['canonical'] === false, 'la que la gente tenía enlazada, con el prefijo, lleva a la misma página sin ser la buena');
    $web = new TestClient($app);
    $r = $web->get('/es/content/41-aviso-legal');
    same(301, $r->status, 'ya no da 404');
    same('/content/41-aviso-legal', (string) preg_replace('#^https?://[^/]+#', '', (string) $r->headers['Location']));
    same(200, $web->follow()->status, 'y al seguirla llega a la página');
    same(200, $web->get('/content/41-aviso-legal')->status, 'la buena responde sin redirigir');
    same(200, $web->get('/en/content/41-legal-notice')->status, 'la inglesa responde en inglés con su prefijo');
});

pwTest('sin «conservar»: «/es/…» redirige con un 301 a la nueva con y sin prefijo, y «/en/…» a la nueva en inglés', function (): void {
    $site = apSite();
    $app = $site[0];
    $id = apPage($site, ['/es/content/43-aviso-legal', '/en/content/43-legal-notice'], ['keep_urls' => false]);
    $new = (string) $app->slugs()->path('page', $id, 'es');
    $newEn = (string) $app->slugs()->path('page', $id, 'en');
    check($new !== '' && $newEn !== '' && strpos($new, 'content/43') === false, 'las direcciones nuevas no son las antiguas: ' . $new . ' y ' . $newEn);
    $sources = array_column($app->db()->select('SELECT source FROM {redirects} ORDER BY source'), 'source');
    same(['content/43-aviso-legal', 'content/43-legal-notice', 'es/content/43-aviso-legal'], $sources, 'se guardan con y sin el prefijo del idioma principal');
    $web = new TestClient($app);
    $r = $web->get('/es/content/43-aviso-legal');
    same(301, $r->status);
    contains($new, (string) $r->headers['Location']);
    same(200, $web->follow()->status);
    $r = $web->get('/content/43-aviso-legal');
    same(301, $r->status, 'la misma sin el prefijo también');
    same(200, $web->follow()->status);
    $r = $web->get('/en/content/43-legal-notice');
    same(301, $r->status);
    contains('/en/' . $newEn, (string) $r->headers['Location'], 'la inglesa va a la nueva en inglés');
    same(200, $web->follow()->status);
});

pwTest('una dirección sin prefijo de idioma sigue como siempre: la antigua es la buena (conservar) o redirige a la nueva', function (): void {
    $site = apSite();
    $app = $site[0];
    $keep = apPage($site, ['/content/45-aviso-legal']);
    same('content/45-aviso-legal', $app->slugs()->path('page', $keep, 'es'));
    same(null, $app->slugs()->resolve('es', 'es/content/45-aviso-legal'), 'sin prefijo no se inventa una dirección con él');
    $go = apPage($site, ['/content/46-aviso-legal'], ['keep_urls' => false]);
    $row = $app->db()->row('SELECT target FROM {redirects} WHERE source = ?', ['content/46-aviso-legal']);
    same('/' . $app->slugs()->path('page', $go, 'es'), (string) $row['target']);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {redirects} WHERE source LIKE 'es/%'"), 'y sin redirecciones con prefijo');
});

pwTest('un contenido que no existe en el idioma del prefijo redirige al idioma principal, con y sin «conservar»', function (): void {
    $site = apSite();
    $app = $site[0];
    foreach ([true, false] as $keep) {
        $n = $keep ? 'a' : 'b';
        $id = apPage($site, ['/es/content/' . $n . '47-aviso', '/en/content/' . $n . '47-notice'], ['keep_urls' => $keep], ['es' => 'Aviso ' . $n]);
        same(null, $app->slugs()->path('page', $id, 'en'), 'no se inventa una dirección en inglés');
        $web = new TestClient($app);
        $r = $web->get('/en/content/' . $n . '47-notice');
        same(301, $r->status, 'redirige (conservar=' . var_export($keep, true) . ')');
        $location = (string) $r->headers['Location'];
        same(0, strpos($location, 'https://tienda.test/'), 'a la dirección completa del idioma principal: ' . $location);
        notContains('/en/', $location, 'sin el prefijo del inglés');
        contains((string) $app->slugs()->path('page', $id, 'es'), $location);
        same(200, $web->follow()->status, 'y al seguirla llega a la página en castellano');
    }
});

pwTest('una dirección con «//», barra final, parámetros y almohadilla se limpia igual con el prefijo', function (): void {
    $site = apSite();
    $app = $site[0];
    $id = apPage($site, ['/es//content/48-aviso/?utm=1#arriba'], ['keep_urls' => false]);
    $sources = array_column($app->db()->select('SELECT source FROM {redirects} ORDER BY source'), 'source');
    same(['content/48-aviso', 'es/content/48-aviso'], $sources);
    same(200, (new TestClient($app))->get('/' . $app->slugs()->path('page', $id, 'es'))->status);
});

pwTest('un prefijo que no es un idioma del sitio («/fr/…») se guarda entero en el idioma principal, como siempre', function (): void {
    $site = apSite();
    $app = $site[0];
    $id = apPage($site, ['/fr/content/49-mentions']);
    $found = $app->slugs()->resolve('es', 'fr/content/49-mentions');
    check($found !== null && $found['entity_id'] === $id, 'la dirección con «fr/» responde en el idioma principal');
    same(null, $app->slugs()->resolve('fr', 'content/49-mentions'));
});

pwTest('si la dirección con el prefijo ya la usa otra cosa, no se pisa y se apunta', function (): void {
    $site = apSite();
    $app = $site[0];
    $other = apPage($site, ['/es/content/50-aviso'], [], ['es' => 'Otra', 'en' => 'Other']);
    $app->db()->run("UPDATE {slugs} SET entity_id = ?, type = 'product' WHERE lang = 'es' AND path = 'es/content/50-aviso'", [$other + 100]);
    $ctx = writersContext($app);
    $ctx->oldPaths('page', $other, ['/es/content/50-aviso']);
    $row = $app->db()->row("SELECT type, entity_id FROM {slugs} WHERE lang = 'es' AND path = 'es/content/50-aviso'");
    same(['product', $other + 100], [(string) $row['type'], (int) $row['entity_id']], 'la de otra entidad se queda como estaba');
    $reasons = implode(' ', array_map('strval', $app->db()->column('SELECT reason FROM {import_log} WHERE run_id = 7')));
    contains('already used by another page', $reasons);
});

pwTest('con la ruta de idioma dada por el escritor («es») y un prefijo de otro idioma del sitio, esa ruta va a su idioma sin el prefijo', function (): void {
    $site = apSite();
    $app = $site[0];
    $app->slugs()->set('product', 9, 'es', 'mesa');
    $app->slugs()->set('product', 9, 'en', 'table');
    $ctx = writersContext($app);
    $ctx->oldPaths('product', 9, ['/es/sillas/9-mesa.html', '/en/chairs/9-table.html'], 'es');
    same('es/sillas/9-mesa.html', $app->slugs()->path('product', 9, 'es'), 'la del idioma principal, entera y tal cual (200 en la misma dirección)');
    same('chairs/9-table.html', $app->slugs()->path('product', 9, 'en'), 'la inglesa, en inglés y sin el prefijo que la tienda quita antes de buscar');
    same(null, $app->slugs()->resolve('es', 'en/chairs/9-table.html'), 'y no se guarda en castellano con el prefijo inglés');
});

pwTest('en simulacro no se guarda ninguna dirección, con prefijo o sin él', function (): void {
    $site = apSite();
    $app = $site[0];
    $app->slugs()->set('page', 77, 'es', 'nueva');
    $before = importCounts($app);
    writersContext($app)->dry(true)->oldPaths('page', 77, ['/es/viejo', '/en/old']);
    same($before, importCounts($app));
});
