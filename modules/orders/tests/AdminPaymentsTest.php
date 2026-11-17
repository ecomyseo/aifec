<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Admin\PaymentsAdmin;

require_once __DIR__ . '/AdminPanelKit.php';
require_once __DIR__ . '/AdminKit.php';

/*
 * «Ajustes › Formas de pago» (/admin/payments): con los módulos de pago de verdad (transferencia, Redsys, PayPal, contra
 * reembolso y pago simulado), encendidos o apagados según la prueba.
 */

if (!function_exists('paymentsAdminApp')) {
    /** IBAN de mentira con el dígito de control bien puesto. */
    function paymentsAdminIban(): string
    {
        return 'ES8200000000000000000000';
    }

    /** El primer escalón de las migas de pan. */
    function paymentsAdminCrumb(string $html): string
    {
        return preg_match('#<nav class="af-crumbs"[^>]*>\s*<a href="[^"]*">([^<]*)</a>#', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * Tienda con todos los módulos de pago instalados y solo esos encendidos.
     *
     * @param string[]            $on
     * @param array<string,mixed> $settings
     */
    function paymentsAdminApp(array $on, array $settings = []): App
    {
        $all = ['orders', 'plugins', 'payments-transfer', 'payments-redsys', 'payments-cod', 'payments-paypal', 'payments-simulated'];

        return testApp(['modules' => $all, 'settings' => $settings + ['modules.active' => array_merge(['orders', 'plugins'], $on)]]);
    }

    /**
     * Las filas de la tabla: clave => [nombre, módulo, estado, entorno, a quién, con «Configurar»].
     *
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:bool}>
     */
    function paymentsAdminRows(string $html): array
    {
        $out = [];
        preg_match_all('#<tr data-af-payment="([^"]+)">(.*?)</tr>#s', $html, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            preg_match_all('#<td[^>]*>(.*?)</td>#s', $row[2], $cells);
            $text = array_map(static function (string $cell): string {
                $cell = (string) preg_replace('#<details.*?</details>#s', '', $cell);

                return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }, $cells[1]);
            preg_match('#<strong>(.*?)</strong>#s', $row[2], $name);
            $out[$row[1]] = [html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text[2], $text[3], $text[4], $text[5], strpos($row[2], '/admin/settings/') !== false];
        }

        return $out;
    }

    /** Los códigos de las formas que se ofrecerían a un pedido normal, en el orden de la caja. @return string[] */
    function paymentsAdminCheckout(App $app): array
    {
        return array_map(static function ($method): string {
            return $method->code();
        }, $app->service('payments')->available(PaymentsAdmin::normalContext($app)));
    }

    /** Las claves de Redsys en pruebas (número de comercio, terminal y clave de mentira). */
    function paymentsAdminRedsysKeys(App $app): void
    {
        $app->settings()->setMany(['payments.redsys.merchant_code' => '123456789', 'payments.redsys.terminal' => '1']);
        $app->setSecret('payments.redsys.secret_test', 'clave-de-mentira');
    }
}

test('lista las formas registradas con su estado: se ofrece, encendida sin claves y apagada', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-redsys'], ['payments.transfer.iban' => paymentsAdminIban()]);
    $web = adminClient($app);
    $r = $web->get('/admin/payments');
    same(200, $r->status);
    $rows = paymentsAdminRows($r->body);
    same(['c:redsys', 'c:bizum', 'c:transfer'], array_keys($rows), 'una fila por forma, en el orden de la caja');
    same(['Transferencia bancaria', 'Transferencia bancaria', 'Se ofrece', 'Real', '—', true], $rows['c:transfer']);
    same(['Pago con tarjeta', 'Redsys', 'Sin configurar: faltan las claves', 'Pruebas', '—', true], $rows['c:redsys']);
    same('Sin configurar: faltan las claves', $rows['c:bizum'][2]);
    contains('href="/admin/settings/payments-redsys"', $r->body, '«Configurar» lleva a su pantalla de ajustes');
    contains('href="/admin/settings/payments-transfer"', $r->body);
    notContains('Hoy no se ofrecería ninguna forma de pago', $r->body, 'hay una que se ofrece: sin aviso');

    $off = substr($r->body, (int) strpos($r->body, 'Módulos de pago apagados'));
    foreach (['payments-cod' => 'Contra reembolso', 'payments-paypal' => 'PayPal', 'payments-simulated' => 'Pago simulado'] as $id => $name) {
        check(preg_match('#<tr class="af-muted" data-af-payment-off="' . $id . '">\s*<td>' . preg_quote($name, '#') . '</td>#', $off) === 1, 'apagado y en gris: ' . $name);
        contains('action="/admin/payments/enable/' . $id . '"', $off, 'con su botón «Encender»');
    }
    same(3, substr_count($off, 'Apagada'));
    notContains('data-af-payment-off="payments-transfer"', $r->body, 'lo encendido no sale entre lo apagado');
});

test('con sus claves, Redsys se ofrece; Bizum, encendido sin activar en sus ajustes, no lo vería un pedido normal', function (): void {
    $app = paymentsAdminApp(['payments-redsys']);
    $web = adminClient($app);
    paymentsAdminRedsysKeys($app);
    $rows = paymentsAdminRows($web->get('/admin/payments')->body);
    same('Se ofrece', $rows['c:redsys'][2]);
    same('Encendida, pero un pedido normal no la vería', $rows['c:bizum'][2], 'tiene claves: no es que falten');
    same(['redsys'], paymentsAdminCheckout($app), 'y es lo que ofrece la caja');
});

test('«Formas de pago» no da por ofrecida una forma sin claves y avisa arriba si ninguna se ofrecería hoy', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-redsys']);
    $web = adminClient($app);
    $r = $web->get('/admin/payments');
    $rows = paymentsAdminRows($r->body);
    same('Sin configurar: faltan las claves', $rows['c:redsys'][2]);
    same('Encendida, pero un pedido normal no la vería', $rows['c:transfer'][2], 'sin IBAN tampoco se ofrece');
    same([], paymentsAdminCheckout($app));
    contains('<div class="af-alert af-alert-warn" role="status">Hoy no se ofrecería ninguna forma de pago a un pedido normal: los clientes no podrían pagar.</div>', $r->body);
    contains('Un pedido normal: 50,00', $r->body);
    contains('a ES, con envío.', $r->body);

    $app->settings()->set('payments.transfer.iban', paymentsAdminIban());
    $r = $web->get('/admin/payments');
    notContains('Hoy no se ofrecería ninguna forma de pago', $r->body, 'con una que se ofrece, el aviso se va');
    same('Se ofrece', paymentsAdminRows($r->body)['c:transfer'][2]);
});

