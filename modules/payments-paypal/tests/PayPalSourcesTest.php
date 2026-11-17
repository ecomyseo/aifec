<?php
/**
 * AIFEC — pruebas de las formas de pago de PayPal como opciones de la caja: el registro (la cartera sigue siendo «paypal»), a
 * quién se ofrece cada una (país, moneda, importe, encendida y cuenta configurada), su orden, sus textos, la pestaña «Formas de
 * pago» de los ajustes (guardada como la manda un navegador) y que la página de la caja no carga nada de PayPal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;
use Aifec\Modules\PaymentsPaypal\PayPalSources;

require_once __DIR__ . '/PayPalKit.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

/**
 * El contexto con que la caja pregunta a cada forma de pago.
 *
 * @param array<string,mixed> $cambios
 *
 * @return array<string,mixed>
 */
function ppsContext(array $cambios = []): array
{
    return $cambios + ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es'];
}

/**
 * Los códigos que la caja ofrece en ese contexto, en su orden.
 *
 * @param array<string,mixed> $contexto
 *
 * @return string[]
 */
function ppsOffered(\Aifec\Kernel\App $app, array $contexto): array
{
    return array_map(static function ($m): string {
        return $m->code();
    }, $app->service('payments')->available(ppsContext($contexto)));
}

/** La política de contenido de una respuesta, directiva => fuentes (como cspParse() de las pruebas del núcleo). */
function ppsCsp(Response $r): array
{
    $out = [];
    foreach (explode(';', isset($r->headers['Content-Security-Policy']) ? (string) $r->headers['Content-Security-Policy'] : '') as $part) {
        $words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($words) && $words !== []) {
            $out[array_shift($words)] = $words;
        }
    }

    return $out;
}

/** ¿Está marcada la casilla de ese campo en la pantalla de ajustes? null si no está. */
function ppsChecked(string $html, string $key): ?bool
{
    if (preg_match('/<input type="checkbox"[^>]*name="f\[' . preg_quote($key, '/') . '\]"[^>]*>/', $html, $m) !== 1) {
        return null;
    }

    return strpos($m[0], 'checked') !== false;
}

test('se registran todas las formas que se ofrecen con su código; la cartera sigue siendo «paypal»', function (): void {
    $app = ppApp();
    $payments = $app->service('payments');
    // P101: tras las nueve de redirección, las cinco del guion de PayPal.
    same(
        ['paypal', 'paypal_card', 'paypal_bancontact', 'paypal_blik', 'paypal_eps', 'paypal_ideal', 'paypal_mybank', 'paypal_p24', 'paypal_trustly',
            'paypal_paylater', 'paypal_card_fields', 'paypal_apple_pay', 'paypal_google_pay', 'paypal_venmo'],
        array_values(array_filter(array_keys($payments->all()), static function (string $c): bool {
            return strpos($c, 'paypal') === 0;
        }))
    );
    $wallet = $payments->get('paypal');
    check($wallet instanceof PayPalPayment, 'la cartera');
    same('paypal', $wallet->code());
    same('paypal', $wallet->source());
    same(null, $payments->get('paypal_paypal'), 'nunca «paypal_paypal»');
    same(null, $payments->get('paypal_pay_upon_invoice'), 'la solo catalogada no se registra');
    same(null, $payments->get('paypal_giropay'), 'las retiradas tampoco');
    throws(function () use ($app): void {
        new PayPalPayment($app, 'pay_upon_invoice');
    }, \InvalidArgumentException::class);
});

test('los códigos van y vuelven: paypal ↔ cartera, paypal_ideal ↔ ideal; lo demás no es de PayPal', function (): void {
    same('paypal', PayPalSources::code('paypal'));
    same('paypal_ideal', PayPalSources::code('ideal'));
    same('paypal', PayPalSources::source('paypal'));
    same('ideal', PayPalSources::source('paypal_ideal'));
    same('card', PayPalSources::source(' PAYPAL_CARD '));
    same('google_pay', PayPalSources::source('paypal_google_pay'));
    foreach (['paypal_paypal', 'paypal_pay_upon_invoice', 'paypal_', 'redsys', 'gw', '', 'paypalideal'] as $other) {
        same(null, PayPalSources::source($other), $other);
    }
    same(14, count(PayPalSources::codes()));
});

