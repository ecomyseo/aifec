<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

use Aifec\Kernel\App;

/**
 * Cambia ajustes SOLO EN MEMORIA, sin escribir nada en la base de datos: lo que necesitan la vista previa del
 * personalizador y las comprobaciones dinámicas del validador. Es un envoltorio de Settings::override() y
 * Settings::restore() del núcleo. Quien lo usa lo restaura siempre (try/finally o al acabar la petición).
 */
final class Memory
{
    /**
     * Pone valores en memoria y devuelve lo que había, para restaurarlo.
     *
     * @param array<string,mixed> $values clave del ajuste => valor
     *
     * @return array<string,array{0:bool,1:mixed}> clave => [existía, valor anterior]
     */
    public static function apply(App $app, array $values): array
    {
        return $app->settings()->override($values);
    }

    /**
     * Devuelve los ajustes a como estaban. Si los ajustes se han vuelto a leer de la base de datos mientras tanto, ya
     * valen los guardados y no hay nada que restaurar.
     *
     * @param array<string,array{0:bool,1:mixed}> $saved lo que devolvió apply()
     */
    public static function restore(App $app, array $saved): void
    {
        $app->settings()->restore($saved);
    }
}
