<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Semantic\Audit;

require_once __DIR__ . '/helpers.php';

/*
 * La nota de un producto en la auditoría AIFEC: un producto completo da 100 y quitar una pieza baja la nota exactamente su peso.
 */

/** Un producto físico con todo lo que pide la auditoría. @param array<string,mixed> $over @return int */
function auditCompleteProduct(App $app, array $over = []): int
{
    static $n = 0;
    ++$n;
    $brand = isset($over['brand_id']) ? (int) $over['brand_id'] : aifecBrand($app, 'Maderas Norte ' . $n);
    $category = aifecCategory($app, 'Sillas ' . $n);
    $images = [aifecMedia($app, 'Silla de roble vista de frente'), aifecMedia($app, 'Silla de roble vista de lado')];

    return aifecProduct($app, $over + [
        'name' => 'Silla de roble ' . $n, 'slug' => 'silla-roble-' . $n, 'price' => 12900, 'brand_id' => $brand, 'gtin' => '8412345678905',
        'categories' => [$category], 'images' => $images, 'summary' => 'Una silla de roble macizo.',
        'description' => str_repeat('Silla de roble macizo con acabado natural. ', 9),
        'facts' => [['label' => 'Material', 'value' => 'Roble'], ['label' => 'Altura', 'value' => '85 cm'], ['label' => 'Peso', 'value' => '5 kg']],
        'faqs' => [['question' => '¿Se monta fácil?', 'answer' => 'Sí, en diez minutos.']],
        'weight' => 5000, 'length' => 450, 'width' => 450, 'height' => 850, 'delivery_min_days' => 2, 'delivery_max_days' => 4,
    ]);
}

/**
 * @return array<string,array<string,mixed>> comprobaciones por código
 */
function auditChecks(array $result): array
{
    $out = [];
    foreach ($result['checks'] as $check) {
        $out[$check['id']] = $check;
    }

    return $out;
}

test('un producto completo da 100 y no le falta nada', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app);

    $result = $app->service('semantic')->auditProduct($id);
    same(100, $result['score']);
    $failed = array_filter($result['checks'], static function (array $check): bool {
        return !$check['ok'];
    });
    same([], array_values($failed), 'ninguna comprobación falla');
    same(array_values(array_diff(array_keys(Audit::PRODUCT), ['translations'])), array_keys(auditChecks($result)), 'con un solo idioma la comprobación de traducciones no se aplica');
    same(100, array_sum(Audit::PRODUCT), 'los pesos suman 100');
    foreach ($result['checks'] as $check) {
        foreach (['id', 'ok', 'label', 'fix_url', 'weight'] as $key) {
            check(array_key_exists($key, $check), 'la comprobación lleva «' . $key . '»');
        }
    }
});

test('sin código de barras ni referencia la nota baja su peso y se lista con el enlace a la ficha', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app, ['gtin' => null]);

    $result = $app->service('semantic')->auditProduct($id);
    same(100 - Audit::PRODUCT['identifier'], $result['score']);
    $checks = auditChecks($result);
    same(false, $checks['identifier']['ok']);
    same(12, $checks['identifier']['weight']);
    same('/admin/r/products/' . $id, $checks['identifier']['fix_url']);
    same('Tiene código de barras, o marca y referencia de fabricante', $checks['identifier']['label']);
});

test('marca y referencia de fabricante valen como identificador aunque no haya código de barras', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app, ['gtin' => null, 'mpn' => 'MN-100']);

    same(100, $app->service('semantic')->auditProduct($id)['score']);
});

test('una descripción de menos de 300 caracteres baja la nota', function (): void {
    $app = semanticApp();
    $corta = auditCompleteProduct($app, ['description' => str_repeat('a', 299)]);
    $justa = auditCompleteProduct($app, ['description' => '<p>' . str_repeat('a', 300) . '</p>']);
    $semantic = $app->service('semantic');

    same(100 - Audit::PRODUCT['description'], $semantic->auditProduct($corta)['score']);
    same(100, $semantic->auditProduct($justa)['score'], 'el HTML no cuenta: son 300 caracteres de texto');
});

test('una imagen sin texto alternativo baja la nota y enlaza a la biblioteca de medios', function (): void {
    $app = semanticApp();
    $sinAlt = aifecMedia($app, '');
    $conAlt = aifecMedia($app, 'Silla de frente');
    $id = auditCompleteProduct($app, ['images' => [$conAlt, $sinAlt]]);

    $result = $app->service('semantic')->auditProduct($id);
    same(100 - Audit::PRODUCT['alt'], $result['score'], 'la ficha rellena el texto con el nombre, pero la auditoría mira la biblioteca');
    $checks = auditChecks($result);
    same(false, $checks['alt']['ok']);
    same(true, $checks['images']['ok']);
    same('/admin/media', $checks['alt']['fix_url']);
});

