<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * FitCheck (17/11/2026): lo que el buscador da por exacto sin que ningún nombre lleve lo pedido lo mira la IA. «aceite de oliva»
 * encontraba un guiso, «zapatos para trabajar de camarera» sandalias de tacón y «merendar con los niños» un jamón de 8 kg, y el MCP
 * de mcp.gmartos.es se fía de ese «exact».
 */

use Aifec\Modules\Assistant\FitCheck;

require_once __DIR__ . '/helpers.php';

test('FitCheck: lo pedido por su nombre no se pregunta; lo demás lo decide la IA y sin nada que encaje la respuesta es «no hay»', function (): void {
    same(true, FitCheck::named('columna de baño', [['name' => 'Columna de Baño Suspendida Zenith']]), 'por su nombre');
    same(true, FitCheck::named('esterilla para yoga', [['name' => 'Esterilla de yoga 4 mm']]), 'las de relleno no cuentan');
    same(false, FitCheck::named('aceite de oliva', [['name' => 'Guiso de Carne con Castañas y Vino']]), 'un guiso no es aceite');
    same(false, FitCheck::named('zapatos para trabajar de camarera', [['name' => 'Bailarinas Nube planas'], ['name' => 'Sandalias Aura de tacón']]), 'una necesidad');

    $app = assistantApp();
    $ids = assistantShop($app);
    $ai = assistantAi($app);
    $tools = $app->service('tools');

    // «madera maciza» solo está en la descripción de la mesa: el buscador la da por exacta y la IA dice que no encaja.
    $ai->fitScript('{"fit":[]}');
    $r = $tools->call('search_products', ['query' => 'madera maciza']);
    same(1, count($ai->fitAsked), 'se le pregunta a la IA');
    same('none', $r['answer_kind'], (string) json_encode($r));
    same([], $r['items']);
    contains((string) $ids['mesa'], (string) json_encode($ai->fitAsked[0]['request']['messages']), 'con los productos que salieron');

    // La IA deja la mesa: sale solo ella.
    $ai->fitScript('{"fit":[' . $ids['mesa'] . ']}');
    $r = $tools->call('search_products', ['query' => 'madera maciza para el salon']);
    same('exact', $r['answer_kind'], (string) json_encode($r));
    same([$ids['mesa']], array_map(static function (array $i): int {
        return (int) $i['id'];
    }, $r['items']));

    // Una respuesta que no sirve (sin «fit», ids que no estaban) o la IA caída: queda como estaba.
    $ai->fitScript('{"fit":[999999]}');
    same('exact', $tools->call('search_products', ['query' => 'mesa robusta salon'])['answer_kind'], 'ids inventados: sin cambios');
    $ai->fail = true;
    same('exact', $tools->call('search_products', ['query' => 'madera maciza'])['answer_kind'], 'IA caída: sin cambios');
    $ai->fail = false;

    // Lo pedido por su nombre: ni se pregunta.
    $before = count($ai->fitAsked);
    $tools->call('search_products', ['query' => 'silla roja']);
    same($before, count($ai->fitAsked), 'por su nombre no se pregunta');

    // Con el modelo del asistente llevando el turno, juzga él.
    $tools->call('search_products', ['query' => 'madera maciza'], ['by_model' => true]);
    same($before, count($ai->fitAsked), 'by_model: no se pregunta');

    // Lo parecido (related) que la IA da por bueno pasa a exacto, y también vale un candidato de más (la frase entendida por la IA
    // del buscador): «un regalo para un amigo que le gusta cocinar» son los lotes regalo que la Gourmet daba como related.
    $check = new FitCheck($app);
    $ai->fitScript('{"fit":[' . $ids['silla_roja'] . ',' . $ids['mesa'] . ']}');
    $r = $check->apply('algo para comer en casa', [
        'query' => 'algo para comer en casa', 'answer_kind' => 'related', 'note' => 'These are NOT exact matches',
        'items' => [['id' => $ids['mesa'], 'name' => 'Mesa de roble', 'answer_kind' => 'related']],
    ], [['id' => $ids['silla_roja'], 'name' => 'Silla roja', 'answer_kind' => 'related']]);
    same('exact', $r['answer_kind'], (string) json_encode($r));
    same(false, isset($r['note']), 'sin la nota de «no exacto»');
    same([$ids['silla_roja'], $ids['mesa']], array_map(static function (array $i): int {
        return (int) $i['id'];
    }, $r['items']), 'en el orden de la IA, con el candidato de más');
    same('exact', $r['items'][0]['answer_kind']);

    // Apagado por ajuste.
    $before = count($ai->fitAsked);
    $app->settings()->set('assistant.fit_check', false);
    $tools->call('search_products', ['query' => 'madera maciza']);
    same($before, count($ai->fitAsked), 'con el ajuste apagado no se pregunta');
});
