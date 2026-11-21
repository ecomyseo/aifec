<?php
/**
 * AIFEC — Web Bot Auth: la firma de los agentes de IA (RFC 9421 con Ed25519), con el vector de prueba del propio RFC y con
 * un agente de mentira que firma como el de ChatGPT.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Modules\Agentic\AgentAccess;
use Aifec\Modules\Agentic\WebBotAuth;

require_once __DIR__ . '/UcpKit.php';

/** Base64url sin relleno. */
function wbaB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Una tienda con un agente «https://chatgpt.com» de mentira: su directorio de claves sale del cliente HTTP de pruebas.
 *
 * @param array<string,mixed> $ajustes
 *
 * @return array{0:App,1:string,2:string} la tienda, la clave privada y su identificador
 */
function wbaShop(array $ajustes = []): array
{
    [$app] = ucpApp($ajustes);
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $x = wbaB64($public);
    $kid = WebBotAuth::thumbprint($x);
    ucpHttp($app)->when('GET', 'chatgpt.com/.well-known/http-message-signatures-directory', static function () use ($x, $kid): HttpResponse {
        return new HttpResponse(200, ['Content-Type' => 'application/http-message-signatures-directory+json'], (string) json_encode([
            'keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x, 'kid' => $kid, 'use' => 'sig']],
            'signature_agent' => 'https://chatgpt.com', 'purpose' => 'ai',
        ]));
    });

    return [$app, sodium_crypto_sign_secretkey($pair), $kid];
}

/**
 * Una petición firmada como la manda el agente: @authority y signature-agent, con su ventana y la etiqueta web-bot-auth.
 *
 * @param array<string,mixed> $cambios authority, agent, created, expires, tag, components
 */
function wbaRequest(App $app, string $secret, string $kid, array $cambios = []): Request
{
    $now = $app->clock()->now();
    $authority = isset($cambios['authority']) ? (string) $cambios['authority'] : 'tienda.test';
    $agent = isset($cambios['agent']) ? (string) $cambios['agent'] : 'https://chatgpt.com';
    $created = isset($cambios['created']) ? (int) $cambios['created'] : $now - 5;
    $expires = isset($cambios['expires']) ? (int) $cambios['expires'] : $now + 55;
    $tag = array_key_exists('tag', $cambios) ? $cambios['tag'] : 'web-bot-auth';
    $only = isset($cambios['components']) ? (string) $cambios['components'] : '';
    $withAgent = $only !== 'authority';
    $withAuthority = $only !== 'agent';
    $components = '(' . trim(($withAuthority ? '"@authority" ' : '') . ($withAgent ? '"signature-agent"' : '')) . ')';
    $params = $components . ';created=' . $created . ';expires=' . $expires . ';keyid="' . $kid . '";alg="ed25519";nonce="bm9uY2UtZGUtcHJ1ZWJh"' . ($tag !== null ? ';tag="' . $tag . '"' : '');
    $base = ($withAuthority ? '"@authority": ' . $authority . "\n" : '') . ($withAgent ? '"signature-agent": "' . $agent . '"' . "\n" : '') . '"@signature-params": ' . $params;
    $signature = base64_encode(sodium_crypto_sign_detached($base, $secret));
    $request = Request::create('GET', '/.well-known/ucp', [], [], [
        'signature-input' => 'sig1=' . $params,
        'signature' => 'sig1=:' . $signature . ':',
        'signature-agent' => '"' . $agent . '"',
        'forwarded' => 'for=203.0.113.7;ip-country=US;locale=en-US;tz=America/New_York',
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
    ]);
    $request->host = 'tienda.test';
    $request->secure = true;
    $request->ip = '192.0.2.50';

    return $request;
}

