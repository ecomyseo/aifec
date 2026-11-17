<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El asistente de migración del panel: origen → conexión → qué traer → equivalencias → simulacro → confirmar → en curso
 * → informe. Cada paso es su propia pantalla; todas piden el permiso «import.run».
 */
final class ImportController
{
    /** Pasos del asistente: clave => texto (en inglés, se enseña con t()). */
    public const STEPS = [
        'source' => 'Source', 'connection' => 'Connection', 'types' => 'What to bring', 'equivalences' => 'Equivalences',
        'simulation' => 'Simulation', 'confirm' => 'Confirm', 'progress' => 'In progress', 'report' => 'Result',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ histórico y origen

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->allow();
        $rows = [];
        foreach ($this->service()->runs()->recent(100) as $run) {
            $progress = $this->service()->report()->progress($run);
            $run['_totals'] = $progress['totals'];
            $rows[] = $run;
        }

        return Admin::page($this->app, 'import::admin/history', ['runs' => $rows, 'open' => $this->service()->runs()->open()], [
            'title' => t('Migration'),
            'active' => '/admin/import',
            'actions' => '<a class="af-btn af-btn-primary" href="' . e($this->app->url()->plain('/admin/import/new')) . '">' . Admin::icon('plus') . e(t('New migration')) . '</a>',
            'help' => Admin::helpText("A migration brings a whole shop here: settings, taxes, shipping, catalogue, customers, orders and content.\nNothing is sent to anybody: no email is composed and the payment methods stay switched off.\nYou can repeat a migration: what is already here is matched, not duplicated."),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function source(Request $request, array $params): Response
    {
        $this->allow();

        return $this->page('source', 0, 'import::admin/source', [
            'readers' => $this->service()->registry()->readers(), 'open' => $this->service()->runs()->open(),
        ], t('Where does the shop come from?'));
    }

    /**
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->allow();
        $key = $request->str('reader');
        $card = null;
        foreach ($this->service()->registry()->readers() as $id => $candidate) {
            if ($id === $key) {
                $card = $candidate;
            }
        }
        if ($card === null) {
            Admin::flash($this->app, 'bad', t('Choose where the shop comes from.'));

            return Response::redirect($this->app->url()->plain('/admin/import/new'));
        }
        $id = $this->service()->createDraft((string) $card['source'], (string) $card['mode'], [], [], [], [
            'label' => mb_substr($request->str('label'), 0, 120) !== '' ? mb_substr($request->str('label'), 0, 120) : null,
            'user_id' => $this->app->auth()->adminId(),
        ]);
        Admin::audit($this->app, 'import.create', 'import', (string) $id, 'Migration started: ' . $card['source'] . ' (' . $card['mode'] . ')');

        return Response::redirect($this->app->url()->plain('/admin/import/' . $id . '/connection'));
    }

    // ------------------------------------------------------------------ conexión

    /**
     * @param array<string,string> $params
     */
    public function connection(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }

        return $this->connectionPage($run, [], null, null);
    }

    /**
     * @param array<string,string> $params
     */
    public function saveConnection(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $card = $this->card($run);
        $conns = $this->service()->connections();
        $saved = $conns->load((int) $run['id']);
        $normal = $conns->normalize($card, $request->post, $saved !== null ? $saved['config'] : null);
        $errors = $normal['errors'];
        $db = [];
        if ($run['mode'] === 'db') {
            $dbNormal = $conns->normalizeDb($request->post, $saved !== null ? $saved['db'] : null);
            $db = $dbNormal['db'];
            $errors += $dbNormal['errors'];
        }
        if ($errors !== []) {
            return $this->connectionPage($run, $errors, null, $request->post);
        }
        $conns->save((int) $run['id'], $normal['config'], $db);
        $this->service()->runs()->update((int) $run['id'], ['source_key' => $this->service()->sourceKey((string) $run['mode'], $normal['config'], $db)]);
        Admin::audit($this->app, 'import.connection', 'import', (string) $run['id'], 'Connection details saved');

        if ($request->bool('probe')) {
            $fresh = $this->service()->runs()->find((int) $run['id']);

            return $this->connectionPage($fresh !== null ? $fresh : $run, [], $this->probeResult((int) $run['id']), null);
        }

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/types'));
    }

    // ------------------------------------------------------------------ qué traer

    /**
     * @param array<string,string> $params
     */
    public function types(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $rows = [];
        $error = null;
        try {
            $reader = $this->service()->connections()->build($run);
            $available = $reader->types();
            $chosen = isset($run['options']['types']) && is_array($run['options']['types']) ? $run['options']['types'] : null;
            foreach (Types::ORDER as $type) {
                $why = null;
                if (!in_array($type, $available, true)) {
                    $why = ['text' => 'This source does not provide this type', 'vars' => []];
                } else {
                    $why = $this->service()->registry()->unavailable($type);
                }
                $count = null;
                if ($why === null) {
                    try {
                        $count = $reader->count($type);
                    } catch (SourceError $e) {
                        $count = null;
                    }
                }
                $rows[] = [
                    'type' => $type, 'label' => Types::labels()[$type], 'count' => $count, 'why' => $why, 'personal' => in_array($type, Types::PERSONAL, true),
                    'checked' => $why === null && ($chosen === null ? !in_array($type, Types::PERSONAL, true) : in_array($type, $chosen, true)),
                ];
            }
        } catch (SourceError $e) {
            $error = Redactor::scrub($e->getMessage(), $this->service()->connections()->secrets((int) $run['id']), 191);
        }

        return $this->page('types', (int) $run['id'], 'import::admin/types', [
            'run' => $run, 'rows' => $rows, 'error' => $error, 'options' => $this->options($run),
        ], t('What do you want to bring?'));
    }

    /**
     * @param array<string,string> $params
     */
    public function saveTypes(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $types = [];
        foreach ($request->arr('types') as $type) {
            if (is_string($type) && Types::valid($type) && $this->service()->registry()->unavailable($type) === null) {
                $types[] = $type;
            }
        }
        $types = Types::sorted($types);
        $since = trim($request->str('since'));
        if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            Admin::flash($this->app, 'bad', t('Write the date as YYYY-MM-DD.'));

            return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/types'));
        }
        if ($types === []) {
            Admin::flash($this->app, 'bad', t('Choose at least one thing to bring.'));

            return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/types'));
        }
        $typed = trim($request->str('source_host'));
        $host = Runner::cleanHost($typed);
        if ($typed !== '' && $host === '') {
            Admin::flash($this->app, 'bad', t('Enter only the domain, for example shop.example'));

            return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/types'));
        }
        $options = $run['options'];
        $options['types'] = $types;
        $options['keep_urls'] = $request->bool('keep_urls');
        $options['import_images'] = $request->bool('import_images');
        $options['overwrite_local'] = $request->bool('overwrite_local');
        $options['team_admins'] = $request->bool('team_admins');
        $options['since'] = $since;
        $shop = trim($request->str('shop'));
        $options['shop'] = $shop;
        if ($host !== '') {
            $options['source_host'] = $host;
        } else {
            unset($options['source_host']);
        }
        $prefix = substr((string) preg_replace('/[^A-Za-z0-9._\-\/]/', '', trim($request->str('order_prefix'))), 0, 20);
        if ($prefix !== '') {
            $options['order_prefix'] = $prefix;
        } else {
            unset($options['order_prefix']);
        }
        $this->service()->runs()->update((int) $run['id'], ['options' => $options]);

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/equivalences'));
    }

    // ------------------------------------------------------------------ equivalencias

    /**
     * @param array<string,string> $params
     */
    public function equivalences(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $service = $this->service();
        $source = ['order_state' => [], 'tax_class' => [], 'customer_group' => [], 'payment_method' => []];
        $error = null;
        try {
            $source = $service->equivalences()->sourceValues($service->connections()->build($run));
        } catch (SourceError $e) {
            $error = Redactor::scrub($e->getMessage(), $service->connections()->secrets((int) $run['id']), 191);
        }
        $local = $service->equivalences()->localOptions();
        $chosen = $run['equivalences'] !== [] ? $run['equivalences'] : $service->equivalences()->propose($source, $local);

        return $this->page('equivalences', (int) $run['id'], 'import::admin/equivalences', [
            'run' => $run, 'source' => $source, 'local' => $local, 'chosen' => $chosen, 'error' => $error,
        ], t('How do the shops match?'));
    }

    /**
     * @param array<string,string> $params
     */
    public function saveEquivalences(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $from = $request->arr('eq_src');
        $to = $request->arr('eq_to');
        $input = [];
        foreach (Equivalences::KINDS as $kind) {
            $sources = isset($from[$kind]) && is_array($from[$kind]) ? array_values($from[$kind]) : [];
            $targets = isset($to[$kind]) && is_array($to[$kind]) ? array_values($to[$kind]) : [];
            foreach ($sources as $i => $sourceValue) {
                if (is_string($sourceValue) && isset($targets[$i]) && is_string($targets[$i])) {
                    $input[$kind][$sourceValue] = $targets[$i];
                }
            }
        }
        $clean = $this->service()->equivalences()->sanitize($input, $this->service()->equivalences()->localOptions());
        $this->service()->runs()->update((int) $run['id'], ['equivalences' => $clean]);

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/simulation'));
    }

    // ------------------------------------------------------------------ simulacro y confirmación

    /**
     * @param array<string,string> $params
     */
    public function simulation(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $sim = $this->latestSimulation((int) $run['id']);

        return $this->page('simulation', (int) $run['id'], 'import::admin/simulation', [
            'run' => $run, 'sim' => $sim, 'view' => $sim !== null ? $this->view($sim) : null,
            'open' => $this->service()->runs()->open(), 'pollUrl' => $sim !== null ? $this->app->url()->plain('/admin/import/' . $sim['id'] . '/poll') : '',
        ], t('Try it first'));
    }

    /**
     * @param array<string,string> $params
     */
    public function simulate(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $back = Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/simulation'));
        $conn = $this->service()->connections()->load((int) $run['id']);
        if ($conn === null) {
            Admin::flash($this->app, 'bad', t('The connection details are missing: enter them again.'));

            return $back;
        }
        if ($this->service()->runs()->open() !== null) {
            Admin::flash($this->app, 'warn', t('Another migration is in progress.'));

            return $back;
        }
        $options = $run['options'];
        $options['simulation_of'] = (int) $run['id'];
        $childId = $this->service()->createDraft((string) $run['source'], (string) $run['mode'], $conn['config'], $conn['db'], $options, [
            'dry_run' => true, 'equivalences' => $run['equivalences'], 'label' => 'Simulation', 'user_id' => $this->app->auth()->adminId(),
            'source_key' => (string) $run['source_key'],
        ]);
        $started = $this->service()->runner()->start($childId);
        if (!$started['ok']) {
            $this->service()->runner()->cancel($childId);
            Admin::flash($this->app, 'bad', t($started['reason']));
        } else {
            Admin::audit($this->app, 'import.simulate', 'import', (string) $run['id'], 'Simulation started');
        }

        return $back;
    }

    /**
     * @param array<string,string> $params
     */
    public function confirm(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $sim = $this->latestSimulation((int) $run['id']);

        return $this->page('confirm', (int) $run['id'], 'import::admin/confirm', [
            'run' => $run, 'sim' => $sim, 'view' => $sim !== null && $sim['status'] === 'done' ? $this->view($sim) : null,
            'open' => $this->service()->runs()->open(), 'options' => $this->options($run),
        ], t('Ready to migrate'));
    }

    /**
     * @param array<string,string> $params
     */
    public function start(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->draft($params);
        if ($run instanceof Response) {
            return $run;
        }
        $back = Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/confirm'));
        if (!$request->bool('ack')) {
            Admin::flash($this->app, 'bad', t('Tick the box to confirm that you have read it.'));

            return $back;
        }
        $started = $this->service()->runner()->start((int) $run['id']);
        if (!$started['ok']) {
            Admin::flash($this->app, 'bad', t($started['reason']));

            return $back;
        }
        Admin::audit($this->app, 'import.start', 'import', (string) $run['id'], 'Migration started');

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/progress'));
    }

    // ------------------------------------------------------------------ en curso

    /**
     * @param array<string,string> $params
     */
    public function progress(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        if ($run['status'] === 'draft') {
            return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/connection'));
        }

        return $this->page('progress', (int) $run['id'], 'import::admin/progress', [
            'run' => $run, 'view' => $this->view($run), 'pollUrl' => $this->app->url()->plain('/admin/import/' . $run['id'] . '/poll'),
        ], $this->title($run));
    }

    /**
     * Latido de la pantalla de progreso: trabaja una tanda si hace falta (así avanza aunque no haya cron) y devuelve el
     * estado.
     *
     * @param array<string,string> $params
     */
    public function poll(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        if ($run['status'] === 'running' && $this->app->has('jobs')) {
            $this->app->service('jobs')->work(5, 1, 'import');
            $fresh = $this->service()->runs()->find((int) $run['id']);
            $run = $fresh !== null ? $fresh : $run;
        }

        $view = $this->view($run);
        $view['bars_html'] = $this->app->view()->render('import::admin/bars', ['view' => $view]);
        $view['notices_html'] = $this->app->view()->render('import::admin/notices', ['view' => $view]);

        return Response::json($view);
    }

    /**
     * @param array<string,string> $params
     */
    public function pause(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        if ($this->service()->runner()->pause((int) $run['id'])) {
            Admin::audit($this->app, 'import.pause', 'import', (string) $run['id'], 'Migration paused');
            Admin::flash($this->app, 'ok', t('The migration is paused.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/progress'));
    }

    /**
     * @param array<string,string> $params
     */
    public function resume(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        if ($this->service()->runner()->resume((int) $run['id'])) {
            Admin::audit($this->app, 'import.resume', 'import', (string) $run['id'], 'Migration resumed');
            Admin::flash($this->app, 'ok', t('The migration goes on.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/progress'));
    }

    /**
     * @param array<string,string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        if ($this->service()->runner()->cancel((int) $run['id'])) {
            Admin::audit($this->app, 'import.cancel', 'import', (string) $run['id'], 'Migration cancelled');
            Admin::flash($this->app, 'ok', t('The migration is cancelled. What was already brought stays here.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/progress'));
    }

    // ------------------------------------------------------------------ informe

    /**
     * @param array<string,string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        $result = $request->str('result');
        $result = in_array($result, ['skipped', 'conflict', 'error', 'note'], true) ? $result : '';

        return $this->page('report', (int) $run['id'], 'import::admin/report', [
            'run' => $run, 'report' => $this->service()->report()->build($run), 'print' => false, 'result' => $result,
            'notes' => $this->service()->runs()->logs((int) $run['id'], '', $result, 200),
        ], t('Migration report'));
    }

    /**
     * @param array<string,string> $params
     */
    public function reportCsv(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);
        $verify = $request->str('part') === 'verify';
        $csv = $verify ? $this->service()->report()->verifyCsv($run) : $this->service()->report()->csv((int) $run['id']);
        $response = Response::text("\xEF\xBB\xBF" . $csv, 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="' . ($verify ? 'check-' : 'migration-') . (int) $run['id'] . '.csv"';
        $response->headers['Cache-Control'] = 'private, no-store';

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    public function reportPrint(Request $request, array $params): Response
    {
        $this->allow();
        $run = $this->find($params);

        return Admin::page($this->app, 'import::admin/report', [
            'run' => $run, 'report' => $this->service()->report()->build($run), 'print' => true, 'result' => '',
            'notes' => $this->service()->runs()->logs((int) $run['id'], '', '', 200),
        ], ['title' => t('Migration report'), 'bare' => true]);
    }

    // ------------------------------------------------------------------ piezas

    private function allow(): void
    {
        $this->app->auth()->requireCap('import.run');
    }

    private function service(): ImportService
    {
        /** @var ImportService $service */
        $service = $this->app->service('import');

        return $service;
    }

    /**
     * La migración de la dirección, o 404.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function find(array $params): array
    {
        $run = $this->service()->runs()->find(isset($params['id']) ? (int) $params['id'] : 0);
        if ($run === null) {
            throw HttpError::notFound();
        }

        return $run;
    }

    /**
     * Una migración en borrador; si ya empezó, manda a su pantalla de progreso o de informe.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>|Response
     */
    private function draft(array $params)
    {
        $run = $this->find($params);
        if ($run['status'] === 'draft') {
            return $run;
        }
        $to = in_array($run['status'], Runs::CLOSED, true) ? 'report' : 'progress';

        return Response::redirect($this->app->url()->plain('/admin/import/' . $run['id'] . '/' . $to));
    }

    /**
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    private function card(array $run): array
    {
        $card = $this->service()->registry()->reader((string) $run['source'], (string) $run['mode']);

        return $card !== null ? $card : ['source' => $run['source'], 'mode' => $run['mode'], 'label' => (string) $run['source'], 'help' => '', 'fields' => []];
    }

    /**
     * La pantalla de conexión, con sus errores y el resultado de «Probar».
     *
     * @param array<string,mixed>      $run
     * @param array<string,string>     $errors
     * @param array<string,mixed>|null $probe
     * @param array<string,mixed>|null $input  lo que se envió (si hubo errores)
     */
    private function connectionPage(array $run, array $errors, ?array $probe, ?array $input): Response
    {
        $saved = $this->service()->connections()->load((int) $run['id']);
        $config = $saved !== null ? $saved['config'] : [];
        $db = $saved !== null ? $saved['db'] : [];
        $card = $this->card($run);
        // Lo guardado nunca vuelve a la pantalla: de un secreto solo se sabe si hay uno.
        $values = [];
        $hasSecret = [];
        foreach ($card['fields'] as $name => $field) {
            $name = (string) $name;
            $isSecret = isset($field['type']) && $field['type'] === 'secret';
            $hasSecret[$name] = $isSecret && isset($config[$name]) && $config[$name] !== '';
            $values[$name] = $isSecret ? '' : ($input !== null && isset($input[$name]) && is_string($input[$name]) ? $input[$name] : (isset($config[$name]) ? (string) $config[$name] : ''));
        }
        $dbValues = [
            'db_host' => $input !== null && isset($input['db_host']) ? (string) $input['db_host'] : (isset($db['host']) ? (string) $db['host'] : ''),
            'db_port' => $input !== null && isset($input['db_port']) ? (string) $input['db_port'] : (isset($db['port']) ? (string) $db['port'] : '3306'),
            'db_name' => $input !== null && isset($input['db_name']) ? (string) $input['db_name'] : (isset($db['name']) ? (string) $db['name'] : ''),
            'db_user' => $input !== null && isset($input['db_user']) ? (string) $input['db_user'] : (isset($db['user']) ? (string) $db['user'] : ''),
        ];

        return $this->page('connection', (int) $run['id'], 'import::admin/connection', [
            'run' => $run, 'card' => $card, 'values' => $values, 'hasSecret' => $hasSecret, 'dbValues' => $dbValues,
            'hasDbPassword' => isset($db['password']) && $db['password'] !== '', 'errors' => $errors, 'probe' => $probe,
        ], t('How do we reach the shop?'), $errors !== [] ? 422 : 200);
    }

    /**
     * Prueba la conexión guardada: la primera lectura real y los recuentos.
     *
     * @return array<string,mixed>
     */
    private function probeResult(int $id): array
    {
        $run = $this->service()->runs()->find($id);
        $secrets = $this->service()->connections()->secrets($id);
        if ($run === null) {
            return ['ok' => false, 'message' => 'Unknown migration'];
        }
        try {
            $reader = $this->service()->connections()->build($run);
            $probe = $reader->probe();
            $counts = [];
            if (!empty($probe['ok'])) {
                foreach ($reader->types() as $type) {
                    try {
                        $counts[$type] = $reader->count($type);
                    } catch (SourceError $e) {
                        $counts[$type] = null;
                    }
                }
                $options = $run['options'];
                $options['source_languages'] = isset($probe['languages']) && is_array($probe['languages']) ? array_values($probe['languages']) : [];
                $this->service()->runs()->update($id, ['options' => $options]);
            }
            $probe['counts'] = $counts;
            $probe['message'] = Redactor::scrub((string) $probe['message'], $secrets, 300);

            return $probe;
        } catch (SourceError $e) {
            return ['ok' => false, 'message' => Redactor::scrub($e->getMessage(), $secrets, 191), 'warnings' => [], 'shops' => [], 'languages' => [], 'counts' => [], 'version' => '', 'currency' => ''];
        }
    }

    /**
     * La última prueba de simulacro de un borrador.
     *
     * @return array<string,mixed>|null
     */
    private function latestSimulation(int $draftId): ?array
    {
        foreach ($this->service()->runs()->recent(50) as $run) {
            if ((int) $run['dry_run'] === 1 && isset($run['options']['simulation_of']) && (int) $run['options']['simulation_of'] === $draftId) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Lo que la pantalla de progreso y el latido necesitan de una migración, ya traducido.
     *
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    private function view(array $run): array
    {
        $progress = $this->service()->report()->progress($run);
        $types = [];
        foreach ($progress['types'] as $row) {
            $row['label'] = t($row['label']);
            $types[] = $row;
        }
        $notices = [];
        foreach ($this->service()->runs()->lastLogs((int) $run['id'], 5) as $log) {
            $notices[] = ['type' => (string) $log['type'], 'source_id' => (string) $log['source_id'], 'result' => (string) $log['result'], 'reason' => (string) $log['reason']];
        }
        $status = (string) $run['status'];

        return [
            'ok' => true,
            'id' => (int) $run['id'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'phase' => (string) $run['phase'],
            'phase_label' => $this->phaseLabel((string) $run['phase'], $status),
            'dry_run' => (int) $run['dry_run'] === 1,
            'pause_reason' => $run['pause_reason'] !== null ? t((string) $run['pause_reason']) : '',
            'closed' => in_array($status, Runs::CLOSED, true),
            'percent' => $progress['percent'],
            'types' => $types,
            'totals' => $progress['totals'],
            'notices' => $notices,
            'report_url' => $this->app->url()->plain('/admin/import/' . $run['id'] . '/report'),
        ];
    }

    /** Lo que se está haciendo cuando ya no se escriben registros (vacío mientras se escriben o si ya acabó). */
    private function phaseLabel(string $phase, string $status): string
    {
        if ($status !== 'running') {
            return '';
        }
        if ($phase === 'finish') {
            return t('Finishing: counters, search index, caches and feeds');
        }

        return $phase === 'verify' ? t('Checking against the source') : '';
    }

    private function statusLabel(string $status): string
    {
        $labels = [
            'draft' => t('Draft'), 'running' => t('Running'), 'paused' => t('Paused'), 'done' => t('Done'), 'failed' => t('Failed'), 'cancelled' => t('Cancelled'),
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * @param array<string,mixed> $run
     */
    private function title(array $run): string
    {
        return (int) $run['dry_run'] === 1 ? t('Simulation') : t('Migration in progress');
    }

    /**
     * Las opciones de una migración con sus valores de partida.
     *
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    private function options(array $run): array
    {
        $settings = $this->app->settings();
        $o = $run['options'];
        $conn = $this->service()->connections()->load((int) $run['id']);

        return [
            'keep_urls' => isset($o['keep_urls']) ? (bool) $o['keep_urls'] : $settings->bool('import.keep_urls', true),
            'import_images' => isset($o['import_images']) ? (bool) $o['import_images'] : $settings->bool('import.import_images', true),
            'overwrite_local' => isset($o['overwrite_local']) ? (bool) $o['overwrite_local'] : $settings->bool('import.overwrite_local', false),
            'since' => isset($o['since']) && is_string($o['since']) ? $o['since'] : '',
            'shop' => isset($o['shop']) && is_string($o['shop']) ? $o['shop'] : '',
            // El dominio de la tienda antigua sale de la conexión; se puede corregir.
            'source_host' => isset($o['source_host']) && is_string($o['source_host']) && $o['source_host'] !== '' ? $o['source_host'] : ($conn !== null ? Runner::hostOf($conn['config']) : ''),
            'order_prefix' => isset($o['order_prefix']) && is_string($o['order_prefix']) ? $o['order_prefix'] : '',
            'team_admins' => !empty($o['team_admins']),
            // Cómo se escriben los precios aquí se lee del sitio (ajustes de impuestos): no se pregunta.
            'prices_include_tax_here' => $settings->bool('tax.prices_include_tax', true),
        ];
    }

    /**
     * Una pantalla del asistente dentro del marco del panel.
     *
     * @param array<string,mixed> $data
     */
    private function page(string $step, int $id, string $template, array $data, string $title, int $status = 200): Response
    {
        $data['step'] = $step;
        $data['id'] = $id;

        return Admin::page($this->app, $template, $data, [
            'title' => $title,
            'crumbs' => [[t('Migration'), $this->app->url()->plain('/admin/import')]],
            'active' => '/admin/import',
        ], $status);
    }
}
