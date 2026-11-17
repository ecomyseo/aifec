<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * POST /consentimiento: apunta la prueba de una decisión. Solo desde la propia web (intermediario csrf).
 *
 * Guarda lo justo y nada personal: el número al azar de la cookie, la versión de la política, las categorías, la fecha, el idioma
 * y si el navegador mandaba la señal Global Privacy Control. No guarda la dirección IP ni el navegador. La misma decisión
 * repetida no se apunta dos veces.
 */
final class ConsentController
{
    /** Decisiones que se aceptan por hora y por dirección. */
    private const HOURLY_LIMIT = 60;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /cookies: la política de cookies publicada por la tienda (la página legal «cookies»), o, si no la hay, una página con las
     * cookies que de verdad se ponen y el botón para cambiar la elección (13/11/2026: /cookies daba 404 y el aviso decía «Usamos
     * cookies» sin explicar cuáles).
     *
     * @param array<string,string> $params
     */
    public function policy(Request $request, array $params): Response
    {
        $map = $this->app->settings()->arr('cms.legal');
        $id = isset($map['cookies']) ? (int) $map['cookies'] : 0;
        if ($id > 0 && $this->app->db()->value("SELECT id FROM {pages} WHERE id = ? AND status = 'published'", [$id]) !== null) {
            $url = $this->app->url()->entity('page', $id, $this->app->lang());
            if ($url !== '' && rtrim($url, '/') !== rtrim($this->app->url()->plain('/cookies'), '/')) {
                return Response::redirect($url, 302);
            }
        }
        $service = $this->service();
        $page = ['type' => 'page', 'title' => t('Cookie policy'), 'description' => t('Which cookies this website uses, what for and for how long, and how to change your choice.')];

        return $this->app->render('consent::front/policy', [
            'page' => $page,
            'table' => $service->cookieTable(),
            'button' => $service->preferencesButton(),
            'shop' => $this->app->settings()->str('store.name', 'AIFEC'),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function record(Request $request, array $params): Response
    {
        $service = $this->service();
        if (!$service->enabled()) {
            throw HttpError::notFound();
        }
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if (!$limiter->hit('consent.record:' . $request->ip, self::HOURLY_LIMIT, 3600)) {
            throw HttpError::tooMany();
        }
        $id = strtolower($request->str('id'));
        if (preg_match('/^[a-f0-9]{12,32}$/', $id) !== 1) {
            throw HttpError::badRequest();
        }
        if ($request->int('v') !== $service->version()) {
            // Una decisión de una política que ya no es la vigente: no se apunta.
            return Response::json(['ok' => false, 'logged' => false, 'error' => 'stale'], 409);
        }
        $letters = ConsentCookie::letters($this->categoriesOf(preg_replace('/[^psm]/', '', $request->str('c'))));
        $action = $request->str('a');
        if (!in_array($action, ['all', 'none', 'custom'], true)) {
            $action = $letters === 'psm' ? 'all' : ($letters === '' ? 'none' : 'custom');
        }
        if (!$this->app->settings()->bool('consent.log', true)) {
            return Response::json(['ok' => true, 'logged' => false]);
        }
        $db = $this->app->db();
        $last = $db->row('SELECT policy_version, categories FROM {consent_log} WHERE cookie_id = ? ORDER BY id DESC', [$id]);
        if ($last !== null && (int) $last['policy_version'] === $service->version() && (string) $last['categories'] === $letters) {
            return Response::json(['ok' => true, 'logged' => false]);
        }
        $db->insert('consent_log', [
            'cookie_id' => $id,
            'policy_version' => $service->version(),
            'categories' => $letters,
            'action' => $action,
            'gpc' => $request->bool('g') ? 1 : 0,
            'lang' => $this->language($request->str('l')),
            'created_at' => $this->app->clock()->sql(),
        ]);

        return Response::json(['ok' => true, 'logged' => true]);
    }

    /**
     * Las categorías que corresponden a unas letras.
     *
     * @return string[]
     */
    private function categoriesOf(string $letters): array
    {
        $out = [];
        foreach (ConsentCookie::LETTERS as $name => $letter) {
            if (strpos($letters, $letter) !== false) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** El idioma, solo si es uno de los de la tienda (si no, vacío: nada que venga de fuera se guarda sin comprobar). */
    private function language(string $lang): string
    {
        $lang = strtolower(substr(trim($lang), 0, 8));

        return in_array($lang, $this->app->languages(), true) ? $lang : '';
    }

    private function service(): ConsentService
    {
        /** @var ConsentService $service */
        $service = $this->app->service('consent');

        return $service;
    }
}
