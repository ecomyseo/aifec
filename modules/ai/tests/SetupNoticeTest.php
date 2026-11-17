<?php
/**
 * AIFEC — con la IA apagada, el inicio del panel dice dónde se enciende y dónde va la clave de la API (13/11/2026: «¿dónde demonios
 * configuro la API de la IA?»), y la pestaña de los ajustes se llama por lo que lleva.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('con la IA apagada, el inicio avisa con el botón «Configurar la IA» que lleva a la pestaña de la clave', function (): void {
    $app = aiApp(['ai.enabled' => false]);
    $web = adminClient($app);
    $home = (string) $web->get('/admin')->body;
    contains('La IA está apagada. Enciéndela y pon la clave de la API de tu proveedor en los ajustes de la IA.', $home);
    contains('href="/admin/settings/ai#tab=aimodels"', $home, 'el botón lleva a la pestaña de la clave');
    contains('Configurar la IA', $home);
    $settings = (string) $web->get('/admin/settings/ai')->body;
    contains('Proveedor y clave de la API', $settings, 'la pestaña dice lo que lleva');
    $editor = adminClient(aiApp(['ai.enabled' => false]), 'editor');
    notContains('Configurar la IA', (string) $editor->get('/admin')->body, 'quien no puede cambiarlo no lo ve');
});

test('con la IA encendida no sale el aviso de «apagada»', function (): void {
    $app = aiApp(['ai.enabled' => true]);
    notContains('La IA está apagada', (string) adminClient($app)->get('/admin')->body);
});
