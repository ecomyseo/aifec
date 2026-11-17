<?php
/**
 * AIFEC — pruebas de las opciones de envío: tarifas por peso y por importe, peso máximo, envío gratis, recogida en tienda,
 * orden, el filtro sellado y los manejadores que registra un plugin.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\ShippingHandler;
use Aifec\Kernel\App;

require_once __DIR__ . '/ShippingKit.php';

/**
 * Las opciones para un paquete en Madrid, salvo que se diga otro destino.
 *
 * @param array<string,mixed>  $package
 * @param array<string,string> $destination
 *
 * @return array<int,array<string,mixed>>
 */
function shippingOptionsFor(App $app, array $package = [], ?array $destination = null): array
{
    return shippingService($app)->options(shippingPackage($package), $destination !== null ? $destination : shippingDest('ES', '28001', '28'));
}

test('las tarifas por peso valen por tramos, con el límite de cada tramo dentro y el último sin tope', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'peso');
    shippingAddRate($app, $c, $pen, 'weight', 0, 1000, 495);
    shippingAddRate($app, $c, $pen, 'weight', 1001, 5000, 795);
    shippingAddRate($app, $c, $pen, 'weight', 5001, 0, 1595);

    foreach ([[0, 495], [500, 495], [1000, 495], [1001, 795], [5000, 795], [5001, 1595], [250000, 1595]] as $case) {
        same(['peso' => $case[1]], shippingPrices(shippingOptionsFor($app, ['weight' => $case[0]])), 'peso ' . $case[0] . ' g');
    }
});

test('un peso que no cae en ningún tramo no se ofrece, y una zona sin tarifas del transportista tampoco', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $can = shippingZoneId($app, 'Canary Islands');
    $c = shippingAddCarrier($app, 'corto');
    shippingAddRate($app, $c, $pen, 'weight', 0, 1000, 495);

    same(['corto' => 495], shippingPrices(shippingOptionsFor($app, ['weight' => 1000])));
    same([], shippingOptionsFor($app, ['weight' => 1001]), 'el tramo que falta: no se ofrece');
    same([], shippingOptionsFor($app, ['weight' => 500], shippingDest('ES', '35001')), 'Canarias existe pero este transportista no tiene tarifas allí');

    shippingAddRate($app, $c, $can, 'weight', 0, 0, 1995);
    same(['corto' => 1995], shippingPrices(shippingOptionsFor($app, ['weight' => 500], shippingDest('ES', '35001'))), 'cada zona con su precio');
    same(['corto' => 495], shippingPrices(shippingOptionsFor($app, ['weight' => 500])), 'y la península sigue con el suyo');
});

test('las tarifas por importe miran lo que suman los artículos, y un tramo a 0 € es un precio de la tabla, no «gratis»', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'importe');
    shippingAddRate($app, $c, $pen, 'price', 0, 4999, 695);
    shippingAddRate($app, $c, $pen, 'price', 5000, 0, 0);

    same(['importe' => 695], shippingPrices(shippingOptionsFor($app, ['items_total' => 0])));
    same(['importe' => 695], shippingPrices(shippingOptionsFor($app, ['items_total' => 4999, 'weight' => 900000])), 'el peso no importa en una tarifa por importe');
    $options = shippingOptionsFor($app, ['items_total' => 5000]);
    same(['importe' => 0], shippingPrices($options));
    same(false, $options[0]['free'], 'cuesta 0 por la tabla, no por «gratis a partir de»');
});