test('el orden se cambia con las flechas, enviando el formulario como un navegador, y la caja lo respeta', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-redsys'], ['payments.transfer.iban' => paymentsAdminIban()]);
    $web = adminClient($app);
    paymentsAdminRedsysKeys($app);
    same(['redsys', 'transfer'], paymentsAdminCheckout($app), 'antes, por orden de registro');
    $page = $web->get('/admin/payments');
    check(preg_match('#name="move" value="up:c:redsys" aria-label="Subir «Pago con tarjeta»" disabled>#', $page->body) === 1, 'la primera no sube');
    check(preg_match('#name="move" value="down:c:transfer" aria-label="Bajar «Transferencia bancaria»" disabled>#', $page->body) === 1, 'la última no baja');

    $r = $web->post('/admin/payments/order', panelKitForm($page->body, '/admin/payments/order', ['move', 'up:c:transfer']));
    same(302, $r->status);
    same('/admin/payments', $r->headers['Location']);
    $page = $web->follow();
    contains('Orden de las formas de pago guardado', $page->body);
    same(['c:redsys', 'c:transfer', 'c:bizum'], array_keys(paymentsAdminRows($page->body)));
    $r = $web->post('/admin/payments/order', panelKitForm($page->body, '/admin/payments/order', ['move', 'up:c:transfer']));
    same(302, $r->status);
    same(['c:transfer', 'c:redsys', 'c:bizum'], array_keys(paymentsAdminRows($web->follow()->body)));
    same('transfer', $app->settings()->str('orders.payment_order'), 'se guarda lo justo: lo demás va por orden de registro');
    same(['transfer', 'redsys'], paymentsAdminCheckout($app), 'la caja ofrece primero la transferencia');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'settings' AND entity_id = 'orders' AND summary = 'orders.payment_order'") >= 1 ? 1 : 0, 'queda en el registro de actividad');
});

test('una flecha que no lleva a ningún sitio no cambia nada, ni una fila que no existe', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-redsys'], ['payments.transfer.iban' => paymentsAdminIban(), 'orders.payment_order' => 'transfer']);
    $web = adminClient($app);
    $page = $web->get('/admin/payments')->body;
    foreach (['up:c:transfer', 'down:c:bizum', 'up:c:nada', 'arriba:c:redsys', 'up:../x', ''] as $move) {
        $r = $web->post('/admin/payments/order', panelKitForm($page, '/admin/payments/order', ['move', $move]));
        same(302, $r->status, $move);
        notContains('Orden de las formas de pago guardado', $web->follow()->body, $move);
        same('transfer', $app->settings()->str('orders.payment_order'), $move);
    }
});

