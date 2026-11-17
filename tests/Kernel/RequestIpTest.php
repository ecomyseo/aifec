<?php
/**
 * AIFEC — pruebas de la dirección del visitante detrás de un proxy: solo se cree una cabecera de proxy si viene de un proxy de confianza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;
use Aifec\Modules\Tools\Checks\ServerChecks;

if (!function_exists('ripRequest')) {
    /**
     * @param array<string,string> $headers
     */
    function ripRequest(string $remote, array $headers = []): Request
    {
        return new Request('GET', '/', [], [], [], $headers, '', [], $remote, 'tienda.test', true);
    }
}

test('sin proxies de confianza la dirección es la de la conexión, y las cabeceras de proxy se ignoran vengan de quien vengan', function (): void {
    $r = ripRequest('198.51.100.20', ['x-forwarded-for' => '203.0.113.9', 'cf-connecting-ip' => '203.0.113.10']);
    same('198.51.100.20', $r->ip, 'de entrada, la de la conexión');
    same('198.51.100.20', $r->remoteAddr);
    $r->trustProxies([]);
    same('198.51.100.20', $r->ip, 'una lista vacía no cree nada');
    $r->trustProxies(['no-es-una-ip', '10.0.0.0/99']);
    same('198.51.100.20', $r->ip, 'una lista con basura tampoco');
    same('198.51.100.20', Request::clientIp('198.51.100.20', ['x-forwarded-for' => '1.1.1.1'], []));
    same('198.51.100.20', Request::clientIp('198.51.100.20', ['cf-connecting-ip' => '1.1.1.1'], [], 'cf-connecting-ip'));
});

test('una cabecera de quien no es un proxy de confianza se ignora aunque haya proxies configurados', function (): void {
    same('198.51.100.99', Request::clientIp('198.51.100.99', ['x-forwarded-for' => '1.1.1.1'], ['10.0.0.0/8']), 'una IP cualquiera dice ser otra');
    same('198.51.100.99', Request::clientIp('198.51.100.99', ['x-forwarded-for' => '10.0.0.7'], ['10.0.0.0/8']), 'ni siquiera diciendo que viene de un proxy');
    same('11.0.0.1', Request::clientIp('11.0.0.1', ['x-forwarded-for' => '1.1.1.1'], ['10.0.0.0/8']), 'justo fuera del rango');
    same('not-an-ip', Request::clientIp('not-an-ip', ['x-forwarded-for' => '1.1.1.1'], ['10.0.0.0/8']), 'una dirección de conexión rara');
});

test('con un proxy de confianza se usa la primera dirección que no lo es, contando desde la derecha', function (): void {
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '198.51.100.7'], ['10.0.0.5']));
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '198.51.100.7'], ['10.0.0.0/8']));
    // Cadena: la de más a la izquierda la escribe el propio visitante y no vale; manda la primera no fiable desde la derecha.
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '203.0.113.9, 198.51.100.7, 10.0.0.6'], ['10.0.0.0/8']));
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => ' 198.51.100.7 ,10.0.0.6,  10.0.0.7 '], ['10.0.0.0/8']), 'con espacios');
    same('203.0.113.9', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '198.51.100.7, 203.0.113.9'], ['10.0.0.0/8']), 'si la última no es de confianza, es esa');
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '10.0.0.6, 10.0.0.7'], ['10.0.0.0/8']), 'si todas son de confianza, queda la conexión');
    same('10.0.0.5', Request::clientIp('10.0.0.5', [], ['10.0.0.0/8']), 'sin cabecera');
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['x-forwarded-for' => ''], ['10.0.0.0/8']), 'cabecera vacía');
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['x-forwarded-for' => ' , '], ['10.0.0.0/8']), 'cabecera de comas');
    $r = ripRequest('10.0.0.5', ['x-forwarded-for' => '198.51.100.7']);
    $r->trustProxies(['10.0.0.0/8']);
    same('198.51.100.7', $r->ip);
    same('10.0.0.5', $r->remoteAddr, 'la de la conexión no se pierde');
});

