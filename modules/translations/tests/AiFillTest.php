<?php
/**
 * AIFEC — pruebas de «Traducir lo que falta» con una IA de mentira: tandas, encargo, glosario, comprobaciones, propuestas que no se
 * ven hasta aprobarlas, «Aprobar todo», «publicar sin revisar», tope de gasto, pausa, reanudación y el vigía de la cola.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\Brief;
use Aifec\Modules\Translations\Fill;
use Aifec\Modules\Translations\FillJobs;
use Aifec\Modules\Translations\Glossary;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\SiteCatalog;

require_once __DIR__ . '/TranslationsKit.php';
require_once __DIR__ . '/../../../tests/Perf/helpers.php';

/** @return array<string,array{area:string,origin:string}> tantos textos de la tienda como se pidan */
function trxManyStrings(int $n): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[sprintf('Shop text %03d', $i)] = ['area' => 'shop', 'origin' => 'core'];
    }

    return $out;
}

test('traduce por tandas: un encargo por cada 40 textos, y el trabajo termina con sus números', function (): void {
    [$app, $ai] = trxAiApp();
    trxBindStrings($app, trxManyStrings(90));
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(3, count($ai->requests), '40 + 40 + 10');
    same(40, count(trxItemsOf($ai->requests[0])));
    same(10, count(trxItemsOf($ai->requests[2])));
    $job = trxJob($app, $id);
    same('done', $job['status']);
    same(90, (int) $job['total']);
    same(90, (int) $job['done']);
    same(0, (int) $job['failed']);
    same(90, (new Proposals($app))->count('fr', 'shop'));
    same('FR Shop text 001', (new Proposals($app))->pending('fr', 'shop')['Shop text 001']['text']);
});