test('sin el permiso de los ajustes de ventas, 403; sin el de módulos, no hay botón «Encender» y su envío da 403', function (): void {
    $app = paymentsAdminApp(['payments-transfer']);
    $app->boot();
    $app->db()->insert('roles', ['code' => 'ventas', 'name' => 'Ventas', 'caps' => json_encode(['orders.view']), 'system' => 0]);
    $app->db()->insert('roles', ['code' => 'cobros', 'name' => 'Cobros', 'caps' => json_encode(['orders.view', 'orders.edit']), 'system' => 0]);
    $viewer = adminClient($app, 'ventas');
    $token = aifecToken($viewer);
    same(403, $viewer->get('/admin/payments')->status);
    same(403, $viewer->post('/admin/payments/order', ['_token' => $token, 'move' => 'up:c:transfer'])->status);
    notContains('/admin/payments', $viewer->get('/admin')->body, 'ni sale en su menú');

    $payer = adminClient($app, 'cobros');
    $r = $payer->get('/admin/payments');
    same(200, $r->status);
    contains('Formas de pago', $r->body);
    notContains('/admin/payments/enable/', $r->body);
    same(403, $payer->post('/admin/payments/enable/payments-cod', ['_token' => aifecToken($payer)])->status);
    notContains('payments-cod', (string) json_encode($app->settings()->get('modules.active')), 'no se ha encendido nada');
    contains('href="/admin/settings/payments-transfer"', $r->body, 'los ajustes de cada forma piden el mismo permiso: sí puede configurarla');
    same(403, adminClient($app, 'support')->get('/admin/settings/payments-transfer')->status, 'quien no lo tiene, no');
});

test('«Encender» enciende un módulo de pago apagado y vuelve a la lista; lo que no es un módulo de pago apagado da 404', function (): void {
    $app = paymentsAdminApp(['payments-transfer']);
    $web = adminClient($app);
    $web->get('/admin/payments');
    $r = $web->post('/admin/payments/enable/payments-cod', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('/admin/payments', $r->headers['Location']);
    check(in_array('payments-cod', (array) $app->settings()->get('modules.active'), true), 'queda encendido');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'plugins.enable' AND entity_id = 'payments-cod'"));
    $web->get('/admin/payments');
    $token = aifecToken($web);
    foreach (['payments-transfer', 'catalog', 'no-existe'] as $id) {
        same(404, $web->post('/admin/payments/enable/' . $id, ['_token' => $token])->status, $id);
    }
});

test('PayPal sale en una sola fila con cuántas de sus formas se ofrecen, y se mueve entera', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-paypal'], ['payments.transfer.iban' => paymentsAdminIban()]);
    $web = adminClient($app);
    $r = $web->get('/admin/payments');
    $rows = paymentsAdminRows($r->body);
    same(['m:payments-paypal', 'c:transfer'], array_keys($rows), 'no catorce filas sueltas');
    same('PayPal', $rows['m:payments-paypal'][0]);
    same('Sin configurar: faltan las claves', $rows['m:payments-paypal'][2]);
    contains('Se ofrecen 0 de 14', $r->body);
    same(14, substr_count(substr($r->body, (int) strpos($r->body, '<details class="af-help"><summary class="af-small">Verlas</summary>')), '<li>') >= 14 ? 14 : 0, 'la lista plegada trae las catorce');

    $app->settings()->setMany(['payments.paypal.client_id_sandbox' => 'cliente-de-mentira']);
    $app->setSecret('payments.paypal.secret_sandbox', 'clave-de-mentira');
    $r = $web->get('/admin/payments');
    $rows = paymentsAdminRows($r->body);
    same('Se ofrece', $rows['m:payments-paypal'][2]);
    $offered = paymentsAdminCheckout($app);
    $paypal = array_values(array_filter($offered, static function (string $code): bool {
        return strpos($code, 'paypal') === 0;
    }));
    check($paypal !== [], 'PayPal ofrece alguna de sus formas');
    contains('Se ofrecen ' . count($paypal) . ' de 14', $r->body);
    same('Pruebas', $rows['m:payments-paypal'][3]);

    $r = $web->post('/admin/payments/order', panelKitForm($r->body, '/admin/payments/order', ['move', 'down:m:payments-paypal']));
    same(302, $r->status);
    same(['c:transfer', 'm:payments-paypal'], array_keys(paymentsAdminRows($web->follow()->body)));
    same(array_merge(['transfer'], $paypal), paymentsAdminCheckout($app), 'la caja: la transferencia y después las de PayPal, juntas');
    check(strlen($app->settings()->str('orders.payment_order')) <= PaymentsAdmin::ORDER_MAX, 'cabe en el campo de los ajustes de ventas');
    $r = $web->post('/admin/payments/order', panelKitForm($web->get('/admin/payments')->body, '/admin/payments/order', ['move', 'up:m:payments-paypal']));
    same(302, $r->status);
    same(array_merge($paypal, ['transfer']), paymentsAdminCheckout($app));
    check(strlen($app->settings()->str('orders.payment_order')) <= PaymentsAdmin::ORDER_MAX, 'las catorce de PayPal delante caben en los 200 caracteres');
});

