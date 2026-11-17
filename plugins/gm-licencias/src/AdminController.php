<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las acciones de la ficha de una licencia en el panel: revocar, reactivar, ampliar las actualizaciones, reemitir la clave
 * firmada y quitar un dominio. Todas piden el permiso «gmlic.edit» (el intermediario «admin» ya exige sesión, origen propio y
 * ficha), apuntan lo que hacen en el registro de actividad y vuelven a la ficha.
 */
final class AdminController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params id
     */
    public function revoke(Request $request, array $params): Response
    {
        $license = $this->license($params);
        $done = $this->licenses()->revoke((int) $license['id'], t('Revoked from the panel'));

        return $this->back($license, $done, 'revoke', t('The licence has been revoked.'), t('The licence was already revoked.'));
    }

    /**
     * @param array<string,string> $params id
     */
    public function reactivate(Request $request, array $params): Response
    {
        $license = $this->license($params);
        $done = $this->licenses()->reactivate((int) $license['id']);

        return $this->back($license, $done, 'reactivate', t('The licence is active again.'), t('Only a revoked licence can be reactivated.'));
    }

    /**
     * @param array<string,string> $params id
     */
    public function extend(Request $request, array $params): Response
    {
        $license = $this->license($params);
        $until = $this->licenses()->extend((int) $license['id'], max(1, min(240, $request->int('months', 12))));

        return $this->back($license, $until !== '', 'extend', t('The updates are included until {date}.', ['date' => substr($until, 0, 10)]), t('Choose a number of months.'));
    }

    /**
     * @param array<string,string> $params id
     */
    public function reissue(Request $request, array $params): Response
    {
        $license = $this->license($params);
        $result = $this->licenses()->reissue((int) $license['id'], $request->str('nif'));
        if (!$result['ok']) {
            Admin::flash($this->app, 'bad', $result['error']);
        }

        return $this->back($license, $result['ok'], 'reissue', t('The key has been signed again.'), '');
    }

    /**
     * @param array<string,string> $params id y domainId
     */
    public function removeDomain(Request $request, array $params): Response
    {
        $license = $this->license($params);
        $done = $this->licenses()->removeDomain((int) $license['id'], isset($params['domainId']) ? (int) $params['domainId'] : 0);

        return $this->back($license, $done, 'remove_domain', t('The domain has been removed. It can be activated again.'), t('That domain is not in this licence.'));
    }

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function license(array $params): array
    {
        $this->app->auth()->requireCap('gmlic.edit');
        $license = $this->licenses()->find(isset($params['id']) ? (int) $params['id'] : 0);
        if ($license === null) {
            throw HttpError::notFound();
        }

        return $license;
    }

    /**
     * @param array<string,mixed> $license
     */
    private function back(array $license, bool $done, string $action, string $okText, string $badText): Response
    {
        if ($done) {
            Admin::flash($this->app, 'ok', $okText);
            Admin::audit($this->app, 'gm_license_' . $action, 'gm_licenses', (string) $license['id'], (string) $license['product_name']);
        } elseif ($badText !== '') {
            Admin::flash($this->app, 'warn', $badText);
        }

        return Response::redirect(Admin::resourceUrl($this->app, 'gm_licenses', (int) $license['id']));
    }

    private function licenses(): Licenses
    {
        $service = $this->app->service('gm_licenses');
        if (!$service instanceof Licenses) {
            throw new \LogicException('The licences service is not the one of the plugin');
        }

        return $service;
    }
}
