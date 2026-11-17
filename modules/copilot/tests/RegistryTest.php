<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El registro de acciones: las de serie solo si su módulo está activo; otros módulos y plugins añaden las suyas con el filtro
 * «copilot.actions»; una definición mala se ignora; un identificador repetido no pisa al primero; y nada de lo que falle dentro de
 * una acción tumba la pantalla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Actions;

require_once __DIR__ . '/helpers.php';

/**
 * Una acción de un módulo ajeno: «saluda a X» guarda un saludo en un ajuste. Se puede deshacer.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function copilotDemoAction(array $over = []): array
{
    return $over + [
        'id' => 'demo.hello', 'label' => 'Greet someone', 'description' => 'Stores a greeting.', 'permission' => 'copilot.use',
        'params' => [['name' => 'who', 'type' => 'string', 'required' => true, 'description' => 'Who to greet']],
        'examples' => ['Greet Ana'],
        'understand' => static function (App $app, string $raw, string $norm): ?array {
            return preg_match('/^saluda a (\w+)/', $norm, $m) === 1 ? ['args' => ['who' => $m[1]], 'score' => 70] : null;
        },
        'preview' => static function (App $app, array $args): array {
            return ['summary' => 'Greet ' . $args['who'], 'changes' => [['label' => 'Greeting', 'from' => (string) $app->settings()->str('demo.hello'), 'to' => 'Hola ' . $args['who']]], 'count' => 1];
        },
        'apply' => static function (App $app, array $args): array {
            $before = $app->settings()->str('demo.hello');
            $app->settings()->set('demo.hello', 'Hola ' . $args['who']);

            return ['ok' => true, 'message' => 'Hecho: Hola ' . $args['who'], 'undo' => ['before' => $before]];
        },
        'undo' => static function (App $app, array $undo): array {
            $app->settings()->set('demo.hello', (string) $undo['before']);

            return ['ok' => true, 'message' => 'Saludo restaurado'];
        },
    ];
}

/**
 * Añade acciones al registro como lo haría otro módulo.
 *
 * @param array<int,array<string,mixed>> $definitions
 */
function copilotRegister(App $app, array $definitions): void
{
    $app->hooks()->filter(Actions::FILTER, static function ($list) use ($definitions): array {
        return array_merge(is_array($list) ? $list : [], $definitions);
    });
}

test('las acciones de serie son estas trece cuando todos los módulos están activos', function (): void {
    $app = copilotApp();
    $ids = array_keys(copilotService($app)->actions()->all());
    sort($ids);
    same([
        'catalog.bulk_price', 'catalog.create', 'catalog.price', 'catalog.stock', 'catalog.visibility', 'coupons.create',
        'orders.note', 'orders.refund', 'orders.ship', 'orders.status', 'settings.set', 'shipping.free_from', 'shipping.rate',
    ], $ids);
    foreach (copilotService($app)->actions()->all() as $id => $def) {
        check($def['label'] !== '' && $def['params'] !== [], $id . ' tiene su etiqueta y sus datos');
        check(is_callable($def['preview']) && is_callable($def['apply']), $id . ' sabe enseñar y aplicar');
    }
});

test('cada grupo de acciones solo existe si su módulo está activo: sin pedidos, catálogo ni cupones solo quedan los ajustes', function (): void {
    $app = testApp(['modules' => ['copilot']]);
    $app->boot();
    same(['settings.set'], array_keys(copilotService($app)->actions()->all()));
    $settings = copilotService($app)->actions()->get('settings.set');
    contains('store.name (Shop name)', $settings['description']);
    notContains('catalog.per_page', $settings['description'], 'los ajustes de un módulo apagado no se ofrecen');

    $catalog = testApp(['modules' => ['copilot', 'catalog']]);
    $catalog->boot();
    $ids = array_keys(copilotService($catalog)->actions()->all());
    sort($ids);
    same(['catalog.bulk_price', 'catalog.create', 'catalog.price', 'catalog.stock', 'catalog.visibility', 'settings.set'], $ids);
});

