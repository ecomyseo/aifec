<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las pantallas del panel «ERP»: conexiones (con su clave, que se enseña una sola vez, y sus avisos), exportaciones, importaciones y
 * el historial de pasadas. Ver admin.php para los permisos (erp.view para mirar, erp.edit para cambiar).
 */
final class ErpController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ conexiones

    /** @param array<string,string> $params */
    public function connections(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $rows = [];
        foreach ($this->erp()->connections()->all() as $connection) {
            $rows[] = ['connection' => $connection, 'webhook' => $this->erp()->webhooks()->find((int) $connection['id']),
                'url' => $this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id'])];
        }

        return Admin::page($this->app, 'erp::admin/connections', [
            'rows' => $rows, 'canEdit' => $this->app->auth()->can('erp.edit'), 'newUrl' => $this->app->url()->plain('/admin/erp/connections/new'),
            'outbound' => $this->app->settings()->bool('erp.outbound', false), 'settingsUrl' => $this->app->url()->plain('/admin/settings/erp'),
            'apiUrl' => $this->app->url()->origin() . $this->app->url()->plain(Routes::PREFIX),
        ], [
            'title' => t('ERP connections'), 'active' => '/admin/erp',
            'help' => Admin::helpText("Each ERP that talks to the shop has its own connection and its own key.\nThe key is shown only once, when the connection is created. If it is lost, create another connection and revoke the old one.\nThe API documentation is at the address shown above and in the openapi.json file."),
        ]);
    }

    /** @param array<string,string> $params */
    public function newConnection(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');

        return $this->connectionForm($this->connectionDefinition(), [], [], 200);
    }

    /** @param array<string,string> $params */
    public function createConnection(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $form = $this->connectionDefinition();
        list($values, $errors) = $form->validate($request);
        $created = null;
        if ($errors === []) {
            try {
                $created = $this->erp()->connections()->create((string) $values['name'], isset($values['scopes']) && is_array($values['scopes']) ? $values['scopes'] : []);
            } catch (\InvalidArgumentException $e) {
                $errors[$e->getMessage() === 'Choose at least one scope' || $e->getMessage() === 'Unknown scope' ? 'scopes' : 'name'] = t($e->getMessage());
            }
        }
        if ($created === null) {
            return $this->connectionForm($form, $values, $errors, 422);
        }
        Admin::audit($this->app, 'erp.connection.create', 'erp_connection', (string) $created['id'], (string) $values['name']);

        // La clave sale en esta respuesta y en ninguna otra: no se guarda en claro en ningún sitio.
        return Admin::page($this->app, 'erp::admin/connection-key', [
            'key' => $created['key'], 'secret' => '', 'connection' => $this->erp()->connections()->find($created['id']),
            'url' => $this->app->url()->plain('/admin/erp/connections/' . $created['id']),
        ], ['title' => t('Connection created'), 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]]]);
    }

    /** @param array<string,string> $params */
    public function connection(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');

        return $this->connectionPage($this->connectionOrFail($params), null, [], 200);
    }

    /** @param array<string,string> $params */
    public function saveWebhook(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $connection = $this->connectionOrFail($params);
        $form = $this->webhookDefinition();
        list($values, $errors) = $form->validate($request);
        $secret = '';
        if ($errors === []) {
            try {
                $saved = $this->erp()->webhooks()->save((int) $connection['id'], (string) $values['url'], isset($values['events']) && is_array($values['events']) ? $values['events'] : [], !empty($values['active']));
                $secret = (string) $saved['secret'];
                Admin::audit($this->app, 'erp.webhook.save', 'erp_connection', (string) $connection['id'], (string) $values['url']);
            } catch (\InvalidArgumentException $e) {
                $errors[in_array($e->getMessage(), ['Choose at least one event', 'Unknown event'], true) ? 'events' : 'url'] = t($e->getMessage());
            }
        }
        if ($errors !== []) {
            return $this->connectionPage($connection, $values, $errors, 422);
        }
        if ($secret !== '') {
            // El secreto de la firma, igual que la clave, solo se enseña ahora.
            return Admin::page($this->app, 'erp::admin/connection-key', [
                'key' => '', 'secret' => $secret, 'connection' => $connection, 'url' => $this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']),
            ], ['title' => t('Messages to the ERP'), 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]]]);
        }
        Admin::flash($this->app, 'ok', t('Saved'));

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']));
    }

    /** @param array<string,string> $params */
    public function toggleConnection(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $connection = $this->connectionOrFail($params);
        $on = !$connection['active'];
        if ($this->erp()->connections()->setActive((int) $connection['id'], $on)) {
            Admin::audit($this->app, 'erp.connection.' . ($on ? 'enable' : 'disable'), 'erp_connection', (string) $connection['id'], (string) $connection['name']);
            Admin::flash($this->app, 'ok', $on ? t('The key works again.') : t('The key is switched off.'));
        } else {
            Admin::flash($this->app, 'bad', t('A revoked key cannot be switched on again.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']));
    }

    /** @param array<string,string> $params */
    public function revokeConnection(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $connection = $this->connectionOrFail($params);
        if ($this->erp()->connections()->revoke((int) $connection['id'])) {
            Admin::audit($this->app, 'erp.connection.revoke', 'erp_connection', (string) $connection['id'], (string) $connection['name']);
            Admin::flash($this->app, 'ok', t('The key is revoked. It will never work again.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']));
    }

    /** @param array<string,string> $params */
    public function rotateSecret(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $connection = $this->connectionOrFail($params);
        if ($this->erp()->webhooks()->find((int) $connection['id']) === null) {
            Admin::flash($this->app, 'warn', t('Set up the messages first.'));

            return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']));
        }
        $secret = $this->erp()->webhooks()->newSecret((int) $connection['id']);
        Admin::audit($this->app, 'erp.webhook.secret', 'erp_connection', (string) $connection['id'], (string) $connection['name']);

        return Admin::page($this->app, 'erp::admin/connection-key', [
            'key' => '', 'secret' => $secret, 'connection' => $connection, 'url' => $this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']),
        ], ['title' => t('Messages to the ERP'), 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]]]);
    }

    /** @param array<string,string> $params */
    public function testWebhook(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $hook = $this->app->db()->find('erp_webhooks', ['id' => (int) $params['id']]);
        if ($hook === null) {
            throw HttpError::notFound();
        }
        $result = $this->erp()->webhooks()->test((int) $hook['id']);
        Admin::flash($this->app, $result['ok'] ? 'ok' : 'bad', $result['ok'] ? t('The ERP answered {status}: the message arrived.', ['status' => (string) $result['status']])
            : t('The test failed: {error}', ['error' => t((string) $result['error'])]));

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $hook['connection_id']));
    }

    /** @param array<string,string> $params */
    public function reactivateWebhook(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $hook = $this->app->db()->find('erp_webhooks', ['id' => (int) $params['id']]);
        if ($hook === null) {
            throw HttpError::notFound();
        }
        $this->erp()->webhooks()->reactivate((int) $hook['id']);
        Admin::audit($this->app, 'erp.webhook.reactivate', 'erp_connection', (string) $hook['connection_id'], (string) $hook['url']);
        Admin::flash($this->app, 'ok', t('The messages are switched on again. Only what happens from now on is sent.'));

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $hook['connection_id']));
    }

    /** @param array<string,string> $params */
    public function deliveries(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $status = $request->str('status');
        $status = in_array($status, ['pending', 'held', 'sent', 'failed', 'expired'], true) ? $status : '';

        return Admin::page($this->app, 'erp::admin/deliveries', [
            'rows' => $this->erp()->webhooks()->recent(null, $status, 100), 'status' => $status, 'canEdit' => $this->app->auth()->can('erp.edit'),
            'listUrl' => $this->app->url()->plain('/admin/erp/deliveries'),
        ], ['title' => t('Messages to the ERP'), 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]]]);
    }

    /** @param array<string,string> $params */
    public function resendDelivery(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $delivery = $this->app->db()->find('erp_deliveries', ['id' => (int) $params['id']]);
        if ($delivery === null) {
            throw HttpError::notFound();
        }
        $result = $this->erp()->webhooks()->resend((int) $delivery['id']);
        if ($result === 'blocked') {
            Admin::flash($this->app, 'warn', t('It cannot be sent now: outgoing messages are off or the messages of this connection are paused.'));
        } else {
            Admin::audit($this->app, 'erp.delivery.resend', 'erp_connection', (string) $delivery['connection_id'], '#' . (int) $delivery['id']);
            Admin::flash($this->app, $result === 'sent' ? 'ok' : 'warn', $result === 'sent' ? t('The message was sent.') : t('The message could not be delivered yet ({result}).', ['result' => $result]));
        }

        return Response::redirect($this->app->url()->plain('/admin/erp/connections/' . (int) $delivery['connection_id']));
    }

    // ------------------------------------------------------------------ perfiles

    /** @param array<string,string> $params */
    public function profiles(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $direction = $this->direction($params);
        $label = $direction === 'import' ? t('ERP imports') : t('ERP exports');

        return Admin::page($this->app, 'erp::admin/profiles', [
            'rows' => $this->erp()->profiles()->all($direction), 'direction' => $direction, 'canEdit' => $this->app->auth()->can('erp.edit'),
            'base' => $this->app->url()->plain('/admin/erp/' . $params['dir']),
        ], [
            'title' => $label, 'active' => '/admin/erp/' . $params['dir'],
            'help' => Admin::helpText($direction === 'import'
                ? "An import reads a CSV or JSON file that the ERP leaves in a folder, at an https address or on an FTP server, and updates stock and prices by SKU.\nIt never creates or deletes products. A file with more than 20 % of bad lines is not applied at all."
                : "An export writes the orders, customers, products, stock or invoices in the format your ERP asks for and sends the file where you choose.\n«Only what is new» starts from the moment the profile is created: it never sends what already existed. The first run of an example profile only marks the starting point."),
        ]);
    }

    /** @param array<string,string> $params */
    public function profileForm(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $direction = $this->direction($params);
        $profile = null;
        $values = [];
        if (isset($params['id'])) {
            $profile = $this->erp()->profiles()->find((int) $params['id']);
            if ($profile === null || $profile['direction'] !== $direction) {
                throw HttpError::notFound();
            }
            $values = Profiles::toForm($profile);
        }

        return $this->profileScreen($direction, $profile, $values, [], 200, null, $request->bool('preview'));
    }

    /** @param array<string,string> $params */
    public function profileSave(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $direction = $this->direction($params);
        $form = new Form($this->app, ProfileForm::definition($this->app, $direction));
        list($values, $errors) = $form->validate($request);
        $id = $request->int('id') > 0 ? $request->int('id') : null;
        $profile = null;
        if ($id !== null) {
            $profile = $this->erp()->profiles()->find($id);
            if ($profile === null || $profile['direction'] !== $direction) {
                throw HttpError::notFound();
            }
        }
        if ($errors === []) {
            foreach ($this->erp()->profiles()->check($values, $direction) as $field => $message) {
                $errors[$field] = t($message);
            }
        }
        if ($errors !== []) {
            return $this->profileScreen($direction, $profile, $values, $errors, 422, null, false);
        }
        $saved = $this->erp()->profiles()->save($values, $direction, $id);
        Admin::audit($this->app, 'erp.profile.' . ($id === null ? 'create' : 'update'), 'erp_profile', (string) $saved, (string) $values['name']);
        Admin::flash($this->app, 'ok', t('Saved'));

        return Response::redirect($this->app->url()->plain('/admin/erp/' . $params['dir'] . '/' . $saved));
    }

    /** @param array<string,string> $params */
    public function profileDelete(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $profile = $this->profileOrFail($params);
        $this->app->db()->delete('erp_exports', ['id' => (int) $profile['id']]);
        (new Cursors($this->app))->forget(Cursors::scope((int) $profile['id']));
        $this->app->settings()->delete('erp.ftp.' . (int) $profile['id']);
        $this->app->settings()->delete('erp.post.' . (int) $profile['id']);
        Admin::audit($this->app, 'erp.profile.delete', 'erp_profile', (string) $profile['id'], (string) $profile['name']);
        Admin::flash($this->app, 'ok', t('Deleted'));

        return Response::redirect($this->app->url()->plain('/admin/erp/' . $params['dir']));
    }

    /** @param array<string,string> $params */
    public function profileRun(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $profile = $this->profileOrFail($params);
        $id = (int) $profile['id'];
        if ($profile['direction'] === 'import') {
            $runs = $this->erp()->importer()->run($id, ['trigger' => 'manual']);
            Admin::audit($this->app, 'erp.import.run', 'erp_profile', (string) $id, (string) $profile['name']);
            $bad = array_filter($runs, static function (array $r): bool {
                return !in_array((string) $r['status'], ['ok', 'empty'], true);
            });
            Admin::flash($this->app, $bad === [] ? 'ok' : 'warn', $bad === [] ? tn('{n} file read', '{n} files read', count($runs)) : t('Some files could not be applied. See the runs.'));

            return Response::redirect($this->app->url()->plain('/admin/erp/runs'));
        }
        $run = $this->erp()->exporter()->run($id, ['trigger' => 'manual']);
        Admin::audit($this->app, 'erp.export.run', 'erp_profile', (string) $id, (string) $profile['name']);

        return $this->afterExport($run, $params);
    }

    /** @param array<string,string> $params */
    public function profileInterval(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.edit');
        $params['dir'] = 'exports';
        $profile = $this->profileOrFail($params);
        $from = $this->day($request->str('from'), false);
        $to = $this->day($request->str('to'), true);
        if ($from === null && $to === null) {
            Admin::flash($this->app, 'warn', t('Choose at least one date.'));

            return Response::redirect($this->app->url()->plain('/admin/erp/exports/' . (int) $profile['id']));
        }
        $count = $this->erp()->exporter()->countRange((int) $profile['id'], $from, $to);
        if ($request->str('confirm') === '') {
            // Primero se enseña cuántos son y se pide confirmar esa cifra.
            return $this->profileScreen('export', $profile, Profiles::toForm($profile), [], 200, ['from' => $from, 'to' => $to, 'count' => $count, 'fromDay' => $request->str('from'), 'toDay' => $request->str('to')], false);
        }
        $run = $this->erp()->exporter()->run((int) $profile['id'], ['trigger' => 'manual', 'range' => ['from' => $from, 'to' => $to, 'confirm' => $request->int('confirm', -1)]]);
        Admin::audit($this->app, 'erp.export.interval', 'erp_profile', (string) $profile['id'], (string) $profile['name'], ['interval' => [null, (string) $from . ' / ' . (string) $to]]);

        return $this->afterExport($run, $params);
    }

    // ------------------------------------------------------------------ pasadas

    /** @param array<string,string> $params */
    public function runs(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $rows = $this->app->db()->select('SELECT r.*, p.name AS profile_name FROM {erp_export_runs} r LEFT JOIN {erp_exports} p ON p.id = r.profile_id ORDER BY r.id DESC LIMIT 100');

        return Admin::page($this->app, 'erp::admin/runs', ['rows' => $rows, 'base' => $this->app->url()->plain('/admin/erp/runs')], [
            'title' => t('ERP runs'), 'active' => '/admin/erp/runs',
            'help' => Admin::helpText("Every run of an export or an import is listed here, with how many records it moved and where they went.\nThe file of an export can be downloaded for a few days (see the settings)."),
        ]);
    }

    /** @param array<string,string> $params */
    public function run(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('erp.view');
        $row = $this->app->db()->row('SELECT r.*, p.name AS profile_name FROM {erp_export_runs} r LEFT JOIN {erp_exports} p ON p.id = r.profile_id WHERE r.id = ?', [(int) $params['id']]);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $report = json_decode((string) $row['report'], true);

        return Admin::page($this->app, 'erp::admin/run', [
            'run' => $row, 'report' => is_array($report) ? $report : [], 'canEdit' => $this->app->auth()->can('erp.edit'),
            'downloadUrl' => $this->app->url()->plain('/admin/erp/runs/' . (int) $row['id'] . '/download'),
            'available' => $this->erp()->exporter()->download((int) $row['id']) !== null,
        ], ['title' => t('Run') . ' #' . (int) $row['id'], 'active' => '/admin/erp/runs', 'crumbs' => [[t('ERP runs'), $this->app->url()->plain('/admin/erp/runs')]]]);
    }

    /** @param array<string,string> $params */
    public function runDownload(Request $request, array $params): Response
    {
        // El fichero lleva datos de clientes y de pedidos: hace falta poder cambiar la configuración del ERP.
        $this->app->auth()->requireCap('erp.edit');
        $file = $this->erp()->exporter()->download((int) $params['id']);
        if ($file === null) {
            throw HttpError::notFound();
        }
        Admin::audit($this->app, 'erp.run.download', 'erp_run', (string) (int) $params['id'], $file['name']);

        return Response::file($file['path'], $file['name'], $file['mime']);
    }

    // ------------------------------------------------------------------ piezas

    private function erp(): Erp
    {
        $erp = $this->app->service('erp');
        if (!$erp instanceof Erp) {
            throw new \LogicException('The erp service is not available');
        }

        return $erp;
    }

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function connectionOrFail(array $params): array
    {
        $connection = $this->erp()->connections()->find((int) $params['id']);
        if ($connection === null) {
            throw HttpError::notFound();
        }

        return $connection;
    }

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function profileOrFail(array $params): array
    {
        $profile = $this->erp()->profiles()->find((int) $params['id']);
        if ($profile === null || $profile['direction'] !== $this->direction($params)) {
            throw HttpError::notFound();
        }

        return $profile;
    }

    /** @param array<string,string> $params */
    private function direction(array $params): string
    {
        return isset($params['dir']) && $params['dir'] === 'imports' ? 'import' : 'export';
    }

    private function connectionDefinition(): Form
    {
        return new Form($this->app, ['fields' => [
            'name' => ['label' => 'Name', 'rules' => 'required|max:120', 'width' => 'md', 'hint' => 'For example the name of the ERP or of the person who connects it.'],
            'scopes' => ['type' => 'checks', 'label' => 'What this key can do', 'options' => Connections::SCOPES, 'default' => ['erp:orders', 'erp:products', 'erp:stock', 'erp:customers'], 'hint' => 'Give only what the ERP needs. Writing does not include reading.'],
        ]]);
    }

    private function webhookDefinition(): Form
    {
        return new Form($this->app, ['fields' => [
            'url' => ['label' => 'Address of the ERP (https)', 'rules' => 'required|max:255', 'hint' => 'The messages are POSTed here, signed with the secret of the connection.'],
            'events' => ['type' => 'checks', 'label' => 'Events', 'options' => Webhooks::EVENTS, 'default' => ['order.paid']],
            'active' => ['type' => 'toggle', 'label' => 'Send the messages', 'default' => 1],
        ]]);
    }

    /**
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     */
    private function connectionForm(Form $form, array $values, array $errors, int $status): Response
    {
        $html = $form->render($values + $form->defaults(), $errors, ['action' => $this->app->url()->plain('/admin/erp/connections/new'), 'submit' => t('Create the connection'), 'cancel' => $this->app->url()->plain('/admin/erp')]);

        return Admin::html($this->app, '<div class="af-card"><div class="af-card-body">' . $html . '</div></div>', [
            'title' => t('New ERP connection'), 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]],
        ], $status);
    }

    /**
     * @param array<string,mixed>      $connection
     * @param array<string,mixed>|null $values
     * @param array<string,string>     $errors
     */
    private function connectionPage(array $connection, ?array $values, array $errors, int $status): Response
    {
        $id = (int) $connection['id'];
        $hook = $this->erp()->webhooks()->find($id);
        $form = $this->webhookDefinition();
        $shown = $values !== null ? $values : ($hook !== null ? ['url' => $hook['url'], 'events' => $hook['events'], 'active' => $hook['active'] ? 1 : 0] : []);
        $canEdit = $this->app->auth()->can('erp.edit');
        $formHtml = $canEdit ? $form->render($shown + $form->defaults(), $errors, ['action' => $this->app->url()->plain('/admin/erp/connections/' . $id . '/webhook'), 'submit' => t('Save')]) : '';

        return Admin::page($this->app, 'erp::admin/connection', [
            'connection' => $connection, 'hook' => $hook, 'form' => $formHtml, 'canEdit' => $canEdit, 'outbound' => $this->app->settings()->bool('erp.outbound', false),
            'deliveries' => $this->erp()->webhooks()->recent($id, '', 30), 'scopes' => Connections::SCOPES, 'base' => $this->app->url()->plain('/admin/erp'),
        ], [
            'title' => (string) $connection['name'], 'active' => '/admin/erp', 'crumbs' => [[t('ERP connections'), $this->app->url()->plain('/admin/erp')]],
        ], $status);
    }

    /**
     * @param array<string,mixed>      $profile
     * @param array<string,mixed>      $values
     * @param array<string,string>     $errors
     * @param array<string,mixed>|null $interval
     */
    private function profileScreen(string $direction, ?array $profile, array $values, array $errors, int $status, ?array $interval, bool $preview): Response
    {
        $dir = $direction === 'import' ? 'imports' : 'exports';
        $form = new Form($this->app, ProfileForm::definition($this->app, $direction));
        $canEdit = $this->app->auth()->can('erp.edit');
        $html = '';
        if ($canEdit) {
            $html = $form->render($values + $form->defaults(), $errors, [
                'action' => $this->app->url()->plain('/admin/erp/' . $dir . '/save'), 'cancel' => $this->app->url()->plain('/admin/erp/' . $dir),
                'hidden' => $profile !== null ? ['id' => (int) $profile['id']] : [],
            ]);
        }
        $table = null;
        // La vista previa enseña filas reales (clientes, pedidos): solo a quien puede editar el perfil, no basta con esconder el botón.
        if ($preview && $canEdit && $profile !== null && $direction === 'export') {
            $table = $this->erp()->exporter()->preview((int) $profile['id']);
        }
        $runs = $profile === null ? [] : $this->app->db()->select('SELECT * FROM {erp_export_runs} WHERE profile_id = ? ORDER BY id DESC LIMIT 10', [(int) $profile['id']]);

        return Admin::page($this->app, 'erp::admin/profile', [
            'form' => $html, 'profile' => $profile, 'direction' => $direction, 'canEdit' => $canEdit, 'preview' => $table, 'interval' => $interval, 'runs' => $runs,
            'base' => $this->app->url()->plain('/admin/erp/' . $dir), 'runsUrl' => $this->app->url()->plain('/admin/erp/runs'),
            'outbound' => $this->app->settings()->bool('erp.outbound', false),
        ], [
            'title' => $profile !== null ? (string) $profile['name'] : ($direction === 'import' ? t('New import') : t('New export')), 'active' => '/admin/erp/' . $dir,
            'crumbs' => [[$direction === 'import' ? t('ERP imports') : t('ERP exports'), $this->app->url()->plain('/admin/erp/' . $dir)]],
        ], $status);
    }

    /**
     * @param array<string,mixed>  $run
     * @param array<string,string> $params
     */
    private function afterExport(array $run, array $params): Response
    {
        $ok = in_array((string) $run['status'], ['ok', 'empty'], true);
        $text = (string) $run['status'] === 'ok' ? tn('{n} record exported.', '{n} records exported.', (int) $run['records'])
            : ((string) $run['status'] === 'empty' ? t('There was nothing to export.') : t('The export did not finish: {error}', ['error' => t((string) ($run['error'] ?? ''))]));
        Admin::flash($this->app, $ok ? 'ok' : 'bad', $text);

        return Response::redirect($this->app->url()->plain((int) ($run['id'] ?? 0) > 0 ? '/admin/erp/runs/' . (int) $run['id'] : '/admin/erp/exports'));
    }

    /** «2026-11-12» → «2026-11-12 00:00:00» (o «23:59:59» si es el final). null si no es una fecha. */
    private function day(string $text, bool $end): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1 || strtotime($text . ' UTC') === false) {
            return null;
        }

        return $text . ($end ? ' 23:59:59' : ' 00:00:00');
    }
}
