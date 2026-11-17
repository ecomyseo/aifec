<?php
/**
 * AIFEC — órdenes de consola del contenido.
 *
 *   php aifec cms:legal [--create] [--refresh [--only=legal-notice,privacy]] [--publish]
 *       las páginas legales (aviso legal, privacidad, cookies, condiciones y envíos): sin opciones dice cómo están; --create crea
 *       las que faltan, --refresh rehace las que existen con los textos de ahora (guarda revisión antes) y --publish las publica.
 *
 * Para qué (14/11/2026): el usuario pidió que las páginas legales de TODAS las tiendas usen siempre los datos de empresa de
 * Ajustes; las que ya estaban creadas llevaban los datos copiados el día que se crearon. Desde el panel: Contenido → Páginas legales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Cms\Legal;

return [
    'cms:legal' => ['help' => 'The legal pages: shows them; --create creates the missing ones, --refresh rewrites the existing ones with the current texts (--only=code,code for some), --publish publishes them', 'run' => static function (App $app, array $args, Console $io): int {
        $legal = new Legal($app);
        if (!empty($args['create'])) {
            $created = $legal->create();
            $io->line('Created: ' . ($created === [] ? 'none' : implode(', ', $created)));
        }
        if (!empty($args['refresh'])) {
            $only = isset($args['only']) && is_string($args['only']) && trim($args['only']) !== '' ? array_values(array_filter(array_map('trim', explode(',', $args['only'])))) : null;
            $done = $legal->refresh($only);
            $io->line('Rewritten: ' . ($done === [] ? 'none' : implode(', ', $done)));
        }
        if (!empty($args['publish'])) {
            $ids = [];
            foreach ($legal->status() as $page) {
                if ($page['id'] > 0 && $page['status'] !== 'published') {
                    $ids[] = $page['id'];
                }
            }
            foreach ($ids as $id) {
                $app->db()->update('pages', ['status' => 'published', 'updated_at' => $app->clock()->sql()], ['id' => $id]);
                $app->hooks()->fire('content.saved', 'page', $id);
            }
            if ($ids !== []) {
                $app->invalidate(['cms']);
            }
            $io->line('Published: ' . count($ids));
        }
        foreach ($legal->status() as $code => $page) {
            $io->line($code . ': ' . ($page['id'] > 0 ? '#' . $page['id'] . ' ' . $page['status'] . ' ' . $app->url()->entity('page', $page['id']) : 'not created'));
        }

        return 0;
    }],
];
