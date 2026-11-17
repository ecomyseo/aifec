<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Cms\Legal;

require_once __DIR__ . '/helpers.php';

test('las páginas legales existentes se registran sin duplicarlas ni cambiar las políticas de la tienda', function (): void {
    $app = cmsApp(['cms']);
    $now = $app->clock()->sql();
    $ids = [];
    foreach (['aviso-legal', 'privacidad', 'envio-y-devoluciones'] as $slug) {
        $id = $app->db()->insert('pages', ['status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $app->db()->insert('page_i18n', ['page_id' => $id, 'lang' => 'es', 'title' => $slug, 'blocks' => '[]', 'excerpt' => 'Política propia de la tienda']);
        $app->slugs()->set('page', $id, 'es', $slug);
        $ids[$slug] = $id;
    }
    $legal = new Legal($app);
    same(['cookies', 'terms'], $legal->create());
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    $map = $app->settings()->arr('cms.legal');
    same($ids['aviso-legal'], $map['legal-notice']);
    same($ids['privacidad'], $map['privacy']);
    same($ids['envio-y-devoluciones'], $map['shipping-returns']);
    same('published', $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$ids['aviso-legal']]));
    same('Política propia de la tienda', $app->db()->value('SELECT excerpt FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$ids['envio-y-devoluciones'], 'es']));
    same([], $legal->create());
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
});
