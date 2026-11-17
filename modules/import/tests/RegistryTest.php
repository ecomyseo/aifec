<?php
/**
 * AIFEC — el registro de lectores y escritores, y las equivalencias que se proponen antes de empezar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Registry;

require_once __DIR__ . '/helpers.php';

test('los lectores llegan por el filtro import.readers; lo que no es un lector se ignora', function (): void {
    $app = importApp();
    $app->hooks()->filter('import.readers', static function ($list): array {
        $list = is_array($list) ? $list : [];

        return array_merge($list, ['No\\Such\\Class', \stdClass::class, 42]);
    });
    $readers = importService($app)->registry()->readers();
    check(isset($readers['woocommerce:public']), 'el lector de mentira está');
    same(ImportFakeReader::class, $readers['woocommerce:public']['class']);
    same('Fake shop', $readers['woocommerce:public']['label']);
    foreach ($readers as $key => $card) {
        check(is_subclass_of($card['class'], 'Aifec\\Modules\\Import\\SourceReader'), $key . ' es un lector');
    }
    same(null, importService($app)->registry()->reader('prestashop', 'inexistente'));
    same(ImportFakeReader::class, importService($app)->registry()->reader('woocommerce', 'public')['class']);
});

test('el módulo registra por sí mismo los lectores que existen', function (): void {
    $app = testApp(['modules' => ['import'], 'settings' => ['modules.active' => ['import']]]);
    $app->boot();
    $classes = $app->hooks()->apply('import.readers', []);
    check(is_array($classes), 'devuelve una lista');
    foreach ($classes as $class) {
        check(class_exists($class), $class . ' existe: solo se añaden las clases que existen');
    }
    same(array_values(array_unique($classes)), array_values($classes), 'y ninguna dos veces');
});

test('los escritores: por el filtro, por su nombre por omisión o ninguno', function (): void {
    $app = importApp();
    $registry = importService($app)->registry();
    same('TaxRates', Registry::studly('tax_rates'));
    same('PostCategories', Registry::studly('post_categories'));
    same('Settings', Registry::studly('settings'));
    same(ImportFakeRedirectsWriter::class, $registry->writerClass('redirects'));
    same(\Aifec\Modules\Import\Writers\ReviewsWriter::class, $registry->writerClass('reviews'), 'sin declarar nada: la clase con el nombre por omisión');
    same(\Aifec\Modules\Import\Writers\TaxRatesWriter::class, $registry->writerClass('tax_rates'));
    same(null, $registry->writerClass('inventado'), 'sin escritor ni clase con el nombre por omisión');
    $writer = $registry->writer('redirects');
    check($writer instanceof ImportFakeRedirectsWriter);
    same($writer, $registry->writer('redirects'), 'una instancia por tipo');
    same(null, $registry->writer('inventado'));

    $app->hooks()->filter('import.writers', static function ($map): array {
        $map = is_array($map) ? $map : [];
        $map['team'] = \stdClass::class;

        return $map;
    });
    same(null, $registry->writerClass('team'), 'una clase que no es un escritor no vale, y tampoco hay uno por omisión');
});

test('un tipo sin escritor o sin su módulo se salta y dice por qué', function (): void {
    $app = importApp(['import', 'media']);
    // Todos los tipos tienen ya su escritor: para probar «no hay escritor» se declara uno que no lo es.
    $app->hooks()->filter('import.writers', static function ($map): array {
        $map = is_array($map) ? $map : [];
        $map['media'] = \stdClass::class;

        return $map;
    });
    $registry = importService($app)->registry();
    same(null, $registry->unavailable('settings'));
    same(null, $registry->unavailable('redirects'));
    same(['text' => 'The «{module}» module is not active', 'vars' => ['module' => 'catalog']], $registry->unavailable('products'));
    same(['text' => 'The «{module}» module is not active', 'vars' => ['module' => 'tax']], $registry->unavailable('tax_classes'));
    same(['text' => 'Unknown type', 'vars' => []], $registry->unavailable('inventado'));
    same(['text' => 'This version cannot write this type yet', 'vars' => []], $registry->unavailable('media'), 'el módulo está activo pero no hay escritor');
});

/**
 * Sitio con pedidos, impuestos, clientes y transferencia: lo que hace falta para ofrecer equivalencias de verdad.
 */
function importEquivalenceApp(): \Aifec\Kernel\App
{
    return importApp(['import', 'media', 'orders', 'payments-transfer', 'payments-cod']);
}