test('si dos tramos valen, gana el de mínimo más alto, y si una zona mezcla bases manda la del peso', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'solape');
    shippingAddRate($app, $c, $pen, 'weight', 0, 5000, 1000);
    shippingAddRate($app, $c, $pen, 'weight', 1000, 5000, 700);
    same(['solape' => 1000], shippingPrices(shippingOptionsFor($app, ['weight' => 999])));
    same(['solape' => 700], shippingPrices(shippingOptionsFor($app, ['weight' => 1000])), 'en el solape, el tramo que empieza más arriba');
    same(['solape' => 700], shippingPrices(shippingOptionsFor($app, ['weight' => 2000])));

    $m = shippingAddCarrier($app, 'mixto');
    shippingAddRate($app, $m, $pen, 'weight', 0, 1000, 500);
    shippingAddRate($app, $m, $pen, 'price', 0, 0, 100);
    same(500, shippingPrices(shippingOptionsFor($app, ['weight' => 500]))['mixto']);
    check(!isset(shippingPrices(shippingOptionsFor($app, ['weight' => 2000]))['mixto']), 'con filas de peso, un peso fuera de ellas no se rescata con las de importe');
});

test('el peso máximo del transportista deja fuera los paquetes más pesados, y 0 es sin límite', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $ligero = shippingAddCarrier($app, 'ligero', ['max_weight' => 2000, 'position' => 1]);
    $libre = shippingAddCarrier($app, 'libre', ['max_weight' => 0, 'position' => 2]);
    foreach ([$ligero, $libre] as $id) {
        shippingAddRate($app, $id, $pen, 'weight', 0, 0, 500);
    }

    same(['ligero' => 500, 'libre' => 500], shippingPrices(shippingOptionsFor($app, ['weight' => 2000])), 'justo en el máximo, entra');
    same(['libre' => 500], shippingPrices(shippingOptionsFor($app, ['weight' => 2001])), 'un gramo de más, no');
    same(['libre' => 500], shippingPrices(shippingOptionsFor($app, ['weight' => 90000000])));
});

test('«gratis a partir de» pone el precio a 0 justo en el límite, y nulo es nunca', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'gratis', ['free_from' => 5000]);
    $n = shippingAddCarrier($app, 'nunca', ['free_from' => null, 'position' => 1]);
    foreach ([$c, $n] as $id) {
        shippingAddRate($app, $id, $pen, 'weight', 0, 0, 495);
    }

    $options = shippingOptionsFor($app, ['items_total' => 4999]);
    same(['gratis' => 495, 'nunca' => 495], shippingPrices($options));
    same([false, false], [$options[0]['free'], $options[1]['free']]);

    $options = shippingOptionsFor($app, ['items_total' => 5000]);
    same(['gratis' => 0, 'nunca' => 495], shippingPrices($options), 'justo en el límite sale gratis');
    same([true, false], [$options[0]['free'], $options[1]['free']]);

    same(['gratis' => 0, 'nunca' => 495], shippingPrices(shippingOptionsFor($app, ['items_total' => 999999])));

    // «0» no es «nulo»: el contrato dice que con un valor, aunque sea 0, todo pedido sale gratis.
    $app->db()->update('carriers', ['free_from' => 0], ['id' => $c]);
    same(['gratis' => 0, 'nunca' => 495], shippingPrices(shippingOptionsFor($app, ['items_total' => 0])));
});

test('un transportista inactivo no se ofrece y las opciones salen por orden de posición', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $ids = [
        'tercero' => shippingAddCarrier($app, 'tercero', ['position' => 30]),
        'primero' => shippingAddCarrier($app, 'primero', ['position' => 10]),
        'apagado' => shippingAddCarrier($app, 'apagado', ['position' => 5, 'active' => 0]),
        'segundo' => shippingAddCarrier($app, 'segundo', ['position' => 20]),
    ];
    foreach ($ids as $id) {
        shippingAddRate($app, $id, $pen, 'weight', 0, 0, 100);
    }

    same(['primero', 'segundo', 'tercero'], array_column(shippingOptionsFor($app), 'code'));
    $app->db()->update('carriers', ['active' => 1], ['id' => $ids['apagado']]);
    same(['apagado', 'primero', 'segundo', 'tercero'], array_column(shippingOptionsFor($app), 'code'));
});

