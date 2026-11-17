<?php
/**
 * AIFEC — el gasto de la IA, en la moneda de la gente: el inicio decía «0,00 / 200,00 céntimos» y ahora dice «0,00 € de 2,00 €».
 * El ajuste del tope sigue guardándose en céntimos, pero se rotula y se escribe en unidades.
 *
 * Fallo que cubre: nadie piensa en 200 céntimos (lo encontró quien escribía el manual, 13/11/2026).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Ai\Usage;

require_once __DIR__ . '/helpers.php';

/** El formulario de los ajustes de la IA tal como lo manda el navegador, con otro valor del tope. */
function aimSettingsForm(array $over = []): array
{
    return $over + [
        'ai.enabled' => '1', 'ai.show_notice' => '0', 'ai.daily_limit_cents' => '2,00', 'ai.daily_limit_calls' => '500', 'ai.monthly_limit_cents' => '0', 'ai.daily_limit_tokens' => '0', 'ai.timeout' => '30',
        'ai.economy.provider' => 'gemini', 'ai.economy.model' => '', 'ai.economy.base_url' => '', 'ai.economy.key' => '',
        'ai.standard.provider' => 'gemini', 'ai.standard.model' => '', 'ai.standard.base_url' => '', 'ai.standard.key' => '',
        'ai.advanced.provider' => 'gemini', 'ai.advanced.model' => '', 'ai.advanced.base_url' => '', 'ai.advanced.key' => '',
        'ai.economy.price_in' => '100', 'ai.economy.price_out' => '400', 'ai.standard.price_in' => '100', 'ai.standard.price_out' => '400',
        'ai.advanced.price_in' => '100', 'ai.advanced.price_out' => '400',
    ];
}

function aimSave(TestClient $web, array $over): Aifec\Kernel\Response
{
    $web->get('/admin/settings/ai');

    return $web->post('/admin/settings/ai', ['_token' => aifecToken($web), 'f' => aimSettingsForm($over)]);
}

function aimApp(string $symbol = '€', string $locale = 'es'): App
{
    $app = aiApp(['shop.currency_symbol' => $symbol]);
    $app->i18n()->setLocale($locale);

    return $app;
}

test('importes de la IA: 200 céntimos son «2,00 €», con la coma del idioma y el símbolo de la tienda', function (): void {
    $app = aimApp();
    same("2,00\u{a0}€", Usage::amount($app, 200 * Usage::MICRO));
    same("0,00\u{a0}€", Usage::amount($app, 0));
    same("0,01\u{a0}€", Usage::amount($app, Usage::MICRO), 'un céntimo');
    same("0,50\u{a0}€", Usage::amount($app, 50 * Usage::MICRO));
    same("1234,57\u{a0}€", Usage::amount($app, 123456789012), 'sin punto de millares, como el resto del panel');
});

test('importes de la IA: sin símbolo de moneda salen en unidades, sin símbolo y no en céntimos', function (): void {
    $app = aimApp('');
    same('2,00', Usage::amount($app, 200 * Usage::MICRO), 'no «200»: unidades');
    same('0,00', Usage::amount($app, 0));
    same('', Usage::symbol($app));
    same('2,50', Usage::amount(aimApp('  '), 250 * Usage::MICRO), 'un símbolo en blanco es no tener símbolo');
});

test('importes de la IA: en inglés, con punto y el símbolo delante', function (): void {
    $app = aimApp('£', 'en');
    same('£2.00', Usage::amount($app, 200 * Usage::MICRO));
    same('£0.01', Usage::amount($app, Usage::MICRO));
    same('1.50', Usage::amount(aimApp('', 'en'), 150 * Usage::MICRO));
});

test('importes de la IA: redondeo a la centésima con enteros, y lo que no llega al céntimo sale con cuatro decimales para no parecer gratis', function (): void {
    $app = aimApp('');
    same('0,01', Usage::amount($app, 500000), 'medio céntimo sube');
    same('0,00', Usage::amount($app, 0));
    same('0,0050', Usage::amount($app, 499999), 'un poco menos de medio céntimo: cuatro decimales');
    same('0,0010', Usage::amount($app, 100000));
    same('0,0001', Usage::amount($app, 1), 'nunca «0,00» si ha costado algo');
    same('0,01', Usage::amount($app, 900000));
    same('0,00', Usage::amount($app, -5), 'un negativo no existe: cero');
    same('0,12', Usage::amount($app, 12345678));
});

test('importes de la IA: no hay coma flotante en ninguna cuenta del gasto', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Usage.php');
    $body = (string) substr($source, (int) strpos($source, 'function amount'), 900);
    notContains('(float)', $body);
    notContains('round(', $body);
    notContains('number_format', $body);
    notContains(' / ', $body, 'solo divisiones enteras (intdiv)');
});

