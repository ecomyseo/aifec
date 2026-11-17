<?php
/**
 * AIFEC — pruebas de la puntuación BM25F.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Search\Bm25;
use Aifec\Search\Tokenizer;

/** Índice de tres módulos con el nombre y el resto de campos tokenizados. */
function search_demo_index(): Bm25
{
    $t = new Tokenizer('es');
    $index = new Bm25();
    $index->add('bizum', ['name' => $t->tokens('Bizum para PrestaShop pago móvil')]);
    $index->add('facturas', ['name' => $t->tokens('Facturas y contabilidad')]);
    $index->add('antibots', ['name' => $t->tokens('Bloqueo de bots y spam')]);

    return $index;
}

test('«pagar con bizum» pone primero el módulo de Bizum', function (): void {
    $t = new Tokenizer('es');
    $res = search_demo_index()->search($t->tokens('pagar con bizum'));
    same('bizum', $res[0]['id']);
    same(1, count($res), 'solo sale lo que contiene algún término');
    check($res[0]['score'] > 0.0);
});

test('el peso del campo cambia el orden', function (): void {
    $docs = [
        'a' => ['name' => ['bizum'], 'attrs' => ['otro', 'cosa']],
        'b' => ['name' => ['otro'], 'attrs' => ['bizum', 'cosa']],
    ];

    $porNombre = new Bm25();
    $porNombre->setWeights(['name' => 3, 'attrs' => 1]);
    $porAtributos = new Bm25();
    $porAtributos->setWeights(['name' => 1, 'attrs' => 3]);
    foreach ($docs as $id => $fields) {
        $porNombre->add($id, $fields);
        $porAtributos->add($id, $fields);
    }

    same('a', $porNombre->search(['bizum'])[0]['id']);
    same('b', $porAtributos->search(['bizum'])[0]['id']);
});

test('export e import dan los mismos resultados, también tras pasar por JSON', function (): void {
    $t = new Tokenizer('es');
    $index = search_demo_index();
    $index->setWeights(['name' => 2.5]);
    $consulta = $t->tokens('bots de bizum y facturas');
    $esperado = $index->search($consulta, 10);

    same($esperado, Bm25::import($index->export())->search($consulta, 10));

    $json = json_encode($index->export());
    check(is_string($json));
    $copia = Bm25::import(json_decode((string) $json, true));
    same($esperado, $copia->search($consulta, 10));
    same($index->vocabulary(), $copia->vocabulary());
    same(3, $copia->count());
});

test('a igualdad de puntuación manda el id', function (): void {
    $index = new Bm25();
    foreach (['c', 'a', 'b'] as $id) {
        $index->add($id, ['name' => ['bizum']]);
    }
    same(['a', 'b', 'c'], array_column($index->search(['bizum']), 'id'));
});

test('el límite se respeta y un índice o una consulta vacíos no fallan', function (): void {
    $index = new Bm25();
    same([], $index->search(['x']));
    foreach (['a', 'b', 'c', 'd'] as $id) {
        $index->add($id, ['name' => ['x']]);
    }
    same(2, count($index->search(['x'], 2)));
    same([], $index->search([]));
    same([], $index->search(['x'], 0));
    same([], $index->search(['inexistente']));
});

test('un término raro puntúa más que uno corriente y un campo corto más que uno largo', function (): void {
    $index = new Bm25();
    $index->add('corto', ['name' => ['bizum']]);
    $index->add('largo', ['name' => ['bizum', 'a', 'b', 'c', 'd', 'e', 'f', 'g']]);
    $index->add('otro1', ['name' => ['comun']]);
    $index->add('otro2', ['name' => ['comun']]);
    $index->add('otro3', ['name' => ['comun']]);
    $index->add('otro4', ['name' => ['comun']]);

    $res = $index->search(['bizum']);
    same(['corto', 'largo'], array_column($res, 'id'));
    check($res[0]['score'] > $res[1]['score']);

    $raro = $res[0]['score'];
    $comun = $index->search(['comun'])[0]['score'];
    check($raro > $comun, "bizum (2 de 6 documentos) pesa más que comun (4 de 6): {$raro} contra {$comun}");
});

test('volver a añadir un documento lo sustituye y quitarlo lo borra', function (): void {
    $index = new Bm25();
    $index->add('a', ['name' => ['viejo']]);
    $index->add('a', ['name' => ['nuevo']]);
    same(1, $index->count());
    same([], $index->search(['viejo']));
    same('a', $index->search(['nuevo'])[0]['id']);
    same(['nuevo'], $index->vocabulary());

    same(true, $index->remove('a'));
    same(false, $index->remove('a'));
    same([], $index->search(['nuevo']));
    same([], $index->vocabulary());
});

test('ids y términos numéricos sobreviven al JSON y los ids salen como texto', function (): void {
    $index = new Bm25();
    $index->add('10', ['name' => ['130', '130kg']]);
    $index->add('9', ['name' => ['130']]);
    $copia = Bm25::import(json_decode((string) json_encode($index->export()), true));

    $res = $copia->search(['130kg', '130']);
    same('10', $res[0]['id']);
    same('string', gettype($res[0]['id']));
    same(['130', '130kg'], $copia->vocabulary());
    same(['130' => 2, '130kg' => 1], $copia->documentFrequencies());
});

test('un campo ausente, vacío o con basura no rompe nada', function (): void {
    $index = new Bm25();
    $index->add('a', ['name' => [], 'attrs' => ['x', '', 5], 'raro' => 'no es lista']);
    $index->add('b', []);
    same(['5', 'x'], $index->vocabulary());
    same('a', $index->search(['x'])[0]['id']);
    throws(function (): void {
        Bm25::import(['k1' => 1.2]);
    }, \InvalidArgumentException::class);
});

test('un df estropeado en la importación no falsea la puntuación', function (): void {
    $index = search_demo_index();
    $export = $index->export();
    $esperado = $index->search(['bizum']);
    $export['df'] = ['bizum' => 999];

    same($esperado, Bm25::import($export)->search(['bizum']));
});
