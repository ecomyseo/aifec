<?php
/**
 * AIFEC — rastrea el panel y la tienda con todos los módulos y mira lo que ninguna prueba de servidor veía y un navegador sí:
 * formularios anidados, botones «Guardar» sin formulario, atributos form= que no apuntan a nada, ids repetidos, ficheros en un
 * formulario sin multipart y avisos de PHP escritos en la página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Los problemas de estructura de un HTML (lista de textos; vacía si está bien).
 *
 * @return string[]
 */
function aifecHtmlProblems(string $html): array
{
    // Sin comentarios, guiones, estilos ni áreas de texto: lo que hay dentro no son etiquetas.
    $clean = (string) preg_replace(['#<!--.*?-->#s', '#<script\b.*?</script>#is', '#<style\b.*?</style>#is', '#<textarea\b([^>]*)>.*?</textarea>#is'], ['', '<script></script>', '<style></style>', '<textarea$1></textarea>'], $html);
    $problems = [];
    $ids = [];
    $formRefs = [];
    $labelFor = [];
    $depth = 0;
    $multipart = false;
    $formOpen = false;
    $filesInForm = false;
    $names = [];
    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $clean, $tags, PREG_SET_ORDER);
    foreach ($tags as $t) {
        $closing = $t[1] === '/';
        $name = strtolower($t[2]);
        $attrs = $t[3];
        if ($closing) {
            if ($name === 'form') {
                if ($depth === 0) {
                    $problems[] = '</form> de más';
                } else {
                    $depth--;
                    if ($filesInForm && !$multipart) {
                        $problems[] = 'formulario con un campo de fichero y sin enctype multipart';
                    }
                    $formOpen = false;
                    $filesInForm = false;
                }
            }
            continue;
        }
        if (preg_match('#\sid="([^"]*)"#', $attrs, $m) === 1 && $m[1] !== '') {
            $ids[$m[1]] = (isset($ids[$m[1]]) ? $ids[$m[1]] : 0) + 1;
        }
        if ($name === 'form') {
            $depth++;
            if ($depth > 1) {
                $problems[] = '<form> dentro de otro <form>';
            }
            $formOpen = true;
            $names = [];
            $multipart = stripos($attrs, 'multipart/form-data') !== false;
            $filesInForm = false;
            continue;
        }
        if ($name === 'label' && preg_match('#\sfor="([^"]+)"#', $attrs, $m) === 1) {
            $labelFor[] = $m[1];
        }
        $isControl = in_array($name, ['input', 'select', 'textarea', 'button'], true);
        if (!$isControl) {
            continue;
        }
        $owner = preg_match('#\sform="([^"]+)"#', $attrs, $m) === 1 ? $m[1] : '';
        if ($owner !== '') {
            $formRefs[] = $owner;
        }
        $type = preg_match('#\stype="([^"]*)"#', $attrs, $m) === 1 ? strtolower($m[1]) : '';
        if ($name === 'input' && $type === 'file' && $formOpen) {
            $filesInForm = true;
        }
        // Dos campos con el mismo nombre en el mismo formulario: PHP se queda con el último y el primero se pierde en silencio.
        if ($formOpen && $owner === '' && !in_array($type, ['radio', 'checkbox', 'submit', 'button', 'image', 'reset'], true) && $name !== 'button'
            && preg_match('#\sname="([^"\[\]]+)"#', $attrs, $nm) === 1) {
            $key = $nm[1];
            $names[$key] = (isset($names[$key]) ? $names[$key] : 0) + 1;
            if ($names[$key] === 2) {
                $problems[] = 'dos campos con el nombre «' . $key . '» en el mismo formulario';
            }
        }
        $submits = ($name === 'button' && $type === 'submit') || ($name === 'input' && ($type === 'submit' || $type === 'image'));
        if ($submits && $depth === 0 && $owner === '') {
            $label = preg_match('#\sname="([^"]*)"#', $attrs, $m) === 1 ? ' name=' . $m[1] : '';
            $problems[] = 'botón de enviar sin formulario (no hace nada)' . $label;
        }
    }
    if ($depth > 0) {
        $problems[] = '<form> sin cerrar';
    }
    foreach ($ids as $id => $n) {
        if ($n > 1) {
            $problems[] = 'id repetido: ' . $id . ' (' . $n . ')';
        }
    }
    foreach ($formRefs as $ref) {
        // «af-cp-none» es a propósito: el filtro del selector de categorías se desliga así del formulario de la ficha.
        if (!isset($ids[$ref]) && $ref !== 'af-cp-none') {
            $problems[] = 'form="' . $ref . '" no apunta a ningún formulario';
        }
    }
    foreach ($labelFor as $for) {
        if (!isset($ids[$for])) {
            $problems[] = 'label for="' . $for . '" sin campo con ese id';
        }
    }
    if (preg_match('#(?:Warning|Notice|Deprecated|Fatal error|Parse error)</b>:|\b(?:Warning|Notice|Deprecated): .{3,80} in /#', $clean) === 1) {
        $problems[] = 'aviso de PHP escrito en la página';
    }

    return array_values(array_unique($problems));
}

