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
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La página pública de demostraciones del sitio maestro (GET /demos) y la captura de portada de cada paquete.
 *
 * La página sale en la caché de página: solo enseña datos públicos (los de acceso de demostración se enseñan a propósito).
 */
final class DemosController
{
    /** @var App */
    private $app;

    /** @var Demos */
    private $demos;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->demos = new Demos($app);
    }

    /**
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $packages = $this->demos->packages();
        // El acceso al panel solo se anuncia si el maestro lo ha encendido. Apagado, ni el enlace ni los datos de acceso
        // llegan a la plantilla (el panel sigue respondiendo en su dirección para quien la conoce).
        $showPanel = $this->app->settings()->bool(Demos::SHOW_PANEL, false);
        $cards = [];
        foreach ($this->demos->registry() as $id => $entry) {
            if (!isset($entry['state']) || $entry['state'] !== 'done' || empty($entry['url'])) {
                continue;
            }
            $package = isset($entry['package']) ? (string) $entry['package'] : '';
            $meta = isset($packages[$package]) ? $packages[$package] : [];
            $url = rtrim((string) $entry['url'], '/');
            // Una tienda apuntada a mano (sin paquete) lleva su sector y su descripción en la ficha y no enseña acceso al panel.
            $adopted = !empty($entry['adopted']);
            $from = $adopted ? $entry : $meta;
            $panel = !$adopted && $showPanel;
            $cards[] = [
                'id' => $id,
                'name' => isset($entry['name']) ? (string) $entry['name'] : $id,
                'sector' => isset($from['sector']) && is_string($from['sector']) ? $from['sector'] : '',
                'description' => isset($from['description']) && is_string($from['description']) ? $from['description'] : '',
                'preview' => !$adopted && !empty($meta['preview']) ? $this->app->url()->to('/demo-preview/' . $package) : '',
                'shop_url' => $url . '/',
                'admin_url' => $panel ? $url . '/admin' : '',
                'panel' => $panel,
                'administration' => $panel && !empty($entry['public_admin']),
                'administrator_email' => $panel && !empty($entry['public_admin']) ? DemoAdministration::email($url) : '',
                'email' => $panel ? DemoGuard::email($url) : '',
                'password' => $panel ? (isset($entry['password']) ? (string) $entry['password'] : DemoGuard::DEFAULT_PASSWORD) : '',
            ];
        }
        usort($cards, static function (array $a, array $b): int {
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $this->app->render('sites::front/demos', [
            'page' => [
                'title' => t('Demo shops'),
                'description' => $showPanel
                    ? t('Try complete shops built with AIFEC: browse them, buy with a test payment and open their panel.')
                    : t('Try complete shops built with AIFEC: browse them and buy with a test payment.'),
                'type' => 'page',
            ],
            'demos' => $cards,
            'showPanel' => $showPanel,
        ])->cacheable(600, ['sites']);
    }

    /**
     * GET /demo-preview/{package}: la captura de portada (demos/<paquete>/preview.png) si el paquete la trae.
     *
     * @param array<string,string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $name = isset($params['package']) ? (string) $params['package'] : '';
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name) !== 1) {
            throw HttpError::notFound();
        }
        $file = $this->demos->packagesDir() . '/' . $name . '/preview.png';
        if (!is_file($file)) {
            throw HttpError::notFound();
        }

        return Response::file($file, $name . '.png', 'image/png', true)->header('Cache-Control', 'public, max-age=3600');
    }
}
