<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las pantallas de «Traducciones» en el panel: la tabla de cobertura de cada idioma, la lista para editar los textos de la interfaz
 * (con buscador, filtros y de 50 en 50), la lista del contenido, el CSV de ida y vuelta, el glosario, «Aprobar todo» y los trabajos
 * de la IA. Sin el módulo de IA (o sin su permiso) los botones de IA no salen y sus direcciones contestan 404 (o 403).
 *
 * Todo funciona sin JavaScript: pestañas y paginación son enlaces, y cada acción es un formulario.
 */
final class TranslationsController
{
    /** Filas por página en las listas. */
    public const PER_PAGE = 50;

    /** La entrada del menú que se marca: el módulo no tiene entrada propia (el menú tiene un tope), cuelga de «Ajustes». */
    private const MENU = '/admin/settings';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.view');
        $service = new Translations($app);
        $langs = $service->languages();
        $lang = $request->str('lang');
        if (!in_array($lang, $langs, true)) {
            $lang = $langs !== [] ? $langs[0] : '';
        }
        $tabs = [];
        foreach ($langs as $one) {
            $tabs[] = ['code' => $one, 'url' => $app->url()->plain('/admin/translations', ['lang' => $one]), 'active' => $one === $lang];
        }
        $installed = AiGate::installed($app);