test('un módulo o plugin añade su acción con el filtro «copilot.actions»: se entiende, se previsualiza, se aplica, se audita y se deshace', function (): void {
    $app = copilotApp();
    copilotRegister($app, [copilotDemoAction()]);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Saluda a Ana');
    same('demo.hello', $row['action']);
    same(['who' => 'ana'], $row['args']);
    same([['label' => 'Greeting', 'from' => '', 'to' => 'Hola ana']], $row['preview']['changes']);
    contains('Greet someone', copilotService($app)->present($row)['action_label']);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same('Hola ana', $app->settings()->str('demo.hello'));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'copilot.apply'"));
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same('', $app->settings()->str('demo.hello'));
    contains('Greet Ana', implode('|', copilotService($app)->examples()), 'y su ejemplo sale en la pantalla');
});

test('una definición que no vale se ignora y las demás siguen: sin identificador, con identificador mal escrito, sin preview, sin permiso o con un dato de un tipo que no existe', function (): void {
    $app = copilotApp();
    $good = copilotDemoAction();
    copilotRegister($app, [
        copilotDemoAction(['id' => 'Mal Id']),
        copilotDemoAction(['id' => 'sinpunto']),
        ['label' => 'Sin id'] + $good,
        copilotDemoAction(['id' => 'demo.nopreview', 'preview' => 'no_existe_esta_funcion']),
        copilotDemoAction(['id' => 'demo.nopermission', 'permission' => '']),
        copilotDemoAction(['id' => 'demo.badtype', 'params' => [['name' => 'x', 'type' => 'raro']]]),
        copilotDemoAction(['id' => 'demo.enumvacio', 'params' => [['name' => 'x', 'type' => 'enum']]]),
        'no es un array',
        $good,
    ]);

    $ids = array_keys(copilotService($app)->actions()->all());
    check(in_array('demo.hello', $ids, true), 'la buena entra');
    foreach (['Mal Id', 'sinpunto', 'demo.nopreview', 'demo.nopermission', 'demo.badtype', 'demo.enumvacio'] as $bad) {
        check(!in_array($bad, $ids, true), $bad . ' no entra');
    }
    same(14, count($ids), 'las trece de serie y la buena');
});

test('un identificador repetido no pisa al primero: una acción ajena no puede sustituir a «orders.ship»', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $called = false;
    copilotRegister($app, [copilotDemoAction([
        'id' => 'orders.ship', 'label' => 'Falsa', 'preview' => static function (): array {
            return ['summary' => 'x', 'changes' => [], 'count' => 0];
        },
        'apply' => static function () use (&$called): array {
            $called = true;

            return ['ok' => true, 'message' => 'x'];
        },
    ])]);
    $def = copilotService($app)->actions()->get('orders.ship');
    same('Mark an order as shipped', $def['label']);

    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'marca el pedido ' . $order['n'] . ' como enviado');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(false, $called, 'la falsa no se ha ejecutado');
    same('shipped', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]), 'se ha ejecutado la de serie');
});

test('un oyente del filtro que falla no deja al copiloto sin acciones', function (): void {
    $app = copilotApp();
    expectHookErrors();
    $app->hooks()->filter(Actions::FILTER, static function (): array {
        throw new \RuntimeException('Un módulo roto');
    }, 50);

    same(13, count(copilotService($app)->actions()->all()));
});

test('si la vista previa de una acción lanza una excepción, la propuesta enseña un error y la pantalla no se cae', function (): void {
    $app = copilotApp();
    copilotRegister($app, [copilotDemoAction(['preview' => static function (): array {
        throw new \RuntimeException('Rota por dentro');
    }])]);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Saluda a Ana');
    same('proposed', $row['status']);
    contains('no ha podido preparar este cambio', $row['preview']['errors'][0]);
    same(200, $web->get('/admin/copilot/' . $row['id'])->status);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same('', $app->settings()->str('demo.hello'), 'y no se aplica');
});

