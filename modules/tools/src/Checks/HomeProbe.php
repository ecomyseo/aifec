<?php
/**
 * AIFEC — petición de prueba a la portada, dentro del propio proceso: sirve para mirar las cabeceras y la caché de página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Se hace una sola vez por diagnóstico. La petición la atiende OTRA aplicación (con los mismos módulos), porque atender
 * una petición cambia el estado de la aplicación (petición en curso, sesiones, cookies) y la pantalla que está
 * pintando el diagnóstico seguiría usándolo.
 */
final class HomeProbe
{
    /** @var Response|null|false false = aún no se ha hecho */
    private $response = false;

    /** @var string */
    private $error = '';

    public function response(App $app): ?Response
    {
        if ($this->response !== false) {
            return $this->response;
        }
        $this->response = null;
        try {
            $nested = new App($app->site(), $app->root(), [
                'public_dir' => $app->publicDir(),
                'public_base' => $app->publicBase(),
                'clock' => $app->clock(),
                'http' => $app->http(),
                'log_files' => false,
                'modules' => $app->modules()->loaded(),
            ]);
            $request = Request::create('GET', '/');
            $parts = parse_url($app->site()->url());
            $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
            $request->host = $host . (is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '');
            $request->secure = is_array($parts) && isset($parts['scheme']) && $parts['scheme'] === 'https';
            $this->response = $nested->handle($request);
        } catch (\Throwable $e) {
            $this->error = get_class($e) . ': ' . $e->getMessage();
        } finally {
            $app->makeCurrent();
        }

        return $this->response;
    }

    public function error(): string
    {
        return $this->error;
    }
}
