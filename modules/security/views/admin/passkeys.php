<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las passkeys de quien ha entrado: la lista, el borrado y el botón de añadir una.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $keys
 * @var bool                            $enabled
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
?><div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Your passkeys')) ?></h2></div>
<?php if ($keys === []) : ?>
    <div class="af-card-body"><div class="af-empty"><?= e(t('You have no passkeys yet.')) ?></div></div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('Added')) ?></th><th><?= e(t('Last used')) ?></th><th></th></tr></thead>
            <tbody>
<?php foreach ($keys as $key) : ?>
                <tr>
                    <td><strong><?= e($key['name']) ?></strong></td>
                    <td><?= e($key['created_at']) ?></td>
                    <td><?= e($key['last_used_at'] !== null ? $key['last_used_at'] : t('Never')) ?></td>
                    <td>
                        <form method="post" action="<?= e($app->url()->plain('/admin/passkeys/delete')) ?>" onsubmit="return confirm('<?= e(t('Delete this passkey?')) ?>')">
                            <?= Admin::tokenField($app) ?>
                            <input type="hidden" name="id" value="<?= e((int) $key['id']) ?>">
                            <button type="submit" class="af-btn af-btn-danger"><?= e(t('Delete')) ?></button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Add a passkey')) ?></h2></div>
    <div class="af-card-body">
<?php if (!$enabled) : ?>
        <p class="af-muted"><?= e(t('Passkeys are turned off in the security settings.')) ?></p>
<?php else : ?>
        <div class="af-field">
            <label class="af-label" for="af-passkey-name"><?= e(t('Name of the device')) ?></label>
            <div class="af-control"><input class="af-input" id="af-passkey-name" type="text" maxlength="80" placeholder="<?= e(t('My phone')) ?>"></div>
        </div>
        <button type="button" class="af-btn af-btn-primary" data-af-passkey-add
            data-options="<?= e($app->url()->plain('/admin/passkeys/options')) ?>" data-register="<?= e($app->url()->plain('/admin/passkeys/register')) ?>"
            data-fail="<?= e(t('That passkey could not be added.')) ?>" data-unsupported="<?= e(t('This browser does not support passkeys.')) ?>"><?= e(t('Add a passkey')) ?></button>
        <p class="af-small af-muted" id="af-passkey-message" role="alert" hidden></p>
        <script src="<?= e($app->url()->asset('m', 'security', 'passkey.js')) ?>" defer></script>
<?php endif; ?>
    </div>
</div>
