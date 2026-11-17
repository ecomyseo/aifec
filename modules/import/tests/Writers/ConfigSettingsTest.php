<?php
/**
 * AIFEC — escritor de la configuración de la tienda: identidad, moneda, idiomas, impuestos, numeración, correo, logotipo y lo que no tiene
 * ajuste propio. Lo que no se puede cambiar (modo del correo, moneda con pedidos, numeración hacia atrás) no se cambia.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\SettingsWriter;

require_once __DIR__ . '/WritersKit.php';

test('los ajustes se crean, se repiten sin cambios y se actualizan aunque se hayan editado aquí (es un tipo de configuración)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new SettingsWriter($app);

    $first = $writer->write(wr('settings'), $ctx);
    same(TypeWriter::CREATED, $first['result']);
    same('Muebles Ñandú & Cía', $app->settings()->str('store.name'), 'el «&» llega tal cual, sin escapar');
    same(1, wrCount($app, 'import_map', "type = 'settings'"));
    same(TypeWriter::UNCHANGED, $writer->write(wr('settings'), $ctx)['result']);

    $app->clock()->advance(120);
    $app->settings()->set('store.name', 'Editado aquí');
    $changed = $writer->write(wr('settings', ['shop_name' => 'Muebles Ñandú']), $ctx);
    same(TypeWriter::UPDATED, $changed['result'], 'sin conflicto: los ajustes se actualizan siempre');
    same('Muebles Ñandú', $app->settings()->str('store.name'));
    same(1, wrCount($app, 'import_map', "type = 'settings'"), 'sigue habiendo una sola pareja');
});

test('la identidad y la dirección van a store.* y el país a la base de impuestos', function (): void {
    $app = writersApp();
    (new SettingsWriter($app))->write(wr('settings'), writersContext($app));
    $settings = $app->settings();

    same('Ñandú Muebles S.L.', $settings->str('store.legal_name'));
    same('B00000000', $settings->str('store.vat_id'));
    same('hola@tienda.test', $settings->str('store.email'));
    same('999500007', $settings->str('store.phone'));
    same("Polígono Sur, nave 4\n41001 Sevilla\nES", $settings->str('store.address'));
    same('ES', $settings->str('tax.base_country'));
    same('41001', $settings->str('tax.base_postcode'));
});

test('la moneda cambia en un sitio sin pedidos y no cambia si ya hay pedidos en otra', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new SettingsWriter($app);
    $writer->write(wr('settings', ['currency' => 'USD']), $ctx);
    same('USD', $app->settings()->str('shop.currency'));
    same('$', $app->settings()->str('shop.currency_symbol'));

    $other = writersApp();
    $now = $other->clock()->sql();
    $other->db()->insert('orders', ['number' => 'A-1', 'email' => 'ana@cliente.test', 'currency' => 'EUR', 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $keep = writersContext($other);
    (new SettingsWriter($other))->write(wr('settings', ['currency' => 'USD']), $keep);
    same('EUR', $other->settings()->str('shop.currency', 'EUR'), 'con pedidos en euros, la moneda no cambia');
    check(count(array_filter($keep->noteTexts('settings'), static function (string $text): bool {
        return strpos($text, 'currency was not changed') !== false;
    })) === 1, 'y se apunta');
});

test('solo se añaden idiomas que AIFEC sabe traducir; el que no, se cuenta aparte', function (): void {
    $app = writersApp(['i18n.languages' => ['es']]);
    $ctx = writersContext($app);
    (new SettingsWriter($app))->write(wr('settings', ['languages' => ['es', 'en', 'xx'], 'default_language' => 'es']), $ctx);

    same(['es', 'en'], $app->settings()->arr('i18n.languages'));
    same(1, $ctx->unmappedCount('language', 'xx'));
    same(0, $ctx->unmappedCount('language', 'en'));
});

test('el idioma principal solo cambia en un sitio sin contenido', function (): void {
    $empty = writersApp(['i18n.languages' => ['es']]);
    (new SettingsWriter($empty))->write(wr('settings', ['languages' => ['en', 'es'], 'default_language' => 'en']), writersContext($empty));
    same(['en', 'es'], $empty->settings()->arr('i18n.languages'), 'sin contenido, el del origen pasa a ser el principal');

    $busy = writersApp(['i18n.languages' => ['es']]);
    $now = $busy->clock()->sql();
    $busy->db()->insert('products', ['kind' => 'physical', 'price' => 100, 'created_at' => $now, 'updated_at' => $now]);
    (new SettingsWriter($busy))->write(wr('settings', ['languages' => ['en', 'es'], 'default_language' => 'en']), writersContext($busy));
    same(['es', 'en'], $busy->settings()->arr('i18n.languages'), 'con contenido, se añade el idioma pero el principal es el de siempre');
});

test('los precios se escriben como en el origen en un sitio sin productos propios; con productos propios no se cambia', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $record = wr('settings', ['prices_include_tax' => false, 'display_prices_with_tax' => false]);
    (new SettingsWriter($app))->write($record, $ctx);
    same(false, $app->settings()->bool('tax.prices_include_tax', true));
    same(false, $app->settings()->bool('tax.display_with_tax', true));
    same(false, (bool) $ctx->option('prices_include_tax_here'), 'y los demás escritores lo ven así');

    $busy = writersApp();
    $now = $busy->clock()->sql();
    $busy->db()->insert('products', ['kind' => 'physical', 'price' => 100, 'created_at' => $now, 'updated_at' => $now]);
    $note = writersContext($busy);
    (new SettingsWriter($busy))->write($record, $note);
    same(true, $busy->settings()->bool('tax.prices_include_tax', true), 'los productos de aquí conservan su sentido');
    same(false, $busy->settings()->bool('tax.display_with_tax', true), 'pero cómo se enseñan sí cambia');
    check($note->noteTexts('settings') !== [], 'se apunta');
});

test('la numeración de pedidos y de facturas se adelanta y nunca retrocede', function (): void {
    $app = writersApp();
    (new SettingsWriter($app))->write(wr('settings', ['order_number_next' => 1043, 'invoice_number_next' => 318]), writersContext($app));
    same(1042, (int) wrValue($app, "SELECT value FROM {sequences} WHERE name = 'order'"), 'el siguiente de aquí es el 1043, mayor que el último del origen (1042)');
    same(317, (int) wrValue($app, "SELECT value FROM {sequences} WHERE name = 'invoice:F'"));

    $ahead = writersApp();
    $ahead->db()->insert('sequences', ['name' => 'order', 'value' => 5000]);
    (new SettingsWriter($ahead))->write(wr('settings', ['order_number_next' => 1043]), writersContext($ahead));
    same(5000, (int) wrValue($ahead, "SELECT value FROM {sequences} WHERE name = 'order'"), 'una secuencia más adelantada no se retrasa');
});

test('el correo se copia con la contraseña cifrada y sin tocar el modo', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    (new SettingsWriter($app))->write(wr('settings'), $ctx);
    $settings = $app->settings();

    same('smtp', $settings->str('mail.transport'));
    same('smtp.tienda.test', $settings->str('mail.smtp_host'));
    same(587, $settings->int('mail.smtp_port'));
    same('tls', $settings->str('mail.smtp_encryption'));
    same('envios@tienda.test', $settings->str('mail.smtp_user'));
    same('hola@tienda.test', $settings->str('mail.from_email'));
    same('clave-de-mentira', $app->secret('mail.smtp_password'), 'se descifra con la clave del sitio');
    same(false, $settings->has('mail.mode'), 'el modo no se toca: sigue retenido');
    foreach ($app->db()->select('SELECT k, v FROM {settings}') as $row) {
        notContains('clave-de-mentira', (string) $row['v'], 'la contraseña no está en claro en «' . $row['k'] . '»');
    }
    foreach ($app->db()->select('SELECT reason FROM {import_log}') as $row) {
        notContains('clave-de-mentira', (string) $row['reason']);
    }
});

test('el logotipo se trae por image() y el icono ausente no cambia nada', function (): void {
    $app = writersApp();
    $dir = tmpDir();
    wrPng($dir, 'img/logo.png', 3);
    $ctx = writersContext($app, [], [], $dir);
    (new SettingsWriter($app))->write(wr('settings', ['logo' => ['path' => 'img/logo.png'], 'icon' => null]), $ctx);

    $id = $app->settings()->int('store.logo');
    check($id > 0, 'el logotipo tiene su identificador de medios');
    same(1, wrCount($app, 'media', 'id = ?', [$id]));
    same(0, $app->settings()->int('store.icon'));
});

test('lo que no tiene ajuste propio se guarda sin secretos, las páginas legales quedan apuntadas y la portada va a posicionamiento', function (): void {
    $app = writersApp();
    (new SettingsWriter($app))->write(wr('settings'), writersContext($app));
    $settings = $app->settings();

    $source = $settings->arr('import.source_settings');
    same('Europe/Madrid', $source['timezone']);
    same(['catalog_mode' => false], $source['extra']);
    notContains('clave-de-mentira', (string) json_encode($source));
    same(['terms' => '3', 'privacy' => '7'], $settings->arr('import.legal_pages'));
    same('Muebles de madera maciza', $settings->str('seo.title.home'));
    same('Mesas, sillas y estanterías hechas en Sevilla.', $settings->str('seo.description.home'));
});

test('el registro de la migración encuentra por su nombre los trece escritores y los tipos quedan disponibles', function (): void {
    $modules = ['import', 'tax', 'shipping', 'catalog', 'media', 'customers', 'orders', 'payments-transfer', 'payments-cod', 'payments-redsys', 'payments-paypal'];
    $app = testApp(['modules' => $modules, 'settings' => ['modules.active' => $modules]]);
    $app->boot();
    $registry = importService($app)->registry();
    $types = ['settings' => 'Settings', 'tax_classes' => 'TaxClasses', 'tax_rates' => 'TaxRates', 'shipping_zones' => 'ShippingZones', 'carriers' => 'Carriers',
        'payment_methods' => 'PaymentMethods', 'customer_groups' => 'CustomerGroups', 'brands' => 'Brands', 'categories' => 'Categories', 'attributes' => 'Attributes',
        'products' => 'Products', 'media' => 'Media', 'redirects' => 'Redirects'];

    same(13, count($types));
    foreach ($types as $type => $name) {
        same('Aifec\\Modules\\Import\\Writers\\' . $name . 'Writer', $registry->writerClass($type), 'el escritor de ' . $type);
        same(null, $registry->unavailable($type), $type . ' se puede escribir');
        $writer = $registry->writer($type);
        check($writer instanceof TypeWriter && $writer->type() === $type, 'el escritor de ' . $type . ' dice que es suyo');
    }
});

test('simulacro de los ajustes: se decide y no cambia nada', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $out = (new SettingsWriter($app))->write(wr('settings'), $dry);

    same(TypeWriter::CREATED, $out['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
    same(false, $app->settings()->has('store.legal_name'));
});