test('lo que propone la IA no se ve en la tienda hasta aprobarlo, y «Aprobar todo» lo publica', function (): void {
    [$app, $ai] = trxAiApp();
    trxBindStrings($app, ['In stock' => ['area' => 'shop', 'origin' => 'catalog']]);
    aifecProduct($app, ['name' => 'Silla', 'i18n' => ['fr' => ['name' => 'Chaise']]]);
    (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(1, (new Proposals($app))->count('fr', 'shop'));
    same([], (new SiteCatalog($app))->all('fr'));
    $shop = (new TestClient($app))->get('/fr/producto/chaise');
    contains('data-af-availability>In stock<', $shop->body, 'la propuesta no sale');
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    contains('data-tr-approve', $page->body);
    $web->post('/admin/translations/fr/shop/approve', ['_token' => aifecToken($web)]);
    same(['In stock' => 'FR In stock'], (new SiteCatalog($app))->all('fr'));
    same(0, (new Proposals($app))->count('fr', 'shop'));
    contains('1 traducción aprobada', $web->follow()->body);
    $after = (new TestClient(perfNext($app)))->get('/fr/producto/chaise');
    contains('data-af-availability>FR In stock<', $after->body);
});

test('con «publicar las traducciones de la IA sin revisar» se publica en seguida y no queda nada pendiente', function (): void {
    [$app] = trxAiApp(['translations.publish_unreviewed' => true]);
    (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(0, (new Proposals($app))->count('fr', 'shop'));
    same('FR Checkout', (new SiteCatalog($app))->all('fr')['Checkout']);
    same('FR {n} item|FR {n} items', (new SiteCatalog($app))->all('fr')['{n} item|{n} items']);
    same('FR 3 items', $app->i18n()->tn('{n} item', '{n} items', 3, [], 'fr'));
});

test('el encargo que viaja: instrucciones fijas, contexto y textos cercados, tienda, sector e idiomas', function (): void {
    [$app, $ai] = trxAiApp();
    (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(1, count($ai->requests));
    $r = $ai->requests[0];
    same(Brief::SYSTEM, $r->system);
    $message = $r->messages[0]->content;
    contains('Shop name: Muebles Roble & Cía', $message);
    contains('What the shop sells: muebles de madera', $message);
    contains('Source language: English', $message);
    contains('Target language: French', $message);
    contains('<<<DATA CONTEXT', $message);
    contains('<<<DATA ITEMS', $message);
    contains('"format":"plural","text":"{n} item|{n} items"', $message);
    contains('"text":"Hello {name}"', $message);
    check($r->jsonSchema !== null && isset($r->jsonSchema['properties']['items']), 'se pide la respuesta con un esquema');
    $usage = $app->db()->row('SELECT * FROM {ai_usage} ORDER BY id DESC');
    same('translations.shop', $usage['task'], 'el gasto se apunta en el registro del módulo de IA');
});

test('el glosario viaja en el encargo y un nombre que no se traduce no se puede perder', function (): void {
    [$app, $ai] = trxAiApp();
    trxBindStrings($app, ['Oslo chair' => ['area' => 'shop', 'origin' => 'core'], 'Add to cart' => ['area' => 'shop', 'origin' => 'core']]);
    Glossary::set($app, 'fr', "cart = panier\nOslo");
    $ai->translate = static function (string $text): string {
        return $text === 'Oslo chair' ? 'Chaise' : 'Ajouter au panier';
    };
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    $message = $ai->requests[0]->messages[0]->content;
    contains('<<<DATA GLOSSARY', $message);
    contains('cart = panier', $message);
    contains('Never translate these names: Oslo', $message);
    $pending = (new Proposals($app))->pending('fr', 'shop');
    same(['Add to cart'], array_keys($pending), 'la que perdió «Oslo» no se propone');
    same(1, (int) trxJob($app, $id)['failed']);
});

test('una respuesta con un marcador perdido, inventado o con otras formas de plural se descarta y cuenta como fallida', function (): void {
    [$app, $ai] = trxAiApp();
    $ai->translate = static function (string $text, string $format): string {
        if ($text === 'Hello {name}') {
            return 'Bonjour';
        }
        if ($format === 'plural') {
            return '{n} articles';
        }

        return $text === 'Checkout' ? 'Payer {total}' : 'Ajouter au panier';
    };
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(['Add to cart'], array_keys((new Proposals($app))->pending('fr', 'shop')));
    $job = trxJob($app, $id);
    same(1, (int) $job['done']);
    same(3, (int) $job['failed']);
    same('done', $job['status']);
});

test('el tope de gasto corta: el trabajo se pausa con el motivo, esa tanda no se consume y no hay más llamadas', function (): void {
    [$app, $ai] = trxAiApp(['ai.daily_limit_calls' => 1]);
    trxBindStrings($app, trxManyStrings(90));
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(1, count($ai->requests));
    $job = trxJob($app, $id);
    same('paused', $job['status']);
    same(Fill::LIMIT, $job['reason']);
    same(40, (int) $job['done']);
    same('Shop text 040', (string) $job['cursor'], 'el cursor se queda en lo último consumido');
    same(40, (new Proposals($app))->count('fr', 'shop'));
    $web = adminClient($app);
    contains('Se ha llegado al tope diario de la IA', $web->get('/admin/translations?lang=fr')->body);
});

test('un trabajo pausado se reanuda donde iba, sin volver a pedir lo ya propuesto', function (): void {
    [$app, $ai] = trxAiApp(['ai.daily_limit_calls' => 1]);
    trxBindStrings($app, trxManyStrings(90));
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same('paused', trxJob($app, $id)['status']);
    $app->settings()->set('ai.daily_limit_calls', 50);
    $web = adminClient($app);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/jobs/' . $id . '/resume', ['_token' => aifecToken($web)]);
    trxWork($app);
    same(3, count($ai->requests));
    same('Shop text 041', (string) trxItemsOf($ai->requests[1])[0]['text'], 'empieza justo después del cursor');
    $job = trxJob($app, $id);
    same('done', $job['status']);
    same(90, (int) $job['done']);
    same(90, (new Proposals($app))->count('fr', 'shop'));
});

test('se puede pausar y parar desde el panel; lo ya propuesto se queda', function (): void {
    [$app, $ai] = trxAiApp();
    trxBindStrings($app, trxManyStrings(90));
    $id = (new Fill($app))->start('fr', 'shop');
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    contains('data-tr-job="' . $id . '"', $page->body);
    $web->post('/admin/translations/jobs/' . $id . '/pause', ['_token' => aifecToken($web)]);
    same('paused', trxJob($app, $id)['status']);
    trxWork($app);
    same(0, count($ai->requests), 'pausado no llama a la IA');
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/jobs/' . $id . '/cancel', ['_token' => aifecToken($web)]);
    same('cancelled', trxJob($app, $id)['status']);
    same('cancelled', (string) (new Fill($app))->runChunk($id)['status'], 'un trabajo parado no corre');
    same(0, count($ai->requests));
});

test('tres tandas fallidas seguidas pausan el trabajo', function (): void {
    [$app, $ai] = trxAiApp();
    trxBindStrings($app, trxManyStrings(170));
    $ai->fail = 'Service unavailable';
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(3, count($ai->requests));
    $job = trxJob($app, $id);
    same('paused', $job['status']);
    same(Fill::STREAK, $job['reason']);
    same(120, (int) $job['failed']);
    same(0, (new Proposals($app))->count('fr', 'shop'));
});

test('una respuesta sin la forma pedida se apunta como fallo en el registro de la IA', function (): void {
    [$app, $ai] = trxAiApp();
    $ai->garbage = true;
    $id = (new Fill($app))->start('fr', 'shop');
    trxWork($app);
    same(4, (int) trxJob($app, $id)['failed']);
    $usage = $app->db()->row('SELECT * FROM {ai_usage} ORDER BY id DESC');
    same(0, (int) $usage['ok']);
});

test('el vigía vuelve a encolar un trabajo que corre sin nada en la cola, y solo ese', function (): void {
    [$app, $ai] = trxAiApp();
    $id = (new Fill($app))->start('fr', 'shop');
    same(null, FillJobs::nudge($app), 'con su tanda en la cola no hace nada');
    $app->db()->run('DELETE FROM {jobs}');
    same('Queued 1 translation jobs again', FillJobs::nudge($app));
    trxWork($app);
    same('done', trxJob($app, $id)['status']);
    same(1, count($ai->requests));
});

test('lanzar dos veces el mismo idioma y la misma fila devuelve el mismo trabajo', function (): void {
    [$app] = trxAiApp();
    $fill = new Fill($app);
    same($fill->start('fr', 'shop'), $fill->start('fr', 'shop'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {translation_jobs}'));
});

test('no se lanza con la IA apagada, en un idioma que no es de la tienda, ni sin nada que traducir', function (): void {
    [$off] = trxAiApp(['ai.enabled' => false]);
    $error = '';
    try {
        (new Fill($off))->start('fr', 'shop');
    } catch (\InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    same('no_ai', $error);
    [$app] = trxAiApp();
    foreach ([['de', 'shop', 'language'], ['fr', 'nada', 'unknown'], ['fr', 'categories', 'nothing'], ['es', 'categories', 'blocked']] as $case) {
        $error = '';
        try {
            (new Fill($app))->start($case[0], $case[1]);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
        same($case[2], $error, $case[0] . '/' . $case[1]);
    }
    (new SiteCatalog($app))->update('fr', ['Orders' => 'Commandes', 'Save' => 'Enregistrer']);
    $error = '';
    try {
        (new Fill($app))->start('fr', 'panel');
    } catch (\InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    same('nothing', $error);
    same('No falta nada por traducir', Fill::message('nothing'));
});
