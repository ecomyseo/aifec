<?php
/**
 * AIFEC — ficha de una opinión en el panel: lo que escribió el cliente, su estado y, para quien puede cambiarlo, publicar, rechazar
 * y responder.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>      $review  Reviews::find()
 * @var array<string,mixed>|null $product ProductData (null si ya no existe)
 * @var bool                     $canEdit puede publicar, rechazar y responder
 * @var array<string,string>     $urls    dirección de cada acción
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
$status = (string) $review['status'];
$kind = $status === 'published' ? 'ok' : ($status === 'rejected' ? 'bad' : 'warn');
$names = ['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected'];

?><div class="af-grid">
    <div class="af-col-8">
        <section class="af-card">
            <div class="af-card-head">
                <h2><?= e((string) $review['title'] !== '' ? (string) $review['title'] : t('Review')) ?></h2>
                <?= Admin::badge(t($names[$status]), $kind) ?>
            </div>
            <div class="af-card-body af-stack">
                <p class="af-nowrap" aria-label="<?= e(tn('{n} star', '{n} stars', (int) $review['rating'])) ?>"><?= e(str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating'])) ?></p>
                <p><?= nl2br(e((string) $review['body'])) ?></p>
<?php if ((string) $review['reply'] !== '') : ?>
                <div class="af-alert af-alert-info">
                    <strong><?= e(t('Reply from the shop')) ?></strong><br>
                    <?= nl2br(e((string) $review['reply'])) ?>
                </div>
<?php endif; ?>
            </div>
        </section>
<?php if ($canEdit) : ?>
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Reply from the shop')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($urls['reply']) ?>" class="af-stack">
                    <?= Admin::tokenField($app) ?>
                    <textarea class="af-textarea" name="reply" rows="4" maxlength="1000" aria-label="<?= e(t('Reply from the shop')) ?>"><?= e((string) $review['reply']) ?></textarea>
                    <p class="af-small af-muted"><?= e(t('Plain text, 1000 characters at most. Leave it empty to remove the reply.')) ?></p>
                    <p><button type="submit" class="af-btn af-btn-primary"><?= e(t('Save the reply')) ?></button></p>
                </form>
            </div>
        </section>
<?php endif; ?>
    </div>
    <div class="af-col-4">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Details')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('Customer')) ?></dt><dd><?= e((string) $review['name']) ?></dd>
                    <dt><?= e(t('Email')) ?></dt><dd><?= e((string) $review['email']) ?></dd>
                    <dt><?= e(t('Product')) ?></dt><dd><?= $product !== null ? '<a href="' . e((string) $product['url']) . '">' . e((string) $product['name']) . '</a>' : e('#' . (int) $review['product_id']) ?></dd>
                    <dt><?= e(t('Verified purchase')) ?></dt><dd><?= e($review['verified'] ? t('Yes') : t('No')) ?></dd>
                    <dt><?= e(t('Date')) ?></dt><dd><?= e($when((string) $review['created_at'])) ?></dd>
<?php if ($review['published_at'] !== null) : ?>
                    <dt><?= e(t('Published')) ?></dt><dd><?= e($when((string) $review['published_at'])) ?></dd>
<?php endif; ?>
                </dl>
            </div>
        </section>
<?php if ($canEdit) : ?>
        <section class="af-card">
            <div class="af-card-body af-stack">
<?php if ($status !== 'published') : ?>
                <form method="post" action="<?= e($urls['publish']) ?>">
                    <?= Admin::tokenField($app) ?>
                    <button type="submit" class="af-btn af-btn-primary af-btn-block"><?= e(t('Publish')) ?></button>
                </form>
<?php endif; ?>
<?php if ($status !== 'rejected') : ?>
                <form method="post" action="<?= e($urls['reject']) ?>">
                    <?= Admin::tokenField($app) ?>
                    <button type="submit" class="af-btn af-btn-danger af-btn-block" data-af-confirm="<?= e(t('Reject this review? It will not be shown in the shop.')) ?>"><?= e(t('Reject')) ?></button>
                </form>
<?php endif; ?>
            </div>
        </section>
<?php endif; ?>
    </div>
</div>
