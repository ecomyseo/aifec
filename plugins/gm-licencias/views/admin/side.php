<?php
/**
 * AIFEC — GM licences: la columna lateral de la ficha de una licencia (dominios, acciones y últimas comprobaciones).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $license
 * @var array<int,array<string,mixed>> $domains
 * @var array<int,array<string,mixed>> $checks
 * @var string                         $base     dirección de las acciones de esta licencia
 * @var bool                           $can_edit
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<div class="af-card">
    <div class="af-card-head"><strong><?= e(t('Domains')) ?></strong></div>
    <div class="af-card-body af-stack">
<?php if ($domains === []) : ?>
        <p class="af-muted"><?= e(t('No domain has activated this licence yet.')) ?></p>
<?php endif; ?>
<?php foreach ($domains as $af_domain) : ?>
        <div class="af-row">
            <span class="af-mono"><?= e((string) $af_domain['domain']) ?></span>
            <span class="af-small af-muted"><?= e((string) $af_domain['last_seen_at']) ?></span>
<?php if ($can_edit) : ?>
            <form method="post" action="<?= e($base . '/domain/' . (int) $af_domain['id'] . '/remove') ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm" data-af-confirm="<?= e(t('Remove this domain?')) ?>"><?= e(t('Remove')) ?></button></form>
<?php endif; ?>
        </div>
<?php endforeach; ?>
    </div>
</div>
<?php if ($can_edit) : ?>
<div class="af-card">
    <div class="af-card-head"><strong><?= e(t('Actions')) ?></strong></div>
    <div class="af-card-body af-stack">
<?php if ((string) $license['status'] === 'revoked') : ?>
        <form method="post" action="<?= e($base . '/reactivate') ?>"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn"><?= e(t('Reactivate')) ?></button></form>
<?php else : ?>
        <form method="post" action="<?= e($base . '/revoke') ?>"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Revoke this licence?')) ?>"><?= e(t('Revoke')) ?></button></form>
<?php endif; ?>
        <form method="post" action="<?= e($base . '/extend') ?>" class="af-stack"><?= Admin::tokenField(app()) ?>
            <label for="gm-lic-months"><?= e(t('Extend the updates (months)')) ?></label>
            <input id="gm-lic-months" class="af-input" type="number" name="months" value="12" min="1" max="240">
            <button type="submit" class="af-btn"><?= e(t('Extend')) ?></button>
        </form>
<?php if ((string) $license['kind'] === 'signed') : ?>
        <form method="post" action="<?= e($base . '/reissue') ?>" class="af-stack"><?= Admin::tokenField(app()) ?>
            <label for="gm-lic-nif"><?= e(t('Tax ID to sign the key with')) ?></label>
            <input id="gm-lic-nif" class="af-input" type="text" name="nif" value="<?= e((string) $license['holder']) ?>" maxlength="20">
            <button type="submit" class="af-btn"><?= e(t('Sign the key again')) ?></button>
        </form>
<?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-head"><strong><?= e(t('Last checks')) ?></strong></div>
    <div class="af-card-body">
<?php if ($checks === []) : ?>
        <p class="af-muted"><?= e(t('Nobody has checked this licence yet.')) ?></p>
<?php else : ?>
        <ul class="af-small">
<?php foreach ($checks as $af_check) : ?>
            <li><?= e((string) $af_check['created_at']) ?> · <?= e((string) $af_check['action']) ?> · <?= e((string) $af_check['result']) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </div>
</div>
