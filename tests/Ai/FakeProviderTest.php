<?php
/**
 * AIFEC — pruebas del proveedor simulado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiMessage;
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiResult;
use Aifec\Ai\AiTool;
use Aifec\Ai\AiToolCall;
use Aifec\Ai\FakeProvider;

test('Simulado: sin guion devuelve [simulado] y las 12 primeras palabras, siempre igual', function (): void {
    $ai = new FakeProvider();
    $req = new AiRequest('', [AiMessage::user('uno dos tres cuatro cinco seis siete ocho nueve diez once doce trece catorce')]);
    $a = $ai->generate($req);
    $b = $ai->generate($req);

    same('[simulado] uno dos tres cuatro cinco seis siete ocho nueve diez once doce', $a->text);
    same($a->text, $b->text);
    same([], $a->toolCalls);
    same(null, $a->json);
    same('fake', $ai->name());
    check($ai instanceof AiProvider);
    same(2, count($ai->requests));
});

test('Simulado: el último mensaje del usuario manda y un mensaje vacío no rompe nada', function (): void {
    $ai = new FakeProvider();
    $r = $ai->generate(new AiRequest('', [AiMessage::user('primero'), AiMessage::assistant('ok'), AiMessage::user("  ñandú\n&  café  ")]));
    same('[simulado] ñandú & café', $r->text);

    $r = $ai->generate(new AiRequest('', []));
    same('[simulado] ', $r->text);
});

test('Simulado: el guion se consume por orden y después vuelve a contestar solo', function (): void {
    $ai = new FakeProvider();
    $ai->script(new AiResult('primera', [], null, 5, 6, 'STOP', 'm'), new AiResult('segunda'));
    $req = new AiRequest('', [AiMessage::user('hola mundo')]);

    $one = $ai->generate($req);
    same('primera', $one->text);
    same(5, $one->tokensIn);
    same('segunda', $ai->generate($req)->text);
    same('[simulado] hola mundo', $ai->generate($req)->text);
    same(3, count($ai->requests));
    same($req, $ai->requests[0]);
});

test('Simulado: con jsonSchema devuelve un JSON válido con objeto anidado, lista y enum', function (): void {
    $ai = new FakeProvider();
    $schema = [
        'type' => 'object',
        'properties' => [
            'titulo' => ['type' => 'string'],
            'precio' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'activo' => ['type' => 'boolean'],
            'estado' => ['type' => 'string', 'enum' => ['borrador', 'publicado']],
            'etiquetas' => ['type' => 'array', 'items' => ['type' => 'string']],
            'dimensiones' => ['type' => 'object', 'properties' => [
                'ancho' => ['type' => 'number'],
                'unidad' => ['enum' => ['cm', 'mm']],
                'vacio' => ['type' => 'object', 'properties' => []],
            ]],
        ],
    ];
    $res = $ai->generate(new AiRequest('', [AiMessage::user('x')], [], $schema));

    $expected = [
        'titulo' => '',
        'precio' => 0,
        'ratio' => 0,
        'activo' => false,
        'estado' => 'borrador',
        'etiquetas' => [],
        'dimensiones' => ['ancho' => 0, 'unidad' => 'cm', 'vacio' => []],
    ];
    same($expected, $res->json);
    same($expected, json_decode($res->text, true));
    contains('"vacio":{}', $res->text);
    contains('"etiquetas":[]', $res->text);
});

test('Simulado: con jsonSchema de tipo lista o con anyOf también devuelve algo válido', function (): void {
    $ai = new FakeProvider();
    $res = $ai->generate(new AiRequest('', [AiMessage::user('x')], [], ['type' => 'array', 'items' => ['type' => 'string']]));
    same('[]', $res->text);
    same([], $res->json);

    $res = $ai->generate(new AiRequest('', [AiMessage::user('x')], [], ['type' => 'object', 'properties' => ['a' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]], 'b' => ['type' => ['null', 'integer']]]]));
    same(['a' => '', 'b' => 0], $res->json);
});

test('Simulado: llamada a herramienta por coincidencia con el nombre o la descripción', function (): void {
    $ai = new FakeProvider();
    $tools = [
        new AiTool('create_order', 'Crea un pedido nuevo', []),
        new AiTool('searchProducts', 'Busca productos del catálogo', []),
    ];

    $res = $ai->generate(new AiRequest('', [AiMessage::user('Quiero buscar un producto')], $tools));
    same('', $res->text);
    same(0, count($res->toolCalls) - 1);
    same('searchProducts', $res->toolCalls[0]->name);
    same([], $res->toolCalls[0]->arguments);
    same('sim_searchProducts', $res->toolCalls[0]->id);
    same('tool_calls', $res->finishReason);

    $res = $ai->generate(new AiRequest('', [AiMessage::user('haz un PEDIDO')], $tools));
    same('create_order', $res->toolCalls[0]->name);

    $res = $ai->generate(new AiRequest('', [AiMessage::user('buenos días')], $tools));
    same([], $res->toolCalls, 'sin coincidencia contesta con texto');
    same('[simulado] buenos días', $res->text);
});

test('Simulado: tras el resultado de una herramienta contesta con texto y no pide otra llamada', function (): void {
    $ai = new FakeProvider();
    $tools = [new AiTool('search', 'Busca productos', [])];
    $res = $ai->generate(new AiRequest('', [
        AiMessage::user('busca productos'),
        AiMessage::assistant('', [new AiToolCall('1', 'search', [])]),
        AiMessage::tool('search', ['total' => 1]),
    ], $tools));

    same([], $res->toolCalls);
    same('[simulado] busca productos', $res->text);
});

test('Simulado: las palabras cortas o corrientes no disparan una herramienta', function (): void {
    $ai = new FakeProvider();
    $res = $ai->generate(new AiRequest('', [AiMessage::user('la que los con para')], [new AiTool('lookup', 'Busca la que los con para', [])]));
    same([], $res->toolCalls);
});

test('Simulado: embed es determinista, tiene 256 dimensiones y está normalizado', function (): void {
    $ai = new FakeProvider();
    $a = $ai->embed(['silla ergonómica', 'taladro percutor', '']);
    $b = $ai->embed(['silla ergonómica', 'taladro percutor', '']);

    same($a, $b);
    same(3, count($a));
    same(256, count($a[0]));
    near(1.0, sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $a[0]))), 1e-9);
    same(256, count($a[2]), 'un texto vacío también devuelve un vector');
    same(0, count($ai->requests), 'embed no cuenta como petición de generate');
});

test('Simulado: textos parecidos quedan más cerca que textos distintos', function (): void {
    $ai = new FakeProvider();
    [$silla, $sillas, $taladro] = $ai->embed(['silla ergonómica', 'sillas ergonomicas', 'taladro percutor']);
    $dot = static function (array $x, array $y): float {
        $s = 0.0;
        foreach ($x as $i => $v) {
            $s += $v * $y[$i];
        }

        return $s;
    };

    check($dot($silla, $sillas) > $dot($silla, $taladro) + 0.2, 'silla debería parecerse más a sillas que a taladro');
});
