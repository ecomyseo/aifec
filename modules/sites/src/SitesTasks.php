<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Kernel\App;
use Aifec\Modules\Jobs\PermanentFailure;

/**
 * El trabajo en segundo plano «sites.build» y la tarea nocturna «sites.reset_demos» (ver jobs.php).
 */
final class SitesTasks
{
    /**
     * Trabajo «sites.build»: crea o restablece la demostración que dice el trabajo (['id' => 'demo-moda']). Si falla, la
     * ficha de la demostración ya queda en «fallida» con su motivo y el trabajo también (sin reintentos: repetirlo no lo arregla).
     *
     * @param array<string,mixed> $payload
     */
    public static function build(array $payload, App $app): string
    {
        if (!Demos::isMaster($app)) {
            throw new PermanentFailure('Demos are only built from the master site');
        }
        $id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        $result = (new Demos($app))->execute($id);
        if (empty($result['ok'])) {
            throw new PermanentFailure(isset($result['error']) ? (string) $result['error'] : 'The demo could not be built');
        }

        return $id . ' is ready';
    }

    /**
     * Tarea «sites.reset_demos» (cada noche a las 04:30): restablece todas las demostraciones, de una en una. Si una falla,
     * sigue con las demás; al final, si alguna falló, lanza con la lista (así el panel de tareas enseña el error).
     */
    public static function resetDemos(App $app): ?string
    {
        if (!Demos::isMaster($app)) {
            return null;
        }
        if (!$app->settings()->bool(Demos::NIGHTLY, true)) {
            // Aunque no se restablezcan, los accesos de los dueños se ponen al día (una contraseña cambiada en el maestro).
            (new Owners($app))->syncAll();

            return 'The nightly reset is turned off';
        }
        $demos = new Demos($app);
        if ($demos->registry() === []) {
            return null;
        }
        $result = $demos->resetAll();
        // Las de paquete ya se han puesto al día al rehacerse; esto alcanza a las apuntadas a mano, que no se rehacen.
        (new Owners($app))->syncAll();
        if ($result['failed'] !== []) {
            $parts = [];
            foreach ($result['failed'] as $id => $error) {
                $parts[] = $id . ': ' . $error;
            }
            throw new \RuntimeException('Reset ' . count($result['done']) . ' demo(s); failed ' . count($result['failed']) . ' (' . implode('; ', $parts) . ')');
        }

        return 'Reset ' . count($result['done']) . ' demo(s)';
    }
}
