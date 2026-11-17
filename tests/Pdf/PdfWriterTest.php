<?php
/**
 * AIFEC — pruebas del generador de PDF (estructura, texto, cursor, páginas y salida).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Pdf\PdfException;
use Aifec\Pdf\PdfWriter;

require_once __DIR__ . '/helpers.php';

test('el PDF empieza por %PDF-1.4, acaba en %%EOF y cada desplazamiento de xref apunta a su objeto', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->setFont('Helvetica', 'B', 14);
    $pdf->cell(0, 10, 'Factura 2026-0001', 'L', true);
    $pdf->setFont('Courier', '', 9);
    $pdf->text(20, 50, 'Courier');
    $data = $pdf->output();

    same('%PDF-1.4', substr($data, 0, 8));
    same('%%EOF', substr($data, -5));
    $inUse = pdfTestCheckXref($data);
    // catálogo, páginas, recursos, 2 fuentes, información, 1 página y su contenido
    same(8, $inUse);
    contains('/Type /Catalog', $data);
    contains('/Root 1 0 R', $data);
    contains('/BaseFont /Helvetica-Bold', $data);
    contains('/BaseFont /Courier', $data);
    notContains('/BaseFont /Helvetica ', $data, 'no se incluye una fuente que no se ha usado');
    contains('/Encoding /WinAnsiEncoding', $data);
});

test('el número de páginas declarado coincide con las añadidas', function (): void {
    $pdf = new PdfWriter();
    same(0, $pdf->pageCount());
    foreach ([1, 2, 3] as $i) {
        $pdf->addPage();
        $pdf->text(10, 20, 'pagina ' . $i);
    }
    same(3, $pdf->pageCount());
    $data = $pdf->output();
    contains('/Count 3', $data);
    same(3, preg_match_all('/\/Type \/Page \/Parent 2 0 R/', $data));
    pdfTestCheckXref($data);
});

test('sin ninguna página añadida el PDF sale con una página vacía y válida', function (): void {
    $data = (new PdfWriter())->output();
    contains('/Count 1', $data);
    pdfTestCheckXref($data);
});

test('el papel: A4 vertical, apaisado y otros tamaños', function (): void {
    $a4 = new PdfWriter();
    near(210.0, $a4->pageWidth(), 0.01);
    near(297.0, $a4->pageHeight(), 0.01);
    contains('/MediaBox [0 0 595.28 841.89]', $a4->output());

    $land = new PdfWriter('L', 'A4');
    near(297.0, $land->pageWidth(), 0.01);
    near(210.0, $land->pageHeight(), 0.01);
    contains('/MediaBox [0 0 841.89 595.28]', $land->output());

    $letter = new PdfWriter('P', 'letter');
    near(215.9, $letter->pageWidth(), 0.01);

    throws(function (): void {
        new PdfWriter('X');
    }, PdfException::class);
    throws(function (): void {
        new PdfWriter('P', 'A9');
    }, PdfException::class);
});

test('stringWidth coincide con la suma de los anchos AFM de cada fuente', function (): void {
    $k = 72 / 25.4;
    $pdf = new PdfWriter();
    $pdf->setFont('Helvetica', '', 10);
    // H=722, o=556, l=222, a=556
    near((722 + 556 + 222 + 556) * 10 / 1000 / $k, $pdf->stringWidth('Hola'), 1e-9);
    near(0.0, $pdf->stringWidth(''), 1e-12);

    $pdf->setFont('Helvetica', 'B', 10);
    // H=722, o=611, l=278, a=556
    near((722 + 611 + 278 + 556) * 10 / 1000 / $k, $pdf->stringWidth('Hola'), 1e-9);

    $pdf->setFont('Helvetica', 'I', 10);
    near((722 + 556 + 222 + 556) * 10 / 1000 / $k, $pdf->stringWidth('Hola'), 1e-9, 'la cursiva usa los anchos de Helvetica');

    $pdf->setFont('Courier', '', 12);
    near(4 * 600 * 12 / 1000 / $k, $pdf->stringWidth('Hola'), 1e-9);

    $pdf->setFont('Helvetica', '', 20);
    near((722 + 556 + 222 + 556) * 20 / 1000 / $k, $pdf->stringWidth('Hola'), 1e-9, 'el ancho crece con el cuerpo');
    // ñ (0xF1) y € (0x80) existen en CP1252: se miden como tales y no como «?»
    $pdf->setFont('Helvetica', '', 10);
    near((556 + 556) * 10 / 1000 / $k, $pdf->stringWidth('ñ€'), 1e-9);
    same($pdf->stringWidth('?'), $pdf->stringWidth('😀'), 'un emoji se mide como «?»');
});

test('el texto UTF-8 sale en CP1252 en el flujo del PDF', function (): void {
    $pdf = new PdfWriter();
    $pdf->setCompression(true);
    $pdf->addPage();
    $pdf->text(10, 20, 'Año 2026: 49,99 € ¿válido?');
    $content = pdfTestContent($pdf->output());
    contains("(A\xF1o 2026: 49,99 \x80 \xBFv\xE1lido?) Tj", $content);
    notContains("\xC3\xB1", $content, 'no quedan bytes UTF-8');

    $pdf2 = new PdfWriter();
    $pdf2->addPage();
    $pdf2->text(10, 20, 'á é í ó ú ü ñ Ñ ¿ ¡ º ª € « »');
    $c2 = pdfTestContent($pdf2->output());
    contains("(\xE1 \xE9 \xED \xF3 \xFA \xFC \xF1 \xD1 \xBF \xA1 \xBA \xAA \x80 \xAB \xBB) Tj", $c2);
});

test('lo que no existe en CP1252 sale como «?», y paréntesis y barras se escapan', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->text(10, 20, "ひ😀x");
    $pdf->text(10, 30, 'a(b)c\\d');
    $pdf->text(10, 40, "UTF-8 roto: \xFF\xC3 fin");
    $pdf->text(10, 50, '');
    $content = pdfTestContent($pdf->output());
    contains('(??x) Tj', $content);
    contains('(a\\(b\\)c\\\\d) Tj', $content);
    contains('(UTF-8 roto: ?? fin) Tj', $content);
    same(3, substr_count($content, ' Tj'), 'el texto vacío no escribe nada');
});

test('las celdas alinean a derecha y centro con las coordenadas correctas', function (): void {
    $k = 72 / 25.4;
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $pdf->setFont('Helvetica', '', 10);
    $pdf->addPage();
    $pdf->setXY(10, 10);
    $pdf->cell(100, 10, 'Hola', 'R');
    $pdf->setXY(10, 30);
    $pdf->cell(100, 10, 'Hola', 'C');
    $pdf->setXY(10, 50);
    $pdf->cell(100, 10, 'Hola');
    $content = pdfTestContent($pdf->output());
    $tw = (722 + 556 + 222 + 556) * 10 / 1000 / $k;
    $hPt = 841.89;

    check(preg_match_all('/(-?[\d.]+) (-?[\d.]+) Td \(Hola\) Tj/', $content, $m) === 3);
    near((10 + 100 - 1 - $tw) * $k, (float) $m[1][0], 0.01, 'derecha');
    near((10 + (100 - $tw) / 2) * $k, (float) $m[1][1], 0.01, 'centro');
    near((10 + 1) * $k, (float) $m[1][2], 0.01, 'izquierda con el margen de celda');
    near($hPt - (10 + 5 + 0.3 * 10 / $k) * $k, (float) $m[2][0], 0.01, 'línea de base');
});

test('cell avanza el cursor y ln lo baja', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->setXY(10, 20);
    $pdf->cell(50, 8, 'uno');
    near(60.0, $pdf->getX(), 1e-9);
    near(20.0, $pdf->getY(), 1e-9);
    $pdf->ln();
    near(10.0, $pdf->getX(), 1e-9, 'ln vuelve al margen izquierdo');
    near(28.0, $pdf->getY(), 1e-9, 'ln sin parámetro baja el alto de la última celda');
    $pdf->ln(4);
    near(32.0, $pdf->getY(), 1e-9);
    $pdf->cell(40, 6, 'dos', 'L', false, false, 1);
    near(10.0, $pdf->getX(), 1e-9);
    near(38.0, $pdf->getY(), 1e-9);
    $pdf->setY(-15);
    near(282.0, $pdf->getY(), 0.01, 'una Y negativa cuenta desde abajo');
});

test('multiCell parte por palabras, devuelve el alto y salta de página sola', function (): void {
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->setXY(10, 20);
    near(15.0, $pdf->multiCell(60, 5, "uno\ndos\ntres"), 1e-9);
    near(35.0, $pdf->getY(), 1e-9);
    near(10.0, $pdf->getX(), 1e-9);
    near(5.0, $pdf->multiCell(60, 5, ''), 1e-9, 'un texto vacío ocupa una línea');

    $long = new PdfWriter();
    $long->addPage();
    $words = [];
    for ($i = 1; $i <= 900; ++$i) {
        $words[] = 'palabra' . $i;
    }
    $text = implode(' ', $words);
    $height = $long->multiCell(0, 5, $text);
    check($long->pageCount() > 1, 'un texto largo tiene que pasar a otra página');
    $lines = $long->wrapText($text, $long->contentWidth() - 2);
    near(count($lines) * 5.0, $height, 1e-9);
    $content = pdfTestContent($long->output());
    contains('(palabra1 palabra2 ', $content);
    contains('palabra900) Tj', $content, 'no se pierde la última palabra');
});

test('el partido de líneas no deja ninguna línea más ancha que la celda', function (): void {
    $pdf = new PdfWriter();
    $pdf->setFont('Helvetica', '', 10);
    $text = "Esta es una frase con palabras normales y una larguísima: " . str_repeat('Supercalifragilístico', 4)
        . " y otra con iiiiiiiiiiiiiiiiiiiiiiiiii WWWWWWWWWWWWWWWWWWWW\n\nPárrafo tras línea vacía con emoji 😀 y año 2026 ¿no? "
        . str_repeat('ab ', 60);
    foreach ([20.0, 35.0, 60.0, 120.0] as $max) {
        $lines = $pdf->wrapText($text, $max);
        check(count($lines) > 1);
        foreach ($lines as $line) {
            check($pdf->stringWidth($line) <= $max + 0.001, "«{$line}» mide " . $pdf->stringWidth($line) . " y la celda {$max}");
        }
        // nada se pierde: sin espacios ni saltos, el texto es el mismo
        $strip = static function (string $s): string {
            return str_replace([' ', "\n"], '', $s);
        };
        same($strip(str_replace('😀', '?', $text)), $strip(str_replace('😀', '?', implode('', $lines))));
    }
    same([''], $pdf->wrapText('', 50.0));
    same(['a', '', 'b'], $pdf->wrapText("a\n\nb", 50.0));
    same(['uno dos'], $pdf->wrapText('uno dos   ', 50.0), 'los espacios del final no cuentan');
    // una celda más estrecha que una letra no se queda en un bucle: una letra por línea
    same(['a', 'b', 'c'], $pdf->wrapText('abc', 0.5));
});

test('{nb} se sustituye por el total de páginas, también en el pie con el ancho bien medido', function (): void {
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $pdf->onFooter(function (PdfWriter $p): void {
        $p->setFont('Helvetica', '', 8);
        $p->setY(-15);
        $p->cell(0, 5, 'Página ' . $p->pageNo() . ' de {nb}', 'R');
    });
    for ($i = 1; $i <= 3; ++$i) {
        $pdf->addPage();
        $pdf->text(10, 20, 'cuerpo {nb} ' . $i);
    }
    $data = $pdf->output();
    $pages = pdfTestPageStreams($data);
    same(3, count($pages));
    contains("(P\xE1gina 1 de 3) Tj", $pages[0]);
    contains("(P\xE1gina 2 de 3) Tj", $pages[1]);
    contains("(P\xE1gina 3 de 3) Tj", $pages[2]);
    contains('(cuerpo 3 2) Tj', $pages[1], 'el marcador del cuerpo también se resuelve');
    notContains('{nb}', $data);

    // el pie sale pegado al margen derecho: se mide con el «3» ya puesto
    $k = 72 / 25.4;
    $probe = new PdfWriter();
    $probe->setFont('Helvetica', '', 8);
    $width = $probe->stringWidth("Página 1 de 3");
    check(preg_match('/(-?[\d.]+) (-?[\d.]+) Td \(P\xE1gina 1 de 3\) Tj/', $pages[0], $m) === 1);
    near((10 + (210 - 20) - 1 - $width) * $k, (float) $m[1], 0.01);

    // pedir el PDF dos veces da lo mismo: los pies no se duplican
    same($data, $pdf->output());
    same(1, substr_count($pages[0], 'Página 1') + substr_count($pages[0], "P\xE1gina 1"));
});

test('la cabecera se ejecuta en cada página, también en las que añade el salto automático', function (): void {
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $calls = 0;
    $pdf->onHeader(function (PdfWriter $p) use (&$calls): void {
        ++$calls;
        $p->setFont('Helvetica', 'B', 12);
        $p->setFillColor(200, 0, 0);
        $p->cell(0, 10, 'CABECERA');
        $p->ln(15);
    });
    $pdf->addPage();
    near(25.0, $pdf->getY(), 1e-9, 'el cuerpo empieza debajo de la cabecera');
    $pdf->setFont('Courier', '', 10);
    $pdf->setFillColor(0, 0, 255);
    for ($i = 0; $i < 120; ++$i) {
        $pdf->cell(0, 5, 'fila ' . $i, 'L', false, false, 1);
    }
    check($pdf->pageCount() >= 3);
    same($pdf->pageCount(), $calls);
    $pages = pdfTestPageStreams($pdf->output());
    foreach ($pages as $i => $content) {
        contains('(CABECERA) Tj', $content, 'página ' . ($i + 1));
    }
    // la cabecera cambió la fuente y el color, pero el cuerpo conserva los suyos
    contains('/F2 10 Tf', $pages[1]);
    contains('0.000 0.000 1.000 rg', $pages[1]);
});

test('el salto automático de página se puede desactivar', function (): void {
    $on = new PdfWriter();
    $on->addPage();
    $on->setXY(10, 280);
    $on->cell(50, 10, 'abajo');
    same(2, $on->pageCount());
    near(10.0, $on->getY(), 1e-9, 'tras el salto escribe arriba');

    $off = new PdfWriter();
    $off->setAutoPageBreak(false);
    $off->addPage();
    $off->setXY(10, 280);
    $off->cell(50, 10, 'abajo');
    same(1, $off->pageCount());
    near(280.0, $off->getY(), 1e-9);
});

test('dibujo: colores, líneas y rectángulos con la Y invertida', function (): void {
    $k = 72 / 25.4;
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $pdf->setDrawColor(255, 0, 0);
    $pdf->setFillColor(0, 128, 255);
    $pdf->setLineWidth(0.5);
    $pdf->addPage();
    $pdf->rect(10, 20, 50, 30, 'DF');
    $pdf->rect(10, 60, 5, 5, 'F');
    $pdf->rect(10, 70, 5, 5);
    $pdf->line(0, 0, 210, 297);
    $c = pdfTestContent($pdf->output());
    contains('1.000 0.000 0.000 RG', $c);
    contains('0.000 0.502 1.000 rg', $c);
    contains(sprintf('%.2F w', 0.5 * $k), $c);
    contains(sprintf('%.2F %.2F %.2F %.2F re B', 10 * $k, 841.89 - 50 * $k, 50 * $k, 30 * $k), $c);
    contains(' re f', $c);
    contains(' re S', $c);
    contains(sprintf('0 %.2F m %.2F 0 l S', 841.89, 210 * $k), $c, 'la esquina de arriba a la izquierda es y = 841,89');
    throws(function () use ($pdf): void {
        $pdf->rect(0, 0, 1, 1, 'Z');
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->setFillColor(300, 0, 0);
    }, PdfException::class);
});

test('título, autor y fecha: texto con acentos en UTF-16 y fecha fijada', function (): void {
    $pdf = new PdfWriter();
    $pdf->setTitle('Factura');
    $pdf->setAuthor('Ecom Expérts ñ');
    $pdf->setCreationDate(1700000000);
    $data = $pdf->output();
    contains('/Title (Factura)', $data);
    contains('/Author <FEFF', $data);
    contains('00E9', $data);
    contains('/CreationDate (D:20231114221320Z)', $data);

    $plain = (new PdfWriter())->output();
    notContains('/CreationDate', $plain, 'sin fecha fijada no se inventa una');
});

test('dos generaciones con la misma fecha dan bytes idénticos', function (): void {
    $make = function (int $date): string {
        $pdf = new PdfWriter();
        $pdf->setTitle('Albarán');
        $pdf->setCreationDate($date);
        $pdf->addPage();
        $pdf->setFont('Helvetica', 'B', 12);
        $pdf->multiCell(80, 6, "Línea uno\nLínea dos con más texto para que se parta");
        $pdf->table(
            [['label' => 'A', 'width' => 40, 'align' => 'L'], ['label' => 'B', 'width' => 40, 'align' => 'R']],
            [['x', '1,00'], ['y', '2,00']]
        );

        return $pdf->output();
    };
    same($make(1700000000), $make(1700000000));
    check($make(1700000000) !== $make(1700000001), 'otra fecha, otros bytes');
});

test('save escribe el mismo PDF que output y avisa si no puede', function (): void {
    $dir = tmpDir();
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->text(10, 10, 'guardado');
    $path = $dir . '/factura.pdf';
    $pdf->save($path);
    same($pdf->output(), (string) file_get_contents($path));
    throws(function () use ($pdf, $dir): void {
        $pdf->save($dir . '/no-existe/factura.pdf');
    }, PdfException::class);
});

test('parámetros inválidos lanzan PdfException', function (): void {
    $pdf = new PdfWriter();
    throws(function () use ($pdf): void {
        $pdf->setFont('Comic Sans', '', 10);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->setFont('Helvetica', 'Z', 10);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->setFont('Helvetica', '', 0);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->setMargins(200, 10, 200, 10);
    }, PdfException::class);
    throws(function () use ($pdf): void {
        $pdf->setLineWidth(-1);
    }, PdfException::class);
    // los alias de la familia valen
    $pdf->setFont('Arial', 'BI', 10);
    contains('/BaseFont /Helvetica-BoldOblique', (function () use ($pdf): string {
        $pdf->addPage();
        $pdf->text(10, 10, 'x');

        return $pdf->output();
    })());
});
