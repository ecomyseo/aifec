<?php
/**
 * AIFEC — pruebas de la codificación WinAnsi y de las tablas de anchos del PDF.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Pdf\PdfEncoding;
use Aifec\Pdf\PdfException;
use Aifec\Pdf\PdfFontMetrics;

test('WinAnsi: letras españolas, símbolos y el euro', function (): void {
    same("\xE1\xE9\xED\xF3\xFA\xFC\xF1\xD1\xBF\xA1\xBA\xAA\x80\xAB\xBB", PdfEncoding::encode('áéíóúüñÑ¿¡ºª€«»'));
    same("\x93hola\x94 \x96 \x97 \x85 \x95 \x99", PdfEncoding::encode('“hola” – — … • ™'));
    same('', PdfEncoding::encode(''));
    same('Hola, mundo 123', PdfEncoding::encode('Hola, mundo 123'));
});

test('WinAnsi: lo que no existe sale como «?», los controles como espacio y el UTF-8 roto no lanza', function (): void {
    same('???', PdfEncoding::encode('ひ😀Ω'));
    same('a b c d', PdfEncoding::encode("a\tb\nc\x7Fd"));
    same('x??y', PdfEncoding::encode("x\xFF\xFEy"), 'bytes inválidos, uno por «?»');
    same('a??b', PdfEncoding::encode("a\xE2\x82b"), 'secuencia cortada');
    same('a?', PdfEncoding::encode("a\xC3"), 'cortada al final');
    same('??', PdfEncoding::encode("\xC0\x80"), 'forma larga inválida');
    same('???', PdfEncoding::encode("\xED\xA0\x80"), 'sustituto UTF-16 suelto');
    same('?', PdfEncoding::encode("\xC2\x81"), 'el 0x81 de CP1252 no existe');
});

test('las letras compuestas con acento suelto se unen antes de convertir', function (): void {
    if (!class_exists('Normalizer')) {
        same("n\xCC\x83", "n\xCC\x83");

        return;
    }
    same("\xF1", PdfEncoding::encode("n\xCC\x83"), 'n + virgulilla suelta es una ñ');
    same(['ñ'], PdfEncoding::chars("n\xCC\x83"));
});

test('chars devuelve cada carácter completo y codePoint su punto de código', function (): void {
    same(['a', 'ñ', '€', '😀', 'z'], PdfEncoding::chars('añ€😀z'));
    same([], PdfEncoding::chars(''));
    same(0x41, PdfEncoding::codePoint('A'));
    same(0xF1, PdfEncoding::codePoint('ñ'));
    same(0x20AC, PdfEncoding::codePoint('€'));
    same(0x1F600, PdfEncoding::codePoint('😀'));
});

test('escape protege paréntesis y barras invertidas', function (): void {
    same('a\\(b\\)c\\\\d', PdfEncoding::escape('a(b)c\\d'));
    same('sin nada', PdfEncoding::escape('sin nada'));
    same('\\\\\\(', PdfEncoding::escape('\\('));
});

test('pdfString: literal si es ASCII y UTF-16BE con marca si lleva acentos o emojis', function (): void {
    same('(Factura \\(2026\\))', PdfEncoding::pdfString('Factura (2026)'));
    same('<FEFF00E100F1>', PdfEncoding::pdfString('áñ'));
    same('<FEFFD83DDE00>', PdfEncoding::pdfString('😀'));
    same('<FEFF20AC>', PdfEncoding::pdfString('€'));
    same('()', PdfEncoding::pdfString(''));
});

test('las tablas de anchos tienen 256 entradas y los valores de los AFM de Adobe', function (): void {
    $h = PdfFontMetrics::widths('Helvetica');
    $b = PdfFontMetrics::widths('Helvetica-Bold');
    same(256, count($h));
    same(256, count($b));
    same(278, $h[32]);
    same(722, $h[ord('H')]);
    same(556, $h[ord('o')]);
    same(222, $h[ord('l')]);
    same(944, $h[ord('W')]);
    same(1015, $h[ord('@')]);
    same(722, $h[0xD1], 'Ñ');
    same(556, $h[0xF1], 'ñ');
    same(556, $h[0x80], '€');
    same(1000, $h[0x85], '…');
    same(1000, $h[0xC6], 'Æ');
    same(611, $h[0xDF], 'ß');
    same(333, $h[0xB2], '²');
    same(611, $b[ord('b')]);
    same(556, $b[ord('a')]);
    same(611, $b[ord('?')]);
    same(975, $b[ord('@')]);
    same(611, $b[0xF1], 'ñ en negrita');
    same(556, $b[0x80], '€ en negrita');
    for ($code = 32; $code < 256; ++$code) {
        check($h[$code] > 0 && $b[$code] > 0, 'ancho cero en el byte ' . $code);
    }
    // Huella de las tablas completas, sacadas de los AFM de Adobe: si alguien toca un ancho, salta aquí.
    same('9b6f18d045e1906526716aabb75b00d7', md5(implode(',', $h)), 'tabla de Helvetica');
    same('f1d502d6af73e48a29ec411e08fa5f1c', md5(implode(',', $b)), 'tabla de Helvetica-Bold');
});

test('Courier es de ancho fijo y la cursiva usa los anchos de la fuente recta', function (): void {
    foreach (['Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique'] as $name) {
        same([600], array_values(array_unique(PdfFontMetrics::widths($name))), $name);
    }
    same(PdfFontMetrics::widths('Helvetica'), PdfFontMetrics::widths('Helvetica-Oblique'));
    same(PdfFontMetrics::widths('Helvetica-Bold'), PdfFontMetrics::widths('Helvetica-BoldOblique'));
    same(2056, PdfFontMetrics::width('Helvetica', 'Hola'));
    same(2400, PdfFontMetrics::width('Courier', 'Hola'));
    same(0, PdfFontMetrics::width('Helvetica', ''));
});

test('baseFont resuelve familia y estilo a su nombre PostScript', function (): void {
    same('Helvetica', PdfFontMetrics::baseFont('Helvetica', ''));
    same('Helvetica', PdfFontMetrics::baseFont('arial', ''));
    same('Helvetica-Bold', PdfFontMetrics::baseFont('Helvetica', 'B'));
    same('Helvetica-Oblique', PdfFontMetrics::baseFont('HELVETICA', 'i'));
    same('Helvetica-BoldOblique', PdfFontMetrics::baseFont('Helvetica', 'BI'));
    same('Helvetica-BoldOblique', PdfFontMetrics::baseFont('Helvetica', 'IB'));
    same('Courier', PdfFontMetrics::baseFont('Courier', ''));
    same('Courier-Bold', PdfFontMetrics::baseFont('courier', 'B'));
    throws(function (): void {
        PdfFontMetrics::baseFont('Times', '');
    }, PdfException::class);
    throws(function (): void {
        PdfFontMetrics::baseFont('Helvetica', 'X');
    }, PdfException::class);
    throws(function (): void {
        PdfFontMetrics::widths('Times-Roman');
    }, PdfException::class);
});
