<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La auditoría de seguridad: la nota, el recuento y cada comprobación con lo que pasa y lo que hay que hacer.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,string>> $rows
 * @var int                              $score
 * @var array<string,int>                $counts
 * @var array<string,mixed>|null         $newer
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
$kind = ['ok' => 'ok', 'warn' => 'warn', 'bad' => 'bad'];
$label = ['ok' => t('Good'), 'warn' => t('Review'), 'bad' => t('Fix now')];
?><div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Security audit')) ?></h2></div>
    <div class="af-card-body af-stat">
        <div class="af-stat-value"><?= e($score) ?> / 100</div>
        <div class="af-stat-note"><?= e(tn('{n} good', '{n} good', $counts['ok'])) ?> · <?= e(tn('{n} to review', '{n} to review', $counts['warn'])) ?> · <?= e(tn('{n} to fix now', '{n} to fix now', $counts['bad'])) ?></div>
        <form method="post" action="<?= e($app->url()->plain('/admin/security/check-version')) ?>" style="margin-top: var(--s3)">
            <?= Admin::tokenField($app) ?>
            <button type="submit" class="af-btn"><?= e(t('Check for a new version now')) ?></button>
        </form>
    </div>
</div>

<div class="af-card">
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr><th><?= e(t('Check')) ?></th><th><?= e(t('Result')) ?></th><th><?= e(t('What happens')) ?></th><th><?= e(t('What to do')) ?></th></tr></thead>
            <tbody>
<?php foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?= e($row['title']) ?></strong></td>
                    <td><span class="af-badge af-badge-<?= e($kind[$row['status']]) ?>"><?= e($label[$row['status']]) ?></span></td>
                    <td><?= e($row['detail']) ?></td>
                    <td><?= e($row['fix']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