test('los ajustes de ventas se siguen pudiendo guardar con el orden que deja esta pantalla', function (): void {
    $app = paymentsAdminApp(['payments-transfer', 'payments-paypal'], ['payments.transfer.iban' => paymentsAdminIban()]);
    $web = adminClient($app);
    $r = $web->post('/admin/payments/order', panelKitForm($web->get('/admin/payments')->body, '/admin/payments/order', ['move', 'up:c:transfer']));
    same(302, $r->status);
    $saved = $app->settings()->str('orders.payment_order');
    $page = $web->get('/admin/settings/orders');
    same($saved, inputValue($page->body, 'f[orders.payment_order]'), 'el campo de los ajustes enseña lo guardado');
    $r = $web->post('/admin/settings/orders', panelKitForm($page->body, '/admin/settings/orders'));
    same(302, $r->status, 'y el formulario de ventas se guarda sin error');
    same($saved, $app->settings()->str('orders.payment_order'));
});

test('el orden más corto: solo se nombra lo que no sale ya por orden de registro', function (): void {
    same([], PaymentsAdmin::shortest(['a', 'b', 'c'], ['a', 'b', 'c']));
    same(['c'], PaymentsAdmin::shortest(['c', 'a', 'b'], ['a', 'b', 'c']));
    same(['b'], PaymentsAdmin::shortest(['b', 'a', 'c'], ['a', 'b', 'c']), 'tras «b» van «a» y «c» por su orden de registro');
    same(['a', 'c'], PaymentsAdmin::shortest(['a', 'c', 'b'], ['a', 'b', 'c']));
    same(['c', 'b'], PaymentsAdmin::shortest(['c', 'b', 'a'], ['a', 'b', 'c']));
    same([], PaymentsAdmin::shortest([], ['a']));
    same([], PaymentsAdmin::shortest(['b'], ['a', 'b']), 'con una lista parcial solo cuentan las de la lista: «b» ya sale primera entre ellas');
    same(['c'], PaymentsAdmin::shortest(['c', 'a'], ['a', 'b', 'c']));
});

test('el orden de la caja: lo nombrado primero, sin repetir, sin mayúsculas ni espacios y sin códigos que no existen', function (): void {
    $app = paymentsAdminApp(['payments-transfer'], ['orders.payment_order' => ' Transfer , nada,transfer']);
    $app->boot();
    same(['transfer'], PaymentsAdmin::effectiveOrder($app, ['transfer']));
    same(['b', 'a'], PaymentsAdmin::effectiveOrder($app, ['b', 'a']), 'sin nada nombrado que exista, por registro');
    $app->settings()->set('orders.payment_order', ['b', 'B', 7, null]);
    same(['b', 'a'], PaymentsAdmin::effectiveOrder($app, ['a', 'b']), 'también guardado como lista');
});

test('un título con «&» sale escapado, y la pantalla no lleva guiones escritos en ella', function (): void {
    $app = paymentsAdminApp(['payments-transfer'], ['payments.transfer.iban' => paymentsAdminIban(), 'payments.transfer.title' => 'Transferencia <b>& Cía</b>']);
    $web = adminClient($app);
    $r = $web->get('/admin/payments');
    contains('Transferencia &lt;b&gt;&amp; Cía&lt;/b&gt;', $r->body);
    notContains('<b>& Cía', $r->body);
    notContains('onclick', $r->body);
    same(1, preg_match_all('#<script>#', $r->body), 'solo el guion del tema claro u oscuro');
    same('Ajustes', paymentsAdminCrumb($r->body));
    contains('<a href="/admin/payments" aria-current="page">Formas de pago</a>', $r->body, 'su entrada de Ajustes va marcada');
});

