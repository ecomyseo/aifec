<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Lo que la tienda declara de su pago a un agente (perfil de UCP, descubrimiento de ACP y /.well-known/aifec.json): las formas de
 * pago encendidas, que paga la persona en la caja y nada de datos de las pasarelas. Con cada forma encendida o apagada, cambia.
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

require_once __DIR__ . '/UcpKit.php';

/** Una forma de pago de mentira que se enciende y se apaga. */
final class DeclPayment implements PaymentMethod
{
    /** @var string */
    private $code;
    /** @var string */
    private $title;
    /** @var bool */
    public $on = true;
    /** @var bool */
    public $test = false;

    public function __construct(string $code, string $title)
    {
        $this->code = $code;
        $this->title = $title;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function info(): array
    {
        return ['title' => $this->title, 'description' => 'Texto de la caja', 'icon' => ''];
    }

    public function available(array $context): bool
    {
        return $this->on;
    }

    public function fee(array $context): int
    {
        return 0;
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    public function start(array $order): PaymentAction
    {
        throw new \RuntimeException('no se usa');
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        throw new \RuntimeException('no se usa');
    }
}

/** @return array{0:App,1:DeclPayment,2:DeclPayment} */
function declApp(): array
{
    $app = aifecOrderApp(['payment' => false, 'modules' => ['agentic', 'semantic'], 'settings' => [
        'modules.active' => ['orders', 'mail', 'media', 'jobs', 'agentic', 'semantic'],
        'agentic.ucp_enabled' => true,
        'agentic.acp_enabled' => true,
    ]]);
    $paypal = new DeclPayment('paypal', 'PayPal');
    $transfer = new DeclPayment('transfer', 'Transferencia bancaria');
    $app->service('payments')->register($paypal);
    $app->service('payments')->register($transfer);

    return [$app, $paypal, $transfer];
}

/** @return array<string,mixed> */
function declJson(App $app, string $path): array
{
    $request = \Aifec\Kernel\Request::create('GET', $path);
    $request->host = 'tienda.test';
    $request->secure = true;
    $response = $app->handle($request);
    $data = json_decode($response->body, true);
    check($response->status === 200 && is_array($data), $path . ' responde: ' . $response->status . ' ' . substr($response->body, 0, 120));

    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $payment */
function declCodes(array $payment): array
{
    $codes = [];
    foreach (isset($payment['methods']) && is_array($payment['methods']) ? $payment['methods'] : [] as $m) {
        $codes[] = $m['code'];
    }

    return $codes;
}

test('aifec.json, ACP y UCP declaran las formas de pago encendidas y que paga la persona en la caja', function (): void {
    [$app] = declApp();
    $aifec = declJson($app, '/.well-known/aifec.json')['payment'];
    same(['paypal', 'transfer'], declCodes($aifec), 'las formas de pago, en el orden de la caja');
    same('person', $aifec['paid_by']);
    same(false, $aifec['agent_pays']);
    same('shop_checkout', $aifec['completion']);
    same('EUR', $aifec['currency']);
    same('PayPal', $aifec['methods'][0]['name']);
    check(isset($aifec['how_to_reach_the_checkout']['ucp'], $aifec['how_to_reach_the_checkout']['mcp_and_api']), 'cómo se llega a la caja');

    $acp = declJson($app, '/.well-known/acp.json')['payment'];
    same(declCodes($aifec), declCodes($acp), 'ACP dice lo mismo que aifec.json');
    same(false, $acp['agent_pays']);

    $ucp = declJson($app, '/.well-known/ucp')['ucp']['payment_handlers'];
    $handler = $ucp['dev.aifec.shop_checkout'][0];
    same('shop_checkout', $handler['id']);
    same(declCodes($aifec), declCodes($handler['config']), 'UCP dice lo mismo');
    same(false, $handler['config']['agent_pays']);
    check(isset($handler['spec'], $handler['schema'], $handler['version']), 'con lo que un lector de perfiles pide de un manejador');
});

test('con cada forma de pago encendida o apagada cambia lo declarado, y sin ninguna no se promete nada', function (): void {
    [$app, $paypal, $transfer] = declApp();
    $paypal->on = false;
    $app->invalidate(['settings']);
    same(['transfer'], declCodes(declJson($app, '/.well-known/aifec.json')['payment']), 'apagado PayPal solo queda la transferencia');
    same(['transfer'], declCodes(declJson($app, '/.well-known/acp.json')['payment']));
    same(['transfer'], declCodes(declJson($app, '/.well-known/ucp')['ucp']['payment_handlers']['dev.aifec.shop_checkout'][0]['config']));

    $paypal->on = true;
    $transfer->test = true;
    $app->invalidate(['settings']);
    $aifec = declJson($app, '/.well-known/aifec.json')['payment'];
    same(['paypal', 'transfer'], declCodes($aifec), 'encendido otra vez');
    same(true, $aifec['methods'][1]['test'], 'una forma en pruebas lo dice');
    same(true, $aifec['test_mode']);

    $paypal->on = false;
    $transfer->on = false;
    $app->invalidate(['settings']);
    $none = declJson($app, '/.well-known/aifec.json')['payment'];
    same([], $none['methods'], 'sin ninguna forma de pago, la lista va vacía');
    check(!isset($none['how_to_reach_the_checkout']), 'y no se promete una caja');
    check(!isset(declJson($app, '/.well-known/acp.json')['payment']), 'ACP no declara pago');
    $raw = $app->handle(\Aifec\Kernel\Request::create('GET', '/.well-known/ucp'))->body;
    contains('"payment_handlers":{}', $raw, 'UCP deja el registro vacío, como objeto');
});

test('lo declarado no lleva ningún dato de las pasarelas: ni claves, ni IBAN, ni códigos de comercio', function (): void {
    [$app] = declApp();
    $app->settings()->setMany(['payments.transfer.iban' => 'ES0000000000000000000000', 'payments.redsys.merchant_code' => '999008881']);
    $app->invalidate(['settings']);
    foreach (['/.well-known/aifec.json', '/.well-known/acp.json', '/.well-known/ucp'] as $path) {
        $body = json_encode(declJson($app, $path));
        notContains('ES0000000000000000000000', (string) $body, $path . ' sin IBAN');
        notContains('999008881', (string) $body, $path . ' sin código de comercio');
    }
});
