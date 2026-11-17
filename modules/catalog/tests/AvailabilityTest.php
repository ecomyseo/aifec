<?php
/**
 * AIFEC — pruebas de la disponibilidad: los cuatro estados con y sin variantes, el aviso de pocas unidades, los plazos
 * de entrega y el filtro sellado product.availability.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * @param array<string,mixed> $datos
 *
 * @return array<string,mixed>
 */
function catalogAvailabilityOf(array $datos, array $settings = []): array
{
    $app = catalogApp($settings);

    return aifecCatalog($app)->product(aifecProduct($app, $datos))['availability'];
}

test('sin control de existencias: en stock y sin cantidad', function (): void {
    $a = catalogAvailabilityOf(['manage_stock' => 0, 'stock' => 0]);
    same('in_stock', $a['status']);
    same(null, $a['qty']);
    same('En stock', $a['label']);
});

test('con existencias suficientes: en stock con su cantidad', function (): void {
    $a = catalogAvailabilityOf(['stock' => 20]);
    same('in_stock', $a['status']);
    same(20, $a['qty']);
    same('En stock', $a['label']);
});

test('quedan pocas: hasta el aviso por defecto (5) incluido, con el texto en singular y plural', function (): void {
    same('low_stock', catalogAvailabilityOf(['stock' => 5])['status']);
    same('Quedan 5', catalogAvailabilityOf(['stock' => 5])['label']);
    same('Queda 1', catalogAvailabilityOf(['stock' => 1])['label']);
    same('in_stock', catalogAvailabilityOf(['stock' => 6])['status']);
});

test('el aviso de pocas unidades es el mayor entre el del producto y catalog.low_stock', function (): void {
    same('low_stock', catalogAvailabilityOf(['stock' => 8, 'stock_alert' => 8])['status']);
    same('in_stock', catalogAvailabilityOf(['stock' => 9, 'stock_alert' => 8])['status']);
    same('in_stock', catalogAvailabilityOf(['stock' => 3], ['catalog.low_stock' => 2])['status'], 'ajuste a 2: con 3 hay de sobra');
    same('low_stock', catalogAvailabilityOf(['stock' => 2], ['catalog.low_stock' => 2])['status']);
    same('low_stock', catalogAvailabilityOf(['stock' => 7, 'stock_alert' => 3], ['catalog.low_stock' => 7])['status'], 'gana el ajuste cuando es mayor');
});

test('sin existencias: agotado, o bajo pedido si se admite; «avisar» no vende', function (): void {
    $out = catalogAvailabilityOf(['stock' => 0]);
    same('out_of_stock', $out['status']);
    same(0, $out['qty']);
    same('Agotado', $out['label']);
    $backorder = catalogAvailabilityOf(['stock' => 0, 'backorders' => 'yes']);
    same('backorder', $backorder['status']);
    same('Bajo pedido', $backorder['label']);
    same('out_of_stock', catalogAvailabilityOf(['stock' => 0, 'backorders' => 'notify'])['status']);
    $negative = catalogAvailabilityOf(['stock' => -3, 'backorders' => 'yes']);
    same('backorder', $negative['status']);
    same(0, $negative['qty'], 'la cantidad que se enseña nunca es negativa');
});

test('plazo de entrega: los dos extremos, solo uno o ninguno', function (): void {
    same([2, 4], catalogAvailabilityOf(['delivery_min_days' => 2, 'delivery_max_days' => 4])['delivery_days']);
    same([3, 3], catalogAvailabilityOf(['delivery_min_days' => 3])['delivery_days']);
    same([5, 5], catalogAvailabilityOf(['delivery_max_days' => 5])['delivery_days']);
    same(null, catalogAvailabilityOf([])['delivery_days']);
});

test('la disponibilidad tiene las claves del contrato', function (): void {
    $a = catalogAvailabilityOf([]);
    same(['status', 'qty', 'label', 'delivery_days', 'purchasable', 'show_price', 'schema'], array_keys($a));
    same(true, $a['purchasable'], 'sin plugins, se puede comprar');
    same(true, $a['show_price'], 'sin plugins, se enseña el precio');
    same('', $a['schema'], 'sin plugins, la disponibilidad de las máquinas es la de siempre');
});

test('con variantes, el producto resume las activas: la mejor manda y la cantidad es la suma', function (): void {
    $a = catalogAvailabilityOf(['variants' => [['stock' => 0], ['stock' => 3], ['stock' => 20]]]);
    same('in_stock', $a['status']);
    same(23, $a['qty']);
    $low = catalogAvailabilityOf(['variants' => [['stock' => 0], ['stock' => 3], ['stock' => 1]]]);
    same('low_stock', $low['status']);
    same(4, $low['qty']);
    same('Quedan 4', $low['label']);
    $none = catalogAvailabilityOf(['variants' => [['stock' => 0], ['stock' => 0]]]);
    same('out_of_stock', $none['status']);
    same(0, $none['qty']);
    $backorder = catalogAvailabilityOf(['backorders' => 'yes', 'variants' => [['stock' => 0], ['stock' => 0]]]);
    same('backorder', $backorder['status']);
});

test('con variantes, una variante inactiva no cuenta, y cada variante tiene la suya', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['variants' => [['sku' => 'a', 'stock' => 50, 'active' => false], ['sku' => 'b', 'stock' => 0], ['sku' => 'c', 'stock' => 2]]]);
    $product = aifecCatalog($app)->product($id);
    same('low_stock', $product['availability']['status'], 'la de 50 está desactivada');
    same(2, $product['availability']['qty']);
    same(2, count($product['variants']), 'solo salen las activas');
    same('out_of_stock', $product['variants'][0]['availability']['status']);
    same('low_stock', $product['variants'][1]['availability']['status']);
    same(2, $product['variants'][1]['availability']['qty']);
});

test('con variantes, todas inactivas: no se puede comprar', function (): void {
    $a = catalogAvailabilityOf(['variants' => [['stock' => 9, 'active' => false]]]);
    same('out_of_stock', $a['status']);
});

test('con variantes y sin control de existencias: en stock', function (): void {
    $a = catalogAvailabilityOf(['manage_stock' => 0, 'variants' => [['stock' => 0]]]);
    same('in_stock', $a['status']);
    same(null, $a['qty']);
});

test('en inglés, los textos salen sin traducir', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    $id = aifecProduct($app, ['stock' => 0]);
    same('Sold out', aifecCatalog($app)->product($id, 'en')['availability']['label']);
    same('Agotado', aifecCatalog($app)->product($id, 'es')['availability']['label']);
});

test('product.availability: sellado, lo que cambia un módulo con «pricing» queda en la ficha', function (): void {
    $app = catalogApp();
    $hooks = $app->hooks();
    $hooks->setCurrentModule('intruso');
    $hooks->filter('product.availability', static function (array $a): array {
        $a['status'] = 'out_of_stock';

        return $a;
    });
    $id = aifecProduct($app, ['stock' => 20]);
    same('in_stock', aifecCatalog($app)->product($id)['availability']['status'], 'sin capacidad no entra');
    $hooks->setCurrentModule('catalog');
    $hooks->filter('product.availability', static function (array $a, array $product): array {
        $a['label'] = 'Entrega hoy (' . $product['sku'] . ')';

        return $a;
    });
    $other = aifecProduct($app, ['stock' => 20, 'sku' => 'Z9']);
    same('Entrega hoy (Z9)', aifecCatalog($app)->product($other)['availability']['label']);
    $hooks->setCurrentModule('core');
});
