<?php
/**
 * AIFEC — ajustes solo en memoria: Settings::override() cambia lo que lee esta petición sin escribir en la base de
 * datos, y Settings::restore() lo deja como estaba. Lo usan las vistas previas y los sitios de prueba del validador.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Settings;

test('override cambia lo que se lee y no escribe nada en la base de datos', function (): void {
    $app = testApp();
    $app->boot();
    $settings = $app->settings();
    $settings->set('store.name', 'Muebles Ñandú & Cía');
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {settings}');

    $saved = $settings->override(['store.name' => 'Vista previa', 'invento.nuevo' => ['a' => 1]]);
    same('Vista previa', $settings->str('store.name'));
    same(['a' => 1], $settings->get('invento.nuevo'));
    check($settings->has('invento.nuevo'), 'mientras dura, la clave nueva existe');
    same('Vista previa', $settings->all('store.')['store.name'], 'all() también lo ve');

    // Otra lectura desde la base de datos (otra petición) no ve nada de eso.
    $other = new Settings($app->db());
    same('Muebles Ñandú & Cía', $other->str('store.name'), 'en la base de datos sigue el valor guardado');
    same(null, $other->get('invento.nuevo'));
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {settings}'), 'no se ha escrito ninguna fila');

    $settings->restore($saved);
    same('Muebles Ñandú & Cía', $settings->str('store.name'), 'restore devuelve el valor de antes');
    check(!$settings->has('invento.nuevo'), 'y la clave que no existía deja de existir');
    same(null, $settings->get('invento.nuevo'));
});

test('override sobre una clave que solo tenía valor por defecto vuelve al valor por defecto', function (): void {
    $app = testApp();
    $app->boot();
    $settings = $app->settings();
    $settings->defaults(['prueba.tope' => 24]);
    same(24, $settings->int('prueba.tope'));

    $saved = $settings->override(['prueba.tope' => 3]);
    same(3, $settings->int('prueba.tope'));
    $settings->restore($saved);
    same(24, $settings->int('prueba.tope'), 'vuelve a mandar el valor por defecto');
    check(!$settings->has('prueba.tope'), 'no queda como si alguien lo hubiera guardado');
});

test('un valor guardado que es null se restaura como null, y tras reload no hay nada que deshacer', function (): void {
    $app = testApp();
    $app->boot();
    $settings = $app->settings();
    $settings->set('prueba.vacio', null);
    $saved = $settings->override(['prueba.vacio' => 'algo']);
    same('algo', $settings->get('prueba.vacio'));
    $settings->restore($saved);
    check($settings->has('prueba.vacio'), 'la clave sigue existiendo');
    same(null, $settings->get('prueba.vacio', 'defecto'), 'con su null guardado');

    $saved = $settings->override(['store.name' => 'Temporal']);
    $settings->reload();
    $settings->restore($saved);
    notContains('Temporal', (string) $settings->str('store.name'), 'tras reload valen los guardados');
});
