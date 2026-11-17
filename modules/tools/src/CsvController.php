<?php
/**
 * AIFEC — pantallas del CSV de productos: exportar, subir, vista previa, confirmar y ver el informe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class CsvController
{
    /** Segundos que se trabaja en la propia petición cuando no hay cola de trabajos. */
    private const INLINE_SECONDS = 20;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/tools/csv
     *
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->guard();

        return Admin::page($this->app, 'tools::admin/csv', [
            'runs' => array_slice((new ImportRuns($this->app))->all(), 0, 10),
            'exportUrl' => $this->app->url()->plain('/admin/tools/csv/export'),
            'uploadUrl' => $this->app->url()->plain('/admin/tools/csv/upload'),
            'languages' => $this->app->languages(),
        ], [
            'title' => t('Products CSV'),
            'active' => '/admin/tools/csv',
            'help' => Admin::helpText("Export gives one row per product and one per variant. Edit the file in a spreadsheet and import it back.\nProducts and variants are matched by their sku: a sku that exists is changed, one that does not is created. Nothing is ever deleted.\nAn empty cell changes nothing."),
        ]);
    }

    /**
     * GET /admin/tools/csv/export
     *
     * @param array<string,string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $this->guard();
        $dir = $this->app->site()->path('tmp', true);
        $this->dropOldExports($dir);
        $path = $dir . '/products-' . bin2hex(random_bytes(6)) . '.csv';
        $rows = (new ProductExporter($this->app))->export($path);
        Admin::audit($this->app, 'tools.csv_export', 'products', '', 'Products exported', ['rows' => ['', (string) $rows]]);

        return Response::file($path, 'products-' . gmdate('Ymd', $this->app->clock()->now()) . '.csv', 'text/csv; charset=UTF-8');
    }

    /**
     * POST /admin/tools/csv/upload
     *
     * @param array<string,string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $this->guard();
        $file = isset($request->files['csv']) && is_array($request->files['csv']) ? $request->files['csv'] : [];
        $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $error = isset($file['error']) ? (int) $file['error'] : 4;
        $back = $this->app->url()->plain('/admin/tools/csv');
        if ($tmp === '' || $error !== 0) {
            Admin::flash($this->app, 'bad', $error === 1 || $error === 2 ? t('The file is too large') : t('Choose a CSV file to upload'));

            return Response::redirect($back);
        }
        try {
            $token = (new ImportRuns($this->app))->create($tmp, isset($file['name']) && is_string($file['name']) ? $file['name'] : 'products.csv');
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', $this->uploadMessage($e->getMessage()));

            return Response::redirect($back);
        }
        Admin::audit($this->app, 'tools.csv_upload', 'import', $token, 'Products file uploaded');

        return Response::redirect($this->app->url()->plain('/admin/tools/csv/' . $token));
    }

    /**
     * GET /admin/tools/csv/{token}: la vista previa si aún no ha empezado; si no, el avance y el informe.
     *
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $this->guard();
        $runs = new ImportRuns($this->app);
        $state = $runs->load(isset($params['token']) ? (string) $params['token'] : '');
        if ($state === null) {
            throw HttpError::notFound();
        }
        $meta = [
            'title' => t('Products CSV'),
            'crumbs' => [[t('Products CSV'), $this->app->url()->plain('/admin/tools/csv')]],
            'active' => '/admin/tools/csv',
        ];
        if ($state['status'] === 'uploaded') {
            return Admin::page($this->app, 'tools::admin/csv_preview', [
                'state' => $state,
                'preview' => $runs->preview((string) $state['token']),
                'runUrl' => $this->app->url()->plain('/admin/tools/csv/' . $state['token'] . '/run'),
                'cancelUrl' => $this->app->url()->plain('/admin/tools/csv/' . $state['token'] . '/cancel'),
            ], $meta);
        }

        return Admin::page($this->app, 'tools::admin/csv_report', [
            'state' => $state,
            'runUrl' => $this->app->url()->plain('/admin/tools/csv/' . $state['token'] . '/run'),
            'reportUrl' => $this->app->url()->plain('/admin/tools/csv/' . $state['token'] . '/report.csv'),
            'hasReport' => is_file($runs->reportPath((string) $state['token'])),
            'cancelUrl' => $this->app->url()->plain('/admin/tools/csv/' . $state['token'] . '/cancel'),
            'inline' => !$this->app->has('jobs'),
        ], $meta);
    }

    /**
     * POST /admin/tools/csv/{token}/run: confirma la importación. Con cola de trabajos va en segundo plano; sin ella, se trabaja
     * unos segundos en la propia petición y el botón «Continuar» sigue por donde iba.
     *
     * @param array<string,string> $params
     */
    public function run(Request $request, array $params): Response
    {
        $this->guard();
        $runs = new ImportRuns($this->app);
        $token = isset($params['token']) ? (string) $params['token'] : '';
        $state = $runs->load($token);
        if ($state === null) {
            throw HttpError::notFound();
        }
        $back = Response::redirect($this->app->url()->plain('/admin/tools/csv/' . $token));
        if ($state['status'] === 'uploaded') {
            $runs->start($token);
            Admin::audit($this->app, 'tools.csv_import', 'import', $token, 'Products import confirmed');
            $state = $runs->load($token);
        }
        if ($state === null || ($state['status'] !== 'queued' && $state['status'] !== 'running')) {
            return $back;
        }
        if ($this->app->has('jobs')) {
            if ($state['status'] === 'queued') {
                $this->app->service('jobs')->push('tools.csv_import', ['token' => $token]);
                Admin::flash($this->app, 'info', t('The import is running in the background. Reload this page to follow it.'));
            }

            return $back;
        }
        $runs->work($token, self::INLINE_SECONDS);

        return $back;
    }

    /**
     * POST /admin/tools/csv/{token}/cancel: descarta la importación (si ya está en marcha, no hace nada).
     *
     * @param array<string,string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        $this->guard();
        $runs = new ImportRuns($this->app);
        $token = isset($params['token']) ? (string) $params['token'] : '';
        $state = $runs->load($token);
        if ($state === null) {
            throw HttpError::notFound();
        }
        if ($state['status'] === 'uploaded' || $state['status'] === 'done') {
            $runs->delete($token);
            Admin::flash($this->app, 'ok', t('The import was discarded.'));
        } else {
            Admin::flash($this->app, 'warn', t('An import that is running cannot be discarded.'));

            return Response::redirect($this->app->url()->plain('/admin/tools/csv/' . $token));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/csv'));
    }

    /**
     * GET /admin/tools/csv/{token}/report.csv
     *
     * @param array<string,string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $this->guard();
        $runs = new ImportRuns($this->app);
        $token = isset($params['token']) ? (string) $params['token'] : '';
        if ($runs->load($token) === null || !is_file($runs->reportPath($token))) {
            throw HttpError::notFound();
        }

        return Response::file($runs->reportPath($token), 'import-report-' . substr($token, 0, 8) . '.csv', 'text/csv; charset=UTF-8');
    }

    // ------------------------------------------------------------------ piezas

    /** El estado de una importación, con palabras. */
    public static function statusLabel(string $status): string
    {
        switch ($status) {
            case 'uploaded':
                return t('Waiting for confirmation');
            case 'queued':
                return t('Queued');
            case 'running':
                return t('Running');
            case 'done':
                return t('Done');
            default:
                return t('Failed');
        }
    }

    /** El color de la insignia de un estado. */
    public static function statusKind(string $status): string
    {
        switch ($status) {
            case 'done':
                return 'ok';
            case 'uploaded':
                return 'warn';
            case 'queued':
            case 'running':
                return 'info';
            default:
                return 'bad';
        }
    }

    /** Permiso para editar el catálogo, y que haya catálogo. */
    private function guard(): void
    {
        $this->app->auth()->requireCap('catalog.edit');
        if (!$this->app->has('catalog')) {
            throw HttpError::notFound();
        }
    }

    private function uploadMessage(string $text): string
    {
        switch ($text) {
            case 'The file is empty':
                return t('The file is empty');
            case 'The file is too large':
                return t('The file is too large');
            case 'The file has no sku column':
                return t('The file has no sku column');
            default:
                return t('The file could not be read');
        }
    }

    /** Las exportaciones de hace más de una hora ya se descargaron: se borran. */
    private function dropOldExports(string $dir): void
    {
        $cut = $this->app->clock()->now() - 3600;
        $names = scandir($dir);
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match('/^products-[a-f0-9]{12}\.csv$/', $name) === 1 && (int) @filemtime($dir . '/' . $name) < $cut) {
                @unlink($dir . '/' . $name);
            }
        }
    }
}
