<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;
use Aifec\Kernel\Slugs;

/**
 * Las direcciones de la versión en texto de cada página y el camino de vuelta: de una dirección a lo que es (portada, índice del
 * blog o una entidad con dirección amigable).
 *
 *   portada            /index.md
 *   índice del blog    /blog.md
 *   una entidad        su dirección amigable sin la barra final y con «.md»: /producto/silla.md
 */
final class Addresses
{
    /** Las entidades con dirección amigable que tienen versión en texto. */
    public const ENTITIES = ['product', 'category', 'brand', 'page', 'post', 'term'];

    /**
     * La dirección completa de la versión en texto de una página de la tienda, o vacío si esa página no tiene.
     *
     * @param array<string,mixed> $page tipo de página, entidad
     */
    public static function forPage(App $app, array $page): string
    {
        $type = isset($page['type']) ? (string) $page['type'] : '';
        if ($type === 'home') {
            return $app->url()->origin() . $app->url()->to('/index.md');
        }
        $entity = isset($page['entity']) && is_array($page['entity']) ? $page['entity'] : null;
        if ($entity !== null && isset($entity['type'], $entity['id']) && in_array((string) $entity['type'], self::ENTITIES, true)) {
            return self::entity($app, (string) $entity['type'], (int) $entity['id']);
        }
        if ($type === 'blog' && $app->router()->has('blog')) {
            return $app->url()->origin() . $app->url()->route('blog') . '.md';
        }

        return '';
    }

    /** La dirección completa de la versión en texto de una entidad, o vacío si no tiene dirección amigable. */
    public static function entity(App $app, string $type, int $id, ?string $lang = null): string
    {
        $path = self::entityPath($app, $type, $id, $lang);

        return $path !== '' ? $app->url()->origin() . $path : '';
    }

    /** Lo mismo sin el dominio: «/producto/silla.md». */
    public static function entityPath(App $app, string $type, int $id, ?string $lang = null): string
    {
        $path = rtrim($app->url()->entity($type, $id, $lang), '/');
        $home = rtrim($app->url()->to('/', [], $lang), '/');
        if ($path === '' || $path === $home) {
            return '';
        }

        return $path . '.md';
    }

    /**
     * ¿A qué corresponde una ruta (ya sin el prefijo de idioma ni el «.md»)?
     *
     * @return array{type:string,id:int,canonical:bool}|null «canonical» = false si es una dirección antigua de la entidad
     */
    public static function resolve(App $app, string $path, bool $explicit): ?array
    {
        $clean = Slugs::clean($path);
        if ($clean === '') {
            // «/» es la portada; «/.md» no es nada.
            return $explicit ? null : ['type' => 'home', 'id' => 0, 'canonical' => true];
        }
        if ($explicit && $clean === 'index') {
            return ['type' => 'home', 'id' => 0, 'canonical' => true];
        }
        if ($app->router()->has('blog') && $clean === Slugs::clean($app->router()->path('blog'))) {
            return ['type' => 'blog', 'id' => 0, 'canonical' => true];
        }
        $hit = $app->slugs()->resolve($app->lang(), $clean);
        if ($hit === null || !in_array($hit['type'], self::ENTITIES, true)) {
            return null;
        }

        return ['type' => $hit['type'], 'id' => $hit['entity_id'], 'canonical' => $hit['canonical'] && $hit['path'] === $clean];
    }
}
