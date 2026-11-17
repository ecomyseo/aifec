<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Cms\Blocks;

/**
 * Un bloque de WordPress leído de sus comentarios `<!-- wp:nombre {json} -->…<!-- /wp:nombre -->`.
 * Sus partes son, por orden, trozos de HTML y bloques hijos. Solo lo usa `GutenbergParser`.
 */
final class WpNode
{
    /** @var string nombre sin el espacio de nombres `core/` */
    public $name;

    /** @var array<string,mixed> */
    public $attrs;

    /** @var array<int,string|WpNode> */
    public $parts = [];

    /**
     * @param array<string,mixed> $attrs
     */
    public function __construct(string $name, array $attrs)
    {
        $this->name = $name;
        $this->attrs = $attrs;
    }

    /** Todo el HTML de dentro, con el de los hijos (sin sus comentarios). */
    public function inner(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            $out .= $part instanceof self ? $part->inner() : $part;
        }

        return $out;
    }

    /** Solo el HTML propio, sin el de los hijos. */
    public function own(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            if (is_string($part)) {
                $out .= $part;
            }
        }

        return $out;
    }

    /** El HTML propio anterior al primer hijo. */
    public function beforeChildren(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            if ($part instanceof self) {
                break;
            }
            $out .= $part;
        }

        return $out;
    }

    /**
     * @return array<int,WpNode>
     */
    public function children(): array
    {
        $out = [];
        foreach ($this->parts as $part) {
            if ($part instanceof self) {
                $out[] = $part;
            }
        }

        return $out;
    }
}