test('Web Bot Auth: el vector Ed25519 del RFC 9421 (B.2.6) se verifica, y un cambio lo rompe', function (): void {
    $public = (string) base64_decode('JrQLj5P/89iXES9+vFgrIy29clF9CC/oPPsw3c5D0bs=');
    $signature = (string) base64_decode('wqcAqbmYJ2ji2glfAMaRy4gruYYnx2nEFN2HN6jrnDnQCK1u02Gb04v9EDgwUPiu4A0w6vuQv5lIp5WPpBKRCw==');
    $input = WebBotAuth::parseInput('sig-b26=("date" "@method" "@path" "@authority" "content-type" "content-length");created=1618884473;keyid="test-key-ed25519"');
    check(isset($input['sig-b26']), 'se lee Signature-Input');
    same(1618884473, $input['sig-b26']['params']['created'], 'created es un número');
    same('test-key-ed25519', $input['sig-b26']['params']['keyid'], 'keyid es una cadena');
    same(['sig-b26' => $signature], WebBotAuth::parseSignatures('sig-b26=:' . base64_encode($signature) . ':'), 'se lee Signature');

    $make = static function (string $host): Request {
        $request = Request::create('POST', '/foo?param=Value&Pet=dog', [], [], [
            'date' => 'Tue, 20 Apr 2021 02:07:55 GMT', 'content-type' => 'application/json', 'content-length' => '18',
        ], '{"hello": "world"}');
        $request->host = $host;

        return $request;
    };
    $base = WebBotAuth::signatureBase($make('example.com'), $input['sig-b26']);
    same(
        "\"date\": Tue, 20 Apr 2021 02:07:55 GMT\n\"@method\": POST\n\"@path\": /foo\n\"@authority\": example.com\n\"content-type\": application/json\n\"content-length\": 18\n\"@signature-params\": (\"date\" \"@method\" \"@path\" \"@authority\" \"content-type\" \"content-length\");created=1618884473;keyid=\"test-key-ed25519\"",
        $base,
        'la base de la firma es la del RFC'
    );
    same(true, WebBotAuth::verifyEd25519((string) $base, $signature, $public), 'la firma del RFC se verifica');
    same(false, WebBotAuth::verifyEd25519((string) WebBotAuth::signatureBase($make('otra.example'), $input['sig-b26']), $signature, $public), 'en otro dominio, no');
    $tocada = $signature;
    $tocada[10] = chr(ord($tocada[10]) ^ 1);
    same(false, WebBotAuth::verifyEd25519((string) $base, $tocada, $public), 'con un bit cambiado, no');
    // La clave que publica ChatGPT en su directorio (16/11/2026): su «kid» es la huella JWK de RFC 7638.
    same('ArwjqcCqtA5oxEWkzXGC-To_0A9whHmajCHtfe8I9aY', WebBotAuth::thumbprint('P_1c4SX6fCavDcq6FRegWcVvaOKsp_aXHPEN1FcKDIA'), 'la huella JWK (RFC 7638) es el identificador publicado por ChatGPT');
});

test('Web Bot Auth: el agente de ChatGPT firmado se reconoce, con la dirección de la persona', function (): void {
    [$app, $secret, $kid] = wbaShop();
    $request = wbaRequest($app, $secret, $kid);
    $app->handle($request);
    $agent = $request->attr(AgentAccess::ATTR);
    check(is_array($agent), 'la petición queda marcada como de un agente verificado');
    same('ChatGPT', $agent['name'], 'ChatGPT');
    same('https://chatgpt.com', $agent['agent'], 'por su origen');
    same('203.0.113.7', $request->ip, 'la dirección pasa a ser la de la persona (Forwarded)');
    same('US', $agent['forwarded']['ip-country'], 'con su país');
    $last = $app->cache()->get('agentic.last_agent');
    same('ChatGPT', is_array($last) ? $last['name'] : '', 'el panel puede decir cuándo vino el último');

    $otra = wbaRequest($app, $secret, $kid);
    $app->handle($otra);
    check(is_array($otra->attr(AgentAccess::ATTR)), 'la segunda también');
    same(1, count(ucpHttp($app)->requests), 'el directorio de claves se descarga una vez y se guarda');
});

