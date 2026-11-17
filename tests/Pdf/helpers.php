<?php
/**
 * AIFEC — utilidades de las pruebas del generador de PDF.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Flujos de contenido de cada página (ya descomprimidos), en el orden del documento.
 * Los flujos de imagen se saltan: su diccionario empieza por /Type /XObject.
 *
 * @return string[]
 */
function pdfTestPageStreams(string $pdf): array
{
    $streams = [];
    $found = preg_match_all('/\d+ 0 obj\n<<\s+(\/Filter \/FlateDecode\s+)?\/Length (\d+) >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE);
    if ($found === false || $found === 0) {
        return $streams;
    }
    foreach ($m[0] as $i => $hit) {
        $start = $hit[1] + strlen($hit[0]);
        $length = (int) $m[2][$i][0];
        $data = substr($pdf, $start, $length);
        if ($m[1][$i][0] !== '') {
            $data = gzuncompress($data);
            check(is_string($data), 'un flujo de contenido no se puede descomprimir');
        }
        $streams[] = $data;
    }

    return $streams;
}

/** Todo el contenido de todas las páginas junto. */
function pdfTestContent(string $pdf): string
{
    return implode("\n", pdfTestPageStreams($pdf));
}

/**
 * Recorre la tabla xref y comprueba que cada desplazamiento apunta de verdad a «n 0 obj».
 * Devuelve el número de objetos en uso.
 */
function pdfTestCheckXref(string $pdf): int
{
    check(preg_match('/startxref\n(\d+)\n%%EOF$/', $pdf, $m) === 1, 'falta startxref al final');
    $xrefPos = (int) $m[1];
    check(substr($pdf, $xrefPos, 5) === "xref\n", 'startxref no apunta a la tabla xref');
    check(preg_match('/^xref\n0 (\d+)\n/', substr($pdf, $xrefPos, 40), $h) === 1, 'cabecera de xref ilegible');
    $count = (int) $h[1];
    $pos = $xrefPos + strlen($h[0]);
    $inUse = 0;
    for ($n = 0; $n < $count; ++$n) {
        $entry = substr($pdf, $pos + $n * 20, 20);
        check(strlen($entry) === 20 && substr($entry, 18) === " \n", "la entrada {$n} de xref no mide 20 bytes");
        check(preg_match('/^(\d{10}) (\d{5}) ([nf]) /', $entry, $e) === 1, "entrada {$n} de xref mal formada");
        if ($e[3] === 'n') {
            $offset = (int) $e[1];
            $label = $n . ' 0 obj';
            check(substr($pdf, $offset, strlen($label)) === $label, "el desplazamiento de «{$label}» no apunta a su objeto");
            ++$inUse;
        }
    }
    check(preg_match('/trailer\n<< \/Size (\d+) /', $pdf, $t) === 1 && (int) $t[1] === $count, '/Size no coincide con la tabla xref');

    return $inUse;
}