test('el inicio dice «0,00 € de 2,00 €» y no «céntimos»', function (): void {
    $app = aimApp();
    $home = adminClient($app)->get('/admin')->body;
    contains('Gasto de IA de hoy', $home);
    contains("0,00\u{a0}€", $home);
    contains("de 2,00\u{a0}€", $home);
    notContains('céntimos', $home);
    notContains('200,00', $home);

    $bare = aimApp('');
    $homeBare = adminClient($bare)->get('/admin')->body;
    contains('af-stat-value', $homeBare);
    contains('de 2,00<', str_replace("\n", '', $homeBare), 'sin símbolo, en unidades');
    notContains('céntimos', $homeBare);
});

test('la pantalla de uso enseña hoy, 30 días, tablas y gráfica en unidades de la moneda, sin céntimos', function (): void {
    $app = aimApp();
    $ai = $app->service('ai');
    aiFake($app)->script(new \Aifec\Ai\AiResult('a', [], null, 10000, 0));
    $ai->ask('product.description', ['prompt' => 'x'], 'economy');
    $page = adminClient($app)->get('/admin/ai/usage');
    same(200, $page->status);
    contains("0,01\u{a0}€", $page->body, 'el céntimo gastado hoy');
    contains("de 2,00\u{a0}€", $page->body, 'frente al tope');
    contains('Coste', $page->body);
    notContains('Céntimos', $page->body);
    notContains('céntimos', $page->body);
    contains("0,01\u{a0}€ ·", $page->body, 'la barra de la gráfica lo dice en su título');
});

test('la gráfica no enseña «0,0001» en el eje cuando todavía no se ha gastado nada', function (): void {
    $app = aimApp();
    $page = adminClient($app)->get('/admin/ai/usage');
    contains('<svg', $page->body);
    contains("0,00\u{a0}€</text>", $page->body);
    notContains('0,0001', $page->body);
});

test('el ajuste del tope se rotula «Tope diario» y se enseña en unidades: 200 céntimos guardados son «2,00»', function (): void {
    $app = aimApp();
    $web = adminClient($app);
    $page = $web->get('/admin/settings/ai');
    same(200, $page->status);
    contains('Tope diario', $page->body);
    notContains('Tope diario en céntimos', $page->body);
    same('2,00', inputValue($page->body, 'f[ai.daily_limit_cents]'));
    same(200, $app->settings()->int('ai.daily_limit_cents'), 'y en la base de datos sigue siendo 200');
});

test('guardar «2,50» en el tope deja 250 céntimos; «2» deja 200; con punto también; y el formulario lo vuelve a enseñar en unidades', function (): void {
    $app = aimApp();
    $web = adminClient($app);
    foreach (['2,50' => 250, '2' => 200, '3.75' => 375, '0' => 0, '10,05' => 1005, '1.234,50' => 123450] as $typed => $cents) {
        same(302, aimSave($web, ['ai.daily_limit_cents' => (string) $typed])->status, 'se acepta «' . $typed . '»');
        $app->settings()->reload();
        same($cents, $app->settings()->int('ai.daily_limit_cents'), '«' . $typed . '» son ' . $cents . ' céntimos');
    }
    aimSave($web, ['ai.daily_limit_cents' => '2,50']);
    $app->settings()->reload();
    same('2,50', inputValue($web->get('/admin/settings/ai')->body, 'f[ai.daily_limit_cents]'), 'vuelve en unidades');
    same(250 * Usage::MICRO, Usage::limitMicro($app), 'y el tope que se aplica es el de 250 céntimos');
});

test('un tope mal escrito no se guarda: vacío, texto o negativo; el valor anterior se queda', function (): void {
    $app = aimApp();
    $web = adminClient($app);
    aimSave($web, ['ai.daily_limit_cents' => '4,00']);
    $app->settings()->reload();
    same(400, $app->settings()->int('ai.daily_limit_cents'));
    foreach (['', 'mucho', '-1', '€', '1e5x'] as $bad) {
        $r = aimSave($web, ['ai.daily_limit_cents' => $bad]);
        check($r->status !== 302, 'se rechaza «' . $bad . '» (' . $r->status . ')');
        $app->settings()->reload();
        same(400, $app->settings()->int('ai.daily_limit_cents'), 'con «' . $bad . '» no cambia');
    }
});

test('con el tope en cero la IA se bloquea, y se escribe «0,00» sin que sea otra cosa', function (): void {
    $app = aimApp();
    $web = adminClient($app);
    same(302, aimSave($web, ['ai.daily_limit_cents' => '0,00'])->status);
    $app->settings()->reload();
    same(0, $app->settings()->int('ai.daily_limit_cents'));
    same(0, Usage::limitMicro($app));
    same("0,00\u{a0}€", Usage::amount($app, Usage::limitMicro($app)));
});

test('la pantalla de lotes sigue hablando en céntimos: no es la de uso ni la del inicio', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Usage.php');
    contains('public static function cents', $source, 'Usage::cents sigue existiendo para los lotes');
    $app = aimApp('');
    same('1,00', Usage::cents($app, 1000000));
    same('0,30', Usage::cents($app, 300000));
});
