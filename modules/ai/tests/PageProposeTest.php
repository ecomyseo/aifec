<?php
/**
 * AIFEC — «Proponer con IA» en una página de contenido: título y descripción para buscadores como propuesta, que se aprueba y
 * se aplica a esa página (ronda 3, 14/11/2026: «no encontré ninguna forma de pedir a la IA una propuesta de texto para una página»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\FakeProvider;

require_once __DIR__ . '/helpers.php';

test('la ficha de una página lleva «Proponer con IA», la propuesta se crea y al aprobarla cambia la descripción de la página', function (): void {
    $app = testApp(['modules' => ['ai', 'cms', 'media'], 'settings' => [
        'ai.enabled' => true, 'ai.daily_limit_cents' => 200,
        'ai.economy.price_in' => 100, 'ai.economy.price_out' => 400, 'ai.standard.price_in' => 100, 'ai.standard.price_out' => 400,
        'ai.advanced.price_in' => 100, 'ai.advanced.price_out' => 400,
    ]]);
    $app->boot();
    foreach (['economy', 'standard', 'advanced'] as $tier) {
        $fake = new FakeProvider();
        $app->bind('ai.provider.' . $tier, static function () use ($fake): FakeProvider {
            return $fake;
        });
    }
    $now = $app->clock()->sql();
    $page = $app->db()->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('page_i18n', ['page_id' => $page, 'lang' => 'es', 'title' => 'Envíos y devoluciones', 'blocks' => '[{"type":"paragraph","attrs":{"html":"Enviamos en 48 horas a toda la península."}}]']);
    $app->slugs()->set('page', $page, 'es', 'envios');

    $web = adminClient($app);
    $form = $web->get('/admin/r/pages/' . $page);
    same(200, $form->status);
    contains('data-af-page-ai', $form->body, 'la tarjeta sale en la página');
    contains('value="page_meta_description"', $form->body);

    aiFake($app)->script(aiJson(['description' => 'Plazos y costes de envío a la península & devoluciones.']));
    $r = $web->post('/admin/ai/propose', ['_token' => aifecToken($web), 'page_id' => $page, 'task' => 'page_meta_description', 'lang' => 'es']);
    same(302, $r->status);
    $row = aiRows($app, 'ai_proposals')[0];
    same('page', $row['entity']);
    same('meta_description', $row['field']);
    same('Plazos y costes de envío a la península & devoluciones.', $row['proposed_text']);
    contains('/admin/ai/proposals/' . $row['id'], (string) $r->headers['Location']);

    $web->get('/admin/ai/proposals/' . $row['id']);
    $web->post('/admin/ai/proposals/' . $row['id'] . '/approve', ['_token' => aifecToken($web)]);
    same('Plazos y costes de envío a la península & devoluciones.', (string) $app->db()->value("SELECT meta_description FROM {page_i18n} WHERE page_id = ? AND lang = 'es'", [$page]));
});
