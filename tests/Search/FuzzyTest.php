<?php
/**
 * AIFEC — pruebas de la tolerancia a erratas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Search\Fuzzy;

test('corrige erratas corrientes y no toca lo que ya está en el vocabulario', function (): void {
    $f = new Fuzzy(['zapatillas', 'bizum', 'silla', 'mesa', 'taladro']);
    same('zapatillas', $f->closest('zapatilas'));
    same('bizum', $f->closest('bizun'));
    same('silla', $f->closest('silla'));
    same('silla', $f->closest('sila'));
});

test('una trasposición cuenta como una sola edición', function (): void {
    $f = new Fuzzy(['mesa', 'bizum']);
    same('mesa', $f->closest('msea'));
    same('bizum', $f->closest('bizmu'));
    same(1, Fuzzy::distance('msea', 'mesa'));
    same(2, Fuzzy::distance('abc', 'xbd'));
    same(0, Fuzzy::distance('', ''));
    same(3, Fuzzy::distance('', 'abc'));
});

test('una palabra corta no se corrige a otra muy distinta', function (): void {
    $f = new Fuzzy(['pato', 'perro', 'abc', 'casa']);
    same(null, $f->closest('gat'));
    same(null, $f->closest('xyz'));
    same(null, $f->closest('ab'), 'menos de tres letras no se corrige');
    same('abc', $f->closest('abd'));
    same(null, $f->closest('cxxa'), 'cuatro letras admiten una sola edición');
});

test('las palabras largas admiten dos ediciones y no más', function (): void {
    $f = new Fuzzy(['ergonomico', 'taladro']);
    same('ergonomico', $f->closest('ergnmico'));
    same(null, $f->closest('ergnmic'));
    same('ergonomico', $f->closest('ergnmico', 2));
    same(null, $f->closest('ergnmico', 1));
});

test('a igualdad de distancia gana el más frecuente y después el alfabético', function (): void {
    same('cosa', (new Fuzzy(['casa' => 1, 'cosa' => 5]))->closest('cxsa'));
    same('casa', (new Fuzzy(['cosa' => 2, 'casa' => 2]))->closest('cxsa'));
    same('casa', (new Fuzzy(['casa' => 1, 'cosa' => 9]))->closest('casx'), 'la distancia manda sobre la frecuencia');
});

test('expand sustituye los desconocidos y apunta las correcciones', function (): void {
    $f = new Fuzzy(['zapatillas' => 4, 'silla' => 9, 'rojas' => 1]);
    $r = $f->expand(['zapatilas', 'silla', '130', 'rojas', 'qwertyuiop', 'se-01x']);
    same(['zapatillas', 'silla', '130', 'rojas', 'qwertyuiop', 'se-01x'], $r['tokens']);
    same(['zapatilas' => 'zapatillas'], $r['corrections']);
    same(['tokens' => [], 'corrections' => []], $f->expand([]));
});

test('vocabulario vacío, término vacío y vocabulario con basura', function (): void {
    same(null, (new Fuzzy([]))->closest('silla'));
    same(null, (new Fuzzy(['silla']))->closest(''));
    $f = new Fuzzy(['silla', '', 7 => 'mesa', 'x' => null, 'y' => [1], 'banco' => 3]);
    same('mesa', $f->closest('mesx'));
    same('banco', $f->closest('bancp'));
});

test('funciona sobre caracteres multibyte', function (): void {
    $f = new Fuzzy(['ñandú', 'camión']);
    same('ñandú', $f->closest('ñandu'));
    same('camión', $f->closest('camion'));
    same(1, Fuzzy::distance('ñandú', 'ñandu'));
});

test('con palabras largas la preselección por trigramas encuentra lo mismo que comparar con todo', function (): void {
    mt_srand(20260512);
    $letras = 'abcdefghilmnoprstu';
    $vocab = [];
    while (count($vocab) < 400) {
        $palabra = '';
        $largo = mt_rand(11, 15);
        for ($i = 0; $i < $largo; ++$i) {
            $palabra .= $letras[mt_rand(0, strlen($letras) - 1)];
        }
        $vocab[$palabra] = mt_rand(1, 50);
    }
    $terminos = array_map('strval', array_keys($vocab));
    $f = new Fuzzy($vocab);

    $comprobadas = 0;
    foreach ($terminos as $i => $palabra) {
        if ($i % 8 !== 0) {
            continue;
        }
        $chars = str_split($palabra);
        $pos = mt_rand(1, count($chars) - 3);
        $errata = [
            'sustitución' => substr_replace($palabra, 'z', $pos, 1),
            'borrado' => substr($palabra, 0, $pos) . substr($palabra, $pos + 1),
            'trasposición' => substr($palabra, 0, $pos) . $palabra[$pos + 1] . $palabra[$pos] . substr($palabra, $pos + 2),
            'dos ediciones' => substr($palabra, 0, 2) . substr($palabra, 3, 4) . 'q' . substr($palabra, 8),
        ];
        foreach ($errata as $tipo => $mala) {
            // Referencia lenta: compara con todo el vocabulario.
            $mejor = null;
            $mejorD = 3;
            $mejorF = 0;
            foreach ($terminos as $candidato) {
                $d = Fuzzy::distance($mala, $candidato, 2);
                $fr = $vocab[$candidato];
                if ($d > 2) {
                    continue;
                }
                if ($mejor === null || $d < $mejorD || ($d === $mejorD && ($fr > $mejorF || ($fr === $mejorF && strcmp($candidato, $mejor) < 0)))) {
                    $mejor = $candidato;
                    $mejorD = $d;
                    $mejorF = $fr;
                }
            }
            same($mejor, $f->closest($mala), "$tipo: «{$mala}» (de «{$palabra}»)");
            ++$comprobadas;
        }
    }
    check($comprobadas >= 150, 'se han comprobado suficientes casos: ' . $comprobadas);
});