test('el rastreador de estructura HTML ve un formulario anidado y un botón sin formulario', function (): void {
    same(['<form> dentro de otro <form>', '</form> de más'], aifecHtmlProblems('<form><form><button type="submit">a</button></form></form></form>'));
    same(['botón de enviar sin formulario (no hace nada) name=x'], aifecHtmlProblems('<form></form><button type="submit" name="x">a</button>'));
    same([], aifecHtmlProblems('<form id="a"></form><button form="a" type="submit">a</button>'));
    same(['form="zz" no apunta a ningún formulario'], aifecHtmlProblems('<button form="zz" type="submit">a</button>'));
    same(['formulario con un campo de fichero y sin enctype multipart'], aifecHtmlProblems('<form method="post"><input type="file" name="f"></form>'));
});

test('panel y tienda con todos los módulos: ninguna página con formularios mal montados', function (): void {
    $app = testApp(['modules' => '*', 'settings' => ['ai.enabled' => true, 'ai.daily_limit_cents' => 200, 'i18n.languages' => ['es', 'en']]]);
    $app->boot();
    $web = adminClient($app);

    // Datos para que haya fichas que abrir: un producto, una página y una entrada.
    require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';
    aifecProduct($app, ['name' => 'Silla']);

    $queue = ['/admin', '/admin/r/products/new', '/admin/r/pages/new', '/admin/r/posts/new', '/admin/r/products/1', '/', '/carrito', '/mi-cuenta', '/finalizar-compra'];
    $seen = [];
    $found = [];
    $skip = '#/(logout|salir|delete|borrar|duplicate|duplicar|export|exportar|download|descargar|restore|clear|vaciar|backup|cron|run|publish|publicar)\b|\.(zip|csv|xml|json|pdf|png|jpe?g|svg|gif|webp|css|js|map)(\?|$)#i';
    $pages = 0;
    while ($queue !== [] && $pages < 600) {
        $uri = array_shift($queue);
        if (isset($seen[$uri])) {
            continue;
        }
        $seen[$uri] = true;
        try {
            $r = $web->get($uri);
        } catch (\Throwable $e) {
            $found[$uri] = ['EXCEPCIÓN: ' . $e->getMessage()];
            continue;
        }
        $pages++;
        if ($r->status >= 500) {
            $found[$uri] = ['HTTP ' . $r->status];
            continue;
        }
        if ($r->status !== 200 || stripos($r->body, '<html') === false) {
            continue;
        }
        // De cada listado de recursos se abren también su ficha nueva y las primeras filas, aunque no haya enlaces.
        if (preg_match('#^/admin/r/([a-z0-9_-]+)$#', $uri, $res) === 1) {
            foreach (['/new', '/1', '/2'] as $tail) {
                $queue[] = $uri . $tail;
            }
        }
        $problems = aifecHtmlProblems($r->body);
        if ($problems !== []) {
            $found[$uri] = $problems;
        }
        if (preg_match_all('~<a\b[^>]*\shref="((?:/admin|/)[^"#?]*(?:\?[^"#]*)?)"~', $r->body, $links) > 0) {
            foreach ($links[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES);
                $isAdmin = strpos($href, '/admin') === 0;
                $isShop = !$isAdmin && substr_count($href, '/') <= 2;
                if (($isAdmin || $isShop) && !isset($seen[$href]) && preg_match($skip, $href) !== 1 && count($queue) < 900) {
                    $queue[] = $href;
                }
            }
        }
    }
    check($pages > 40, 'el rastreo abrió ' . $pages . ' páginas (esperadas más de 40)');
    $texto = '';
    foreach ($found as $uri => $list) {
        $texto .= "\n  " . $uri . ' → ' . implode(' | ', $list);
    }
    same('', $texto, $pages . ' páginas rastreadas');
});
