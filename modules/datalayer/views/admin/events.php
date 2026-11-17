<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Los últimos eventos que mandó el servidor y lo que contestó cada servicio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,int> $counts
 * @var bool $serverOn
 * @var string $settingsUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$kinds = ['sent' => 'ok', 'queued' => 'info', 'skipped' => 'warn', 'failed' => 'bad'];
$labels = ['sent' => t('Sent'), 'queued' => t('Queued'), 'skipped' => t('Skipped'), 'failed' => t('Failed')];
// Los motivos que apunta el módulo (en inglés en la base, se traducen al enseñarlos).
$notes = [
    'No consent' => t('No consent'), 'The switch is off' => t('The switch is off'), 'Older than 24 hours' => t('Older than 24 hours'),
    'Not configured' => t('Not configured'), 'No answer in 24 hours' => t('No answer in 24 hours'),
];
?><?php if (!$serverOn) : ?>
<div class="af-alert af-alert-info"><?= e(t('Server events are off.')) ?> <a href="<?= e($settingsUrl) ?>"><?= e(t('Turn them on in the settings')) ?></a></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Last events')) ?></h2></div>
    <div class="af-card-body">
<?php if ($rows === []) : ?>
        <div class="af-empty"><?= e(t('No server events yet')) ?></div>
<?php else : ?>
        <p class="af-muted af-small"><?php foreach ($kinds as $status => $kind) : ?><?= Admin::badge($labels[$status] . ': ' . (isset($counts[$status]) ? $counts[$status] : 0), $kind) ?> <?php endforeach; ?></p>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Order')) ?></th><th scope="col"><?= e(t('Event')) ?></th><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th class="af-num" scope="col"><?= e(t('Answer')) ?></th><th scope="col"><?= e(t('Detail')) ?></th></tr></thead>
                <tbody>
<?php foreach ($rows as $row) : ?>
<?php $status = (string) $row['status']; $note = $row['response'] !== null ? (string) $row['response'] : ''; ?>
                    <tr>
                        <td class="af-nowrap"><?= e($row['created_at']) ?></td>
                        <td><?= e($row['number']) ?></td>
                        <td><?= e($row['kind']) ?></td>
                        <td><?= e($row['destination'] === 'meta' ? 'Meta' : 'GA4') ?></td>
                        <td><?= Admin::badge(isset($labels[$status]) ? $labels[$status] : $status, isset($kinds[$status]) ? $kinds[$status] : '') ?></td>
                        <td class="af-num"><?= e($row['http_status'] !== null ? $row['http_status'] : '–') ?></td>
                        <td class="af-small af-muted"><?= e(isset($notes[$note]) ? $notes[$note] : $note) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