test('de serie, a un comprador de España en euros se le ofrecen la cartera y la tarjeta, nada más', function (): void {
    same(['paypal', 'paypal_card'], ppsOffered(ppApp(), []));
});

// Tabla de casos con todas las locales encendidas: [país, moneda, importe, lo que se ofrece, por qué].
foreach ([
    ['ES', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_trustly'], 'España en euros: la cartera, la tarjeta y Trustly; iDEAL no'],
    ['NL', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_ideal', 'paypal_trustly'], 'Países Bajos en euros: iDEAL'],
    ['BE', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_bancontact'], 'Bélgica en euros: Bancontact'],
    ['BE', 'EUR', 50, ['paypal', 'paypal_card'], 'Bancontact no baja de 1 EUR'],
    ['PL', 'PLN', 2000, ['paypal', 'paypal_card', 'paypal_blik', 'paypal_p24'], 'Polonia en złotys: BLIK y Przelewy24'],
    ['PL', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_p24'], 'Polonia en euros: Przelewy24 sí, BLIK no'],
    ['AT', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_eps', 'paypal_trustly'], 'Austria: eps y Trustly'],
    ['IT', 'EUR', 2000, ['paypal', 'paypal_card', 'paypal_mybank'], 'Italia: MyBank'],
    ['SE', 'SEK', 2000, ['paypal', 'paypal_card', 'paypal_trustly'], 'Suecia en coronas: Trustly'],
    ['NL', 'XXX', 2000, [], 'una moneda que PayPal no cobra: ninguna'],
    ['FR', 'EUR', 2000, ['paypal', 'paypal_card'], 'Francia: solo la cartera y la tarjeta'],
] as $case) {
    test('la caja ofrece por país, moneda e importe: ' . $case[4], function () use ($case): void {
        $app = ppSourcesApp(['bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly']);
        same($case[3], ppsOffered($app, ['country' => $case[0], 'currency' => $case[1], 'total' => $case[2]]));
    });
}

test('una forma apagada no se ofrece aunque el comprador sea de su país, pero sigue registrada', function (): void {
    $app = ppApp();
    same(['paypal', 'paypal_card'], ppsOffered($app, ['country' => 'NL']), 'iDEAL apagada de serie');
    check($app->service('payments')->get('paypal_ideal') !== null, 'registrada para devolver sus pedidos');
    $app->settings()->set(PayPalSettings::enabledKey('ideal'), 1);
    same(['paypal', 'paypal_card', 'paypal_ideal'], ppsOffered($app, ['country' => 'NL']));
    $app->settings()->set(PayPalSettings::enabledKey('card'), 0);
    $app->settings()->set(PayPalSettings::enabledKey('paypal'), 0);
    same(['paypal_ideal'], ppsOffered($app, ['country' => 'NL']), 'también la cartera y la tarjeta se apagan');
});

test('con la cuenta sin configurar no se ofrece ninguna, aunque estén encendidas', function (): void {
    $app = ppApp([PayPalSettings::enabledKey('ideal') => 1, PayPalSettings::enabledKey('trustly') => 1], false);
    same([], ppsOffered($app, ['country' => 'NL']));
    same([], ppsOffered($app, []));
});

test('manda el país de facturación cuando la caja lo da; si no, el del destino', function (): void {
    $app = ppSourcesApp(['ideal']);
    same(['paypal', 'paypal_card', 'paypal_ideal'], ppsOffered($app, ['country' => 'ES', 'billing_country' => 'NL']));
    same(['paypal', 'paypal_card'], ppsOffered($app, ['country' => 'NL', 'billing_country' => 'ES']));
    same(['paypal', 'paypal_card', 'paypal_ideal'], ppsOffered($app, ['country' => 'NL', 'billing_country' => '']));
});

test('el orden de la caja es el número que pone el comercio (a igual número, el del catálogo)', function (): void {
    $app = ppSourcesApp(['ideal', 'trustly'], [
        PayPalSettings::positionKey('ideal') => 1, PayPalSettings::positionKey('paypal') => 2, PayPalSettings::positionKey('card') => 30,
        PayPalSettings::positionKey('trustly') => 2,
    ]);
    same(['paypal_ideal', 'paypal', 'paypal_trustly', 'paypal_card'], ppsOffered($app, ['country' => 'NL']));
    same(['ideal', 'paypal', 'trustly', 'bancontact', 'blik', 'eps', 'mybank', 'p24', 'paylater', 'card_fields', 'apple_pay', 'google_pay', 'venmo', 'card'], (new PayPalSettings($app))->ordered());
});

test('cada una se presenta con su nombre y una frase de una línea, en castellano', function (): void {
    $app = ppApp([PayPalSettings::TITLE => 'PayPal & más']);
    $p = $app->service('payments');
    same(['title' => 'PayPal & más', 'description' => 'Paga en la página segura de PayPal.', 'icon' => ''], $p->get('paypal')->info(), 'la cartera con el título del comerciante');
    same('Tarjeta por PayPal', $p->get('paypal_card')->info()['title'], 'el título del comerciante es solo de la cartera');
    same('Paga con tarjeta en la página segura de PayPal, sin necesidad de cuenta de PayPal.', $p->get('paypal_card')->info()['description']);
    same('iDEAL por PayPal', $p->get('paypal_ideal')->info()['title']);
    same('Pagas en la página de tu banco.', $p->get('paypal_ideal')->info()['description']);
    same('Przelewy24 por PayPal', $p->get('paypal_p24')->info()['title']);
    same('Paga con un código BLIK de la aplicación de tu banco.', $p->get('paypal_blik')->info()['description']);
    contains('unos días', $p->get('paypal_trustly')->info()['description']);
    foreach (PayPalSources::codes() as $code) {
        same(0, $p->get($code)->fee(ppsContext()), 'sin recargo: ' . $code);
        same(true, $p->get($code)->supportsRefund());
        same(true, $p->get($code)->isTest(), 'en el sandbox, de pruebas: ' . $code);
    }
});

test('el «no» de PayPal se explica en una frase útil para quien lleva la tienda', function (): void {
    ppApp();
    contains('no está activada en tu cuenta de PayPal', PayPalSources::explain('ideal', 'NOT_ENABLED_FOR_PAYMENT_SOURCE'));
    contains('iDEAL', PayPalSources::explain('ideal', 'NOT_ENABLED_FOR_PAYMENT_SOURCE'));
    contains('preferencias de pago', PayPalSources::explain('blik', 'INVALID_CURRENCY_CODE'));
    contains('datos del comprador', PayPalSources::explain('p24', 'MISSING_REQUIRED_PARAMETER'));
    contains('no puede recibir pagos', PayPalSources::explain('eps', 'PAYEE_ACCOUNT_RESTRICTED'));
    contains('no ha aceptado', PayPalSources::explain('trustly', 'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR'));
    contains('no ha podido iniciar el pago con Bancontact (ALGO_NUEVO)', PayPalSources::explain('bancontact', 'ALGO_NUEVO'));
    contains('(?)', PayPalSources::explain('bancontact', ''));
});

test('la pestaña «Formas de pago» dice a quién se ofrece cada una, cuáles hay que activar en PayPal y cuáles deja fuera la moneda', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);
    $page = $web->get('/admin/settings/payments-paypal')->body;

    contains('data-af-tab="ppsources"', $page);
    contains('Quién puede usar cada una', $page);
    contains('iDEAL por PayPal', $page);
    contains('Países Bajos', $page, 'el país con su nombre');
    contains('Bélgica', $page);
    contains('Cualquier país', $page, 'la cartera y la tarjeta');
    contains('Actívala en tu cuenta de PayPal.', $page);
    contains('Con la tienda en EUR nunca se ofrecen estas:', $page);
    contains('BLIK solo cobra en PLN.', $page, 'el aviso de la moneda');
    notContains('iDEAL solo cobra', $page, 'iDEAL sí cobra en euros');
    contains('Paga más tarde', $page, 'las que necesitan el guion de PayPal, con su motivo');
    contains('Apple Pay', $page);
    same(true, ppsChecked($page, PayPalSettings::enabledKey('paypal')), 'la cartera, marcada de serie');
    same(true, ppsChecked($page, PayPalSettings::enabledKey('card')), 'la tarjeta, marcada de serie');
    foreach (['bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly'] as $local) {
        same(false, ppsChecked($page, PayPalSettings::enabledKey($local)), $local . ', sin marcar');
    }
    same('6', inputValue($page, 'f[' . PayPalSettings::positionKey('ideal') . ']'), 'el orden de serie');
    contains('hay que activarlas también en tu cuenta de PayPal', $page, 'la ayuda lo explica');
});

test('con la tienda en złotys el aviso nombra las que solo cobran en euros, y no BLIK', function (): void {
    $page = adminClient(ppApp(['shop.currency' => 'PLN'], false))->get('/admin/settings/payments-paypal')->body;
    contains('Con la tienda en PLN nunca se ofrecen estas:', $page);
    contains('iDEAL solo cobra en EUR.', $page);
    contains('Trustly solo cobra en EUR, DKK, SEK, GBP, NOK.', $page);
    notContains('BLIK solo cobra', $page);
    notContains('Przelewy24 solo cobra', $page);
});

test('las casillas se guardan como las manda un navegador: marcada llega «1», desmarcada solo el oculto «0»', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);
    $form = ppForm([PayPalSettings::positionKey('ideal') => '1', PayPalSettings::positionKey('paypal') => '3'] + ppSourcesForm(['paypal', 'ideal', 'trustly']));

    $r = ppSave($web, $form, 'ppsources');
    same(302, $r->status);
    contains('#tab=ppsources', $r->headers['Location'], 'vuelve a la pestaña de las formas de pago');
    $app->settings()->reload();
    $settings = new PayPalSettings($app);
    same(true, $settings->enabled('paypal'));
    same(false, $settings->enabled('card'), 'desmarcada: llega solo el «0»');
    same(true, $settings->enabled('ideal'));
    same(true, $settings->enabled('trustly'));
    same(false, $settings->enabled('blik'));
    same(1, $settings->position('ideal'));
    same(3, $settings->position('paypal'));

    $page = $web->get('/admin/settings/payments-paypal')->body;
    same(true, ppsChecked($page, PayPalSettings::enabledKey('ideal')), 'al volver a la pantalla sale marcada');
    same(false, ppsChecked($page, PayPalSettings::enabledKey('card')), 'y la tarjeta, desmarcada');
    same('1', inputValue($page, 'f[' . PayPalSettings::positionKey('ideal') . ']'));

    // Un campo de casilla que no llega en absoluto también es «apagada».
    $sinCasillas = ppForm();
    unset($sinCasillas[PayPalSettings::enabledKey('ideal')]);
    same(302, ppSave($web, $sinCasillas, 'ppsources')->status);
    $app->settings()->reload();
    same(false, (new PayPalSettings($app))->enabled('ideal'));
});

test('un orden con mala forma no se guarda', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);
    $r = ppSave($web, ppForm([PayPalSettings::positionKey('ideal') => 'primero']), 'ppsources');
    same(422, $r->status);
    $app->settings()->reload();
    same(6, (new PayPalSettings($app))->position('ideal'), 'se queda el de serie');
});