test('Web Bot Auth: lo que no se cree (otro dominio, caducada, sin etiqueta, sin cubrir el agente, agente desconocido, apagado)', function (): void {
    $casos = [
        'firmada para otro dominio' => ['authority' => 'otra-tienda.example'],
        'caducada' => ['created' => 1000, 'expires' => 1060],
        'una ventana de más de un día' => ['created' => time() - 10, 'expires' => time() + 3 * 86400],
        'creada en el futuro' => ['created' => time() + 3600, 'expires' => time() + 3700],
        'sin la etiqueta web-bot-auth' => ['tag' => null],
        'que no cubre Signature-Agent' => ['components' => 'authority'],
        'que no cubre el dominio (@authority): valdría en cualquier tienda' => ['components' => 'agent'],
    ];
    foreach ($casos as $caso => $cambios) {
        [$app, $secret, $kid] = wbaShop();
        if (isset($cambios['created']) && $cambios['created'] > 2000) {
            $delta = $app->clock()->now() - time();
            $cambios['created'] += $delta;
            $cambios['expires'] += $delta;
        }
        $request = wbaRequest($app, $secret, $kid, $cambios);
        $app->handle($request);
        same(null, $request->attr(AgentAccess::ATTR), 'no se cree una firma ' . $caso);
        same('192.0.2.50', $request->ip, 'y la dirección no cambia (' . $caso . ')');
    }

    [$app, $secret, $kid] = wbaShop();
    $request = wbaRequest($app, $secret, $kid, ['agent' => 'https://agente-desconocido.example']);
    $app->handle($request);
    same(null, $request->attr(AgentAccess::ATTR), 'un agente que no está en la lista de confianza no se cree');
    same([], ucpHttp($app)->requests, 'y no se descarga nada de su dominio');

    [$app2, $secret2, $kid2] = wbaShop(['agentic.verify_agents' => false]);
    $off = wbaRequest($app2, $secret2, $kid2);
    $app2->handle($off);
    same(null, $off->attr(AgentAccess::ATTR), 'con la verificación apagada no se mira');

    [$app3, $secret3] = wbaShop();
    [, , $otroKid] = wbaShop();
    $ajena = wbaRequest($app3, $secret3, $otroKid);
    $app3->handle($ajena);
    same(null, $ajena->attr(AgentAccess::ATTR), 'una clave que no está en el directorio del agente no se cree');
});

test('Web Bot Auth: si el directorio de claves no responde, no se cree y no se insiste en cada petición', function (): void {
    [$app] = ucpApp();
    $http = ucpHttp($app);
    $http->when('GET', 'chatgpt.com/.well-known/http-message-signatures-directory', static function (HttpRequest $r): HttpResponse {
        return new HttpResponse(503, [], 'caído');
    });
    $pair = sodium_crypto_sign_keypair();
    $kid = WebBotAuth::thumbprint(wbaB64(sodium_crypto_sign_publickey($pair)));
    foreach ([1, 2] as $vez) {
        $request = wbaRequest($app, sodium_crypto_sign_secretkey($pair), $kid);
        $app->handle($request);
        same(null, $request->attr(AgentAccess::ATTR), 'no verificado (' . $vez . ')');
    }
    same(1, count($http->requests), 'un solo intento: el fallo también se guarda unos minutos');
});

test('Web Bot Auth: Forwarded solo da la dirección si es una IP', function (): void {
    same('203.0.113.7', WebBotAuth::forwarded('for=203.0.113.7;ip-country=US')['for'], 'una IP');
    same('2001:db8::1', WebBotAuth::forwarded('for="[2001:db8::1]";ip-country=US')['for'], 'una IPv6 entre corchetes');
    same(false, isset(WebBotAuth::forwarded('for=_oculto;ip-country=US')['for']), 'un nombre no');
    same('es-ES', WebBotAuth::forwarded('locale=es-ES')['locale'], 'el idioma');
    same('', WebBotAuth::origin('http://chatgpt.com'), 'un origen sin https no vale');
    same('https://chatgpt.com', WebBotAuth::origin('https://ChatGPT.com/'), 'el origen se normaliza');
});
