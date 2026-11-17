<?php
/**
 * AIFEC — pruebas de las utilidades de vectores.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Search\Vector;

test('el coseno de un vector consigo mismo es 1, el de uno ortogonal 0 y el del opuesto -1', function (): void {
    $v = [0.3, -1.2, 4.5, 0.01];
    near(1.0, Vector::cosine($v, $v), 1e-9);
    near(0.0, Vector::cosine([1, 0], [0, 1]), 1e-12);
    near(-1.0, Vector::cosine([1, 2], [-1, -2]), 1e-9);
});

test('el coseno de un vector nulo o de dimensiones distintas es 0 y no falla', function (): void {
    near(0.0, Vector::cosine([0, 0], [1, 1]));
    near(0.0, Vector::cosine([], []));
    near(0.0, Vector::cosine([1, 2, 3], [1, 2]));
});

test('normalize deja longitud 1 y respeta el vector nulo', function (): void {
    $n = Vector::normalize([3, 4]);
    near(0.6, $n[0], 1e-12);
    near(0.8, $n[1], 1e-12);
    same([0.0, 0.0], Vector::normalize([0, 0]));
    same([], Vector::normalize([]));
});

test('pack y unpack conservan los valores con error menor de 1e-6', function (): void {
    $v = Vector::hashEmbedding('silla ergonómica de oficina', 64);
    $v[] = -0.123456;
    $v[] = 1.0;
    $v[] = 0.0;
    $volver = Vector::unpack(Vector::pack($v));
    same(count($v), count($volver));
    foreach ($v as $i => $x) {
        near($x, $volver[$i], 1e-6);
    }
    same(4 * count($v), strlen(Vector::pack($v)));
    same('', Vector::pack([]));
    same([], Vector::unpack(''));
});

test('unpack rechaza una longitud que no es múltiplo de 4', function (): void {
    throws(function (): void {
        Vector::unpack('abcde');
    }, \InvalidArgumentException::class);
});

test('topK ordena por coseno, respeta k y desempata por id', function (): void {
    $cand = [
        'cerca' => [0.9, 0.1],
        'igual' => [1.0, 0.0],
        'lejos' => [0.0, 1.0],
        'otro-igual' => [2.0, 0.0],
        7 => [-1.0, 0.0],
        'dimension-mala' => [1.0, 0.0, 0.0],
    ];
    $res = Vector::topK([1.0, 0.0], $cand, 3);
    same(['igual', 'otro-igual', 'cerca'], array_column($res, 'id'));
    near(1.0, $res[0]['score'], 1e-9);

    $todos = Vector::topK([1.0, 0.0], $cand, 99);
    same(5, count($todos), 'el de otra dimensión se salta');
    same('7', $todos[4]['id']);
    near(-1.0, $todos[4]['score'], 1e-9);

    same([], Vector::topK([1.0, 0.0], $cand, 0));
    same([], Vector::topK([], $cand, 3));
    same([], Vector::topK([0.0, 0.0], $cand, 3));
    same([], Vector::topK([1.0, 0.0], [], 3));
});

test('hashEmbedding es determinista, tiene la dimensión pedida y longitud 1', function (): void {
    $a = Vector::hashEmbedding('silla ergonómica', 256);
    $b = Vector::hashEmbedding('silla ergonómica', 256);
    same($a, $b);
    same(256, count($a));
    near(1.0, sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $a))), 1e-9);
    same(32, count(Vector::hashEmbedding('algo', 32)));
});

test('«silla ergonómica» queda más cerca de «sillas ergonomicas» que de «taladro percutor»', function (): void {
    $q = Vector::hashEmbedding('silla ergonómica');
    $cerca = Vector::cosine($q, Vector::hashEmbedding('sillas ergonomicas'));
    $lejos = Vector::cosine($q, Vector::hashEmbedding('taladro percutor'));
    near(1.0, $cerca, 1e-9);
    check($cerca > $lejos + 0.5, "cerca {$cerca} lejos {$lejos}");
});

test('las erratas y las palabras compartidas acercan los vectores', function (): void {
    $q = Vector::hashEmbedding('zapatillas deportivas');
    $errata = Vector::cosine($q, Vector::hashEmbedding('zapatilas deportivas'));
    $parcial = Vector::cosine(Vector::hashEmbedding('silla de oficina'), Vector::hashEmbedding('silla ergonomica oficina'));
    $otro = Vector::cosine($q, Vector::hashEmbedding('taladro percutor'));
    check($errata > 0.6, "errata {$errata}");
    check($parcial > 0.5, "parcial {$parcial}");
    check($errata > $otro + 0.4, "errata {$errata} otro {$otro}");
});

test('hashEmbedding lleva signo: con pocas posiciones aparecen cosenos negativos', function (): void {
    $palabras = ['taladro', 'percutor', 'mesa', 'zapatillas', 'bizum', 'factura', 'cable', 'lampara', 'bolso', 'camiseta'];
    $negativos = 0;
    foreach ($palabras as $i => $a) {
        foreach ($palabras as $j => $b) {
            if ($i < $j && Vector::cosine(Vector::hashEmbedding($a, 8), Vector::hashEmbedding($b, 8)) < 0) {
                ++$negativos;
            }
        }
    }
    check($negativos > 0, 'sin signo todos los cosenos serían positivos');
});

test('hashEmbedding de un texto sin palabras es el vector nulo y no falla', function (): void {
    same(array_fill(0, 16, 0.0), Vector::hashEmbedding('', 16));
    same(array_fill(0, 16, 0.0), Vector::hashEmbedding('😀 !!!', 16));
    near(0.0, Vector::cosine(Vector::hashEmbedding('', 16), Vector::hashEmbedding('silla', 16)));
    throws(function (): void {
        Vector::hashEmbedding('x', 0);
    }, \InvalidArgumentException::class);
});

test('hashEmbedding aguanta 5.000 palabras', function (): void {
    $v = Vector::hashEmbedding(str_repeat('silla ergonómica con reposabrazos ', 1000), 128);
    same(128, count($v));
    near(1.0, sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v))), 1e-9);
});
