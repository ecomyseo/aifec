<?php
/**
 * AIFEC — pantalla de información del sistema (sin ningún secreto).
 *
 * @var array<string,mixed> $info
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Util\Bytes;

?>
<div class="af-grid">
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Versions')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt>AIFEC</dt><dd><?= e($info['aifec']) ?></dd>
                    <dt>PHP</dt><dd><?= e($info['php']) ?> (<?= e($info['sapi']) ?>)</dd>
                    <dt><?= e(t('Database')) ?></dt><dd><?= e($info['database']['driver']) ?> <?= e($info['database']['version']) ?></dd>
                    <dt><?= e(t('System')) ?></dt><dd><?= e($info['os']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('This site')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('Identifier')) ?></dt><dd><?= e($info['site']) ?></dd>
                    <dt><?= e(t('Address')) ?></dt><dd><?= e($info['url']) ?></dd>
                    <dt><?= e(t('Time zone')) ?></dt><dd><?= e($info['time_zone']) ?></dd>
                    <dt><?= e(t('Languages')) ?></dt><dd><?= e(implode(', ', $info['languages'])) ?></dd>
                    <dt><?= e(t('Theme')) ?></dt><dd><?= e($info['theme']['name'] !== '' ? $info['theme']['name'] : $info['theme']['slug']) ?></dd>
                    <dt><?= e(t('Size of the data folder')) ?></dt><dd><?= e(($info['data']['truncated'] ? '≥ ' : '') . Bytes::format($info['data']['bytes'], 1)) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Modules')) ?></h2></div>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Identifier')) ?></th><th><?= e(t('Version')) ?></th><th><?= e(t('Status')) ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ($info['modules'] as $module) : ?>
                    <?php $failed = isset($info['failed'][$module['id']]); ?>
                    <tr>
                        <td><?= e($module['name']) ?></td>
                        <td class="af-mono af-small"><?= e($module['id']) ?></td>
                        <td><?= e($module['version']) ?></td>
                        <td>
                            <?php if ($failed) : ?>
                                <?= Admin::badge(t('Failed'), 'bad') ?> <span class="af-small"><?= e($info['failed'][$module['id']]) ?></span>
                            <?php elseif ($module['loaded']) : ?>
                                <?= Admin::badge(t('Loaded'), 'ok') ?>
                            <?php else : ?>
                                <?= Admin::badge(t('Off'), 'info') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('PHP extensions')) ?></h2></div>
    <div class="af-card-body af-mono af-small"><?= e(implode(', ', $info['extensions'])) ?></div>
</div>