test('sin ninguna imagen fallan la imagen y su texto alternativo', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app, ['images' => []]);

    $result = $app->service('semantic')->auditProduct($id);
    same(100 - Audit::PRODUCT['images'] - Audit::PRODUCT['alt'], $result['score']);
});

test('un servicio no pide peso ni plazo de entrega', function (): void {
    $app = semanticApp();
    $servicio = auditCompleteProduct($app, ['kind' => 'service', 'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0, 'delivery_min_days' => null, 'delivery_max_days' => null]);
    $fisico = auditCompleteProduct($app, ['weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0]);
    $semantic = $app->service('semantic');

    $result = $semantic->auditProduct($servicio);
    same(100, $result['score']);
    $checks = auditChecks($result);
    check(!isset($checks['weight']) && !isset($checks['delivery']), 'las dos comprobaciones ni aparecen');
    same(100 - Audit::PRODUCT['weight'], $semantic->auditProduct($fisico)['score'], 'el físico sin peso ni medidas pierde su peso');
});

test('un físico con peso pero sin una de las medidas no pasa', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app, ['height' => 0]);

    same(100 - Audit::PRODUCT['weight'], $app->service('semantic')->auditProduct($id)['score']);
});

test('con más de un idioma se pide la traducción de cada uno y con ella la comprobación pasa', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['i18n.languages' => ['es', 'en']]);
    $sin = auditCompleteProduct($app);
    $con = auditCompleteProduct($app, ['i18n' => ['en' => ['name' => 'Oak chair', 'description' => 'A solid oak chair.']]]);
    $semantic = $app->service('semantic');

    same(100 - Audit::PRODUCT['translations'], $semantic->auditProduct($sin)['score']);
    same(100, $semantic->auditProduct($con)['score']);
});

test('los atributos que pide el tipo de producto tienen que estar rellenos', function (): void {
    $app = semanticApp();
    $color = aifecAttribute($app, 'color', 'Color', ['rojo']);
    $material = aifecAttribute($app, 'material', 'Material', ['roble']);
    $family = $app->db()->insert('families', ['name' => 'Sillas', 'position' => 0]);
    foreach ([$color, $material] as $attribute) {
        $app->db()->insert('family_attributes', ['family_id' => $family, 'attribute_id' => $attribute, 'position' => 0]);
    }
    $a = auditCompleteProduct($app, ['family_id' => $family, 'attributes' => ['color' => 'rojo']]);
    $b = auditCompleteProduct($app, ['family_id' => $family, 'attributes' => ['color' => 'rojo', 'material' => 'roble']]);
    $semantic = $app->service('semantic');

    same(100 - Audit::PRODUCT['attributes'], $semantic->auditProduct($a)['score'], 'falta el material');
    same(100, $semantic->auditProduct($b)['score']);
});

test('un borrador también se puntúa y un producto que no existe da cero sin comprobaciones', function (): void {
    $app = semanticApp();
    $borrador = auditCompleteProduct($app, ['status' => 'draft']);
    $semantic = $app->service('semantic');

    same(100, $semantic->auditProduct($borrador)['score']);
    same(['score' => 0, 'checks' => []], $semantic->auditProduct(99999));
});

test('un precio de cero, sin resumen, sin datos clave o sin preguntas restan cada uno su peso', function (): void {
    $app = semanticApp();
    $semantic = $app->service('semantic');
    $casos = [
        'price' => ['price' => 0],
        'summary' => ['summary' => ''],
        'facts' => ['facts' => [['label' => 'Material', 'value' => 'Roble'], ['label' => 'Altura', 'value' => '85 cm']]],
        'faqs' => ['faqs' => []],
    ];
    foreach ($casos as $codigo => $over) {
        $id = auditCompleteProduct($app, $over);
        same(100 - Audit::PRODUCT[$codigo], $semantic->auditProduct($id)['score'], 'sin ' . $codigo);
    }
});

test('sin marca ni categoría la nota baja sus pesos (el código de barras sigue valiendo como identificador)', function (): void {
    $app = semanticApp();
    $id = auditCompleteProduct($app);
    $app->db()->update('products', ['brand_id' => null], ['id' => $id]);
    $app->db()->delete('product_categories', ['product_id' => $id]);
    $app->invalidate(['catalog']);

    $result = $app->service('semantic')->auditProduct($id);
    same(100 - Audit::PRODUCT['brand'] - Audit::PRODUCT['category'], $result['score']);
    same(true, auditChecks($result)['identifier']['ok']);
});
