<?php
/**
 * AIFEC — utilidades del panel: pintar una pantalla dentro del marco, ficha de formulario, avisos y auditoría.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Csrf;

final class Admin
{
    /**
     * Pinta una plantilla dentro del marco del panel.
     *
     * $meta: title (string), crumbs ([[texto, dirección|null], …]), actions (HTML de los botones de cabecera),
     * help (HTML del acordeón de ayuda), active (ruta del menú que se marca), bare (true = sin menú: acceso).
     * Si la pantalla es de un conjunto de pestañas del menú (ver Menu), bajo el título sale la barra con sus hermanas.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     */
    public static function page(App $app, string $template, array $data = [], array $meta = [], int $status = 200): Response
    {
        return self::html($app, $app->view()->render($template, $data), $meta, $status);
    }

    /**
     * Envuelve un HTML ya montado en el marco del panel.
     *
     * @param array<string,mixed> $meta
     */
    public static function html(App $app, string $html, array $meta = [], int $status = 200): Response
    {
        $request = $app->request();
        $bare = !empty($meta['bare']);
        $flashes = $app->session('admin')->pullFlash();
        $menu = $bare ? [] : Menu::build($app, isset($meta['active']) ? (string) $meta['active'] : $request->path);
        $page = $app->view()->render('core::admin/layout', [
            'content' => $html,
            'title' => isset($meta['title']) ? (string) $meta['title'] : '',
            'crumbs' => isset($meta['crumbs']) && is_array($meta['crumbs']) ? $meta['crumbs'] : [],
            'actions' => isset($meta['actions']) ? (string) $meta['actions'] : '',
            'help' => isset($meta['help']) ? (string) $meta['help'] : '',
            'bare' => $bare,
            'menu' => $menu,
            'pagetabs' => Menu::tabs($menu),
            'user' => $bare ? null : $app->auth()->admin(),
            'flashes' => $flashes,
            'token' => $bare ? '' : self::token($app),
            'siteName' => $app->settings()->str('store.name', 'AIFEC'),
        ]);
        $response = Response::html($page, $status);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    /** Ficha contra envíos falsificados, atada a la sesión del panel. Vale dos horas. */
    public static function token(App $app): string
    {
        return Csrf::issue($app->session('admin')->csrfSecret(), 'admin', $app->clock()->now());
    }

    /** Campo oculto con la ficha, para cualquier formulario del panel escrito a mano. */
    public static function tokenField(App $app): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token($app)) . '">';
    }

    public static function checkToken(App $app, Request $request): bool
    {
        $token = $request->header('x-csrf-token');
        if ($token === null || $token === '') {
            $value = $request->input('_token', '');
            $token = is_string($value) ? $value : '';
        }
        if ($token === '') {
            return false;
        }

        return Csrf::check($app->session('admin')->csrfSecret(), 'admin', $token, $app->clock()->now(), 43200);
    }

    /** Aviso para la pantalla siguiente. $kind: ok, bad, warn, info. */
    public static function flash(App $app, string $kind, string $message): void
    {
        $session = $app->session('admin');
        $current = $session->get('_flash', []);
        $list = is_array($current) && isset($current['messages']) && is_array($current['messages']) ? $current['messages'] : [];
        $list[] = [$kind, $message];
        $session->flash('messages', $list);
    }

    /**
     * Apunta en el registro de auditoría quién hizo qué. Nunca lanza: un fallo aquí no puede tumbar un guardado.
     *
     * @param array<string,mixed> $changes campo => [antes, después]; los secretos llegan ya enmascarados
     */
    public static function audit(App $app, string $action, string $entity = '', string $entityId = '', string $summary = '', array $changes = []): void
    {
        try {
            $admin = $app->auth()->admin();
            $app->db()->insert('audit', [
                'user_id' => $admin !== null ? (int) $admin['id'] : null,
                'user_name' => $admin !== null ? (string) $admin['name'] : null,
                'action' => substr($action, 0, 80),
                'entity' => $entity !== '' ? substr($entity, 0, 60) : null,
                'entity_id' => $entityId !== '' ? substr($entityId, 0, 60) : null,
                'summary' => $summary !== '' ? mb_substr($summary, 0, 190) : null,
                'changes' => $changes !== [] ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip' => $app->request()->ip,
                'created_at' => $app->clock()->sql(),
            ]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'audit');
        }
    }

    /** Texto de ayuda de una definición (en inglés, un párrafo por línea) → HTML traducido y escapado. */
    public static function helpText(string $text): string
    {
        $html = '';
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $html .= '<p>' . e(t(trim($line))) . '</p>';
            }
        }

        return $html;
    }

    public static function icon(string $name, string $class = 'af-ico'): string
    {
        return Icons::svg($name, $class);
    }

    /** Insignia de estado. $kind: ok, warn, bad, info o vacío. */
    public static function badge(string $text, string $kind = ''): string
    {
        $class = 'af-badge' . (in_array($kind, ['ok', 'warn', 'bad', 'info'], true) ? ' af-badge-' . $kind : '');

        return '<span class="' . $class . '">' . e($text) . '</span>';
    }

    /**
     * Definición de un recurso declarado por algún módulo en su admin.php ('resources' => ['nombre' => […]]).
     *
     * @return array<string,mixed>|null
     */
    public static function resource(App $app, string $name): ?array
    {
        foreach ($app->adminDefinitions() as $module => $definition) {
            if (isset($definition['resources'][$name]) && is_array($definition['resources'][$name])) {
                $resource = $definition['resources'][$name];
                $resource['name'] = $name;
                $resource['module'] = $module;

                return $resource;
            }
        }

        return null;
    }

    /**
     * Dirección de un recurso: lista, alta ('new') o edición (un número).
     *
     * @param int|string|null $id
     */
    public static function resourceUrl(App $app, string $name, $id = null, array $query = []): string
    {
        return $app->url()->plain('/admin/r/' . $name . ($id !== null && $id !== '' ? '/' . $id : ''), $query);
    }

    /**
     * Vuelve a la dirección pedida en «next» si es del propio panel; si no, a la de reserva.
     * Nunca redirige fuera del sitio.
     */
    public static function back(App $app, Request $request, string $fallback): Response
    {
        $next = $request->str('next');
        $base = $app->site()->basePath . '/admin';
        if ($next !== '' && strpos($next, $base) === 0 && strpos($next, '//') === false && strpos($next, "\\") === false && preg_match('/[\r\n]/', $next) !== 1) {
            return Response::redirect($next);
        }

        return Response::redirect($fallback);
    }

    /**
     * Todos los permisos que declaran los módulos: clave => texto.
     *
     * @return array<string,string>
     */
    public static function capabilities(App $app): array
    {
        $caps = [];
        foreach ($app->adminDefinitions() as $definition) {
            foreach (isset($definition['caps']) && is_array($definition['caps']) ? $definition['caps'] : [] as $cap => $label) {
                $caps[(string) $cap] = (string) $label;
            }
        }
        ksort($caps);

        return $caps;
    }
}