test('las equivalencias se leen del origen y se proponen por el significado', function (): void {
    $app = importEquivalenceApp();
    $classes = importRecords('tax_classes', 2);
    $classes[0]['code'] = 'standard';
    $classes[1]['code'] = 'rarito';
    $groups = importRecords('customer_groups', 2);
    $groups[0]['name'] = ['es' => 'Customers'];
    $groups[1]['name'] = ['es' => 'Profesionales'];
    $methods = importRecords('payment_methods', 3);
    $methods[0]['code'] = 'bacs';
    $methods[1]['code'] = 'cod';
    $methods[2]['code'] = 'redsys';
    ImportFakeReader::$data = ['tax_classes' => $classes, 'customer_groups' => $groups, 'payment_methods' => $methods, 'orders' => []];
    ImportFakeReader::$orderStates = [
        ['id' => '3', 'name' => ['es' => 'Enviado'], 'meaning' => 'shipped'],
        ['id' => '5', 'name' => 'Cancelado', 'meaning' => 'cancelled'],
        ['id' => '8', 'name' => 'Esperando', 'meaning' => 'on_hold'],
        ['id' => '9', 'name' => 'Raro', 'meaning' => 'inventado'],
    ];
    $id = importDraft($app);
    $service = importService($app);
    $reader = $service->connections()->build(importRun($app, $id));
    $source = $service->equivalences()->sourceValues($reader);
    same(['3', '5', '8', '9'], array_column($source['order_state'], 'id'));
    same('Enviado', $source['order_state'][0]['name'], 'el nombre por idioma se reduce a un texto');
    same(['101', '102'], array_column($source['tax_class'], 'id'));
    same(['Customers', 'Profesionales'], array_column($source['customer_group'], 'name'));
    same(['bacs', 'cod', 'redsys'], array_column($source['payment_method'], 'meaning'));

    $local = $service->equivalences()->localOptions();
    check(isset($local['order_state']['shipped'], $local['order_state']['pending_payment']), 'estados de aquí');
    check(isset($local['tax_class']['standard']), 'clases de aquí');
    check(isset($local['customer_group']['1']), 'grupos de aquí');
    check(isset($local['payment_method']['transfer'], $local['payment_method']['cod']), 'formas de pago de aquí');

    $proposal = $service->equivalences()->propose($source, $local);
    same(['3' => 'shipped', '5' => 'cancelled', '8' => 'on_hold'], $proposal['order_state'], 'por significado; el raro queda sin proponer');
    same(['101' => 'standard'], $proposal['tax_class'], 'por código');
    same(['101' => '1'], $proposal['customer_group'], 'por nombre');
    same(['101' => 'transfer', '102' => 'cod'], $proposal['payment_method'], 'por código y alias; Redsys no tiene equivalente');
});

test('si el lector no sabe dar los estados de pedido, se sacan de los pedidos', function (): void {
    $app = importEquivalenceApp();
    $orders = importRecords('orders', 3);
    $orders[0]['state'] = 'wc-processing';
    $orders[0]['state_meaning'] = 'processing';
    $orders[1]['state'] = 'wc-processing';
    $orders[1]['state_meaning'] = 'processing';
    $orders[2]['state'] = 'wc-refunded';
    $orders[2]['state_meaning'] = 'refunded';
    ImportFakeReader::$data = ['orders' => $orders];
    $id = importDraft($app);
    $service = importService($app);
    // Un lector sin orderStates(): se usa uno anónimo que envuelve al de mentira.
    $reader = new class (importService($app)->connections()->build(importRun($app, $id))) implements \Aifec\Modules\Import\SourceReader {
        /** @var \Aifec\Modules\Import\SourceReader */
        private $inner;

        public function __construct(\Aifec\Modules\Import\SourceReader $inner)
        {
            $this->inner = $inner;
        }

        public static function describe(): array
        {
            return ImportFakeReader::describe();
        }

        public function source(): string
        {
            return $this->inner->source();
        }

        public function mode(): string
        {
            return $this->inner->mode();
        }

        public function key(): string
        {
            return $this->inner->key();
        }

        public function probe(): array
        {
            return $this->inner->probe();
        }

        public function types(): array
        {
            return $this->inner->types();
        }

        public function count(string $type): ?int
        {
            return $this->inner->count($type);
        }

        public function read(string $type, string $cursor, int $limit): array
        {
            return $this->inner->read($type, $cursor, $limit);
        }

        public function checksums(): array
        {
            return $this->inner->checksums();
        }

        public function unmapped(): array
        {
            return $this->inner->unmapped();
        }
    };
    $source = $service->equivalences()->sourceValues($reader);
    same(['wc-processing', 'wc-refunded'], array_column($source['order_state'], 'id'), 'los distintos, una vez cada uno');
    $proposal = $service->equivalences()->propose($source, $service->equivalences()->localOptions());
    same(['wc-processing' => 'processing', 'wc-refunded' => 'refunded'], $proposal['order_state']);
});

test('lo que envía la persona se limpia: solo valores del origen hacia opciones que existen', function (): void {
    $app = importEquivalenceApp();
    $service = importService($app);
    $local = $service->equivalences()->localOptions();
    $clean = $service->equivalences()->sanitize([
        'order_state' => ['3' => 'shipped', '4' => 'no-existe', '5' => '', str_repeat('x', 70) => 'shipped'],
        'tax_class' => ['1' => 'standard'], 'inventado' => ['1' => 'x'], 'customer_group' => 'no es un array',
    ], $local);
    same(['order_state' => ['3' => 'shipped'], 'tax_class' => ['1' => 'standard']], $clean);
});