test('lo que una forma declara (países, si está configurada) se enseña; lo que no, «—»; y una que falla no tumba la pantalla', function (): void {
    $app = paymentsAdminApp([]);
    $app->boot();
    $make = static function (string $code, string $title, $countries, ?bool $configured, bool $offered, bool $fails = false): PaymentMethod {
        return new class ($code, $title, $countries, $configured, $offered, $fails) implements PaymentMethod {
            /** @var array<int,mixed> */
            private $d;

            /** @param mixed $countries */
            public function __construct(string $code, string $title, $countries, ?bool $configured, bool $offered, bool $fails)
            {
                $this->d = [$code, $title, $countries, $configured, $offered, $fails];
            }

            public function code(): string
            {
                return $this->d[0];
            }

            public function info(): array
            {
                return ['title' => $this->d[1], 'description' => '', 'icon' => ''];
            }

            public function available(array $context): bool
            {
                if ($this->d[5]) {
                    throw new \RuntimeException('Gateway down');
                }

                return $this->d[4];
            }

            public function fee(array $context): int
            {
                return 0;
            }

            public function isTest(): bool
            {
                return false;
            }

            public function start(array $order): PaymentAction
            {
                return PaymentAction::paid('x');
            }

            public function supportsRefund(): bool
            {
                return false;
            }

            public function refund(array $order, int $amount, string $reason): string
            {
                return '';
            }

            /** @return string[] */
            public function countries(): array
            {
                return is_array($this->d[2]) ? $this->d[2] : [];
            }

            public function configured(): bool
            {
                return $this->d[3] !== false;
            }
        };
    };
    $payments = $app->service('payments');
    $payments->register($make('ibe', 'Ibérica', ['PT', 'ES'], true, true));
    $payments->register($make('mundo', 'Mundial', [], true, true));
    $payments->register($make('sinclave', 'Sin clave', ['ES'], false, false));
    $payments->register($make('rota', 'Rota', ['ES'], true, true, true));
    $payments->register(new AifecOrderPayment('vieja'));
    $rows = paymentsAdminRows(adminClient($app)->get('/admin/payments')->body);
    same(['Ibérica', '—', 'Se ofrece', 'Real', 'ES, PT', false], $rows['c:ibe'], 'sus países, sin módulo y sin «Configurar»');
    same('Cualquiera', $rows['c:mundo'][4], 'una lista vacía es «cualquiera»');
    same('Sin configurar: faltan las claves', $rows['c:sinclave'][2], 'lo dice ella misma');
    same('Encendida, pero un pedido normal no la vería', $rows['c:rota'][2], 'la que falla cuenta como que no se ofrece');
    same('—', $rows['c:vieja'][4], 'la que no declara países, «—»');
});

test('«Formas de pago» está en Ajustes con su nombre y la ven quienes cambian los ajustes de ventas', function (): void {
    $app = paymentsAdminApp(['payments-transfer']);
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    contains('<a href="/admin/payments">Formas de pago</a>', $home);
    $web->get('/admin/palette.json?q=formas');
    same('/admin/payments', array_column($web->json()['items'] ?? [], 'url', 'label')['Formas de pago'] ?? '', 'y la encuentra el buscador del panel');
});

test('el contra reembolso de verdad: se ofrece a un pedido normal, enseña sus países y no deja ningún error en el registro', function (): void {
    // 13/11/2026, en vivo: su método de países era privado, la pantalla lo llamaba, saltaba un TypeError (capturado y al
    // registro) y el contra reembolso salía como «un pedido normal no la vería» sin ser verdad. Ninguna prueba lo encendía.
    $app = paymentsAdminApp(['payments-cod'], ['payments.cod.countries' => ['ES', 'PT']]);
    $before = count($app->log()->records);
    $web = adminClient($app);
    $r = $web->get('/admin/payments');
    same(200, $r->status);
    $rows = paymentsAdminRows($r->body);
    check(isset($rows['c:cod']), 'el contra reembolso tiene su fila');
    same('Se ofrece', $rows['c:cod'][2]);
    same('ES, PT', $rows['c:cod'][4], 'a quién se ofrece: sus países');
    $errors = array_values(array_filter(array_slice($web->app->log()->records, $before), static function (array $record): bool {
        return isset($record['level']) && $record['level'] === 'error';
    }));
    same([], array_column($errors, 'message'), 'pintar la pantalla no apunta ningún error');
    check(in_array('cod', paymentsAdminCheckout($app), true), 'y la caja lo ofrece');
});
