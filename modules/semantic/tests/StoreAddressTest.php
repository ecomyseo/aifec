<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Front\StoreAddress;

require_once __DIR__ . '/helpers.php';

/*
 * Auditoría externa del 24/11/2026: el JSON-LD de la organización, llms.txt e index.md llevaban todo el domicilio dentro de streetAddress
 * («Conde de urgel 46800 Xativa ES»). La dirección se escribe ahora en campos (calle, código postal, localidad, provincia y país) y de ahí
 * salen los tres; con solo el texto libre de siempre, todo sigue igual. Direcciones inventadas.
 */

/** El Organization que sale en la portada. */
function addressOrganization(\Aifec\Kernel\App $app): array
{
    $org = semanticFind(semanticLd((new TestClient($app))->get('/')->body), 'Organization');
    check($org !== null, 'hay una Organization');

    return is_array($org) ? $org : [];
}

test('con los campos de la dirección, Organization lleva cada dato en el suyo y llms.txt e index.md salen de los mismos campos', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms'], [
        'store.address_street' => 'Calle de Ejemplo 1', 'store.address_postcode' => '28013', 'store.address_city' => 'Madrid',
        'store.address_region' => 'Madrid', 'store.address_country' => 'es',
    ]);
    $address = addressOrganization($app)['address'];
    same('PostalAddress', $address['@type']);
    same('Calle de Ejemplo 1', $address['streetAddress']);
    same('Madrid', $address['addressLocality']);
    same('28013', $address['postalCode']);
    same('Madrid', $address['addressRegion']);
    same('ES', $address['addressCountry'], 'el país en mayúsculas');
    $web = new TestClient($app);
    $llms = $web->get('/llms.txt')->body;
    contains('Calle de Ejemplo 1, 28013 Madrid, Madrid', $llms, 'llms.txt: la dirección en una línea y con comas');
    $index = $web->get('/index.md')->body;
    contains('Calle de Ejemplo 1, 28013 Madrid, Madrid', $index, 'index.md: la misma');
    notContains('Calle de Ejemplo 1 28013', $llms, 'no la mezcla en una sola frase sin comas');
    // El texto libre que leen facturas, correos y pies se escribe a partir de los campos al guardar los ajustes.
    $app->hooks()->fire('settings.saved', 'core', ['store.address_street'], $app);
    same("Calle de Ejemplo 1\n28013 Madrid\nMadrid", $app->settings()->str('store.address'));
});

test('con solo el texto libre de siempre, nada cambia: sigue entero en streetAddress y no se inventa ningún reparto', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms'], ['store.address' => 'Calle de Ejemplo 1 28013 Madrid ES']);
    $address = addressOrganization($app)['address'];
    same('Calle de Ejemplo 1 28013 Madrid ES', $address['streetAddress']);
    check(!isset($address['addressLocality']) && !isset($address['postalCode']), 'sin localidad ni código postal inventados');
    same('ES', $address['addressCountry']);
    same(false, StoreAddress::structured($app));
    same('Calle de Ejemplo 1 28013 Madrid ES', StoreAddress::oneLine($app));
    // Guardar los ajustes sin campos no toca el texto libre.
    $app->hooks()->fire('settings.saved', 'core', ['store.address_street'], $app);
    same('Calle de Ejemplo 1 28013 Madrid ES', $app->settings()->str('store.address'));
    // Y sin ninguna dirección, Organization no lleva domicilio.
    $none = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    check(!isset(addressOrganization($none)['address']), 'sin dirección, sin domicilio');
});

test('el país de la dirección sin escribir es el de la tienda para los impuestos, y uno mal escrito no pasa', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms'], ['store.address_street' => 'Calle de Ejemplo 1', 'tax.base_country' => 'pt']);
    same('PT', StoreAddress::country($app));
    $bad = semanticApp(['semantic', 'catalog', 'media', 'cms'], ['store.address_street' => 'Calle de Ejemplo 1', 'store.address_country' => 'España']);
    same('ES', StoreAddress::country($bad), 'un país que no son dos letras se ignora');
    $fields = [];
    foreach ((array) (require dirname(__DIR__, 3) . '/core/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    foreach (array_values(StoreAddress::FIELDS) as $key) {
        check(isset($fields[$key]), $key . ' está en los ajustes');
    }
});
