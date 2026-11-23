<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Agentic\Ucp;

require_once __DIR__ . '/UcpKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

/*
 * El descubrimiento de UCP y ACP de punta a punta (23/11/2026, una auditoría dio 404 en «/.well-known/ucp» y los 404 reales eran
 * de «/.well-known/ucp.json»): todas las formas de pedir el perfil, la pregunta previa OPTIONS, qué responde la tienda con el
 * protocolo apagado, el perfil frente a lo que exige la especificación y que cada dirección que anuncian los documentos contesta.
 */

/** @return \Aifec\Kernel\App */
function discoveryApp(array $settings = ['agentic.ucp_enabled' => true, 'agentic.acp_enabled' => true])
{
    $modules = ['orders', 'agentic', 'mcp', 'semantic', 'catalog', 'media', 'cms', 'api', 'tools'];

    return aifecOrderApp(['modules' => $modules, 'settings' => ['modules.active' => $modules] + $settings]);
}

test('el perfil UCP se sirve igual en todas sus formas, con JSON y los orígenes abiertos, y también con HEAD y OPTIONS', function (): void {
    $app = discoveryApp();
    $reference = ucpJson(ucpCall($app, 'GET', '/.well-known/ucp'));
    $forms = ['/.well-known/ucp', '/.well-known/ucp.json', '/.well-known/ucp/', '/.well-known/ucp/' . Ucp::VERSIONS[0], '/ucp/v1'];
    foreach ($forms as $path) {
        $get = ucpCall($app, 'GET', $path);
        same(200, $get->status, 'GET ' . $path);
        contains('application/json', (string) $get->headers['Content-Type'], 'GET ' . $path . ' es JSON');
        same('*', $get->headers['Access-Control-Allow-Origin'] ?? null, 'GET ' . $path . ' con CORS abierto');
        $body = ucpJson($get);
        if (strpos($path, '/.well-known/ucp/') === 0 && strlen($path) > strlen('/.well-known/ucp/')) {
            // El perfil de una versión concreta es independiente: no repite la lista de versiones, pero sí los mismos servicios y claves.
            same($reference['ucp']['services'], $body['ucp']['services'], $path . ' anuncia los mismos servicios');
            same($reference['keys'], $body['keys'], $path . ' y las mismas claves');
        } else {
            same($reference, $body, $path . ' es el mismo perfil');
        }
        $head = ucpCall($app, 'HEAD', $path);
        same(200, $head->status, 'HEAD ' . $path);
        $options = ucpCall($app, 'OPTIONS', $path);
        same(204, $options->status, 'OPTIONS ' . $path);
        same('*', $options->headers['Access-Control-Allow-Origin'] ?? null, 'OPTIONS ' . $path . ' con CORS abierto');
        contains('GET', (string) ($options->headers['Access-Control-Allow-Methods'] ?? ''), 'OPTIONS ' . $path . ' dice qué métodos admite');
    }
    // Las versiones antiguas son perfiles propios y una que no existe da 404 en JSON.
    same(200, ucpCall($app, 'GET', '/.well-known/ucp/' . Ucp::VERSIONS[1])->status);
    same(404, ucpCall($app, 'GET', '/.well-known/ucp/1999-01-01')->status);
    // Las direcciones «.well-known» se leen tal cual: con mayúsculas no son otra forma de la misma (RFC 8615), dan 404 en HTML o JSON.
    same(404, ucpCall($app, 'GET', '/.well-known/UCP')->status, 'con mayúsculas no existe: se documenta en 03-servidor-web.md');
});

test('ACP responde también a HEAD y OPTIONS en sus dos formas', function (): void {
    $app = discoveryApp();
    foreach (['/.well-known/acp.json', '/.well-known/acp'] as $path) {
        same(200, ucpCall($app, 'GET', $path)->status, 'GET ' . $path);
        same(200, ucpCall($app, 'HEAD', $path)->status, 'HEAD ' . $path);
        $options = ucpCall($app, 'OPTIONS', $path);
        same(204, $options->status, 'OPTIONS ' . $path);
        same('*', $options->headers['Access-Control-Allow-Origin'] ?? null);
    }
});

test('con UCP o ACP apagados la respuesta es JSON y dice por qué, y el diagnóstico lo avisa', function (): void {
    $app = discoveryApp(['agentic.ucp_enabled' => false, 'agentic.acp_enabled' => false]);
    foreach (['/.well-known/ucp', '/.well-known/ucp.json', '/ucp/v1'] as $path) {
        $r = ucpCall($app, 'GET', $path);
        same(404, $r->status, $path);
        contains('application/json', (string) $r->headers['Content-Type']);
        same('ucp_disabled', ucpJson($r)['error']['code']);
        contains('turned off', ucpJson($r)['error']['message']);
    }
    $acp = ucpCall($app, 'GET', '/.well-known/acp.json');
    same(404, $acp->status);
    same('acp_disabled', ucpJson($acp)['code']);
    same(404, ucpCall($app, 'OPTIONS', '/.well-known/ucp')->status, 'ni siquiera la pregunta previa promete lo que no hay');

    $doctor = static function () use ($app): array {
        foreach ((new \Aifec\Modules\Tools\Doctor($app))->run() as $row) {
            if ($row['id'] === 'agentic.protocols') {
                return $row;
            }
        }

        return [];
    };
    same('warn', $doctor()['status'], 'con los dos apagados avisa');
    contains('UCP + ACP', $doctor()['detail']);
    $app->settings()->set('agentic.ucp_enabled', true);
    same('warn', $doctor()['status'], 'con uno apagado, avisa');
    contains('ACP', $doctor()['detail']);
    $app->settings()->set('agentic.acp_enabled', true);
    same('ok', $doctor()['status'], 'con los dos encendidos no avisa');
});

