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

use Aifec\Admin\Admin;
use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Cms\Blocks\GutenbergParser;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Pantallas propias del panel de contenidos: vista previa, conversión y vista previa de bloques, restaurar una versión,
 * mensajes de formularios en CSV, direcciones no encontradas y páginas legales.
 */
final class ContentController
{
    /** Tamaño máximo del código de bloques de WordPress que se acepta pegar. */
    private const MAX_IMPORT = 2000000;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function cms(): Cms
    {
        /** @var Cms $cms */
        $cms = $this->app->service('cms');

        return $cms;
    }

    // ------------------------------------------------------------------ vista previa

    /**
     * Pinta el contenido guardado, publicado o no, con el tema; no se indexa.
     *
     * @param array<string,string> $params type (page|post), id
     */
    public function preview(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $type = isset($params['type']) ? (string) $params['type'] : '';
        if ($type !== 'page' && $type !== 'post') {
            throw HttpError::notFound();
        }
        $lang = $request->str('lang');
        $lang = in_array($lang, $this->app->languages(), true) ? $lang : $this->app->defaultLang();
        $cms = $this->cms();
        $content = $type === 'page' ? $cms->page((int) $params['id'], $lang, true) : $cms->post((int) $params['id'], $lang, true);
        if ($content === null) {
            throw HttpError::notFound();
        }
        $meta = [
            'type' => $type,
            'title' => (string) $content['title'],
            'description' => (string) $content['seo']['description'],
            'robots' => 'noindex, nofollow',
            'canonical' => (string) $content['url_abs'],
            'body_class' => 'af-preview',
        ];
        $response = Response::html($this->app->view()->render('cms::admin/preview', ['content' => $content, 'meta' => $meta, 'type' => $type, 'visible' => $cms->isVisible($content)]));
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    // ------------------------------------------------------------------ bloques

    /**
     * Convierte código de bloques de WordPress (`<!-- wp:paragraph -->…`) a los bloques del sistema.
     *
     * @param array<string,string> $params
     */
    public function importBlocks(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $content = $request->input('content');
        if (!is_string($content) || trim($content) === '') {
            throw HttpError::badRequest('The content is empty');
        }
        if (strlen($content) > self::MAX_IMPORT) {
            throw HttpError::badRequest('The content is too long');
        }

        return Response::json(['blocks' => GutenbergParser::parse($content)]);
    }

    /**
     * Pinta unos bloques como los verá la tienda (vista previa del editor).
     *
     * @param array<string,string> $params
     */
    public function renderBlocks(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $blocks = $request->input('blocks');
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true);
        }
        if (!is_array($blocks)) {
            throw HttpError::badRequest('The blocks are not valid');
        }

