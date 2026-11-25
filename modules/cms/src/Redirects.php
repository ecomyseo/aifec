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

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Text\UrlFilter;

/**
 * Redirecciones en marcha: el filtro «request.not_found» del núcleo. Si hay una redirección activa para la ruta pedida
 * (comparación exacta de la ruta sin barra final, o con comodín final `*`), responde con ella y suma su contador; si no,
 * apunta la ruta en `not_found` (contador y última vez; como mucho 5.000 filas, las menos recientes se borran).
 *
 * Una ruta se guarda sin barra inicial ni final y sin prefijo de idioma. Un destino que lleva `*` recibe lo que casó el comodín.
 */
final class Redirects
{
    public const MAX_NOT_FOUND = 5000;

    /**
     * El filtro: devuelve la respuesta de la redirección o lo que ya había (null: nadie contesta y el núcleo da su 404).
     *
     * @param mixed $current
     *
     * @return mixed
     */
    public static function handle($current, Request $request, App $app)
    {
        if ($current !== null || !in_array($request->method, ['GET', 'HEAD'], true)) {
            return $current;
        }
        $path = self::normalize($request->path);
        if ($path === '' || strlen($path) > 191) {
            return null;
        }
        $db = $app->db();

        $hit = $db->row('SELECT * FROM {redirects} WHERE active = 1 AND source = ?', [$path]);
        $tail = '';
        if ($hit === null) {
            // Con comodín final: gana el más largo.
            $best = -1;
            foreach ($db->select("SELECT * FROM {redirects} WHERE active = 1 AND source LIKE '%*'") as $row) {
                $prefix = substr((string) $row['source'], 0, -1);
                if (strpos($path, $prefix) === 0 && strlen($prefix) > $best) {
                    $best = strlen($prefix);
                    $hit = $row;
                    // La cola de un comodín nunca lleva barras invertidas ni caracteres de control: «/\\sitio.example» se leería como otra web.
                    $tail = (string) preg_replace('#[\\\\\x00-\x1f\x7f]+#', '', (string) substr($path, strlen($prefix)));
                }
            }
        }
        if ($hit !== null) {
            $target = str_replace('*', $tail, trim((string) $hit['target']));
            $location = self::location($app, $target, '');
            // Una redirección a sí misma no se hace (daría un bucle).
            $toSelf = preg_match('#^https?://#i', $target) !== 1 && self::normalize($target) === $path;
            if ($location !== '' && !$toSelf) {
                $db->run('UPDATE {redirects} SET hits = hits + 1 WHERE id = ?', [(int) $hit['id']]);

                return Response::redirect($location, (int) $hit['code'] === 302 ? 302 : 301);
            }
        }

        self::note($app, $path, $request);

        return null;
    }

    /**
     * Ruta sin barra inicial ni final ni dirección completa delante: «/Viejo/» → «Viejo»; «https://x.test/a/b?c» → «a/b».
     */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^https?://[^/]+(.*)$#i', $path, $m) === 1) {
            $path = $m[1];
        }
        $cut = strcspn($path, '?#');

        return trim((string) preg_replace('#/+#', '/', substr($path, 0, $cut)), '/');
    }

    /**
     * La dirección a la que se manda: completa tal cual si es http(s); una ruta del sitio con su subcarpeta e idioma.
     */
    private static function location(App $app, string $target, string $tail): string
    {
        $target = trim(str_replace('*', $tail, $target));
        $safe = UrlFilter::safe($target);
        if ($safe === null || preg_match('#[\\\\\x00-\x1f\x7f]#', $safe) === 1) {
            return '';
        }
        if (preg_match('#^https?://#i', $safe) === 1) {
            return $safe;
        }
        if (strpos($safe, '#') === 0 || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $safe) === 1) {
            return '';
        }

        return $app->url()->to('/' . ltrim($safe, '/'));
    }

    /**
     * Apunta una ruta que no existe y poda las más antiguas si hay demasiadas.
     */
    private static function note(App $app, string $path, Request $request): void
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $referer = trim((string) $request->header('referer', ''));
        $referer = $referer !== '' ? substr($referer, 0, 191) : null;

        $row = $db->row('SELECT path FROM {not_found} WHERE path = ?', [$path]);
        if ($row !== null) {
            // Quien llega sin origen no borra el origen que ya se apuntó.
            if ($referer !== null) {
                $db->run('UPDATE {not_found} SET hits = hits + 1, last_at = ?, referer = ? WHERE path = ?', [$now, $referer, $path]);
            } else {
                $db->run('UPDATE {not_found} SET hits = hits + 1, last_at = ? WHERE path = ?', [$now, $path]);
            }

            return;
        }
        $db->insert('not_found', ['path' => $path, 'hits' => 1, 'referer' => $referer, 'first_at' => $now, 'last_at' => $now]);

        $total = (int) $db->value('SELECT COUNT(*) FROM {not_found}');
        if ($total > self::MAX_NOT_FOUND) {
            $old = $db->column('SELECT path FROM {not_found} ORDER BY last_at ASC, first_at ASC LIMIT ?', [$total - self::MAX_NOT_FOUND]);
            $params = [];
            $marks = $db->in(array_map('strval', $old), $params);
            if ($old !== []) {
                $db->run('DELETE FROM {not_found} WHERE path IN (' . $marks . ')', $params);
            }
        }
    }
}