        return Admin::page($app, 'translations::admin/index', [
            'lang' => $lang,
            'default' => $app->defaultLang(),
            'tabs' => $tabs,
            'rows' => $lang !== '' ? $service->coverage($lang) : [],
            'jobs' => $lang !== '' && $installed && $app->db()->tableExists('translation_jobs') ? (new Fill($app))->recent($lang) : [],
            'glossary' => $lang !== '' ? Glossary::get($app, $lang) : '',
            'canEdit' => $app->auth()->can('translations.edit'),
            'aiInstalled' => $installed,
            'aiAvailable' => AiGate::available($app),
            'canApproveAi' => $installed && $app->auth()->can('ai.use') && $app->auth()->can('translations.edit'),
            'publish' => $app->settings()->bool('translations.publish_unreviewed', false),
            'base' => $app->url()->plain('/admin/translations'),
            'settingsUrl' => $app->url()->plain('/admin/settings'),
            'ownUrl' => $app->url()->plain('/admin/translations/' . $app->defaultLang() . '/shop'),
        ], [
            'title' => t('Translations'),
            'crumbs' => [[t('Settings'), $app->url()->plain('/admin/settings')]],
            'active' => self::MENU,
            'help' => self::help(),
        ]);
    }

    /**
     * «Ver y editar»: la lista de textos de la interfaz o la del contenido.
     *
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.view');
        $lang = $this->lang($params);
        $code = (string) $params['scope'];
        if ($code === Strings::SHOP || $code === Strings::PANEL) {
            return $this->stringsScreen($request, $lang, $code);
        }
        $scope = Scopes::get($app, $code);
        $page = max(1, $request->int('page', 1));
        $list = $scope !== null ? $scope->rows($lang, $page, self::PER_PAGE) : null;
        if ($scope === null || $list === null) {
            throw HttpError::notFound();
        }

        return Admin::page($app, 'translations::admin/content', [
            'lang' => $lang,
            'label' => $scope->label(),
            'blocked' => $scope->blocked($lang),
            'list' => $list,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'url' => $app->url()->plain('/admin/translations/' . $lang . '/' . $code),
            'proposalsUrl' => AiGate::installed($app) && $scope->approvalNeedsAi() ? $app->url()->plain('/admin/ai/proposals') : '',
        ], [
            'title' => $scope->label() . ' · ' . strtoupper($lang),
            'crumbs' => [[t('Translations'), $app->url()->plain('/admin/translations', ['lang' => $lang])]],
            'active' => self::MENU,
        ]);
    }

    /**
     * Guarda una página de la lista de textos de la interfaz. Un solo envío con varios botones: «save» guarda las filas que han
     * cambiado; «approve:N» aprueba la propuesta de la fila N (con lo escrito en su campo); «reject:N» la descarta.
     *
     * @param array<string,string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.edit');
        $lang = $this->lang($params);
        $area = (string) $params['scope'];
        if ($area !== Strings::SHOP && $area !== Strings::PANEL) {
            throw HttpError::notFound();
        }
        $back = Response::redirect($app->url()->plain('/admin/translations/' . $lang . '/' . $area, array_filter([
            'q' => $request->str('q'),
            'f' => $request->str('f'),
            'page' => $request->int('page', 1) > 1 ? (string) $request->int('page', 1) : '',
        ], static function (string $v): bool {
            return $v !== '';
        })), 303);

        $strings = Strings::inArea($app, $area);
        $keys = $request->arr('k');
        $values = $request->arr('v');
        $shown = $request->arr('o');
        $proposed = $request->arr('p');
        $action = $request->str('action', 'save');
        $only = null;
        if (preg_match('/^(approve|reject):(\d+)$/', $action, $m) === 1) {
            $only = (int) $m[2];
            $action = $m[1];
        }
        $code = Strings::codeCatalog($app, $lang);
        $site = (new SiteCatalog($app))->all($lang);
        $changes = [];
        $clearProposals = [];
        $errors = [];
        foreach ($keys as $i => $key) {
            $i = (int) $i;
            if ($only !== null && $i !== $only) {
                continue;
            }
            $key = is_string($key) ? $key : '';
            if ($key === '' || !isset($strings[$key])) {
                continue;
            }
            $value = isset($values[$i]) && is_string($values[$i]) ? Check::clean($values[$i]) : '';
            $before = isset($shown[$i]) && is_string($shown[$i]) ? Check::clean($shown[$i]) : '';
            $isProposal = isset($proposed[$i]) && (string) $proposed[$i] === '1';
            if ($action === 'reject') {
                $clearProposals[] = $key;
                continue;
            }
            if ($action === 'save' && $value === $before) {
                continue;
            }
            if ($value === '') {
                // Vacío: vuelve el texto de serie (y una propuesta que se borra queda descartada).
                if (isset($site[$key])) {
                    $changes[$key] = '';
                }
                if ($isProposal) {
                    $clearProposals[] = $key;
                }
                continue;
            }
            $problem = Check::problem($key, $value);
            if ($problem !== null) {
                $errors[] = t('"{text}" was not saved: {reason}', ['text' => mb_substr($key, 0, 60), 'reason' => Check::message($problem)]);
                continue;
            }
            $changes[$key] = isset($code[$key]) && $code[$key] === $value ? '' : $value;
            $clearProposals[] = $key;
        }
        $saved = $changes !== [] ? (new SiteCatalog($app))->update($lang, $changes) : 0;
        if ($clearProposals !== []) {
            (new Proposals($app))->remove($lang, $area, $clearProposals);
        }
        foreach (array_slice($errors, 0, 10) as $error) {
            Admin::flash($app, 'bad', $error);
        }
        if ($action === 'reject') {
            Admin::flash($app, 'ok', t('Proposal discarded.'));
        } elseif ($saved > 0 || $clearProposals !== []) {
            Admin::audit($app, 'translations.save', 'translations', $lang, $area . ': ' . $saved);
            Admin::flash($app, 'ok', tn('{n} translation saved.', '{n} translations saved.', $saved));
        } elseif ($errors === []) {
            Admin::flash($app, 'info', t('Nothing changed.'));
        }

        return $back;
    }

    /** @param array<string,string> $params */
    public function approve(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.edit');
        $lang = $this->lang($params);
        $scope = Scopes::get($app, (string) $params['scope']);
        if ($scope === null) {
            throw HttpError::notFound();
        }
        if ($scope->approvalNeedsAi()) {
            if (!AiGate::installed($app)) {
                throw HttpError::notFound();
            }
            $app->auth()->requireCap('ai.use');
        }
        $approved = $scope->approveAll($lang);
        Admin::audit($app, 'translations.approve', 'translations', $lang, $scope->code() . ': ' . $approved);
        Admin::flash($app, 'ok', tn('{n} translation approved.', '{n} translations approved.', $approved));

        return Response::redirect($app->url()->plain('/admin/translations', ['lang' => $lang]), 303);
    }

    /** @param array<string,string> $params */
    public function fill(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!AiGate::installed($app)) {
            throw HttpError::notFound();
        }
        $app->auth()->requireCap('translations.edit');
        $app->auth()->requireCap('ai.use');
        $lang = $this->lang($params);
        try {
            $admin = $app->auth()->admin();
            $id = (new Fill($app))->start($lang, (string) $params['scope'], $admin !== null ? (int) $admin['id'] : null);
            Admin::audit($app, 'translations.fill', 'translations', $lang, (string) $params['scope'] . ' #' . $id);
            Admin::flash($app, 'ok', t('The AI is translating in the background. What it proposes waits here for your approval.'));
        } catch (\InvalidArgumentException $e) {
            Admin::flash($app, 'bad', Fill::message($e->getMessage()));
        }

        return Response::redirect($app->url()->plain('/admin/translations', ['lang' => $lang]), 303);
    }

    /** @param array<string,string> $params */
    public function job(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!AiGate::installed($app)) {
            throw HttpError::notFound();
        }
        $app->auth()->requireCap('translations.edit');
        $app->auth()->requireCap('ai.use');
        $fill = new Fill($app);
        $id = (int) $params['id'];
        $job = $fill->find($id);
        if ($job === null) {
            throw HttpError::notFound();
        }
        $action = (string) $params['action'];
        $done = $action === 'pause' ? $fill->pause($id) : ($action === 'resume' ? $fill->resume($id) : $fill->cancel($id));
        Admin::flash($app, $done ? 'ok' : 'info', $done ? t('Done.') : t('Nothing changed.'));

        return Response::redirect($app->url()->plain('/admin/translations', ['lang' => (string) $job['lang']]), 303);
    }

    /** @param array<string,string> $params */
    public function glossary(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!AiGate::installed($app)) {
            throw HttpError::notFound();
        }
        $app->auth()->requireCap('translations.edit');
        $lang = $request->str('lang');
        if (!in_array($lang, $app->languages(), true)) {
            throw HttpError::badRequest();
        }
        Glossary::set($app, $lang, $request->str('glossary'));
        Admin::audit($app, 'translations.glossary', 'translations', $lang);
        Admin::flash($app, 'ok', t('Glossary saved.'));

        return Response::redirect($app->url()->plain('/admin/translations', ['lang' => $lang]), 303);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.view');
        $lang = $this->lang($params);
        $response = Response::text(Csv::export($app, $lang), 200, 'text/csv; charset=utf-8');
        $response->headers['Content-Disposition'] = 'attachment; filename="translations-' . $lang . '.csv"';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /** @param array<string,string> $params */
    public function import(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('translations.edit');
        $lang = $this->lang($params);
        $csv = $request->str('csv');
        $file = isset($request->files['file']) && is_array($request->files['file']) ? $request->files['file'] : [];
        if ($csv === '' && isset($file['tmp_name']) && is_string($file['tmp_name']) && $file['tmp_name'] !== '' && is_uploaded_file($file['tmp_name'])) {
            $read = file_get_contents($file['tmp_name'], false, null, 0, 8 * 1024 * 1024);
            $csv = is_string($read) ? $read : '';
        }
        $back = Response::redirect($app->url()->plain('/admin/translations', ['lang' => $lang]), 303);
        if (trim($csv) === '') {
            Admin::flash($app, 'bad', t('Paste the CSV or choose a file.'));

            return $back;
        }
        $result = Csv::import($app, $lang, $csv);
        Admin::audit($app, 'translations.import', 'translations', $lang, 'saved ' . $result['saved'] . ', rejected ' . count($result['rejected']));
        Admin::flash($app, 'ok', t('CSV read: {saved} saved, {unchanged} unchanged, {skipped} empty, {rejected} rejected.', [
            'saved' => $result['saved'], 'unchanged' => $result['unchanged'], 'skipped' => $result['skipped'], 'rejected' => count($result['rejected']),
        ]));
        foreach (array_slice($result['rejected'], 0, 10) as $row) {
            Admin::flash($app, 'bad', t('Line {line}: {reason}', ['line' => $row['line'], 'reason' => $row['reason']]));
        }

        return $back;
    }

    // ------------------------------------------------------------------ interior

    private function stringsScreen(Request $request, string $lang, string $area): Response
    {
        $app = $this->app;
        $q = trim($request->str('q'));
        $filter = in_array($request->str('f'), ['missing', 'proposed'], true) ? $request->str('f') : 'all';
        $page = max(1, $request->int('page', 1));
        $site = (new SiteCatalog($app))->all($lang);
        $code = Strings::codeCatalog($app, $lang);
        $pending = (new Proposals($app))->pending($lang, $area);
        $list = [];
        foreach (Strings::inArea($app, $area) as $key => $info) {
            $key = (string) $key;
            $translated = isset($site[$key]) || isset($code[$key]) || $lang === 'en';
            if ($filter === 'missing' && ($translated || isset($pending[$key]))) {
                continue;
            }
            if ($filter === 'proposed' && !isset($pending[$key])) {
                continue;
            }
            $current = isset($site[$key]) ? $site[$key] : (isset($code[$key]) ? $code[$key] : '');
            $proposal = isset($pending[$key]) ? $pending[$key]['text'] : '';
            if ($q !== '' && mb_stripos($key, $q, 0, 'UTF-8') === false && mb_stripos($current, $q, 0, 'UTF-8') === false && mb_stripos($proposal, $q, 0, 'UTF-8') === false) {
                continue;
            }
            $list[] = [
                'key' => $key,
                'origin' => $info['origin'],
                'code' => isset($code[$key]) ? $code[$key] : '',
                'site' => isset($site[$key]) ? $site[$key] : '',
                'proposal' => $proposal,
            ];
        }
        $total = count($list);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return Admin::page($app, 'translations::admin/strings', [
            'lang' => $lang,
            'area' => $area,
            'rows' => array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'filter' => $filter,
            'canEdit' => $app->auth()->can('translations.edit'),
            'url' => $app->url()->plain('/admin/translations/' . $lang . '/' . $area),
        ], [
            'title' => ($area === Strings::PANEL ? t('Interface of the panel') : t('Interface of the shop')) . ' · ' . strtoupper($lang),
            'crumbs' => [[t('Translations'), $app->url()->plain('/admin/translations', ['lang' => $lang])]],
            'active' => self::MENU,
        ]);
    }

    /**
     * El idioma de la dirección, que tiene que ser uno de la tienda.
     *
     * @param array<string,string> $params
     */
    private function lang(array $params): string
    {
        $lang = isset($params['lang']) ? (string) $params['lang'] : '';
        if (!in_array($lang, $this->app->languages(), true)) {
            throw HttpError::notFound();
        }

        return $lang;
    }

    /** La ayuda plegada de la pantalla. */
    private static function help(): string
    {
        return '<p>' . e(t('Each language of the shop has its tab. The table says how many texts there are, how many are translated and how many the AI has proposed and wait for approval.')) . '</p>'
            . '<p>' . e(t('The interface (buttons, messages) is saved in the data folder of the site and wins over the texts of the code: you can also change a sentence of the main language. Leave a field empty to go back to the default text.')) . '</p>'
            . '<p>' . e(t('Products and pages are saved where each module saves them. Categories, attributes and the body of the pages are translated in their own forms.')) . '</p>'
            . '<p>' . e(t('With the AI module on, "Translate what is missing" runs in the background, in batches, within the daily AI cap. What it writes is a proposal: it is not shown in the shop until someone approves it, unless the setting "Publish the AI translations without review" is on.')) . '</p>'
            . '<p>' . e(t('A translation that loses or invents a {marker}, or that has a different number of plural forms, is not saved.')) . '</p>';
    }
}