        return Response::json(['html' => $this->cms()->render(BlockSchema::validate($blocks))]);
    }

    // ------------------------------------------------------------------ versiones

    /**
     * Carga una versión como borrador.
     *
     * @param array<string,string> $params id de la versión
     */
    public function restore(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $db = $this->app->db();
        $row = $db->row('SELECT entity, entity_id FROM {revisions} WHERE id = ?', [(int) $params['id']]);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $type = (string) $row['entity'];
        $table = $type === 'page' ? 'pages' : 'posts';
        $current = $db->row('SELECT status FROM {' . $table . '} WHERE id = ?', [(int) $row['entity_id']]);
        if ($current !== null && $current['status'] !== 'draft') {
            // Restaurar deja el contenido como borrador: quitarlo de la tienda es una decisión de quien publica.
            $this->app->auth()->requireCap('content.publish');
        }
        $done = (new Revisions($this->app))->restore((int) $params['id']);
        if ($done === null) {
            Admin::flash($this->app, 'bad', t('This version cannot be restored'));

            return Response::redirect($this->app->url()->plain('/admin'));
        }
        Admin::audit($this->app, 'restore', $type, (string) $done['id'], 'Version ' . (int) $params['id']);
        Admin::flash($this->app, 'ok', t('Version restored as a draft'));

        return Response::redirect(Admin::resourceUrl($this->app, $type === 'page' ? 'pages' : 'posts', $done['id']));
    }

    // ------------------------------------------------------------------ mensajes

    /**
     * @param array<string,string> $params
     */
    public function exportEntries(Request $request, array $params): Response
    {
        return EntryAdmin::export($request, $this->app);
    }

    // ------------------------------------------------------------------ direcciones no encontradas

    /**
     * Lo más pedido que no existe, para crear una redirección con un clic.
     *
     * @param array<string,string> $params
     */
    public function notFound(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $rows = $this->app->db()->select('SELECT * FROM {not_found} ORDER BY hits DESC, last_at DESC LIMIT 100');

        return Admin::page($this->app, 'cms::admin/not-found', [
            'rows' => $rows,
            'canEdit' => $this->app->auth()->can('content.edit'),
            'createUrl' => $this->app->url()->plain('/admin/cms/not-found/redirect'),
            'clearUrl' => $this->app->url()->plain('/admin/cms/not-found/clear'),
        ], [
            'title' => t('Not found'),
            'active' => '/admin/cms/not-found',
            'help' => Admin::helpText('These are the addresses that visitors asked for and that do not exist, the most requested first. Write where each one has to go and press Redirect: from then on the visitor lands there.')
                . Admin::helpText('The list keeps the last 5000 addresses.'),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function createRedirect(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $path = $request->str('path');
        $problem = RedirectAdmin::createFromNotFound($this->app, $path, $request->str('target'), $request->int('code', 301));
        if ($problem === null) {
            Admin::audit($this->app, 'create', 'redirects', '', Redirects::normalize($path));
            Admin::flash($this->app, 'ok', t('Redirection created'));
        } else {
            Admin::flash($this->app, 'bad', $problem);
        }

        return Response::redirect($this->app->url()->plain('/admin/cms/not-found'));
    }

    /**
     * @param array<string,string> $params
     */
    public function clearNotFound(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $path = $request->str('path');
        if ($path !== '') {
            $this->app->db()->delete('not_found', ['path' => Redirects::normalize($path)]);
        } else {
            $this->app->db()->run('DELETE FROM {not_found}');
        }
        Admin::flash($this->app, 'ok', t('Done'));

        return Response::redirect($this->app->url()->plain('/admin/cms/not-found'));
    }

    // ------------------------------------------------------------------ páginas legales

    /**
     * @param array<string,string> $params
     */
    public function legal(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $legal = new Legal($this->app);
        $settings = $this->app->settings();
        $missing = [];
        foreach (['store.legal_name' => 'Legal name', 'store.vat_id' => 'Tax ID', 'store.email' => 'Contact email', 'store.address' => 'Address'] as $key => $label) {
            if ($settings->str($key, '') === '') {
                $missing[] = $label;
            }
        }

        return Admin::page($this->app, 'cms::admin/legal', [
            'pages' => $legal->status(),
            'missing' => $missing,
            'canEdit' => $this->app->auth()->can('content.edit'),
            'createUrl' => $this->app->url()->plain('/admin/cms/legal'),
            'settingsUrl' => $this->app->url()->plain('/admin/settings/core'),
        ], [
            'title' => t('Legal pages'),
            'active' => '/admin/cms/legal',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function createLegal(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        if ($request->bool('refresh')) {
            $done = (new Legal($this->app))->refresh();
            Admin::audit($this->app, 'update', 'pages', '', 'Legal pages rewritten: ' . implode(', ', $done));
            Admin::flash($this->app, 'ok', tn('{n} legal page rewritten', '{n} legal pages rewritten', count($done)));

            return Response::redirect($this->app->url()->plain('/admin/cms/legal'));
        }
        $created = (new Legal($this->app))->create();
        if ($created === []) {
            Admin::flash($this->app, 'info', t('All the legal pages already exist'));
        } else {
            Admin::audit($this->app, 'create', 'pages', '', 'Legal pages: ' . implode(', ', $created));
            Admin::flash($this->app, 'ok', tn('{n} page created as a draft', '{n} pages created as drafts', count($created)));
        }

        return Response::redirect($this->app->url()->plain('/admin/cms/legal'));
    }
}
