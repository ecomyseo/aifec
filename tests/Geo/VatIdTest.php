<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * El NIF-IVA con formato VIES (solo el formato y el control español: que exista lo dice VIES), su regla en los ajustes y los datos
 * mercantiles del titular en las páginas legales y en Organization. Solo números de ejemplo.
 */

declare(strict_types=1);

use Aifec\Geo\VatId;

test('NIF-IVA: formato VIES por país, con control para los españoles y sin prefijo para el país de la tienda', function (): void {
    foreach (['ES12345678Z', 'es 12345678-z', '12345678Z', 'B12345674', 'ESB12345674', 'X1234567L', 'DE123456789', 'FR12345678901', 'FRAB123456789', 'NL123456789B01',
        'ATU12345678', 'IT12345678901', 'PT123456789', 'BE0123456789', 'EL123456789', 'GR123456789', 'IE1234567T', 'SE123456789001', 'XI123456789', 'PL1234567890'] as $ok) {
        check(VatId::valid($ok), $ok . ' es válido');
    }
    foreach (['', 'ES12345678A', '12345678A', 'B12345675', 'DE12345678', 'DE1234567890', 'FR1234567890', 'NL123456789A01', 'ATX12345678', 'IT1234567890', 'ES', 'hola mundo', '123'] as $bad) {
        check(!VatId::valid($bad), '«' . $bad . '» no es válido');
    }
    // Fuera de la UE no hay formato VIES que comprobar: vale algo con forma de identificador.
    check(VatId::valid('GB123456789'), 'Reino Unido, sin formato VIES');
    check(VatId::valid('CHE123456789'), 'Suiza');
    check(!VatId::valid('GB1'), 'pero no cualquier cosa');
    // Sin prefijo se entiende del país de la tienda.
    check(VatId::valid('123456789', 'DE'), 'alemán sin prefijo');
    check(!VatId::valid('123456789', 'ES'), 'el mismo número no es un NIF español');
});

test('NIF-IVA: la forma que lee VIES', function (): void {
    same('ESB12345674', VatId::vies('b-12345674'));
    same('ES12345678Z', VatId::vies('12.345.678-z'));
    same('DE123456789', VatId::vies('de 123 456 789'));
    same('EL123456789', VatId::vies('GR123456789'), 'Grecia se escribe EL en VIES');
    same(null, VatId::vies('B12345675'), 'con el control mal, nada');
    same(null, VatId::vies(''));
    same(null, VatId::vies('GB123456789'), 'fuera de la UE no hay forma VIES');
    same(['country' => 'ES', 'number' => 'B12345674'], VatId::split('ESB12345674'));
    same(['country' => 'ES', 'number' => 'B12345674'], VatId::split('B12345674'));
});

test('Ajustes → General: el NIF-IVA y el teléfono se validan; los datos mercantiles salen en el aviso legal y el NIF-IVA, en Organization', function (): void {
    $app = testApp(['modules' => ['cms', 'semantic', 'seo']]);
    $app->boot();
    $web = adminClient($app);
    $page = $web->get('/admin/settings/core');
    same(200, $page->status);
    $token = aifecToken($web);
    $post = static function (array $values) use ($web, $token) {
        return $web->post('/admin/settings/core', ['_token' => $token] + $values);
    };

    // Un NIF-IVA mal escrito no se guarda y dice por qué.
    $r = $post(['f' => ['store.vat_id' => 'B12345675', 'store.phone' => '+44 7700 900123']]);
    contains('Escribe un NIF-IVA válido', $r->body, 'el aviso en castellano');
    notContains('B12345675', (string) $app->settings()->str('store.vat_id', ''), 'y no se guarda');
    $r = $post(['f' => ['store.vat_id' => 'B12345674', 'store.phone' => 'no es un teléfono']]);
    contains('Escribe un teléfono válido', $r->body, 'el teléfono también se comprueba');

    // Con datos buenos se guardan, salen en el aviso legal y en Organization.
    $post(['f' => [
        'shop.currency' => 'EUR', 'shop.currency_symbol' => '€', 'store.name' => 'Tienda de Ejemplo', 'store.legal_name' => 'Ana Ruiz Ejemplo', 'store.vat_id' => 'b-12345674', 'store.phone' => '+44 7700 900123',
        'store.adr_name' => 'Junta Arbitral de Ejemplo', 'store.adr_url' => 'https://arbitraje.example/ejemplo', 'store.complaints_url' => 'https://tienda.test/reclamaciones',
    ]]);
    same('b-12345674', $app->settings()->str('store.vat_id', ''), 'el NIF-IVA se guarda como lo escribió el titular (VIES lo normaliza al publicarlo)');
    $legal = $app->service('cms')->renderFresh([['type' => 'company', 'attrs' => []]]);
    contains('Junta Arbitral de Ejemplo', $legal);
    contains('href="https://arbitraje.example/ejemplo"', $legal);
    contains('href="https://tienda.test/reclamaciones"', $legal);
    contains('Resolución extrajudicial de litigios', $legal);
    contains('Hoja de reclamaciones', $legal);
    contains('+44 7700 900123', $legal);
    $home = (new TestClient($app))->get('/');
    contains('"vatID":"ESB12345674"', $home->body, 'Organization lleva el NIF-IVA con el formato de VIES');
    contains('"telephone":"+44 7700 900123"', $home->body);
});

test('sin datos de resolución de litigios ni hoja de reclamaciones no se enseña nada, y nunca el enlace a la plataforma ODR cerrada', function (): void {
    $app = testApp(['modules' => ['cms'], 'settings' => ['store.legal_name' => 'Ana Ruiz Ejemplo', 'store.vat_id' => 'B12345674']]);
    $app->boot();
    $legal = $app->service('cms')->renderFresh([['type' => 'company', 'attrs' => []]]);
    notContains('Resolución extrajudicial', $legal);
    notContains('reclamaciones', $legal);
    notContains('ec.europa.eu/consumers/odr', $legal, 'la plataforma ODR cerró en julio de 2025');
});
