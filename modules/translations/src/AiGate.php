<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * Cuándo sale la IA en las traducciones. Este módulo no depende del de IA: todo se pregunta con $app->has('ai') y se pide por su
 * servicio («ai»: tope de gasto, registro de uso, propuestas). Sin el módulo de IA, todo lo manual funciona y los botones de IA no
 * salen.
 */
final class AiGate
{
    /** ¿Está el módulo de IA cargado? */
    public static function installed(App $app): bool
    {
        return $app->has('ai');
    }

    /** ¿Está la IA encendida, con un proveedor listo y con la cola de trabajos? No mira permisos. */
    public static function ready(App $app): bool
    {
        if (!$app->has('ai') || !$app->has('jobs')) {
            return false;
        }
        $ai = $app->service('ai');
        if (!is_object($ai) || !method_exists($ai, 'enabled') || !method_exists($ai, 'ask') || !$ai->enabled()) {
            return false;
        }
        if ($app->has('ai.provider.economy')) {
            return true;
        }
        $kind = method_exists($ai, 'kind') ? (string) $ai->kind('economy') : 'gemini';

        return $kind === 'fake' || $app->secret('ai.economy.key') !== '';
    }

    /** ¿Puede quien mira lanzar la IA ahora mismo? (IA lista y los permisos «ai.use» y «translations.edit»). */
    public static function available(App $app): bool
    {
        return self::ready($app) && $app->auth()->can('ai.use') && $app->auth()->can('translations.edit');
    }
}