test('la página de la caja ofrece cada forma de PayPal como una opción y no carga nada de PayPal', function (): void {
    $app = frontShop(['modules' => ['payments-paypal'], 'settings' => ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-paypal'], PayPalSettings::enabledKey('trustly') => 1]]);
    $app->settings()->setMany([PayPalSettings::CLIENT_ID_SANDBOX => PP_CLIENT, PayPalSettings::WEBHOOK_ID_SANDBOX => PP_WEBHOOK]);
    $app->setSecret(PayPalSettings::SECRET_SANDBOX, PP_SECRET);
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);
    $r = $web->get('/finalizar-compra');

    same(200, $r->status);
    foreach (['paypal', 'paypal_card', 'paypal_trustly'] as $code) {
        contains('name="payment_method" value="' . $code . '"', $r->body, 'una opción propia: ' . $code);
    }
    contains('Tarjeta por PayPal', $r->body);
    contains('Trustly por PayPal', $r->body);
    notContains('value="paypal_ideal"', $r->body, 'iDEAL no es para un comprador de España');
    notContains(PP_SECRET, $r->body);

    $csp = ppsCsp($r);
    check(isset($csp['script-src']), 'la página lleva su política de contenido');
    foreach (['script-src', 'frame-src', 'connect-src'] as $directive) {
        foreach (isset($csp[$directive]) ? $csp[$directive] : [] as $source) {
            notContains('paypal', $source, $directive . ' no abre nada de PayPal: el comprador va a PayPal, no viene PayPal a la página');
        }
    }
    same(0, preg_match('/<script[^>]+src="https?:\/\/[^"]*paypal/i', $r->body), 'ningún guion de PayPal');
    same(0, preg_match('/<iframe[^>]+paypal/i', $r->body), 'ningún marco de PayPal');
});
