<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\SchemaCheck;

/**
 * @return array<string,mixed>
 */
function assistantSchema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['id'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1],
            'note' => ['type' => 'string', 'maxLength' => 5],
            'on' => ['type' => 'boolean', 'default' => false],
            'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
            'tags' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2],
        ],
    ];
}

test('una entrada correcta sale normalizada, con los valores por defecto puestos', function (): void {
    $r = SchemaCheck::check(assistantSchema(), ['id' => 7]);
    same(true, $r['ok']);
    same(['id' => 7, 'qty' => 1, 'on' => false], $r['value']);
});

test('los números que llegan como texto («5», «5.0») se convierten; el texto que no es número es un error', function (): void {
    $r = SchemaCheck::check(assistantSchema(), ['id' => '12', 'qty' => 3.0]);
    same(true, $r['ok']);
    same(12, $r['value']['id']);
    same(3, $r['value']['qty']);

    $bad = SchemaCheck::check(assistantSchema(), ['id' => 'doce']);
    same(false, $bad['ok']);
    contains('input.id must be an integer', $bad['errors'][0]);
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 2.5])['ok'], '2,5 no es un entero');
    same(false, SchemaCheck::check(assistantSchema(), ['id' => true])['ok'], 'un booleano no es un entero');
});

test('lo que falta es un error y lo que sobra también cuando el esquema cierra las propiedades', function (): void {
    $missing = SchemaCheck::check(assistantSchema(), []);
    same(false, $missing['ok']);
    contains('input.id is required', $missing['errors'][0]);

    $extra = SchemaCheck::check(assistantSchema(), ['id' => 1, 'price' => 1]);
    same(false, $extra['ok']);
    contains('input.price is not accepted', $extra['errors'][0]);
});

test('los límites de números, textos y listas se comprueban', function (): void {
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 0])['ok'], 'mínimo');
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'qty' => 100])['ok'], 'máximo');
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'note' => 'demasiado'])['ok'], 'largo máximo');
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'tags' => [1, 2, 3]])['ok'], 'elementos máximos');
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'tags' => [1, 'x']])['ok'], 'los elementos también se validan');
    same(true, SchemaCheck::check(assistantSchema(), ['id' => 1, 'tags' => ['1', 2]])['ok']);
    same([1, 2], SchemaCheck::check(assistantSchema(), ['id' => 1, 'tags' => ['1', 2]])['value']['tags']);
});

test('un valor fuera de la lista (enum) es un error; los textos se recortan de espacios; un null cuenta como ausente', function (): void {
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'mode' => 'c'])['ok']);
    $r = SchemaCheck::check(assistantSchema(), ['id' => 1, 'mode' => ' a ', 'note' => null]);
    same(true, $r['ok']);
    same('a', $r['value']['mode']);
    check(!array_key_exists('note', $r['value']), 'un null no es un valor');
});

test('los booleanos aceptan «true», «false», 1 y 0 y rechazan lo demás; una lista donde va un objeto es un error', function (): void {
    same(true, SchemaCheck::check(assistantSchema(), ['id' => 1, 'on' => 'true'])['value']['on']);
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'on' => 0])['value']['on']);
    same(false, SchemaCheck::check(assistantSchema(), ['id' => 1, 'on' => 'quizá'])['ok']);
    same(false, SchemaCheck::check(assistantSchema(), [1, 2, 3])['ok'], 'una lista no es un objeto');
    same(false, SchemaCheck::check(assistantSchema(), 'texto')['ok']);
});
