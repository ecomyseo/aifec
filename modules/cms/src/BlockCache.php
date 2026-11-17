<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Cms\Blocks\BlockRenderer;
use Aifec\Kernel\App;

/**
 * Pinta una página de bloques sin volver a pintar lo que no ha cambiado (como las secciones de Shopify).
 *
 * Dos niveles de caché, los dos en la caché de datos del sitio:
 *
 * - El ESQUELETO de la página: el HTML de todos los bloques de contenido puro (párrafos, títulos, imágenes, columnas…)
 *   con un hueco marcado donde va cada bloque que pinta otro módulo. Clave: el contenido de los bloques, el tema y su
 *   versión y el idioma; así una edición da otra clave y nada se queda viejo.
 * - Cada bloque que pinta datos de otro módulo (una tarjeta de producto, una cuadrícula de productos), por separado,
 *   con las etiquetas de lo que enseña: cambiar un producto invalida el bloque que lo enseña y no los demás.
 *
 * Los bloques que dependen del visitante o del reloj (DYNAMIC: un formulario con sus errores, las últimas entradas) no
 * se guardan nunca: se pintan en cada petición dentro de su hueco. Un plugin puede añadir tipos con el filtro
 * «cms.block.dynamic».
 */
final class BlockCache
{
    /** Tipos de bloque que nunca se guardan: dependen del visitante (form) o de la hora (posts). */
    public const DYNAMIC = ['form', 'posts'];

    /** Tipos que pinta otro módulo y van en su propio hueco del esqueleto. */
    private const SLOTS = ['products', 'product', 'posts', 'form', 'company'];

    /** Segundos que vale un esqueleto o un bloque guardado (además de sus etiquetas). */
    private const TTL = 3600;

    /** @var App */
    private $app;

    /** @var array<string,callable> pintores de los bloques dinámicos (image, products, product, posts, form) */
    private $painters;

    /** @var int cuántas veces se ha llamado a un pintor en esta instancia (para comprobar que no se repinta) */
    private $painted = 0;

    /** @var int cuántos esqueletos se han pintado (no salieron de la caché) */
    private $skeletons = 0;

    /** @var string[]|null */
    private $dynamic;

    /**
     * @param array<string,callable> $painters
     */
    public function __construct(App $app, array $painters)
    {
        $this->app = $app;
        $this->painters = $painters;
    }

    /**
     * Bloques → HTML, reutilizando lo ya pintado.
     *
     * @param array<int|string,mixed> $blocks
     */
    public function render(array $blocks): string
    {
        $skeleton = $this->skeleton($blocks);
        $html = (string) $skeleton['html'];
        $nonce = (string) $skeleton['nonce'];
        foreach ((array) $skeleton['slots'] as $i => $slot) {
            $type = (string) $slot[0];
            $attrs = is_array($slot[1]) ? $slot[1] : [];
            $marker = '<!--af-slot-' . $nonce . '-' . $i . '-->';
            $wrapped = '<div class="af-b af-b-' . $type . '">' . $marker . '</div>';
            $out = $this->paint($type, $attrs);
            if ($out !== '') {
                $html = str_replace($marker, $out, $html);
                continue;
            }
            // Un bloque que no pinta nada no deja ni su envoltorio (igual que al pintarlo todo de una vez).
            if (strpos($html, "\n" . $wrapped) !== false) {
                $html = str_replace("\n" . $wrapped, '', $html);
            } elseif (strpos($html, $wrapped . "\n") !== false) {
                $html = str_replace($wrapped . "\n", '', $html);
            } else {
                $html = str_replace($wrapped, '', $html);
            }
        }

        return $html;
    }

    /**
     * Un bloque que pinta otro módulo: de la caché si se puede; los dinámicos, siempre al momento.
     *
     * @param array<string,mixed> $attrs
     */
    public function paint(string $type, array $attrs): string
    {
        if (!isset($this->painters[$type])) {
            return '';
        }
        if ($this->isDynamic($type) || $this->personal()) {
            ++$this->painted;

            return $this->call($type, $attrs);
        }
        $key = 'cms:block:' . md5($type . '|' . json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . '|' . $this->context());

        return (string) $this->app->cache()->remember($key, self::TTL, function () use ($type, $attrs): string {
            ++$this->painted;

            return $this->call($type, $attrs);
        }, self::tags($type, $attrs));
    }