test('rangos CIDR: bordes, máscaras que parten un byte, /32, /0 e IPv4 dentro de IPv6', function (): void {
    $via = static function (string $visitor, string $range): bool {
        return Request::clientIp('10.9.9.9', ['x-forwarded-for' => $visitor], ['10.9.9.9', $range]) === '10.9.9.9';
    };
    // «true» = la dirección está dentro del rango y por eso se salta (queda la conexión).
    same(true, $via('192.168.1.0', '192.168.1.0/24'));
    same(true, $via('192.168.1.255', '192.168.1.0/24'));
    same(false, $via('192.168.2.1', '192.168.1.0/24'));
    same(true, $via('172.31.255.255', '172.16.0.0/12'));
    same(false, $via('172.32.0.1', '172.16.0.0/12'));
    same(false, $via('172.15.255.255', '172.16.0.0/12'));
    same(true, $via('203.0.113.127', '203.0.113.0/25'));
    same(false, $via('203.0.113.128', '203.0.113.0/25'));
    same(true, $via('203.0.113.5', '203.0.113.5/32'));
    same(false, $via('203.0.113.6', '203.0.113.5/32'));
    same(true, $via('1.2.3.4', '0.0.0.0/0'));
    same(true, $via('203.0.113.1', '203.0.113.0/31'));
    same(false, $via('203.0.113.2', '203.0.113.0/31'));
    // IPv6
    same(true, $via('2001:db8::2', '2001:db8::/32'));
    same(true, $via('2001:db8:ffff::1', '2001:db8::/32'));
    same(false, $via('2001:db9::1', '2001:db8::/32'));
    same(false, $via('2001:db8::1', '2001:db8::/128'), 'IPv6 /128 solo es esa');
    same(true, $via('2001:db8::1', '2001:db8::1/128'));
    same(true, $via('2001:0DB8:0:0:0:0:0:3', '2001:db8::/64'), 'otra forma de escribir la misma');
    same(false, $via('2606:4700::1', '2001:db8::/32'));
    // Una IPv4 no está en un rango IPv6 y al revés.
    same(false, $via('1.2.3.4', '2001:db8::/32'));
    same(false, $via('2001:db8::1', '10.0.0.0/8'));
    // IPv4 «mapeada» en IPv6.
    same('198.51.100.7', Request::clientIp('::ffff:10.0.0.5', ['x-forwarded-for' => '198.51.100.7'], ['10.0.0.0/8']));
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '::ffff:198.51.100.7'], ['10.0.0.0/8']));
});

test('IPv6: el proxy y el visitante pueden ser IPv6 y la dirección sale en su forma canónica', function (): void {
    same('2606:4700::1111', Request::clientIp('2001:db8::1', ['x-forwarded-for' => '2606:4700:0:0:0:0:0:1111'], ['2001:db8::/32']));
    same('2606:4700::1111', Request::clientIp('2001:db8::1', ['x-forwarded-for' => '2606:4700::1111, 2001:db8::2'], ['2001:db8::/32']));
    same('2001:db8::1', Request::clientIp('2001:db8::1', ['x-forwarded-for' => '2001:db8:1::5, 2001:db8::2'], ['2001:db8::/32']), 'todo de confianza');
    same('198.51.100.7', Request::clientIp('2001:db8::1', ['x-forwarded-for' => '198.51.100.7'], ['2001:db8::/32']), 'visitante IPv4 detrás de un proxy IPv6');
    same('2606:4700::1', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '2606:4700::1'], ['10.0.0.5']), 'visitante IPv6 detrás de un proxy IPv4');
    same('2606:4700::1', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '[2606:4700::1]:443'], ['10.0.0.5']), 'con corchetes y puerto');
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '198.51.100.7:51234'], ['10.0.0.5']), 'IPv4 con puerto');
});

