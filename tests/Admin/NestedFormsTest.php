<?php
/**
 * AIFEC — un formulario del panel nunca lleva otro dentro: Guardar, «Guardar y cerrar» y Borrar tienen que pertenecer al de guardado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Form;

require_once dirname(__DIR__, 2) . '/modules/ai/tests/helpers.php';

/**
 * Recorre las etiquetas <form> y </form> en orden como lo hace un navegador (no admite anidar) y dice cuántas veces se anida o sobra un cierre.
 *
 * @return array{nested:int,stray:int,forms:int}
 */
function aifecFormNesting(string $html): array
{
    preg_match_all('#<(/?)form\b#i', $html, $m);
    $depth = 0;
    $nested = 0;
    $stray = 0;
    $forms = 0;
    foreach ($m[1] as $closing) {
        if ($closing === '') {
            $forms++;
            $depth++;
            if ($depth > 1) {
                $nested++;
            }
        } elseif ($depth === 0) {
            $stray++;
        } else {
            $depth--;
        }
    }

    return ['nested' => $nested, 'stray' => $stray, 'forms' => $forms];
}

test('Form::render con una columna lateral que lleva su propio <form>: nada anidado y sus controles apuntan a un form de fuera', function (): void {
    $app = testApp();
    $app->boot();
    $form = new Form($app, ['fields' => ['name' => ['label' => 'Name']]]);
    $side = '<div class="af-card"><form method="post" action="/x/ai/propose" class="af-stack"><input type="hidden" name="product_id" value="3">'
        . '<select name="lang"><option>es</option></select><button type="submit" name="task" value="summary">Resumen</button></form></div>';
    $html = $form->render(['name' => 'Silla'], [], ['action' => '/x/save', 'delete' => '/x/delete', 'side' => $side]);

    same(['nested' => 0, 'stray' => 0, 'forms' => 2], aifecFormNesting($html), 'un form de guardado y uno vacío para la tarjeta, sin anidar');
    preg_match('#<form id="(af-side-form-\d+)"[^>]*action="/x/ai/propose"[^>]*></form>#', $html, $id);
    check(isset($id[1]), 'el formulario de la tarjeta se pinta fuera, vacío y con su id');
    contains('<button form="' . ($id[1] ?? '?') . '" type="submit" name="task" value="summary">', $html, 'el botón de la tarjeta apunta a su formulario');
    contains('<input form="' . ($id[1] ?? '?') . '" type="hidden" name="product_id" value="3">', $html);
    contains('<select form="' . ($id[1] ?? '?') . '" name="lang">', $html);
    // Lo de guardado sigue dentro del suyo: ni lleva form= ni está después de su cierre.
    $save = substr($html, 0, (int) strpos($html, '</form>'));
    contains('class="af-btn af-btn-primary"', $save, 'Guardar está dentro del formulario de guardado');
    contains('formaction="/x/delete"', $save, 'Borrar está dentro del formulario de guardado');
    notContains('<button type="submit" class="af-btn af-btn-primary" form=', $html);
});

test('la ficha de producto del panel con la tarjeta «Proponer con IA»: un solo formulario de guardado con sus botones dentro y ninguno anidado', function (): void {
    $app = aiApp([], ['es', 'en']);
    $p = aiProduct($app, ['name' => 'Silla']);
    $web = adminClient($app);
    $page = $web->get('/admin/r/products/' . $p);
    same(200, $page->status);
    $n = aifecFormNesting($page->body);
    same(0, $n['nested'], 'ningún form dentro de otro');
    same(0, $n['stray'], 'ningún </form> de más');
    // El primer </form> de la ficha que sigue al de guardado cierra DESPUÉS de la barra de botones.
    $start = (int) strpos($page->body, 'class="af-form"');
    check($start > 0, 'hay un formulario de guardado');
    $end = (int) strpos($page->body, '</form>', $start);
    $actions = (int) strpos($page->body, 'af-form-actions', $start);
    check($actions > 0 && $actions < $end, 'la barra de botones queda antes del cierre del formulario de guardado');
    contains('name="task" value="summary"', $page->body);
    check(preg_match('#<button form="af-side-form-\d+" type="submit" class="af-btn af-btn-sm" name="task" value="summary"#', $page->body) === 1, 'los botones de la IA apuntan a su formulario');
});
