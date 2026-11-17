<?php
/**
 * AIFEC — pantalla de plantillas de correo: botón de prueba y lista de todas las plantillas declaradas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var bool   $canEdit
 * @var string $testUrl
 * @var string $email
 * @var string $mode
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><?php if ($canEdit) : ?>
<div class="af-card"><div class="af-card-body af-toolbar">
    <form method="post" action="<?= e($testUrl) ?>">
        <?= Admin::tokenField(app()) ?>
        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('mail') ?><?= e(t('Send a test email')) ?></button>
    </form>
    <span class="af-muted"><?= e(t('It goes to {email}.', ['email' => $email])) ?></span>
</div></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th><?= e(t('Module')) ?></th>
                <th><?= e(t('Template')) ?></th>
                <th><?= e(t('Description')) ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td class="af-mono"><?= e($row['module']) ?></td>
                    <td class="af-mono"><?= e($row['key']) ?><?= $row['system'] ? ' ' . Admin::badge(t('System'), 'info') : '' ?></td>
                    <td><?= e($row['description']) ?></td>
                    <td class="af-cell-actions"><a class="af-btn af-btn-sm" href="<?= e($row['url']) ?>"><?= Admin::icon('eye') ?><?= e(t('Preview')) ?></a>
                        <?php if ($canEdit) : ?>
                            <a class="af-btn af-btn-sm" href="<?= e($row['url'] . '/edit') ?>"><?= e(t('Edit texts')) ?></a>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