test('si aplicar lanza una excepción o dice que no ha ido bien, la propuesta queda «no hecha» con el motivo y sigue la pantalla', function (): void {
    $app = copilotApp();
    copilotRegister($app, [
        copilotDemoAction(['apply' => static function (): array {
            throw new \RuntimeException('Rota por dentro');
        }]),
        copilotDemoAction(['id' => 'demo.nope', 'understand' => static function (App $app, string $raw, string $norm): ?array {
            return strpos($norm, 'rechaza') === 0 ? ['args' => ['who' => 'x'], 'score' => 70] : null;
        }, 'apply' => static function (): array {
            return ['ok' => false, 'message' => 'No me dejan'];
        }]),
    ]);
    $web = copilotAdmin($app);

    $boom = copilotAsk($web, 'Saluda a Ana');
    $r = copilotPost($web, '/admin/copilot/' . $boom['id'] . '/apply');
    same(303, $r->status);
    $boom = copilotRow($app, (int) $boom['id']);
    same(['failed', 'No se ha podido hacer el cambio.'], [$boom['status'], $boom['result']]);
    $no = copilotAsk($web, 'rechaza esto');
    copilotPost($web, '/admin/copilot/' . $no['id'] . '/apply');
    $no = copilotRow($app, (int) $no['id']);
    same(['failed', 'No me dejan'], [$no['status'], $no['result']]);
    same(200, $web->get('/admin/copilot')->status);
});

test('dos acciones que entienden lo mismo con la misma puntuación: se ofrecen las dos y elegir la otra cambia la propuesta', function (): void {
    $app = copilotApp();
    copilotRegister($app, [
        copilotDemoAction(['id' => 'demo.uno', 'label' => 'First reading', 'understand' => static function (App $app, string $raw, string $norm): ?array {
            return strpos($norm, 'lee esto') === 0 ? ['args' => ['who' => 'uno'], 'score' => 70] : null;
        }]),
        copilotDemoAction(['id' => 'demo.dos', 'label' => 'Second reading', 'understand' => static function (App $app, string $raw, string $norm): ?array {
            return strpos($norm, 'lee esto') === 0 ? ['args' => ['who' => 'dos'], 'score' => 70] : null;
        }]),
    ]);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Lee esto');
    same('demo.dos', $row['action'], 'a igualdad, el identificador más bajo en el alfabeto desempata siempre igual');
    same([['action' => 'demo.uno', 'args' => ['who' => 'uno'], 'label' => 'First reading']], $row['preview']['alternatives']);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('Quizá querías decir', $page);
    contains('/choose', $page);

    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/choose', ['alt' => '0']);
    same(303, $r->status);
    $row = copilotRow($app, (int) $row['id']);
    same(['demo.uno', ['who' => 'uno']], [$row['action'], $row['args']]);
    same('demo.dos', $row['preview']['alternatives'][0]['action'], 'y la que había queda como otra lectura');
});

test('un lector de texto que devuelve basura o lanza una excepción no rompe: las demás acciones siguen entendiendo', function (): void {
    $app = copilotApp();
    copilotRegister($app, [
        copilotDemoAction(['id' => 'demo.basura', 'understand' => static function (): string {
            return 'basura';
        }]),
        copilotDemoAction(['id' => 'demo.lanza', 'understand' => static function (): ?array {
            throw new \RuntimeException('Rota');
        }]),
        copilotDemoAction(['id' => 'demo.bajo', 'understand' => static function (App $app, string $raw, string $norm): ?array {
            return ['args' => ['who' => 'x'], 'score' => 10];
        }]),
    ]);
    $ids = copilotShop($app);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');
    same('catalog.price', $row['action']);
    same('proposed', $row['status']);
});

test('la acción con un permiso que depende de los datos exige el del dato elegido', function (): void {
    $app = copilotApp();
    copilotRegister($app, [copilotDemoAction(['permission' => static function (App $app, array $args): string {
        return isset($args['who']) && $args['who'] === 'jefa' ? 'settings.edit' : 'copilot.use';
    }])]);
    adminOrdersRole($app, 'basico', ['copilot.use']);
    $web = copilotAdmin($app, 'basico');

    $ok = copilotAsk($web, 'Saluda a Ana');
    same('proposed', $ok['status']);
    $no = copilotAsk($web, 'Saluda a jefa');
    same('failed', $no['status'], 'saludar a la jefa pide un permiso que no tiene');
});
