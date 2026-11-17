<?php
/**
 * AIFEC — los menús de IA que se parecen dicen cuál habla con los clientes y cuál ayuda al dueño (13/11/2026, Rosa en su primer
 * día: «IA», «Asistente», «Copiloto», «WebMCP», «Auditoría AIFEC» y «Robots y asistentes» sin saber cuál era cuál).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

test('Asistente, Copiloto, WebMCP, Auditoría y Robots explican en su ayuda qué son frente a los demás', function (): void {
    $app = testApp(['modules' => '*']);
    $admin = adminClient($app);
    $expected = [
        '/admin/assistant' => '«Asistente» es el chat que contesta a tus clientes en la tienda.',
        '/admin/assistant/unanswered' => '«Asistente» es el chat que contesta a tus clientes en la tienda.',
        '/admin/copilot' => '«Copiloto» hace tareas en este panel por ti',
        '/admin/webmcp' => 'no es un chat de la tienda',
        '/admin/semantic/audit' => '«Auditoría AIFEC» comprueba que los buscadores y los asistentes de IA leen bien tu tienda.',
        '/admin/semantic/bots' => '«Robots y asistentes» cuenta los buscadores y asistentes de IA que visitan tu tienda.',
    ];
    foreach ($expected as $url => $text) {
        $r = $admin->get($url);
        same(200, $r->status, $url);
        contains(e($text), (string) $r->body, $url);
    }
});
