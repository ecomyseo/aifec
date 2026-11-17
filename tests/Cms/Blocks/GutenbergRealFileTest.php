<?php
/**
 * AIFEC — conversión del fichero real de 9.000 palabras de la página de AIFEC en WordPress.
 *
 * Se usa el original de la carpeta de manuales si existe y, si no, su copia en `fixtures/`.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\BlockRenderer;
use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Cms\Blocks\BlockText;
use Aifec\Cms\Blocks\GutenbergParser;
use Aifec\Text\HtmlSanitizer;

/** El contenido del fichero real. */
function cmsbRealSource(): string
{
    $original = dirname(__DIR__, 3) . '/../manuales/AI-first e-commerce/aifec-pagina-wordpress-bloques.txt';
    $path = is_file($original) ? $original : __DIR__ . '/fixtures/aifec-pagina-wordpress-bloques.txt';
    $content = file_get_contents($path);
    if (!is_string($content) || strlen($content) < 50000) {
        throw new \RuntimeException('No se puede leer el fichero real de la página: ' . $path);
    }

    return $content;
}

/** Palabras del origen sin etiquetas ni comentarios: las etiquetas de bloque separan, las de formato no. */
function cmsbRealWords(string $source): int
{
    $text = (string) preg_replace('/<!--.*?-->/s', '', $source);
    $text = (string) preg_replace('#</?(?:p|h[1-6]|li|ul|ol|div|tr|td|th|table|thead|tbody|figure|figcaption|details|summary|blockquote|hr)\b[^>]*>#i', ' ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

    return is_array($words) ? count($words) : 0;
}

/** @return array<int,array<string,mixed>> */
function cmsbRealFlat(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        $out[] = $b;
        foreach (cmsbRealFlat($b['children']) as $c) {
            $out[] = $c;
        }
    }

    return $out;
}

test('el fichero real se convierte sin perder encabezados, tablas, detalles, listas ni la cita', function (): void {
    $source = cmsbRealSource();
    $blocks = GutenbergParser::parse($source);
    $flat = cmsbRealFlat($blocks);
    $types = array_count_values(array_map(static function (array $b): string {
        return $b['type'];
    }, $flat));

    same(substr_count($source, '<!-- wp:heading'), $types['heading'] ?? 0);
    same(substr_count($source, '<!-- wp:table'), $types['table'] ?? 0);
    same(3, $types['table']);
    same(substr_count($source, '<!-- wp:details'), $types['details'] ?? 0);
    same(10, $types['details']);
    same(substr_count($source, '<!-- wp:list '), $types['list'] ?? 0);
    same(substr_count($source, '<!-- wp:quote'), $types['quote'] ?? 0);
    same(substr_count($source, '<!-- wp:separator'), $types['separator'] ?? 0);
    same(1, $types['group']);

    $items = 0;
    foreach ($flat as $b) {
        if ($b['type'] === 'list') {
            $items += count($b['attrs']['items']);
        }
    }
    same(substr_count($source, '<!-- wp:list-item'), $items);

    // Cada encabezado con su nivel: mismo número de h2 y de h3 que en el origen.
    $levels = [2 => 0, 3 => 0];
    foreach ($flat as $b) {
        if ($b['type'] === 'heading') {
            ++$levels[$b['attrs']['level']];
        }
    }
    same(substr_count($source, '<h2'), $levels[2]);
    same(substr_count($source, '<h3'), $levels[3]);
});

test('el fichero real: nada sin reconocer, ningún bloque html con review', function (): void {
    $blocks = GutenbergParser::parse(cmsbRealSource());
    foreach (cmsbRealFlat($blocks) as $b) {
        check($b['type'] !== 'html', 'bloque html inesperado: ' . substr(json_encode($b['attrs']) ?: '', 0, 200));
        check(!isset($b['attrs']['review']), 'bloque con review');
    }
    same([], BlockSchema::errors($blocks));
    same($blocks, BlockSchema::validate($blocks), 'el resultado ya está normalizado');
    same([], GutenbergParser::shortcodes(cmsbRealSource()));
});

test('el fichero real: las palabras coinciden con las del origen dentro del 2 %', function (): void {
    $source = cmsbRealSource();
    $blocks = GutenbergParser::parse($source);
    $origin = cmsbRealWords($source);
    $got = BlockText::words($blocks);
    check($origin > 8000, 'el origen debería tener unas 9.000 palabras: ' . $origin);
    check(abs($got - $origin) <= $origin * 0.02, "palabras: origen {$origin}, bloques {$got}");
});

test('el fichero real pintado: sin comentarios de WordPress y con el último párrafo', function (): void {
    $source = cmsbRealSource();
    $html = (new BlockRenderer())->render(GutenbergParser::parse($source));

    notContains('<!-- wp:', $html);
    notContains('<!-- /wp:', $html);
    notContains('<!--', $html);
    notContains('wp-block-', $html);

    same(substr_count($source, '<!-- wp:heading'), preg_match_all('/<h[23]\b/', $html));
    same(3, preg_match_all('/<table\b/', $html));
    same(10, preg_match_all('/<details\b/', $html));
    same(1, preg_match_all('/<blockquote\b/', $html));
    same(substr_count($source, '<!-- wp:list '), preg_match_all('/<(?:ul|ol)\b[^>]*af-b-list/', $html));
    same(substr_count($source, '<!-- wp:list-item'), preg_match_all('/<li\b/', $html));

    // El último párrafo del origen, entero, está en el texto del HTML pintado.
    preg_match_all('#<!-- wp:paragraph -->\s*<p[^>]*>(.*?)</p>\s*<!-- /wp:paragraph -->#s', $source, $paragraphs);
    $last = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) end($paragraphs[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    check(strlen($last) > 100, 'el último párrafo debería ser largo');
    $rendered = (string) preg_replace('/\s+/u', ' ', HtmlSanitizer::text($html));
    contains($last, $rendered);
    check(substr($html, -4) === '</p>', 'el HTML acaba con el último párrafo');
});

test('el fichero real: los enlaces del índice llevan a anclas que existen', function (): void {
    $blocks = GutenbergParser::parse(cmsbRealSource());
    $html = (new BlockRenderer())->render($blocks);
    preg_match_all('/<a href="#([^"]+)">/', $html, $links);
    check(count($links[1]) >= 10, 'el índice debería tener enlaces internos');
    preg_match_all('/<h[1-6][^>]* id="([^"]+)"/', $html, $ids);
    same(count($ids[1]), count(array_unique($ids[1])), 'anclas repetidas');
    foreach ($links[1] as $link) {
        check(in_array($link, $ids[1], true), 'el enlace #' . $link . ' no tiene encabezado');
    }
});

test('el fichero real: nada ejecutable en el HTML pintado', function (): void {
    $html = (new BlockRenderer())->render(GutenbergParser::parse(cmsbRealSource()));
    foreach (['<script', 'javascript:', ' onclick', ' onerror', '<iframe', '<style'] as $bad) {
        notContains($bad, $html);
    }
});