    /** ¿Depende este tipo de bloque del visitante o de la hora? (entonces no se guarda) */
    public function isDynamic(string $type): bool
    {
        if ($this->dynamic === null) {
            $list = $this->app->hooks()->apply('cms.block.dynamic', self::DYNAMIC);
            $this->dynamic = is_array($list) ? array_values(array_map('strval', $list)) : self::DYNAMIC;
        }

        return in_array($type, $this->dynamic, true);
    }

    /** ¿Lleva algún bloque dinámico, a cualquier profundidad? */
    public function hasDynamic(array $blocks, int $depth = 0): bool
    {
        if ($depth > 20) {
            return false;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ($this->isDynamic(isset($block['type']) ? (string) $block['type'] : '')) {
                return true;
            }
            if (isset($block['children']) && is_array($block['children']) && $this->hasDynamic($block['children'], $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Veces que se ha llamado a un pintor (un bloque que salió de la caché no cuenta). */
    public function painted(): int
    {
        return $this->painted;
    }

    /** Esqueletos pintados (no salieron de la caché). */
    public function skeletons(): int
    {
        return $this->skeletons;
    }

    /**
     * Etiquetas de caché de un bloque según lo que enseña.
     *
     * @param array<string,mixed> $attrs
     *
     * @return string[]
     */
    public static function tags(string $type, array $attrs): array
    {
        $tags = ['cms-blocks'];
        if ($type === 'company') {
            // Los datos de la empresa salen de Ajustes, y el enlace al formulario, de las páginas.
            $tags[] = 'settings';
            $tags[] = 'cms';
        }
        if ($type === 'products' || $type === 'product') {
            // Los precios y el formato del dinero dependen del catálogo y de los ajustes.
            $tags[] = 'catalog';
            $tags[] = 'settings';
            $ids = $type === 'product' ? [isset($attrs['id']) ? $attrs['id'] : 0] : (isset($attrs['ids']) && is_array($attrs['ids']) ? $attrs['ids'] : []);
            foreach ($ids as $id) {
                if ((int) $id > 0) {
                    $tags[] = 'product:' . (int) $id;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * El esqueleto de unos bloques: de la caché, o pintado ahora con un hueco por cada bloque de otro módulo.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return array{html:string,nonce:string,slots:array<int,array{0:string,1:array<string,mixed>}>}
     */
    private function skeleton(array $blocks): array
    {
        $cache = $this->app->cache();
        $key = 'cms:skeleton:' . md5((string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . '|' . $this->context());
        $saved = $cache->get($key);
        if (is_array($saved) && isset($saved['html'], $saved['nonce'], $saved['slots']) && is_array($saved['slots'])) {
            return $saved;
        }
        ++$this->skeletons;
        $nonce = bin2hex(random_bytes(6));
        $slots = [];
        $painters = [];
        foreach (self::SLOTS as $type) {
            $painters[$type] = static function (array $attrs) use (&$slots, $type, $nonce): string {
                $i = count($slots);
                $slots[] = [$type, $attrs];

                return '<!--af-slot-' . $nonce . '-' . $i . '-->';
            };
        }
        // Las imágenes de la biblioteca van dentro del esqueleto (pasan por el limpiador de HTML, que quitaría un hueco):
        // se pintan con su propia caché.
        $painters['image'] = function (array $attrs): string {
            return $this->paint('image', $attrs);
        };
        $html = (new BlockRenderer($painters))->render($blocks);
        $skeleton = ['html' => $html, 'nonce' => $nonce, 'slots' => $slots];
        $cache->set($key, $skeleton, self::TTL, ['cms-blocks']);

        return $skeleton;
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function call(string $type, array $attrs): string
    {
        try {
            $out = ($this->painters[$type])($attrs);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($out) ? $out : '';
    }

    /**
     * ¿Tiene este visitante su cuenta abierta? Entonces los bloques de otros módulos ni salen de la copia compartida ni la
     * escriben: lo que un plugin le pinte distinto (su precio, por el filtro «product.price») no se guarda para los demás ni
     * se le tapa con el de los demás. Es la misma regla, y la misma marca legible «u», que la caché de página.
     */
    private function personal(): bool
    {
        return $this->app->request()->cookie($this->app->cookieName('u')) !== null;
    }

    /** Lo que cambia el HTML de un bloque además de su contenido: el tema (y su versión) y el idioma. */
    private function context(): string
    {
        $theme = $this->app->theme();

        return (isset($theme['slug']) ? (string) $theme['slug'] : '') . '@' . (isset($theme['version']) ? (string) $theme['version'] : '')
            . '|' . $this->app->lang();
    }
}
