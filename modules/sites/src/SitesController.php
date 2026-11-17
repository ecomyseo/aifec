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

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El panel maestro («Sites and demos»): la tabla de sitios, crear una demostración, restablecerla y borrarla. Crear y
 * restablecer van en segundo plano (trabajo «sites.build»): la petición del panel solo encola y vuelve a la tabla, donde
 * cada demostración enseña su estado (en cola, en curso, hecha o fallida con su motivo).
 */
final class SitesController
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
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');

        return Admin::page($this->app, 'sites::admin/index', [
            'sites' => $this->demos->sites(),
            'packages' => $this->demos->packages(),
            'nightly' => $this->app->settings()->bool(Demos::NIGHTLY, true),
            'showPanel' => $this->app->settings()->bool(Demos::SHOW_PANEL, false),
            'defaultPassword' => DemoGuard::DEFAULT_PASSWORD,
            'jobs' => $this->app->has('jobs'),
        ], [
            'title' => t('Sites and demos'),
            'active' => '/admin/sites',
            'help' => Admin::helpText("A demo is a complete shop built from a package of the demos folder and served in a folder of this site.\nEvery night each demo is deleted and built again from its package, so visitors can do anything in it.\nThe panel of a demo opens with the demo person and the password shown on the public demos page; that person cannot change people, settings, plugins or sites.\nCreating and resetting run in the background: the table shows how each one is going."),
        ]);
    }

    /**
     * POST /admin/sites/create: package, slug, name.
     *
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');
        $result = $this->demos->queueCreate([
            'package' => $request->str('package'), 'slug' => $request->str('slug'), 'name' => $request->str('name'),
            'public_admin' => $request->str('public_admin') === '1',
        ]);
        if (!empty($result['ok'])) {
            Admin::audit($this->app, 'sites.create', 'site', (string) $result['id'], 'Demo requested');
            Admin::flash($this->app, 'ok', t('The demo is being built. Its state is in the table.'));
        } else {
            Admin::flash($this->app, 'bad', t('The demo was not created: {error}', ['error' => (string) $result['error']]));
        }

        return Response::redirect($this->app->url()->plain('/admin/sites'));
    }

    /**
     * POST /admin/sites/reset/{id}
     *
     * @param array<string,string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        $result = $this->demos->queueReset($id);
        if (!empty($result['ok'])) {
            Admin::audit($this->app, 'sites.reset', 'site', $id, 'Demo reset requested');
            Admin::flash($this->app, 'ok', t('The demo is being reset. Its state is in the table.'));
        } else {
            Admin::flash($this->app, 'bad', t('The demo was not reset: {error}', ['error' => (string) $result['error']]));
        }

        return Response::redirect($this->app->url()->plain('/admin/sites'));
    }

    /**
     * POST /admin/sites/delete/{id}
     *
     * @param array<string,string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        $result = $this->demos->delete($id);
        if (!empty($result['ok'])) {
            Admin::audit($this->app, 'sites.delete', 'site', $id, 'Demo deleted');
            Admin::flash($this->app, 'ok', t('The demo was deleted.'));
        } else {
            Admin::flash($this->app, 'bad', t('The demo was not deleted: {error}', ['error' => (string) $result['error']]));
        }

        return Response::redirect($this->app->url()->plain('/admin/sites'));
    }

    /**
     * GET /admin/sites/open/{id}: «Abrir panel». Pone al día en esa demostración las cuentas de los administradores de este
     * sitio (ver Owners) y lleva a su panel: quien lo pulsa entra allí con su mismo correo y su misma contraseña, como
     * administrador. Un sitio que no es una demostración se abre sin tocar nada.
     *
     * @param array<string,string> $params
     */
    public function open(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        foreach ($this->demos->sites() as $site) {
            if ($site['id'] !== $id || empty($site['exists']) || (string) $site['admin_url'] === '') {
                continue;
            }
            if (!empty($site['demo']) && empty($site['master'])) {
                $result = (new Owners($this->app))->syncSite($id);
                if (!$result['ok']) {
                    Admin::flash($this->app, 'warn', t('The panel of {site} could not be prepared for your account: {error}', ['site' => $id, 'error' => $result['error']]));

                    return Response::redirect($this->app->url()->plain('/admin/sites'));
                }
            }

            return Response::redirect((string) $site['admin_url']);
        }
        Admin::flash($this->app, 'bad', t('That site does not exist.'));

        return Response::redirect($this->app->url()->plain('/admin/sites'));
    }

    /**
     * POST /admin/sites/settings: el interruptor del restablecimiento de cada noche.
     *
     * @param array<string,string> $params
     */
    public function settings(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('sites.manage');
        $on = $request->int('nightly', 0) === 1;
        $this->app->settings()->set(Demos::NIGHTLY, $on);
        Admin::audit($this->app, 'sites.settings', 'setting', Demos::NIGHTLY, $on ? 'Nightly reset on' : 'Nightly reset off');
        // El acceso al panel en la página pública de demostraciones: solo cambia si el formulario lo trae (el campo oculto
        // viaja siempre con el formulario del panel; una petición antigua sin él no lo toca).
        if ($request->input('show_panel') !== null) {
            $show = $request->int('show_panel', 0) === 1;
            if ($show !== $this->app->settings()->bool(Demos::SHOW_PANEL, false)) {
                $this->app->settings()->set(Demos::SHOW_PANEL, $show);
                Admin::audit($this->app, 'sites.settings', 'setting', Demos::SHOW_PANEL, $show ? 'Panel access shown on the demos page' : 'Panel access hidden on the demos page');
                $this->app->invalidate(['sites']);
            }
        }
        Admin::flash($this->app, 'ok', $on ? t('The demos will be reset every night.') : t('The demos will not be reset every night.'));

        return Response::redirect($this->app->url()->plain('/admin/sites'));
    }
}
