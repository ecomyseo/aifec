<?php
/**
 * AIFEC — pruebas de las tablas del generador de PDF.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Pdf\PdfException;
use Aifec\Pdf\PdfWriter;

require_once __DIR__ . '/helpers.php';

/** Columnas de prueba: concepto a la izquierda e importe a la derecha. */
function pdfTestColumns(float $first = 100.0): array
{
    return [
        ['label' => 'Concepto', 'width' => $first, 'align' => 'L'],
        ['label' => 'Importe', 'width' => 40, 'align' => 'R'],
    ];
}

/**
 * Rectángulos del contenido: [x, y, ancho, alto, operador] en puntos.
 *
 * @return array<int,array<int,string>>
 */
function pdfTestRects(string $content): array
{
    preg_match_all('/(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) re ([SBf])/', $content, $m, PREG_SET_ORDER);

    return array_map(static function (array $r): array {
        return array_slice($r, 1);
    }, $m);
}

test('la cabecera de la tabla se repite en cada página y no se pierde ninguna fila', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $rows = [];
    for ($i = 1; $i <= 120; ++$i) {
        $rows[] = ['Fila ' . $i, number_format($i * 1234.56, 2, ',', '.')];
    }
    $pdf->table(pdfTestColumns(), $rows);
    check($pdf->pageCount() >= 3, 'ciento veinte filas no caben en dos páginas');
    $pages = pdfTestPageStreams($pdf->output());
    same($pdf->pageCount(), count($pages));
    foreach ($pages as $i => $content) {
        contains('(Concepto) Tj', $content, 'cabecera en la página ' . ($i + 1));
        contains('(Importe) Tj', $content);
    }
    $all = implode("\n", $pages);
    contains('(Fila 1) Tj', $all);
    contains('(Fila 120) Tj', $all);
    contains('(148.147,20) Tj', $all, 'el importe de la fila 120');
    same(120, preg_match_all('/\(Fila \d+\) Tj/', $all), 'cada fila sale una vez');
    // la cabecera va en negrita y las filas en normal
    contains('/BaseFont /Helvetica-Bold', $pdf->output());
});

test('las celdas con texto largo se parten y todas las de la fila tienen el mismo alto', function (): void {
    $k = 72 / 25.4;
    $pdf = new PdfWriter();
    $pdf->addPage();
    $long = 'Un concepto con una descripción bastante larga que no cabe en una sola línea de la celda';
    $pdf->table(pdfTestColumns(60), [['corto', '1,00'], [$long, '2,00']]);
    $lines = count($pdf->wrapText($long, 60 - 2));
    check($lines >= 3, 'el texto largo ocupa varias líneas');
    $rects = pdfTestRects(pdfTestContent($pdf->output()));
    // cabecera (2 rectángulos) + 2 filas (2 rectángulos cada una)
    same(6, count($rects));
    near(5 * $k, (float) $rects[2][3], 0.01);
    near(5 * $k, (float) $rects[3][3], 0.01);
    near($lines * 5 * $k, (float) $rects[4][3], 0.01, 'celda del texto largo');
    near($lines * 5 * $k, (float) $rects[5][3], 0.01, 'la celda de al lado mide lo mismo');
    // y queda en su sitio: el importe de la fila alta va a la derecha en la primera línea
    contains('(2,00) Tj', pdfTestContent($pdf->output()));
});

test('una fila que no cabe se pasa entera a la página siguiente con la cabecera repetida', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->setXY(10, 270);
    $pdf->table(pdfTestColumns(), [["a\nb\nc\nd", '9,99']]);
    same(2, $pdf->pageCount());
    $pages = pdfTestPageStreams($pdf->output());
    notContains('(a) Tj', $pages[0], 'la fila no se queda partida en la primera página');
    contains('(Concepto) Tj', $pages[1]);
    contains('(a) Tj', $pages[1]);
    contains('(d) Tj', $pages[1]);
    contains('(9,99) Tj', $pages[1]);
});

test('una fila más alta que una página se reparte por líneas sin perder nada', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $words = [];
    for ($i = 1; $i <= 400; ++$i) {
        $words[] = sprintf('W%03d', $i);
    }
    $pdf->table([['label' => 'Texto', 'width' => 20, 'align' => 'L']], [[implode(' ', $words)]]);
    check($pdf->pageCount() >= 4, 'son unos mil milímetros de alto');
    $content = pdfTestContent($pdf->output());
    $missing = [];
    foreach ($words as $word) {
        if (strpos($content, $word) === false) {
            $missing[] = $word;
        }
    }
    same([], $missing, 'palabras que han desaparecido');
});

test('el cursor queda debajo de la tabla y la tabla respeta la X de partida', function (): void {
    $k = 72 / 25.4;
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->setXY(30, 20);
    $pdf->table(pdfTestColumns(), [['a', '1'], ['b', '2'], ['c', '3']], ['lineHeight' => 6]);
    near(30.0, $pdf->getX(), 1e-9);
    near(20 + 4 * 6, $pdf->getY(), 1e-9, 'cabecera y tres filas de 6 mm');
    $rects = pdfTestRects(pdfTestContent($pdf->output()));
    near(30 * $k, (float) $rects[0][0], 0.01);
    near((30 + 100) * $k, (float) $rects[1][0], 0.01, 'la segunda columna empieza donde acaba la primera');
});

test('opciones: sin cabecera, sin borde y sin relleno', function (): void {
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $pdf->addPage();
    $pdf->table(pdfTestColumns(), [['x', '1']], ['header' => false, 'border' => false]);
    $c = pdfTestContent($pdf->output());
    notContains(' re ', $c, 'sin borde ni relleno no hay rectángulos');
    notContains('(Concepto)', $c);
    contains('(x) Tj', $c);

    $b = new PdfWriter();
    $b->setCompression(false);
    $b->addPage();
    $b->table(pdfTestColumns(), [['x', '1']], ['headerFill' => null]);
    $rects = pdfTestRects(pdfTestContent($b->output()));
    same('S', $rects[0][4], 'sin relleno la cabecera solo lleva borde');

    $f = new PdfWriter();
    $f->setCompression(false);
    $f->addPage();
    $f->table(pdfTestColumns(), [], ['headerFill' => [10, 20, 30]]);
    $content = pdfTestContent($f->output());
    contains('0.039 0.078 0.118 rg', $content);
    contains('0.000 0.000 0.000 rg', $content, 'el color de relleno anterior se repone');
    same(2, count(pdfTestRects($content)), 'sin filas, solo la cabecera');
});

test('los textos con tildes, comillas y «&» salen bien en las celdas de la tabla', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->table(pdfTestColumns(), [['Café "especial" & más (1 ud.)', '3,50 €'], [null, 5]]);
    $content = pdfTestContent($pdf->output());
    contains("(Caf\xE9 \"especial\" & m\xE1s \\(1 ud.\\)) Tj", $content);
    contains("(3,50 \x80) Tj", $content);
    contains('(5) Tj', $content, 'un número se convierte en texto');
});

test('una tabla mal definida lanza PdfException', function (): void {
    $pdf = new PdfWriter();
    throws(function () use ($pdf): void {
        $pdf->table([], []);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->table([['label' => 'Sin ancho']], []);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->table([['label' => 'Cero', 'width' => 0]], []);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->table(pdfTestColumns(), ['no soy una fila']);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->table(pdfTestColumns(), [], ['lineHeight' => 0]);
    }, PdfException::class);
});
