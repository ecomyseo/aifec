<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

/**
 * La sección de la portada que aporta este módulo: el contenido de la página que el comerciante marcó como portada, con
 * el orden 10 (la primera). Si no hay ninguna marcada, o no se ve ahora, no aporta nada.
 */
final class HomeSection
{
    public const ID = 'cms.home';

    public const ORDER = 10;

    /**
     * El filtro «home.sections».
     *
     * @param mixed $sections lo que ya hay: [['id', 'order', 'html']]
     * @param mixed $app
     *
     * @return array<int,array<string,mixed>>
     */
    public static function add($sections, $app): array
    {
        $sections = is_array($sections) ? array_values($sections) : [];
        if (!($app instanceof App) || !$app->has('cms')) {
            return $sections;
        }
        /** @var Cms $cms */
        $cms = $app->service('cms');
        // No se usa homePageId(): comprueba que se ve leyendo la página, y un formulario con errores guardados los gasta en esa lectura.
        $id = $app->db()->value('SELECT id FROM {pages} WHERE is_home = 1 ORDER BY id LIMIT 1');
        $page = $id !== null ? $cms->page((int) $id) : null;
        if ($page === null || trim((string) $page['html']) === '') {
            return $sections;
        }
        $html = (string) $page['html'];
        $sections[] = [
            'id' => self::ID,
            'order' => self::ORDER,
            // El nombre con el que sale en «Diseño → Portada» (lo que pinta no siempre empieza por un encabezado).
            'title' => t('Home page content'),
            'html' => '<div class="af-prose" style="max-width:none">' . $html . '</div>' . Support::formScript($app, $html),
        ];

        return $sections;
    }
}
