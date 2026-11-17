<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Media;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Media\MediaException;

/**
 * Las pantallas de la biblioteca de medios: rejilla, ficha de una imagen, subida (con y sin JavaScript) y el
 * fragmento del selector que abre el guion del panel desde los campos de imagen de los formularios.
 */
final class MediaController
{
    /** Imágenes por página en la biblioteca. */
    private const PAGE_SIZE = 48;

    /** Imágenes por página en el selector (cabe en una ventana). */
    private const PICKER_SIZE = 24;

    /** Ficheros que se atienden en una sola subida. */
    private const MAX_FILES = 50;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/media: rejilla con buscador, filtro por carpeta, zona de subida y paginación.
     *
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.view');
        $library = $this->library();
        $q = trim($request->str('q'));
        $folder = trim($request->str('folder'));
        $total = $library->count($q, $folder);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, $request->int('p', 1)));

        $items = [];
        foreach ($library->search($q, $folder, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE) as $row) {
            $items[] = ['row' => $row, 'img' => $library->describe($row), 'size' => self::bytes($this->app, (int) $row['bytes'])];
        }

        return Admin::page($this->app, 'media::admin/index', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'folder' => $folder,
            'folders' => $library->folders(),
            'canEdit' => $this->app->auth()->can('media.edit'),
            'uploadUrl' => $this->app->url()->plain('/admin/media/upload'),
            'baseUrl' => $this->app->url()->plain('/admin/media'),
        ], [
            'title' => t('Media'),
            'active' => '/admin/media',
            'help' => Admin::helpText("Images are kept as uploaded and a lighter copy is made for each size the theme uses.\nThe same file uploaded twice is stored once.\nFiles are checked by what they contain, not by their name."),
        ]);
    }

    /**
     * GET /admin/media/{id}: vista previa, medidas, variantes, dirección, dónde se usa y formularios.
     *
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.view');
        $library = $this->library();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $row = $library->get($id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $variants = [];
        foreach ($row['variants'] as $name => $variant) {
            $variants[] = ['name' => (string) $name, 'url' => $this->app->url()->media($variant['path']), 'width' => $variant['width'], 'height' => $variant['height']];
        }

        return Admin::page($this->app, 'media::admin/show', [
            'row' => $row,
            'img' => $library->describe($row),
            'variants' => $variants,
            'uses' => $library->usage($id),
            'folders' => array_keys($library->folders()),
            'size' => self::bytes($this->app, (int) $row['bytes']),
            'canEdit' => $this->app->auth()->can('media.edit'),
            'saveUrl' => $this->app->url()->plain('/admin/media/' . $id),
            'deleteUrl' => $this->app->url()->plain('/admin/media/' . $id . '/delete'),
            'regenerateUrl' => $this->app->url()->plain('/admin/media/' . $id . '/regenerate'),
        ], [
            'title' => $row['filename'],
            'crumbs' => [[t('Media'), $this->app->url()->plain('/admin/media')]],
            'active' => '/admin/media',
        ]);
    }

    /**
     * POST /admin/media/{id}: guarda el texto alternativo, el título y la carpeta.
     *
     * @param array<string,string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.edit');
        $library = $this->library();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $row = $library->get($id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $data = ['alt' => $request->str('alt'), 'title' => $request->str('title'), 'folder' => $request->str('folder')];
        $library->update($id, $data);
        $after = $library->get($id);
        $changes = [];
        foreach (['alt', 'title', 'folder'] as $field) {
            if ($after !== null && $after[$field] !== $row[$field]) {
                $changes[$field] = [$row[$field], $after[$field]];
            }
        }
        Admin::audit($this->app, 'media.update', 'media', (string) $id, (string) $row['filename'], $changes);
        Admin::flash($this->app, 'ok', t('Saved.'));

        return Response::redirect($this->app->url()->plain('/admin/media/' . $id));
    }

    /**
     * POST /admin/media/{id}/delete: borra. Si está en uso hace falta «force».
     *
     * @param array<string,string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.edit');
        $library = $this->library();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $row = $library->get($id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        if (!$library->delete($id, $request->bool('force'))) {
            Admin::flash($this->app, 'warn', t('This image is in use. Confirm to delete it anyway.'));

            return Response::redirect($this->app->url()->plain('/admin/media/' . $id));
        }
        Admin::audit($this->app, 'media.delete', 'media', (string) $id, (string) $row['filename']);
        Admin::flash($this->app, 'ok', t('Image deleted.'));

        return Response::redirect($this->app->url()->plain('/admin/media'));
    }

    /**
     * POST /admin/media/{id}/regenerate: rehace las variantes.
     *
     * @param array<string,string> $params
     */
    public function regenerate(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.edit');
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($this->library()->get($id) === null) {
            throw HttpError::notFound();
        }
        try {
            $this->library()->regenerate($id);
            Admin::audit($this->app, 'media.regenerate', 'media', (string) $id, 'Variants rebuilt');
            Admin::flash($this->app, 'ok', t('The sizes were rebuilt.'));
        } catch (MediaException $e) {
            Admin::flash($this->app, 'bad', t($e->getMessage()));
        }

        return Response::redirect($this->app->url()->plain('/admin/media/' . $id));
    }

    /**
     * POST /admin/media/upload: uno o varios ficheros (`files[]`). Con JavaScript contesta JSON
     * `{ok, items: [{id, thumb, filename, width, height}], errors: [{name, message}]}`; sin él, vuelve a la rejilla con un aviso.
     *
     * @param array<string,string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.edit');
        $library = $this->library();
        $items = [];
        $errors = [];
        $files = $this->uploadedFiles($request);
        if ($files === []) {
            $errors[] = ['name' => '', 'message' => t('No file was received.')];
        }
        $userId = $this->app->auth()->adminId();
        foreach ($files as $file) {
            if ($file['error'] === 4 && $file['name'] === '') {
                continue;
            }
            if ($file['error'] !== 0) {
                $errors[] = ['name' => $file['name'], 'message' => in_array($file['error'], [1, 2], true) ? t('The file is too large') : t('The upload failed')];
                continue;
            }
            try {
                $row = $library->store($file['tmp_name'], $file['name'], ['user_id' => $userId]);
                $img = $library->describe($row);
                $items[] = ['id' => $row['id'], 'thumb' => $img['thumb'], 'filename' => $row['filename'], 'width' => $row['width'], 'height' => $row['height']];
                Admin::audit($this->app, 'media.upload', 'media', (string) $row['id'], (string) $row['filename']);
            } catch (MediaException $e) {
                $errors[] = ['name' => $file['name'], 'message' => t($e->getMessage())];
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'media.upload');
                $errors[] = ['name' => $file['name'], 'message' => t('The upload failed')];
            }
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => $errors === [] && $items !== [], 'items' => $items, 'errors' => $errors]);
        }
        if ($items !== []) {
            Admin::flash($this->app, 'ok', tn('{n} image uploaded', '{n} images uploaded', count($items)));
        }
        foreach ($errors as $error) {
            Admin::flash($this->app, 'bad', ($error['name'] !== '' ? $error['name'] . ': ' : '') . $error['message']);
        }

        return Response::redirect($this->app->url()->plain('/admin/media'));
    }

    /**
     * GET /admin/media/picker?multiple=0|1&q=&page=: el fragmento (sin marco) que enseña la ventana del selector.
     *
     * @param array<string,string> $params
     */
    public function picker(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('media.view');
        $library = $this->library();
        $q = trim($request->str('q'));
        $total = $library->count($q, '');
        $pages = max(1, (int) ceil($total / self::PICKER_SIZE));
        $page = min($pages, max(1, $request->int('page', 1)));

        $items = [];
        foreach ($library->search($q, '', self::PICKER_SIZE, ($page - 1) * self::PICKER_SIZE) as $row) {
            $img = $library->describe($row);
            $items[] = ['id' => $row['id'], 'thumb' => $img['thumb'], 'filename' => $row['filename'], 'alt' => $img['alt']];
        }
        $multiple = $request->str('multiple') === '1';
        $html = $this->app->view()->render('media::admin/picker', [
            'items' => $items,
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'multiple' => $multiple,
            'canEdit' => $this->app->auth()->can('media.edit'),
            'url' => $this->app->url()->plain('/admin/media/picker'),
            'uploadUrl' => $this->app->url()->plain('/admin/media/upload'),
        ]);
        $response = Response::html($html);
        $response->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
        $response->headers['Pragma'] = 'no-cache';
        $response->headers['Expires'] = '0';

        return $response;
    }

    /**
     * Peso legible: «512 B», «48,3 KB», «2,1 MB».
     */
    public static function bytes(App $app, int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return $app->i18n()->number($bytes / 1024, 1) . ' KB';
        }

        return $app->i18n()->number($bytes / 1048576, 1) . ' MB';
    }

    private function library(): Library
    {
        /** @var Library $library */
        $library = $this->app->service('media');

        return $library;
    }

    /**
     * Los ficheros de `files` como los entrega PHP (`files[]` = listas por campo) o como uno solo.
     *
     * @return array<int,array{name:string,tmp_name:string,error:int}>
     */
    private function uploadedFiles(Request $request): array
    {
        $raw = isset($request->files['files']) && is_array($request->files['files']) ? $request->files['files'] : [];
        $list = [];
        if (isset($raw['name']) && is_array($raw['name'])) {
            foreach ($raw['name'] as $i => $name) {
                $list[] = [
                    'name' => is_string($name) ? $name : '',
                    'tmp_name' => isset($raw['tmp_name'][$i]) && is_string($raw['tmp_name'][$i]) ? $raw['tmp_name'][$i] : '',
                    'error' => isset($raw['error'][$i]) ? (int) $raw['error'][$i] : 4,
                ];
            }
        } elseif (isset($raw['name']) && is_string($raw['name'])) {
            $list[] = [
                'name' => $raw['name'],
                'tmp_name' => isset($raw['tmp_name']) && is_string($raw['tmp_name']) ? $raw['tmp_name'] : '',
                'error' => isset($raw['error']) ? (int) $raw['error'] : 4,
            ];
        }

        return array_slice($list, 0, self::MAX_FILES);
    }
}
