<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\Assistant;
use Aifec\Modules\Assistant\Conversations;
use Aifec\Modules\Assistant\Tools;

return static function (App $app): void {
    // Las herramientas que comparten el asistente, la API y el MCP, y el asistente mismo.
    $app->bind('tools', static function (App $app): Tools {
        return new Tools($app);
    });
    $app->bind('assistant', static function (App $app): Assistant {
        return new Assistant($app);
    });

    // El botón flotante y la ventana de conversación. No lleva nada personal (el primer mensaje es el mismo para todos y la
    // conversación se abre al escribir), así que las páginas siguen siendo cacheables con el asistente encendido.
    $app->hooks()->slot('body.end', static function () use ($app): string {
        if (!$app->settings()->bool('assistant.enabled', true)) {
            return '';
        }
        /** @var Assistant $assistant */
        $assistant = $app->service('assistant');

        return $app->view()->render('assistant::front/widget', ['assistant' => $assistant]);
    });

    // Encender, apagar o retocar el asistente cambia todas las páginas guardadas en la caché.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'assistant') {
            $application->invalidate(['pages']);
        }
    });

    // Una conversación que acaba en un pedido se apunta como tal: el pedido se hace en el mismo navegador, con la misma cookie.
    $app->hooks()->on('order.placed', static function (array $order) use ($app): void {
        try {
            $conversations = new Conversations($app);
            $token = (string) $app->request()->cookie($conversations->cookieName(), '');
            if ($token !== '' && isset($order['id'])) {
                $conversations->markOrder($token, (int) $order['id']);
            }
        } catch (\Throwable $e) {
            // Sin petición (una tarea, la consola) no hay cookie que leer: no pasa nada.
        }
    });
};