test('el perfil UCP cumple lo que exige la especificación (ucp.dev, 2026-08-25)', function (): void {
    $app = discoveryApp();
    $raw = ucpCall($app, 'GET', '/.well-known/ucp')->body;
    $profile = json_decode($raw, true);
    check(isset($profile['ucp']) && is_array($profile['ucp']), 'un objeto JSON con el miembro «ucp»');
    same('2026-08-25', $profile['ucp']['version']);
    // «ucp.version, ucp.services y ucp.payment_handlers son obligatorios; los registros han de estar aunque vayan vacíos».
    check(isset($profile['ucp']['services']) && $profile['ucp']['services'] !== [], 'services');
    check(array_key_exists('payment_handlers', $profile['ucp']), 'payment_handlers presente aunque esté vacío');
    contains('"payment_handlers":{}', $raw, 'y como objeto, no como lista');
    foreach ($profile['ucp']['services']['dev.ucp.shopping'] as $binding) {
        foreach (['version', 'spec', 'transport'] as $field) {
            check(isset($binding[$field]) && $binding[$field] !== '', 'cada servicio lleva «' . $field . '»');
        }
        check(!isset($binding['endpoint']) || strpos((string) $binding['endpoint'], 'https://') === 0, 'los endpoints van con https');
    }
    foreach ($profile['ucp']['capabilities'] as $name => $versions) {
        check(strpos($name, 'dev.ucp.') === 0, 'la capacidad «' . $name . '» es de la especificación');
        foreach ($versions as $capability) {
            foreach (['version', 'spec', 'schema'] as $field) {
                check(isset($capability[$field]), $name . ' lleva «' . $field . '»');
            }
        }
    }
    // Las claves de firma van en el «keys[]» de la raíz, como un conjunto JWK.
    check(isset($profile['keys']) && $profile['keys'] !== [], 'keys[] en la raíz');
    foreach ($profile['keys'] as $key) {
        check(in_array($key['kty'], ['EC', 'OKP'], true) && !empty($key['kid']) && !empty($key['x']), 'una clave JWK con tipo, identificador y parte pública');
        check(!isset($key['d']), 'y nunca la parte privada');
    }
});

test('cada dirección que anuncian el perfil, ACP, aifec.json, ard.json, el llms.txt y la ficha MCP contesta', function (): void {
    $app = discoveryApp();
    $documents = [
        '/.well-known/ucp' => ucpCall($app, 'GET', '/.well-known/ucp')->body,
        '/.well-known/acp.json' => ucpCall($app, 'GET', '/.well-known/acp.json')->body,
        '/.well-known/aifec.json' => ucpCall($app, 'GET', '/.well-known/aifec.json')->body,
        '/.well-known/ard.json' => ucpCall($app, 'GET', '/.well-known/ard.json')->body,
        '/.well-known/mcp/server-card.json' => ucpCall($app, 'GET', '/.well-known/mcp/server-card.json')->body,
        '/llms.txt' => ucpCall($app, 'GET', '/llms.txt')->body,
    ];
    $found = [];
    foreach ($documents as $name => $body) {
        same(true, $body !== '', $name . ' existe');
        preg_match_all('#https://tienda\.test(/[^\s"\'<>)\\\\\]]*)#', str_replace('\/', '/', $body), $m);
        foreach ($m[1] as $path) {
            $path = (string) preg_replace('/[#].*$/', '', rtrim($path, '.,;'));
            if ($path === '' || strpbrk($path, '{}<>') !== false || strpos($path, 'TU-') !== false) {
                continue;
            }
            $found[$path][] = $name;
        }
    }
    check(count($found) >= 8, 'los documentos anuncian direcciones propias (' . count($found) . ')');
    $mustAnswer = ['/.well-known/ucp', '/ucp/v1', '/.well-known/acp.json', '/acp/feed.jsonl', '/llms.txt'];
    foreach ($mustAnswer as $path) {
        check(isset($found[$path]), 'se anuncia ' . $path);
    }
    $broken = [];
    foreach ($found as $path => $where) {
        $r = ucpCall($app, 'GET', $path);
        $r->drain();
        // 200, o 405 si es una puerta que solo admite POST (existe, pero no se lee con GET). 404 o un error del servidor, nunca.
        if ($r->status !== 200 && $r->status !== 405) {
            $broken[] = $path . ' → ' . $r->status . ' (anunciada en ' . implode(', ', array_unique($where)) . ')';
        }
    }
    same([], $broken, 'ninguna dirección anunciada da 404 ni error');
});
