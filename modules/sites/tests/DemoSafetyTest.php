<?php
/**
 * AIFEC — pruebas de lo que hace seguro a un sitio de demostración: correo retenido, solo el pago simulado, noindex y una
 * persona del panel con un papel que no puede cambiar personas, ajustes de correo, plugins ni sitios.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Media\MediaException;
use Aifec\Modules\Sites\DemoGuard;

require_once __DIR__ . '/helpers.php';

test('una demostración es demo, lleva noindex en su portada y tiene el correo retenido sin que salga ni un aviso del sistema', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    same(true, $demo->site()->isDemo());
    contains('<meta name="robots" content="noindex', aifecSitesFront($w, '/demos/minimo/')->body);
    contains('noindex', (string) aifecSitesFront($w, '/demos/minimo/categoria/sillas')->body);
    same('hold', $demo->settings()->str('mail.mode'));
    same('log', $demo->settings()->str('mail.transport'));
    same(false, $demo->settings()->bool('mail.system_always', true), 'ni siquiera salen las plantillas del sistema');
    same(true, $demo->settings()->bool('seo.noindex_site'));

    $id = $demo->service('mailer')->queue('mail_test', 'ana@cliente.test');
    same('held', (string) $demo->db()->value('SELECT status FROM {mail_messages} WHERE id = ?', [$id]), 'el aviso del sistema queda retenido');
    same(['sent' => 0, 'failed' => 0, 'held' => 0], $demo->service('mailer')->sendPending(10) + ['held' => 0]);
    same(0, (int) $demo->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE status = 'sent'"));
    aifecSitesRelease($demo);
});

test('en la demostración solo está disponible la forma de pago simulada', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $payments = $demo->service('payments');
    $context = ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es'];
    same(['simulated'], array_map(static function ($method): string {
        return $method->code();
    }, $payments->available($context)));
    same(null, $payments->get('cod'), 'no hay contra reembolso');
    same(null, $payments->get('transfer'), 'ni transferencia');
    $active = $demo->settings()->arr('modules.active');
    foreach ($active as $module) {
        check(!in_array($module, ['payments-cod', 'payments-transfer', 'plugins', 'sites'], true), 'el módulo ' . $module . ' no está encendido');
    }
    aifecSitesRelease($demo);
});

test('la persona demo entra a su panel y no puede abrir personas, ajustes de correo, ajustes generales, plugins ni sitios', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $web = new TestClient($demo);
    same(422, aifecLogin($web, 'demo@tienda.test', 'una-clave-que-no-es')->status, 'una contraseña mala no entra');
    same(302, aifecLogin($web, 'demo@tienda.test', DemoGuard::DEFAULT_PASSWORD)->status, 'la contraseña de demostración entra');
    same(200, $web->get('/admin')->status, 'el panel abre');
    same(200, $web->get('/admin/r/products')->status, 'y puede ver los productos');
    same(403, $web->get('/admin/r/users')->status, 'personas: 403');
    same(403, $web->get('/admin/r/roles')->status, 'papeles: 403');
    same(403, $web->get('/admin/settings/mail')->status, 'ajustes de correo: 403');
    same(403, $web->get('/admin/settings/core')->status, 'ajustes generales: 403');
    $web->get('/admin');
    same(403, $web->post('/admin/settings/mail', ['_token' => aifecToken($web), 'f' => ['mail.mode' => 'live']])->status, 'y no puede encender el correo');
    same('hold', $demo->settings()->str('mail.mode'), 'el correo sigue retenido');
    foreach (['/admin/plugins', '/admin/sites'] as $path) {
        $status = $web->get($path)->status;
        check($status === 403 || $status === 404, $path . ' no abre (en una demostración ni existe el módulo): ' . $status);
    }
    foreach (['team.edit', 'team.view', 'settings.edit', 'mail.edit', 'mail.view', 'plugins.view', 'plugins.edit', 'sites.manage'] as $cap) {
        same(false, $demo->auth()->can($cap), 'la persona demo no tiene ' . $cap);
    }
    same(true, $demo->auth()->can('catalog.edit'), 'y sí puede editar el catálogo');
    aifecSitesRelease($web);
    aifecSitesRelease($demo);
});

test('un papel demo antiguo, con el comodín de apariencia, se queda sin el permiso de crear temas al aplicar la guarda', function (): void {
    // Los sitios creados antes del 13/11/2026 traen «appearance.*» en el papel demo: la guarda lo cambia por permisos concretos.
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $demo->db()->update('roles', ['caps' => json_encode(['dashboard.*', 'appearance.*', 'appearance.create', 'catalog.*', 'plugins.view'])], ['code' => 'demo']);
    DemoGuard::apply($demo, 'una-clave-larga-de-prueba-1');
    $caps = json_decode((string) $demo->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']), true);
    check(is_array($caps), 'el papel se lee');
    check(!in_array('appearance.*', $caps, true), 'el comodín de apariencia ya no está');
    same(false, DemoGuard::allows($caps, 'appearance.create'), 'crear temas, no');
    same(true, DemoGuard::allows($caps, 'appearance.edit'), 'personalizar, sí');
    same(true, DemoGuard::allows($caps, 'appearance.view'));
    same(true, DemoGuard::allows($caps, 'catalog.edit'), 'lo demás se conserva');
    same(false, DemoGuard::allows($caps, 'plugins.view'));
    same(count($caps), count(array_unique($caps)), 'sin permisos repetidos');
    aifecSitesRelease($demo);
});

test('el papel demo ve y toca el sistema de IA (asistente, IA, búsqueda por significado), y en una demo el gasto de IA tiene un tope fijo', function (): void {
    // 13/11/2026, el usuario con la cuenta «Demo»: «sigo sin ver la administración de todo el sistema de IA».
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $original = (string) $demo->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']);
    $demo->db()->update('roles', ['caps' => json_encode(['dashboard.*', 'catalog.*'])], ['code' => 'demo']);
    DemoGuard::apply($demo, 'una-clave-larga-de-prueba-1');
    $caps = json_decode((string) $demo->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']), true);
    foreach (['assistant.view', 'assistant.edit', 'ai.view', 'ai.edit', 'ai.use', 'semantic.view', 'semantic.edit', 'copilot.use'] as $cap) {
        same(true, DemoGuard::allows($caps, $cap), $cap);
    }
    same(false, DemoGuard::allows($caps, 'settings.edit'), 'los ajustes generales siguen cerrados');
    same(false, DemoGuard::allows($caps, 'team.edit'));
    $demo->settings()->set('ai.daily_limit_calls', 100000);
    $demo->settings()->set('ai.daily_limit_cents', 999999);
    same(\Aifec\Modules\Ai\Usage::DEMO_CALLS, \Aifec\Modules\Ai\Usage::limitCalls($demo), 'en una demo, subir el tope de llamadas no sirve');
    same(\Aifec\Modules\Ai\Usage::DEMO_CENTS * 1000000, \Aifec\Modules\Ai\Usage::limitMicro($demo), 'ni el de dinero');
    $demo->settings()->set('ai.daily_limit_calls', 50);
    same(50, \Aifec\Modules\Ai\Usage::limitCalls($demo), 'bajarlo sí');
    $w['master']->settings()->set('ai.daily_limit_calls', 100000);
    same(100000, \Aifec\Modules\Ai\Usage::limitCalls($w['master']), 'el maestro no tiene el tope de demo');
    // El mundo de pruebas se comparte entre pruebas: se deja como estaba.
    $demo->db()->update('roles', ['caps' => $original], ['code' => 'demo']);
    $demo->settings()->set('ai.daily_limit_calls', 500);
    $demo->settings()->set('ai.daily_limit_cents', 200);
    $w['master']->settings()->set('ai.daily_limit_calls', 500);
    // Una demostración ya hecha se pone al día al sincronizar las cuentas (Abrir panel, sites:owners), sin rehacerla.
    $demo->db()->update('roles', ['caps' => json_encode(['dashboard.*'])], ['code' => 'demo']);
    aifecSitesRelease($demo);
    $sync = (new \Aifec\Modules\Sites\Owners($w['master']))->syncSite('demo-minimo');
    check($sync['ok'], 'se sincroniza: ' . $sync['error']);
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $after = json_decode((string) $demo->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']), true);
    same(true, DemoGuard::allows($after, 'ai.edit'), 'la demo ya hecha gana los permisos de IA');
    $demo->db()->update('roles', ['caps' => $original], ['code' => 'demo']);
    aifecSitesRelease($demo);
});

test('el papel demo pierde lo que sobra solo en ESA demostración y el del maestro no se toca', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $caps = json_decode((string) $demo->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']), true);
    $masterCaps = json_decode((string) $w['master']->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']), true);
    check(is_array($caps) && is_array($masterCaps), 'los dos papeles se leen');
    check(!in_array('plugins.view', $caps, true), 'a la demostración le han quitado plugins.view');
    check(in_array('plugins.view', $masterCaps, true), 'el papel del maestro sigue como venía de serie');
    foreach (DemoGuard::MUST_DENY as $cap) {
        same(false, DemoGuard::allows($caps, $cap), 'la demostración no concede ' . $cap);
    }
    // En una demostración pública se personaliza el tema, pero no se crean temas (eso escribe ficheros en el servidor).
    check(in_array('appearance.create', DemoGuard::MUST_DENY, true), 'crear temas está entre lo que una demostración nunca concede');
    same(true, DemoGuard::allows($caps, 'appearance.edit'), 'personalizar sí');
    same(false, DemoGuard::allows($caps, 'appearance.create'), 'crear temas no');
    check(!in_array('appearance.*', $caps, true), 'el comodín de apariencia se ha cambiado por permisos concretos');
    same(true, DemoGuard::allows($masterCaps, 'plugins.view'));
    same(true, DemoGuard::allows(['catalog.*'], 'catalog.edit'));
    same(false, DemoGuard::allows(['catalog.view'], 'catalog.edit'));
    same(true, DemoGuard::allows(['*'], 'plugins.edit'));
    aifecSitesRelease($demo);
});

test('en la demostración no se sube nada que no sea una imagen, ni con el nombre de una', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $dir = tmpDir('aifec-sites-upload');
    $cases = [
        'texto.png' => 'esto no es una imagen',
        'script.php' => '<?php echo 1;',
        'pagina.svg' => '<html><body>no soy un svg</body></html>',
        'zip.jpg' => "PK\x03\x04 con cabecera de zip",
    ];
    foreach ($cases as $name => $content) {
        file_put_contents($dir . '/' . $name, $content);
        $failed = false;
        try {
            $demo->service('media')->store($dir . '/' . $name, $name);
        } catch (MediaException $e) {
            $failed = true;
        }
        check($failed, $name . ' se rechaza por su contenido');
    }
    same(6, (int) $demo->db()->value('SELECT COUNT(*) FROM {media}'), 'la biblioteca sigue con sus seis imágenes');
    aifecSitesRelease($demo);
});

test('la persona de demostración usa el dominio de la tienda y una contraseña distinta si se pide', function (): void {
    same('demo@muestras.tienda-grande.test', DemoGuard::email('https://muestras.tienda-grande.test/demos/moda'), 'un subdominio se conserva entero');
    same('demo@tienda.test', DemoGuard::email('https://www.tienda.test/demos/x'));
    same('demo@tienda.test', DemoGuard::email('http://localhost:8080/demos/x'), 'sin dominio de verdad, el reservado');
    same('demo@tienda.test', DemoGuard::email('http://127.0.0.1/demos/x'));

    $w = aifecSitesFresh();
    $r = $w['demos']->create(['package' => 'minimo', 'slug' => 'otra', 'password' => 'otra-clave-1234', 'name' => 'Tienda & más "otra"']);
    same(true, $r['ok'], (string) ($r['error'] ?? ''));
    same('otra-clave-1234', $r['password']);
    $demo = aifecSitesDemoApp($w, 'demo-otra');
    $web = new TestClient($demo);
    same(422, aifecLogin($web, 'demo@tienda.test', DemoGuard::DEFAULT_PASSWORD)->status, 'la de por defecto ya no vale');
    same(302, aifecLogin($web, 'demo@tienda.test', 'otra-clave-1234')->status);
    same('Tienda & más "otra"', $demo->settings()->str('store.name'), 'el nombre con «&» y comillas se guarda tal cual');
    contains('Tienda &amp; más', aifecSitesFront($w, '/demos/otra/')->body, 'y sale escapado');
    aifecSitesRelease($web);
    aifecSitesRelease($demo);
});
