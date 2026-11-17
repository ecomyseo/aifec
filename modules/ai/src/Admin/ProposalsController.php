<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Ai\AiException;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Ai\AiAdmin;
use Aifec\Modules\Ai\Ai;
use Aifec\Modules\Ai\Diff;
use Aifec\Modules\Ai\Generators;
use Aifec\Modules\Ai\Proposals;

/**
 * La bandeja de propuestas del panel: listado con filtros, la comparación lado a lado (actual / propuesto, con las diferencias
 * por palabras), editar el texto propuesto antes de aprobar, aprobar, rechazar y aprobar varias a la vez. También recibe el botón
 * «Proponer con IA» de la ficha de producto. Nada se escribe en la tienda sin que una persona apruebe.
 */
final class ProposalsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function proposals(): Proposals
    {
        /** @var Ai $ai */
        $ai = $this->app->service('ai');

        return $ai->proposals();
    }

    /** Ver la bandeja: quien puede ver el gasto o quien puede usar la IA. */
    private function canView(): void
    {
        $auth = $this->app->auth();
        if (!$auth->can('ai.view') && !$auth->can('ai.use')) {
            throw HttpError::forbidden('Missing permission: ai.use');
        }
    }

    private function canUse(): void
    {
        $this->app->auth()->requireCap('ai.use');
    }

    // ------------------------------------------------------------------ bandeja

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $this->canView();
        $table = new Table($app, $this->listConfig());
        $counts = $this->counts();
        $filters = $request->get('f', []);
        $filters = is_array($filters) ? $filters : [];
        $clean = $request->str('q') === '' && count($filters) <= 1;
        $tab = static function (string $value) use ($filters, $clean): bool {
            return $clean && count($filters) === 1 && isset($filters['status']) && (string) $filters['status'] === $value;
        };
        $tabs = [
            ['label' => t('Pending'), 'url' => $app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']]), 'count' => $counts['pending'], 'kind' => 'warn', 'active' => $tab('pending')],
            ['label' => t('Approved'), 'url' => $app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'approved']]), 'count' => $counts['approved'], 'kind' => 'ok', 'active' => $tab('approved')],
            ['label' => t('Rejected'), 'url' => $app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'rejected']]), 'count' => $counts['rejected'], 'kind' => '', 'active' => $tab('rejected')],
            ['label' => t('All'), 'url' => $app->url()->plain('/admin/ai/proposals'), 'count' => $counts['all'], 'kind' => '', 'active' => $clean && $filters === []],
        ];

        return Admin::page($app, 'ai::admin/proposals', ['tabs' => $tabs, 'table' => $table->render($request)], [
            'title' => t('AI proposals'),
            'active' => '/admin/ai/proposals',
            'help' => Admin::helpText("The «AI» menu is your writing helper: it drafts texts for you to approve and never talks to customers. The chat that answers customers is «Assistant»; «Copilot» does tasks in this panel for you.
The AI proposes and you approve: nothing it writes reaches the shop until you press Approve.\nOpen a proposal to compare it with the current text, correct it and approve it. You can also select several and approve them together.\nA new proposal for the same field and language replaces the one that was waiting."),
        ]);
    }

    /** @param array<string,string> $params id */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $this->canView();
        $proposal = $this->proposal((int) $params['id']);
        $field = (string) $proposal['field'];
        $diff = Diff::render(Proposals::display($field, (string) $proposal['current_text']), Proposals::display($field, (string) $proposal['proposed_text']));
        $base = '/admin/ai/proposals/' . (int) $proposal['id'];

        return Admin::page($app, 'ai::admin/proposal', [
            'proposal' => $proposal,
            'diff' => $diff,
            'target' => $this->targetName($proposal),
            'targetUrl' => $this->targetUrl($proposal),
            'fieldLabel' => AiAdmin::fieldLabel($field),
            'canUse' => $app->auth()->can('ai.use'),
            'urls' => [
                'save' => $app->url()->plain($base . '/save'),
                'approve' => $app->url()->plain($base . '/approve'),
                'reject' => $app->url()->plain($base . '/reject'),
            ],
        ], [
            'title' => t('Proposal for {field}', ['field' => t(AiAdmin::fieldLabel($field))]),
            'crumbs' => [[t('AI proposals'), $app->url()->plain('/admin/ai/proposals')]],
            'active' => '/admin/ai/proposals',
        ]);
    }

    // ------------------------------------------------------------------ acciones

    /** @param array<string,string> $params id */
    public function save(Request $request, array $params): Response
    {
        $this->canUse();
        $proposal = $this->proposal((int) $params['id']);
        try {
            $this->proposals()->edit((int) $proposal['id'], $request->str('text'));
            Admin::audit($this->app, 'edit', 'ai_proposal', (string) $proposal['id'], 'Proposal edited');
            Admin::flash($this->app, 'ok', t('The text is saved. It is not published until you approve it.'));
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t('The text cannot be saved: {reason}', ['reason' => t($e->getMessage())]));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/proposals/' . (int) $proposal['id']));
    }

    /** @param array<string,string> $params id */
    public function approve(Request $request, array $params): Response
    {
        $this->canUse();
        $proposal = $this->proposal((int) $params['id']);
        $id = (int) $proposal['id'];
        $edited = $request->input('text') !== null ? $request->str('text') : null;
        try {
            $result = $this->proposals()->approve($id, $edited);
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t('The text cannot be saved: {reason}', ['reason' => t($e->getMessage())]));

            return Response::redirect($this->app->url()->plain('/admin/ai/proposals/' . $id));
        }
        if ($result === Proposals::OK) {
            Admin::audit($this->app, 'approve', 'ai_proposal', (string) $id, 'Proposal approved');
            Admin::flash($this->app, 'ok', t('Approved and written to the shop.'));

            return Response::redirect($this->app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']]));
        }
        Admin::flash($this->app, $result === Proposals::NOBODY_APPLIES ? 'warn' : 'info', $result === Proposals::NOBODY_APPLIES
            ? t('No module can apply this proposal yet, so it stays pending.')
            : t('This proposal was already resolved.'));

        return Response::redirect($this->app->url()->plain('/admin/ai/proposals/' . $id));
    }

    /** @param array<string,string> $params id */
    public function reject(Request $request, array $params): Response
    {
        $this->canUse();
        $proposal = $this->proposal((int) $params['id']);
        if ($this->proposals()->reject((int) $proposal['id'])) {
            Admin::audit($this->app, 'reject', 'ai_proposal', (string) $proposal['id'], 'Proposal rejected');
            Admin::flash($this->app, 'ok', t('Rejected. Nothing was changed in the shop.'));
        } else {
            Admin::flash($this->app, 'info', t('This proposal was already resolved.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']]));
    }

    /** @param array<string,string> $params */
    public function bulk(Request $request, array $params): Response
    {
        $this->canUse();
        $action = $request->str('action');
        $ids = array_values(array_filter(array_map('intval', $request->arr('ids')), static function (int $id): bool {
            return $id > 0;
        }));
        $approved = 0;
        $stuck = 0;
        $rejected = 0;
        foreach ($ids as $id) {
            if ($action === 'approve') {
                try {
                    $result = $this->proposals()->approve($id);
                } catch (\InvalidArgumentException $e) {
                    $result = Proposals::NOBODY_APPLIES;
                }
                if ($result === Proposals::OK) {
                    ++$approved;
                    Admin::audit($this->app, 'approve', 'ai_proposal', (string) $id, 'Proposal approved');
                } elseif ($result === Proposals::NOBODY_APPLIES) {
                    ++$stuck;
                }
            } elseif ($action === 'reject' && $this->proposals()->reject($id)) {
                ++$rejected;
                Admin::audit($this->app, 'reject', 'ai_proposal', (string) $id, 'Proposal rejected');
            }
        }
        if ($action === 'approve') {
            Admin::flash($this->app, $stuck > 0 ? 'warn' : 'ok', tn('{n} proposal approved', '{n} proposals approved', $approved)
                . ($stuck > 0 ? ' · ' . tn('{n} could not be applied and stays pending', '{n} could not be applied and stay pending', $stuck) : ''));
        } else {
            Admin::flash($this->app, 'ok', tn('{n} proposal rejected', '{n} proposals rejected', $rejected));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']]));
    }

    /**
     * El botón «Proponer con IA» de la ficha de producto: pide el texto a la IA, deja la propuesta pendiente y lleva a la bandeja.
     *
     * @param array<string,string> $params
     */
    public function propose(Request $request, array $params): Response
    {
        $app = $this->app;
        $this->canUse();
        $productId = $request->int('product_id');
        $task = $request->str('task');
        // Una página de contenido: su título o su descripción para buscadores (14/11/2026).
        $pageId = $request->int('page_id');
        if ($pageId > 0 && in_array($task, Generators::PAGE_TASKS, true)) {
            $back = Admin::resourceUrl($app, 'pages', $pageId);
            try {
                $created = (new Generators($app))->run($task, $pageId, ['lang' => $request->str('lang')]);
            } catch (AiException $e) {
                Admin::flash($app, 'bad', t('The AI could not write it: {reason}', ['reason' => t($e->getMessage())]));

                return Response::redirect($back);
            } catch (\InvalidArgumentException $e) {
                Admin::flash($app, 'bad', t('The AI could not write it: {reason}', ['reason' => t($e->getMessage())]));

                return Response::redirect($back);
            }
            Admin::audit($app, 'propose', 'page', (string) $pageId, 'AI proposal: ' . $task);
            Admin::flash($app, 'ok', tn('{n} proposal is waiting for your approval.', '{n} proposals are waiting for your approval.', 1));

            return Response::redirect($app->url()->plain('/admin/ai/proposals/' . $created));
        }
        $back = $productId > 0 ? Admin::resourceUrl($app, 'products', $productId) : $app->url()->plain('/admin/ai/proposals');
        if (!in_array($task, Generators::TASKS, true) || $productId <= 0) {
            Admin::flash($app, 'bad', t('Choose what the AI should write.'));

            return Response::redirect($back);
        }
        $generators = new Generators($app);
        $options = ['lang' => $request->str('lang')];
        if ($task === 'translate') {
            $options['field'] = $request->str('field');
            $options['to'] = $request->str('to');
        }
        $created = [];
        try {
            if ($task === 'alt') {
                foreach ($this->imageIds($productId) as $mediaId) {
                    $created[] = $generators->run('alt', $mediaId, $options + ['product_id' => $productId]);
                }
                if ($created === []) {
                    Admin::flash($app, 'info', t('This product has no images.'));

                    return Response::redirect($back);
                }
            } else {
                $created[] = $generators->run($task, $productId, $options);
            }
        } catch (AiException $e) {
            Admin::flash($app, 'bad', t('The AI could not write it: {reason}', ['reason' => t($e->getMessage())]));

            return Response::redirect($back);
        } catch (\InvalidArgumentException $e) {
            Admin::flash($app, 'bad', t('The AI could not write it: {reason}', ['reason' => t($e->getMessage())]));

            return Response::redirect($back);
        }
        Admin::audit($app, 'propose', 'product', (string) $productId, 'AI proposal: ' . $task);
        Admin::flash($app, 'ok', tn('{n} proposal is waiting for your approval.', '{n} proposals are waiting for your approval.', count($created)));

        return Response::redirect(count($created) === 1
            ? $app->url()->plain('/admin/ai/proposals/' . $created[0])
            : $app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']]));
    }

    // ------------------------------------------------------------------ interno

    /**
     * @return array<string,mixed>
     */
    private function proposal(int $id): array
    {
        $proposal = $this->proposals()->find($id);
        if ($proposal === null) {
            throw HttpError::notFound();
        }

        return $proposal;
    }

    /**
     * Las imágenes del producto (hasta diez), en el orden de su galería.
     *
     * @return int[]
     */
    private function imageIds(int $productId): array
    {
        $product = $this->app->has('catalog') ? $this->app->service('catalog')->product($productId, null, true) : null;
        $ids = [];
        foreach (is_array($product) && isset($product['images']) && is_array($product['images']) ? $product['images'] : [] as $image) {
            if (is_array($image) && isset($image['id']) && (int) $image['id'] > 0) {
                $ids[] = (int) $image['id'];
            }
        }

        return array_slice($ids, 0, 10);
    }

    /**
     * @return array{pending:int,approved:int,rejected:int,all:int}
     */
    private function counts(): array
    {
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ($this->app->db()->select('SELECT status, COUNT(*) AS n FROM {ai_proposals} GROUP BY status') as $row) {
            $status = (string) $row['status'];
            if (isset($out[$status])) {
                $out[$status] = (int) $row['n'];
            }
            $out['all'] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $proposal
     */
    private function targetName(array $proposal): string
    {
        $db = $this->app->db();
        $id = (int) $proposal['entity_id'];
        if ((string) $proposal['entity'] === 'product' && $db->tableExists('product_i18n')) {
            $name = $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? ORDER BY id', [$id]);

            return is_string($name) && $name !== '' ? $name : '#' . $id;
        }
        if ((string) $proposal['entity'] === 'media' && $db->tableExists('media')) {
            $name = $db->value('SELECT filename FROM {media} WHERE id = ?', [$id]);

            return is_string($name) && $name !== '' ? $name : '#' . $id;
        }

        return (string) $proposal['entity'] . ' #' . $id;
    }

    /**
     * @param array<string,mixed> $proposal
     */
    private function targetUrl(array $proposal): string
    {
        if ((string) $proposal['entity'] === 'product') {
            return Admin::resourceUrl($this->app, 'products', (int) $proposal['entity_id']);
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function listConfig(): array
    {
        $app = $this->app;
        $hasProducts = $app->db()->tableExists('product_i18n');
        $join = $hasProducts ? " LEFT JOIN {product_i18n} pi ON pi.product_id = p.entity_id AND p.entity = 'product' AND pi.lang = ?" : '';
        $user = $app->auth()->can('ai.use');

        return [
            'from' => '{ai_proposals} p' . $join,
            'params' => $hasProducts ? [$app->defaultLang()] : [],
            'select' => 'p.id, p.entity, p.entity_id, p.field, p.lang, p.status, p.model, p.task, p.created_at' . ($hasProducts ? ', pi.name AS target_name' : ", '' AS target_name"),
            'key' => 'id',
            'key_sql' => 'p.id',
            'search' => $hasProducts ? ['pi.name', 'p.field', 'p.model'] : ['p.field', 'p.model'],
            'filters' => [
                'entity' => ['label' => 'Entity', 'column' => 'p.entity', 'options' => ['product' => 'Product', 'media' => 'Image', 'page' => 'Page', 'post' => 'Post']],
                'field' => ['label' => 'Field', 'column' => 'p.field', 'options' => [
                    'name' => 'Name', 'summary' => 'Summary', 'description' => 'Description', 'facts' => 'Key facts', 'faqs' => 'Frequently asked questions',
                    'meta_title' => 'Meta title', 'meta_description' => 'Meta description', 'alt' => 'Alternative text',
                ]],
                'status' => ['label' => 'Status', 'column' => 'p.status', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
            ],
            'sort' => ['p.id', 'desc'],
            'per_page' => 25,
            'bulk' => $user ? [
                'approve' => ['label' => 'Approve', 'confirm' => 'Approve the selected proposals? They will be written to the shop.'],
                'reject' => ['label' => 'Reject', 'confirm' => 'Reject the selected proposals?'],
            ] : [],
            'bulk_url' => $app->url()->plain('/admin/ai/proposals/bulk'),
            'empty' => ['title' => 'No proposals yet', 'text' => 'Ask the AI for one from a product page, or run a batch.'],
            'row_url' => static function (array $row) use ($app): string {
                return $app->url()->plain('/admin/ai/proposals/' . (int) $row['id']);
            },
            'columns' => [
                'created_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => 'p.created_at', 'hide_sm' => true],
                'target_name' => ['label' => 'Text of', 'link' => true, 'render' => static function (array $row, App $application): string {
                    $name = (string) $row['target_name'];

                    return e($name !== '' ? $name : (string) $row['entity'] . ' #' . (int) $row['entity_id']);
                }],
                'field' => ['label' => 'Field', 'render' => static function (array $row, App $application): string {
                    return e(t(AiAdmin::fieldLabel((string) $row['field'])));
                }],
                'lang' => ['label' => 'Language', 'hide_sm' => true, 'render' => static function (array $row, App $application): string {
                    return e((string) $row['lang'] !== '' ? strtoupper((string) $row['lang']) : '—');
                }],
                'status' => ['label' => 'Status', 'type' => 'badge', 'map' => [
                    'pending' => ['Pending', 'warn'], 'approved' => ['Approved', 'ok'], 'rejected' => ['Rejected', '']],
                ],
                'model' => ['label' => 'Model', 'hide_sm' => true],
            ],
        ];
    }
}
