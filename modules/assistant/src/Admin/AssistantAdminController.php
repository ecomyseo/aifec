<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El panel del asistente: las conversaciones (sin datos personales en el listado), la ficha de una conversación con sus
 * mensajes y las herramientas usadas, y las preguntas que no supo contestar agrupadas para ver qué contenido falta.
 * Todo es de solo lectura (permiso «assistant.view»); los ajustes están en la pantalla de ajustes del módulo.
 */
final class AssistantAdminController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('assistant.view');
        $table = new Table($app, $this->listConfig());

        return Admin::page($app, 'assistant::admin/list', ['tabs' => $this->tabs('list'), 'table' => $table->render($request), 'timing' => $this->timing(), 'commercial' => \Aifec\Modules\Assistant\CommerceReport::week($app)], [
            'title' => t('Assistant'),
            'active' => '/admin/assistant',
            'help' => Admin::helpText("«Assistant» is the chat that answers your customers in the shop. «AI» drafts texts for you to approve and «Copilot» does tasks in this panel.\nEach row is a visitor who wrote to the assistant. The list shows no personal data: open a conversation to read it.\nThe outcome says how far the conversation went: the cart, the checkout or an order, with its amount.\nThe simple mode is the assistant without the AI: it answers with fixed rules and the shop data."),
        ]);
    }

    /**
     * @param array<string,string> $params id
     */
    public function answerRequest(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('assistant.reply');
        $app->auth()->requireCap('assistant.view');
        $text = trim($request->str('reply'));
        if ($text === '' || mb_strlen($text, 'UTF-8') > 1500) {
            throw HttpError::badRequest();
        }
        $id = (int) $params['id'];
        $count = $app->db()->run("UPDATE {conversations} SET handoff_reply = ?, handoff_status = 'replied', last_at = ? WHERE id = ? AND handoff_status = 'pending' AND handoff_request = ?", [
            \Aifec\Modules\Assistant\Masker::secrets($text), $app->clock()->sql(), $id, $request->int('request_id'),
        ]);
        if (!$count) { throw new HttpError(409); }
        return Response::redirect($app->url()->plain('/admin/assistant/' . $id), 303);
    }

    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('assistant.view');
        $id = (int) $params['id'];
        $conversation = $app->db()->row('SELECT * FROM {conversations} WHERE id = ?', [$id]);
        if ($conversation === null) {
            throw HttpError::notFound();
        }
        // El pedido en que acabó, si lo hubo: su número, su importe y por dónde entró (source = assistant si lo hizo el chat).
        $order = null;
        if ($conversation['order_id'] !== null && $conversation['order_id'] !== '' && $app->has('orders')) {
            $order = $app->db()->row('SELECT id, number, total, currency, source FROM {orders} WHERE id = ?', [(int) $conversation['order_id']]);
        }
        $rows = $app->db()->select('SELECT * FROM {conversation_messages} WHERE conversation_id = ? ORDER BY id', [$id]);
        $messages = [];
        foreach ($rows as $row) {
            $tools = isset($row['tools']) && (string) $row['tools'] !== '' ? json_decode((string) $row['tools'], true) : [];
            $row['tools_used'] = is_array($tools) ? $tools : [];
            $messages[] = $row;
        }

        return Admin::page($app, 'assistant::admin/show', ['conversation' => $conversation, 'messages' => $messages, 'order' => $order], [
            'title' => t('Conversation {n}', ['n' => $id]),
            'crumbs' => [[t('Assistant'), $app->url()->plain('/admin/assistant')]],
            'active' => '/admin/assistant',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function unanswered(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('assistant.view');
        $groups = $app->db()->select(
            "SELECT phrase, COUNT(*) AS n, MAX(created_at) AS last_at, MAX(conversation_id) AS conversation_id FROM {conversation_messages} WHERE unanswered = 1 AND role = 'user' AND phrase IS NOT NULL GROUP BY phrase ORDER BY n DESC, last_at DESC LIMIT 100"
        );

        return Admin::page($app, 'assistant::admin/unanswered', ['tabs' => $this->tabs('unanswered'), 'groups' => $groups, 'weekly' => $app->service('assistant')->conversations()->weeklySearches(), 'learning' => \Aifec\Modules\Assistant\SearchEvidence::suggestions($app)], [
            'title' => t('Unanswered questions'),
            'active' => '/admin/assistant/unanswered',
            'help' => Admin::helpText("«Assistant» is the chat that answers your customers in the shop. «AI» drafts texts for you to approve and «Copilot» does tasks in this panel.\nThese are the questions the assistant could not answer, grouped when they are the same. Emails and long numbers are hidden.\nThe ones that repeat tell you what content is missing from the shop: a product, a policy or a page.\nThey are deleted with their conversations after the days set in the settings."),
        ]);
    }

    // ------------------------------------------------------------------ interior

    /**
     * Cuánto tardan las respuestas con IA de los últimos 7 días: la mediana y el percentil 90 de lo que tardó la IA más lo que
     * tardaron las herramientas, en milisegundos. Solo cuentan las respuestas en las que se usó la IA (ai_ms con valor).
     *
     * @return array{n:int,median:int,p90:int}
     */
    private function timing(): array
    {
        $since = $this->app->clock()->sql($this->app->clock()->now() - 7 * 86400);
        $rows = $this->app->db()->column(
            "SELECT ai_ms + COALESCE(tools_ms, 0) FROM {conversation_messages} WHERE role = 'assistant' AND ai_ms IS NOT NULL AND created_at >= ? ORDER BY id DESC LIMIT 5000",
            [$since]
        );

        return self::summary(array_map('intval', is_array($rows) ? $rows : []));
    }

    /**
     * La mediana y el percentil 90 (el de «el más cercano por arriba»: el valor por debajo del cual está el 90 % de los demás) de
     * una lista de milisegundos.
     *
     * @param int[] $values
     *
     * @return array{n:int,median:int,p90:int}
     */
    public static function summary(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'median' => 0, 'p90' => 0];
        }
        sort($values);
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);

        return ['n' => $n, 'median' => $median, 'p90' => $values[max(0, (int) ceil(0.9 * $n) - 1)]];
    }

    /**
     * La navegación entre el listado y las preguntas sin respuesta.
     *
     * @return array<int,array<string,mixed>>
     */
    private function tabs(string $active): array
    {
        $url = $this->app->url();
        $unanswered = (int) $this->app->db()->value("SELECT COUNT(*) FROM {conversation_messages} WHERE unanswered = 1 AND role = 'user'");

        return [
            ['label' => t('Conversations'), 'url' => $url->plain('/admin/assistant'), 'count' => 0, 'active' => $active === 'list'],
            ['label' => t('Unanswered questions'), 'url' => $url->plain('/admin/assistant/unanswered'), 'count' => $unanswered, 'active' => $active === 'unanswered'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function listConfig(): array
    {
        $app = $this->app;
        // Con el módulo de pedidos presente, el listado trae el importe del pedido en que acabó la conversación.
        $orders = $app->has('orders');

        return [
            'from' => '{conversations} c' . ($orders ? ' LEFT JOIN {orders} o ON o.id = c.order_id' : ''),
            'select' => 'c.handoff_status, c.id, c.started_at, c.last_at, c.messages, c.unanswered, c.mode, c.lang, c.cart_added, c.checkout, c.order_id'
                . ($orders ? ', o.total AS order_total, o.currency AS order_currency' : ', NULL AS order_total, NULL AS order_currency'),
            'key' => 'id',
            'key_sql' => 'c.id',
            'search' => [],
            'filters' => [
                'outcome' => ['label' => 'Outcome', 'options' => [
                    'handoff' => 'Customer requests', 'order' => 'Ended in an order', 'checkout' => 'Went to the checkout', 'cart' => 'Added to the cart', 'unanswered' => 'Has unanswered questions',
                ], 'sql' => [
                    'handoff' => "c.handoff_status = 'pending'", 'order' => 'c.order_id IS NOT NULL', 'checkout' => 'c.checkout = 1', 'cart' => 'c.cart_added = 1', 'unanswered' => 'c.unanswered > 0',
                ]],
                'mode' => ['label' => 'Mode', 'column' => 'c.mode', 'options' => ['ai' => 'With AI', 'rules' => 'Simple mode']],
                'from' => ['label' => 'From', 'type' => 'date_from', 'column' => 'c.started_at'],
                'to' => ['label' => 'To', 'type' => 'date_to', 'column' => 'c.started_at'],
            ],
            'sort' => ['c.id', 'desc'],
            'per_page' => 25,
            'empty' => ['title' => 'No conversations yet', 'text' => 'They show up here when a visitor writes to the assistant.'],
            'row_url' => static function (array $row) use ($app): string {
                return $app->url()->plain('/admin/assistant/' . (int) $row['id']);
            },
            'columns' => [
                'started_at' => ['label' => 'Started', 'type' => 'datetime', 'sort' => 'c.started_at', 'link' => true],
                'messages' => ['label' => 'Messages', 'type' => 'int', 'sort' => 'c.messages'],
                'mode' => ['label' => 'Mode', 'type' => 'badge', 'map' => ['ai' => ['With AI', 'info'], 'rules' => ['Simple mode', '']], 'hide_sm' => true],
                'outcome' => ['label' => 'Outcome', 'render' => static function (array $row, App $application): string {
                    if ($row['handoff_status'] === 'pending') { return Admin::badge(t('Customer requests'), 'warn'); }
                    if ($row['order_id'] !== null && $row['order_id'] !== '') {
                        $amount = $row['order_total'] !== null && $row['order_total'] !== ''
                            ? ' ' . e($application->i18n()->money((int) $row['order_total'], (string) $row['order_currency']))
                            : '';

                        return Admin::badge(t('Order'), 'ok') . $amount;
                    }
                    if (!empty($row['checkout'])) {
                        return Admin::badge(t('Checkout'), 'info');
                    }
                    if (!empty($row['cart_added'])) {
                        return Admin::badge(t('Cart'), 'info');
                    }

                    return '<span class="af-muted">' . e(t('Chat only')) . '</span>';
                }],
                'unanswered' => ['label' => 'Unanswered', 'type' => 'int', 'sort' => 'c.unanswered'],
            ],
        ];
    }
}