test('la recogida en tienda cuesta lo que dice su ajuste, no necesita dirección ni tarifas y sirve en cualquier zona', function (): void {
    $app = shippingTestApp();
    $c = shippingAddCarrier($app, 'tienda', ['handler' => 'pickup', 'name' => 'Recogida en tienda', 'settings' => json_encode(['price' => 250, 'address' => 'Calle Mayor 1, Madrid'])]);
    $gratis = shippingAddCarrier($app, 'tienda-gratis', ['handler' => 'pickup', 'position' => 1]);

    foreach ([shippingDest('ES', '28001'), shippingDest('ES', '35001'), shippingDest('ES', '07001'), shippingDest('ES', '52001')] as $destination) {
        $options = shippingOptionsFor($app, [], $destination);
        same(['tienda' => 250, 'tienda-gratis' => 0], shippingPrices($options), $destination['postcode']);
        same([false, false], array_column($options, 'needs_address'));
    }
    $carrier = shippingService($app)->carrier($c);
    same('Calle Mayor 1, Madrid', $carrier['settings']['address'], 'el texto del sitio de recogida va en settings.address');
    same(0, shippingPrices(shippingOptionsFor($app))['tienda-gratis'], 'sin ajuste, gratis');
    same(true, $gratis > 0);

    // También le vale lo de «gratis a partir de» y lo del peso máximo.
    $app->db()->update('carriers', ['free_from' => 3000, 'max_weight' => 1000], ['id' => $c]);
    $options = shippingOptionsFor($app, ['items_total' => 3000, 'weight' => 900]);
    same([0, true], [$options[0]['price'], $options[0]['free']]);
    same(['tienda-gratis' => 0], shippingPrices(shippingOptionsFor($app, ['weight' => 1001])));
});

test('un paquete que no necesita envío no tiene opciones, y sin zona tampoco', function (): void {
    $app = shippingTestApp();
    shippingAddCarrier($app, 'tienda', ['handler' => 'pickup']);
    same(['tienda' => 0], shippingPrices(shippingOptionsFor($app)));
    same([], shippingOptionsFor($app, ['needs_shipping' => false]), 'solo digitales o servicios');
    same(['tienda' => 0], shippingPrices(shippingService($app)->options(['weight' => 100, 'items_total' => 100, 'qty' => 1], shippingDest('ES', '28001'))), 'sin la clave, se da por hecho que hace falta');
    same([], shippingOptionsFor($app, [], shippingDest('FR', '75001')), 'Francia no tiene zona');
});

test('cada opción lleva sus datos con sus tipos, también con los números guardados como texto', function (): void {
    $app = shippingTestApp();
    $standard = (int) $app->db()->value("SELECT id FROM {tax_classes} WHERE code = 'standard'");
    $c = shippingAddCarrier($app, 'gls-24', [
        'name' => 'GLS & «Express» ñ', 'delay' => '24–48 h', 'min_days' => 1, 'max_days' => 2, 'tax_class_id' => $standard, 'position' => 3,
    ]);
    $bare = shippingAddCarrier($app, 'sin-datos', ['position' => 4]);
    $pen = shippingZoneId($app, 'Peninsula');
    shippingAddRate($app, $c, $pen, 'weight', 0, 0, 495);
    shippingAddRate($app, $bare, $pen, 'weight', 0, 0, 100);

    $options = shippingOptionsFor($app);
    same([
        'carrier_id' => $c, 'code' => 'gls-24', 'name' => 'GLS & «Express» ñ', 'delay' => '24–48 h', 'price' => 495, 'free' => false,
        'tax_class_id' => $standard, 'min_days' => 1, 'max_days' => 2, 'needs_address' => true,
    ], $options[0]);
    same([
        'carrier_id' => $bare, 'code' => 'sin-datos', 'name' => 'Transportista sin-datos', 'delay' => '', 'price' => 100, 'free' => false,
        'tax_class_id' => null, 'min_days' => null, 'max_days' => null, 'needs_address' => true,
    ], $options[1]);

    $options = shippingService($app)->options(['weight' => '500', 'items_total' => '2000', 'qty' => '1', 'needs_shipping' => '1'], shippingDest('ES', '28001'));
    same(['gls-24' => 495, 'sin-datos' => 100], shippingPrices($options));
});

