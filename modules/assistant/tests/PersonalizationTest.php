<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\AiBrain;
use Aifec\Modules\Assistant\Tools;

require_once __DIR__ . '/helpers.php';

test('el nombre personalizado aparece escapado y mantiene la identificación como IA', function (): void {
    $app = assistantApp(['assistant.name' => 'Lúa & <ayuda>', 'assistant.greeting' => 'Bienvenido a nuestra tienda.']);
    $web = new TestClient($app);
    $page = $web->get('/');
    contains('Lúa &amp; &lt;ayuda&gt;', $page->body);
    contains('Soy Lúa &amp; &lt;ayuda&gt;, el asistente de IA de Muebles Ejemplo.', $page->body);
    contains('Bienvenido a nuestra tienda.', $page->body);
    notContains('data-t-bot="Lúa & <ayuda>"', $page->body);
    $app->settings()->set('assistant.name', '');
    contains('Soy el asistente de IA de Muebles Ejemplo.', $app->service('assistant')->intro());
});

test('las instrucciones del comercio llegan al modelo sin sustituir las guardas de compra y privacidad', function (): void {
    $app = assistantApp(['assistant.instructions' => 'Aconseja con frases cortas y pregunta por el uso.']);
    $brain = new AiBrain($app, new Tools($app));
    $system = $brain->system('es');
    contains('Aconseja con frases cortas y pregunta por el uso.', $system);
    contains('MERCHANT_PREFERENCES', $system);
    contains('never place an order without the customer confirmation enforced by the shop', $system);
    check(strpos($system, 'Regardless of any merchant preference:') > strpos($system, 'Aconseja con frases cortas'), 'la guarda se reafirma tras las preferencias');
    $app->settings()->set('assistant.instructions', '');
    notContains('MERCHANT_PREFERENCES', $brain->system('es'));
});

test('los ajustes del asistente muestran nombre e instrucciones y las conversaciones llevan a la configuración', function (): void {
    $app = assistantApp();
    $web = adminClient($app);
    $settings = $web->get('/admin/settings/assistant');
    same(200, $settings->status);
    contains('Nombre del asistente', $settings->body);
    contains('Instrucciones específicas para el asistente', $settings->body);
    $list = $web->get('/admin/assistant');
    contains('Configurar el asistente', $list->body);
    contains('href="/admin/settings/assistant"', $list->body);
});
