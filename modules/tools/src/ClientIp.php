<?php
/**
 * AIFEC — la dirección del visitante detrás de un proxy o de una CDN.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Lo que el diagnóstico cuenta de la dirección del visitante. Quien la fija detrás de un proxy de confianza
 * (`security.trusted_proxies`) es el núcleo, en App::handle: no depende de que este módulo esté encendido.
 */
final class ClientIp
{
    /** ¿Llega una cabecera de proxy que, sin proxies de confianza configurados, el sistema ignora? */
    public static function headerIgnored(Request $request, App $app): bool
    {
        $hasHeader = $request->header('x-forwarded-for') !== null || $request->header('cf-connecting-ip') !== null;
        if (!$hasHeader || Request::parseProxyList($app->settings()->str('security.trusted_proxies', '')) !== []) {
            return false;
        }
        // Si la conexión ya llega con una dirección pública, el servidor web ya ha puesto la del visitante (mod_remoteip detrás
        // de nginx, lo habitual en un alojamiento): la cabecera sobra y no hay nada que avisar. Solo preocupa cuando quien
        // conecta es la propia máquina o una red privada: ahí hay un proxy delante y todos los visitantes parecen el mismo.
        $remote = (string) ($request->remoteAddr !== '' ? $request->remoteAddr : $request->ip);

        return filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