test('CF-Connecting-IP solo se lee si el ajuste lo dice, y solo si viene de un proxy de confianza', function (): void {
    $h = ['cf-connecting-ip' => '198.51.100.7', 'x-forwarded-for' => '203.0.113.9'];
    same('203.0.113.9', Request::clientIp('10.0.0.5', $h, ['10.0.0.0/8']), 'de serie manda X-Forwarded-For');
    same('203.0.113.9', Request::clientIp('10.0.0.5', $h, ['10.0.0.0/8'], 'x-forwarded-for'));
    same('198.51.100.7', Request::clientIp('10.0.0.5', $h, ['10.0.0.0/8'], 'cf-connecting-ip'));
    same('198.51.100.7', Request::clientIp('10.0.0.5', $h, ['10.0.0.0/8'], 'CF-Connecting-IP'), 'el nombre de la cabecera no distingue mayúsculas');
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['x-forwarded-for' => '203.0.113.9'], ['10.0.0.0/8'], 'cf-connecting-ip'), 'con CF configurada, la otra cabecera no vale');
    same('198.51.100.99', Request::clientIp('198.51.100.99', $h, ['10.0.0.0/8'], 'cf-connecting-ip'), 'de quien no es de confianza, nada');
    same('2606:4700::1', Request::clientIp('10.0.0.5', ['cf-connecting-ip' => '2606:4700:0::1'], ['10.0.0.0/8'], 'cf-connecting-ip'));
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['cf-connecting-ip' => 'basura'], ['10.0.0.0/8'], 'cf-connecting-ip'));
    same('10.0.0.5', Request::clientIp('10.0.0.5', ['cf-connecting-ip' => '1.1.1.1, 2.2.2.2'], ['10.0.0.0/8'], 'cf-connecting-ip'), 'CF lleva una sola dirección');
    same('203.0.113.9', Request::clientIp('10.0.0.5', $h, ['10.0.0.0/8'], 'otra-cabecera'), 'un nombre desconocido vuelve a la de serie');
});

test('una cabecera con basura no se cree: se queda la dirección de la conexión', function (): void {
    foreach (['unknown', 'basura', '1.2.3.4, <script>alert(1)</script>', '1.2.3.4, ', "1.2.3.4,\0", '999.1.1.1', '1.2.3', 'fe80::1%eth0', str_repeat('1', 100), '1.2.3.4, 5.6.7.8, unknown'] as $value) {
        same('10.0.0.5', Request::clientIp('10.0.0.5', ['x-forwarded-for' => $value], ['10.0.0.0/8']), 'basura: ' . substr($value, 0, 30));
    }
    // Basura a la izquierda de una buena que sí vale: manda la buena de la derecha.
    same('198.51.100.7', Request::clientIp('10.0.0.5', ['x-forwarded-for' => 'basura, 198.51.100.7'], ['10.0.0.0/8']), 'la primera desde la derecha es buena y se usa sin mirar más');
});

test('la lista de proxies de confianza se escribe como cada persona quiera y solo entran direcciones y rangos válidos', function (): void {
    $list = Request::parseProxyList("10.0.0.0/8, 2001:db8::/32\n192.168.0.1;bad;10.0.0.0/33;::1/129  ;; 192.168.0.1\r\n172.16.0.0/12");
    same(['10.0.0.0/8', '2001:db8::/32', '192.168.0.1', '172.16.0.0/12'], $list);
    same([], Request::parseProxyList(''));
    same([], Request::parseProxyList('  , ; '));
    foreach (['10.0.0.1', '10.0.0.0/8', '0.0.0.0/0', '::1', '::/0', '2001:db8::/128', '::ffff:10.0.0.1'] as $ok) {
        same(true, Request::validRange($ok), $ok);
    }
    foreach (['', '10.0.0.0/', '/8', '10.0.0.0/33', '::/129', '10.0.0.0/-1', '10.0.0.0/8/8', 'localhost', '10.0.0', '10.0.0.0/x', '1.2.3.4 '] as $bad) {
        same(false, Request::validRange($bad), 'no vale: ' . $bad);
    }
});