test('carrier() da el transportista con el ajuste descodificado, y null si no existe', function (): void {
    $app = shippingTestApp();
    $c = shippingAddCarrier($app, 'tienda', ['handler' => 'pickup', 'settings' => json_encode(['price' => 250, 'address' => 'Plaza "Mayor" & ñ']), 'free_from' => 7000, 'max_weight' => 3000]);
    $carrier = shippingService($app)->carrier($c);
    same($c, $carrier['id']);
    same('pickup', $carrier['handler']);
    same(['price' => 250, 'address' => 'Plaza "Mayor" & ñ'], $carrier['settings']);
    same(7000, $carrier['free_from']);
    same(3000, $carrier['max_weight']);
    same(null, $carrier['tax_class_id']);
    same(1, $carrier['active']);

    $bad = shippingAddCarrier($app, 'roto', ['settings' => '{no es json']);
    same([], shippingService($app)->carrier($bad)['settings'], 'un ajuste roto es un ajuste vacío');
    same(null, shippingService($app)->carrier(99999));
});

test('el filtro shipping.options está sellado: sin la capacidad pricing no entra, y con ella cambia las opciones y recibe paquete y destino', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'normal');
    shippingAddRate($app, $c, $pen, 'weight', 0, 0, 500);

    // Sin permiso: el oyente no se registra y queda anotado.
    $app->hooks()->setCurrentModule('x');
    $app->hooks()->filter('shipping.options', static function (array $options): array {
        $options[0]['price'] = 1;

        return $options;
    });
    $app->hooks()->setCurrentModule('core');
    same(['normal' => 500], shippingPrices(shippingOptionsFor($app)), 'el oyente no se registró');
    $errors = $app->hooks()->errors();
    $last = $errors[count($errors) - 1];
    same('x', $last['module']);
    same('shipping.options', $last['hook']);
    contains('Sealed hook', $last['error']);

    // Con la capacidad concedida sí.
    $seen = [];
    $app->hooks()->grant('y', 'pricing');
    $app->hooks()->setCurrentModule('y');
    $app->hooks()->filter('shipping.options', static function (array $options, array $package, array $destination) use (&$seen): array {
        $seen = [$package, $destination];
        $options[0]['price'] += 100;
        $options[] = ['carrier_id' => 0, 'code' => 'extra', 'name' => 'Extra', 'delay' => '', 'price' => 900, 'free' => false, 'tax_class_id' => null,
            'min_days' => null, 'max_days' => null, 'needs_address' => true];

        return $options;
    });
    $app->hooks()->setCurrentModule('core');
    same(['normal' => 600, 'extra' => 900], shippingPrices(shippingOptionsFor($app, ['weight' => 1234, 'items_total' => 4321], shippingDest('ES', '28001', '28'))));
    same(1234, $seen[0]['weight']);
    same(4321, $seen[0]['items_total']);
    same(true, $seen[0]['needs_shipping']);
    same(['country' => 'ES', 'region' => '28', 'postcode' => '28001', 'city' => ''], $seen[1], 'el destino llega como se dio, sin claves internas');
});

