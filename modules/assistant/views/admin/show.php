<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Una conversación del asistente: sus mensajes, las herramientas que usó y si llegó al carrito o a un pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $conversation la fila de conversations
 * @var array<int,array<string,mixed>> $messages     las filas de conversation_messages, con tools_used ya descodificado
 * @var array<string,mixed>|null       $order        el pedido en que acabó (id, number, total, currency, source), o null
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;

$app = app();
$tz = $app->site()->timezone();
$when = static function (?string $sql) use ($app, $tz): string {
    $time = Clock::parse($sql);

    return $time > 0 ? $app->i18n()->date($time, 'datetime', $tz) : '';
};
$outcome = ($conversation['order_id'] !== null && $conversation['order_id'] !== '')
    ? Admin::badge(t('Order'), 'ok')
    : (!empty($conversation['checkout']) ? Admin::badge(t('Checkout'), 'info') : (!empty($conversation['cart_added']) ? Admin::badge(t('Cart'), 'info') : e(t('Chat only'))));

?><div class="af-grid">
    <div class="af-col-8">
<?php if (in_array($conversation['handoff_status'], ['pending', 'replied'], true)) : ?>
        <section class="af-card">
            <h2><?= e(t('Customer requests')) ?></h2>
            <p><?= e((string) $conversation['handoff_summary']) ?></p>
<?php if ($conversation['handoff_status'] === 'pending' && $app->auth()->can('assistant.reply')) : ?>
            <form method="post" action="<?= e($app->url()->plain('/admin/assistant/' . (int) $conversation['id'] . '/reply')) ?>">
                <?= Admin::tokenField($app) ?>
                <input type="hidden" name="request_id" value="<?= (int) $conversation['handoff_request'] ?>">
                <label for="af-human-reply"><?= e(t('Answer as the shop team')) ?></label>
                <textarea class="af-input" id="af-human-reply" name="reply" required maxlength="1500"></textarea>
                <button class="af-btn" type="submit"><?= e(t('Send reply')) ?></button>
            </form>
<?php elseif ($conversation['handoff_status'] === 'replied') : ?>
            <p><?= nl2br(e((string) $conversation['handoff_reply'])) ?></p>
<?php endif; ?>
        </section>
<?php endif; ?>
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Messages')) ?></h2></div>
            <div class="af-card-body af-stack">
<?php foreach ($messages as $message) : ?>
                <div class="af-alert af-alert-<?= $message['role'] === 'user' ? 'info' : 'ok' ?>">
                    <strong><?= e($message['role'] === 'user' ? t('Customer') : t('Assistant')) ?></strong>
                    <span class="af-small af-muted"><?= e($when((string) $message['created_at'])) ?></span>
<?php if (!empty($message['unanswered'])) : ?>
                    <?= Admin::badge(t('Not answered'), 'warn') ?>
<?php endif; ?>
                    <br><?= nl2br(e((string) $message['content'])) ?>
<?php if ($message['tools_used'] !== []) : ?>
                    <br><span class="af-small af-muted"><?= e(t('Tools used')) ?>:</span>
<?php foreach ($message['tools_used'] as $tool) : ?>
                    <?= Admin::badge((string) $tool['name'], !empty($tool['ok']) ? 'ok' : 'bad') ?>
<?php if ((string) $tool['name'] === 'search_products' && isset($tool['input']['query']) && is_string($tool['input']['query']) && $tool['input']['query'] !== '') : ?>
                    <?php // Con qué palabras buscó: así se ve por qué encontró (o no) lo que el cliente quería. Sin correos ni teléfonos. ?>
                    <span class="af-small af-muted" data-assistant-query>«<?= e(mb_substr(\Aifec\Modules\Assistant\Masker::forStorage($tool['input']['query']), 0, 80, 'UTF-8')) ?>»</span>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
                </div>
<?php endforeach; ?>
<?php if ($messages === []) : ?>
                <p class="af-muted"><?= e(t('This conversation has no messages.')) ?></p>
<?php endif; ?>
            </div>
        </section>
    </div>
    <div class="af-col-4">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Details')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('Started')) ?></dt><dd><?= e($when((string) $conversation['started_at'])) ?></dd>
                    <dt><?= e(t('Last message')) ?></dt><dd><?= e($when((string) $conversation['last_at'])) ?></dd>
                    <dt><?= e(t('Messages')) ?></dt><dd><?= (int) $conversation['messages'] ?></dd>
                    <dt><?= e(t('Mode')) ?></dt><dd><?= e((string) $conversation['mode'] === 'ai' ? t('With AI') : t('Simple mode')) ?></dd>
                    <dt><?= e(t('Estimated AI cost')) ?></dt><dd><?= e(!empty($conversation['ai_unpriced']) ? t('Some provider prices are missing') : ($conversation['ai_cost_micro'] === null ? t('No measurements yet') : \Aifec\Modules\Ai\Usage::amount($app, (int) $conversation['ai_cost_micro']))) ?></dd>
<?php if ($conversation['vote'] !== null) : ?>
                    <dt><?= e(t('Rating')) ?></dt><dd><?= (int) $conversation['vote'] === 1 ? '👍' : '👎' ?> <?= e(t(['products' => 'Product suggestions', 'clarity' => 'Clarity', 'speed' => 'Response time'][$conversation['vote_reason'] ?? ''] ?? '')) ?></dd>
<?php endif; ?>
                    <dt><?= e(t('Outcome')) ?></dt><dd><?= $outcome ?></dd>
<?php if ($conversation['order_id'] !== null && $conversation['order_id'] !== '' && $app->auth()->can('orders.view')) : ?>
                    <dt><?= e(t('Order')) ?></dt><dd><a href="<?= e($app->url()->plain('/admin/orders/' . (int) $conversation['order_id'])) ?>">#<?= (int) $conversation['order_id'] ?></a></dd>
<?php if (is_array($order)) : ?>
                    <dt><?= e(t('Order total')) ?></dt><dd><?= e($app->i18n()->money((int) $order['total'], (string) $order['currency'])) ?><?= (string) $order['source'] === 'assistant' ? ' ' . Admin::badge(t('Placed by the assistant'), 'ok') : '' ?></dd>
<?php endif; ?>
<?php endif; ?>
                </dl>
            </div>
        </section>
    </div>
</div>