test('de punta a punta: con proxies de confianza en los ajustes, la petición que llega a la aplicación lleva la dirección del visitante', function (): void {
    // Sin ningún módulo: la dirección la fija el núcleo (App::handle), no depende de que «tools» esté encendido.
    $app = testApp(['settings' => ['security.trusted_proxies' => "10.0.0.0/8\n2001:db8::/32"]]);
    $request = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '198.51.100.7, 10.0.0.6']);
    $request->ip = $request->remoteAddr = '10.0.0.5';
    $request->host = 'tienda.test';
    $request->secure = true;
    $app->handle($request);
    same('198.51.100.7', $request->ip, 'el visitante');
    same('10.0.0.5', $request->remoteAddr);

    $forged = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '198.51.100.7']);
    $forged->ip = $forged->remoteAddr = '203.0.113.50';
    $forged->host = 'tienda.test';
    $forged->secure = true;
    $app->handle($forged);
    same('203.0.113.50', $forged->ip, 'una cabecera de quien no es proxy de confianza se ignora');

    $app->settings()->set('security.proxy_header', 'cf-connecting-ip');
    $cf = Request::create('GET', '/admin/login', [], [], ['cf-connecting-ip' => '198.51.100.8', 'x-forwarded-for' => '198.51.100.9']);
    $cf->ip = $cf->remoteAddr = '10.0.0.5';
    $cf->host = 'tienda.test';
    $cf->secure = true;
    $app->handle($cf);
    same('198.51.100.8', $cf->ip, 'con CF-Connecting-IP en los ajustes');

    $plain = testApp(['modules' => ['tools']]);
    $direct = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '198.51.100.7']);
    $direct->ip = $direct->remoteAddr = '10.0.0.5';
    $direct->host = 'tienda.test';
    $direct->secure = true;
    $plain->handle($direct);
    same('10.0.0.5', $direct->ip, 'de serie no hay proxies de confianza y la cabecera no vale');
});

test('el diagnóstico enseña la IP que ve el sistema y avisa si llega una cabecera de proxy sin proxies de confianza', function (): void {
    $app = testApp(['modules' => ['tools']]);
    $request = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '198.51.100.7']);
    $request->ip = $request->remoteAddr = '10.0.0.5';
    $request->host = 'tienda.test';
    $request->secure = true;
    $app->handle($request);
    $result = ServerChecks::clientIp($app);
    same('warn', $result['status']);
    contains('10.0.0.5', $result['detail']);
    contains('puede que haya un proxy delante', $result['detail']);
    same('/admin/settings', $result['fix_url']);

    $plain = Request::create('GET', '/admin/login');
    $plain->ip = $plain->remoteAddr = '10.0.0.5';
    $plain->host = 'tienda.test';
    $plain->secure = true;
    $app->handle($plain);
    $result = ServerChecks::clientIp($app);
    same('ok', $result['status'], 'sin cabecera de proxy, nada que avisar');
    same('10.0.0.5', $result['detail']);

    // Lo habitual en un alojamiento (nginx delante de Apache con mod_remoteip): la cabecera llega, pero la conexión ya trae
    // la dirección pública del visitante. No hay nada que avisar (13/11/2026: el diagnóstico avisaba en vivo sin motivo).
    $restored = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '79.116.44.217']);
    $restored->ip = $restored->remoteAddr = '79.116.44.217';
    $restored->host = 'tienda.test';
    $restored->secure = true;
    $app->handle($restored);
    $result = ServerChecks::clientIp($app);
    same('ok', $result['status'], 'con la dirección pública ya puesta por el servidor web, la cabecera sobra');
    same('79.116.44.217', $result['detail']);

    $app->settings()->set('security.trusted_proxies', '10.0.0.0/8');
    $behind = Request::create('GET', '/admin/login', [], [], ['x-forwarded-for' => '198.51.100.7']);
    $behind->ip = $behind->remoteAddr = '10.0.0.5';
    $behind->host = 'tienda.test';
    $behind->secure = true;
    $app->handle($behind);
    $result = ServerChecks::clientIp($app);
    same('ok', $result['status'], 'con el proxy configurado, bien');
    contains('198.51.100.7', $result['detail']);
    contains('10.0.0.5', $result['detail']);

    $found = false;
    foreach ((new Aifec\Modules\Tools\Doctor($app))->checks() as $check) {
        if (is_array($check) && $check['id'] === 'server.client_ip') {
            $found = true;
            same('IP que ve el sistema', $app->i18n()->t('IP the system sees'));
        }
    }
    check($found, 'el diagnóstico trae la comprobación «IP que ve el sistema»');
});