test('un filtro que devuelve basura no rompe las opciones: se queda lo de antes', function (): void {
    $app = shippingTestApp();
    $c = shippingAddCarrier($app, 'normal');
    shippingAddRate($app, $c, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 500);
    $app->hooks()->grant('y', 'pricing');
    $app->hooks()->setCurrentModule('y');
    $mode = 'texto';
    $app->hooks()->filter('shipping.options', static function ($options) use (&$mode) {
        return $mode === 'texto' ? 'no soy una lista' : [['code' => 'valida'], 'no soy una opción', 7];
    });
    $app->hooks()->setCurrentModule('core');

    same(['normal' => 500], shippingPrices(shippingOptionsFor($app)), 'un texto no sustituye a la lista');
    $mode = 'mezcla';
    same(1, count(shippingOptionsFor($app)), 'de una lista con basura solo pasan las opciones que son listas');
});

test('un plugin registra su propio manejador y el transportista que lo usa se ofrece con su precio', function (): void {
    $app = shippingTestApp();
    $pen = shippingZoneId($app, 'Peninsula');
    $seen = new \stdClass();
    $handler = new class($seen) implements ShippingHandler {
        /** @var \stdClass */
        private $seen;

        public function __construct(\stdClass $seen)
        {
            $this->seen = $seen;
        }

        public function code(): string
        {
            return 'express-demo';
        }

        public function needsAddress(): bool
        {
            return true;
        }

        public function quote(array $carrier, array $package, array $destination): ?array
        {
            $this->seen->carrier = $carrier;
            $this->seen->package = $package;
            $this->seen->destination = $destination;

            return $package['weight'] > 30000 ? null : ['price' => 700 + $package['qty'], 'min_days' => 1, 'max_days' => 1];
        }
    };
    $c = shippingAddCarrier($app, 'urgente', ['handler' => 'express-demo', 'settings' => json_encode(['token' => 'abc']), 'min_days' => 5, 'max_days' => 9]);
    shippingAddCarrier($app, 'huerfano', ['handler' => 'plugin-apagado', 'position' => 1]);

    $shipping = shippingService($app);
    same(['rates', 'pickup'], $shipping->handlerCodes());
    same([], shippingOptionsFor($app), 'antes de registrarlo, el transportista no se ofrece (y el huérfano tampoco)');

    $shipping->registerHandler($handler);
    same(['rates', 'pickup', 'express-demo'], $shipping->handlerCodes());
    same(true, $shipping->handler('express-demo') === $handler);

    $options = shippingOptionsFor($app, ['qty' => 3, 'weight' => 800]);
    same(['urgente' => 703], shippingPrices($options));
    same([1, 1], [$options[0]['min_days'], $options[0]['max_days']], 'los plazos que da el manejador mandan sobre los del transportista');
    same(true, $options[0]['needs_address']);
    same($c, $seen->carrier['id']);
    same(['token' => 'abc'], $seen->carrier['settings'], 'el manejador recibe el ajuste ya descodificado');
    same(3, $seen->package['qty']);
    same($pen, $seen->destination['zone_id'], 'y la zona ya resuelta');
    same('28001', $seen->destination['postcode']);

    same([], shippingOptionsFor($app, ['weight' => 30001]), 'si el manejador devuelve null, no se ofrece');

    throws(function () use ($shipping): void {
        $shipping->registerHandler(new class implements ShippingHandler {
            public function code(): string
            {
                return '  ';
            }

            public function needsAddress(): bool
            {
                return false;
            }

            public function quote(array $carrier, array $package, array $destination): ?array
            {
                return null;
            }
        });
    }, \InvalidArgumentException::class);
});

test('un manejador que da un precio negativo no baja el envío de 0', function (): void {
    $app = shippingTestApp();
    shippingAddCarrier($app, 'regalo', ['handler' => 'raro']);
    shippingService($app)->registerHandler(new class implements ShippingHandler {
        public function code(): string
        {
            return 'raro';
        }

        public function needsAddress(): bool
        {
            return false;
        }

        public function quote(array $carrier, array $package, array $destination): ?array
        {
            return ['price' => -500, 'min_days' => null, 'max_days' => null];
        }
    });
    same(['regalo' => 0], shippingPrices(shippingOptionsFor($app)));
});
