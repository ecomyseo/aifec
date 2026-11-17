<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Pedir una devolución o el desistimiento de un pedido: lo que se puede devolver y hasta cuándo, el formulario (líneas, motivo,
 * comentario y fotos si es defectuoso) y, debajo, el estado de las devoluciones que el pedido ya tiene. Es privada: no se indexa y no
 * se guarda en ninguna caché.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page
 * @var array<string,string>           $order        number y placed
 * @var array<string,mixed>            $eligibility  ReturnPolicy::eligibility()
 * @var array<int,array<string,mixed>> $returns      las devoluciones del pedido, ya preparadas
 * @var array<string,mixed>            $access       action, cancel, redirect, hidden, back, back_label
 * @var array<string,string>           $reasons      código => texto traducido de los motivos que se ofrecen
 * @var bool                           $photos       se pueden adjuntar fotos
 * @var array<string,mixed>            $values       lo escrito antes: lines, reason, comment
 * @var array<string,string>           $errors       campo => texto
 * @var string                         $error        el primer error
 * @var string                         $done         número de la devolución recién pedida ('' si no)
 * @var array<string,string>           $dates        return_until y withdrawal_until, ya con formato
 * @var array<string,int>              $days         return y withdrawal
 * @var string[]                       $exclusions   texto sobre los productos excluidos por ley
 * @var int                            $maxLength    largo máximo del comentario
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
$af_lines = isset($eligibility['lines']) && is_array($eligibility['lines']) ? $eligibility['lines'] : [];
$af_chosen = isset($values['lines']) && is_array($values['lines']) ? $values['lines'] : [];
?>
<div class="af-container af-section" data-af-returns="<?= e((string) $order['number']) ?>">
    <h1 class="af-title"><?= e(t('Returns of order {number}', ['number' => (string) $order['number']])) ?></h1>
<?php if ($done !== '') : ?>
    <p class="af-alert af-alert-ok" role="status" data-af-return-done><?= e(!empty($unshipped)
        ? t('We have registered your withdrawal {number}. Your order has not been shipped yet: we will try to stop it, we will email you what happens next and, once it is settled, we will refund you.', ['number' => $done])
        : t('We have registered your request {number}. We have emailed you the instructions.', ['number' => $done])) ?></p>
<?php endif; ?>
<?php if ($error !== '') : ?>
    <p class="af-alert af-alert-bad" role="alert" data-af-return-error><?= e($error) ?></p>
<?php endif; ?>

<?php if ($returns !== []) : ?>
    <section class="af-card af-stack" aria-labelledby="af-returns-list-h" data-af-return-list>
        <h2 id="af-returns-list-h"><?= e(t('Your returns')) ?></h2>
<?php foreach ($returns as $af_return) : ?>
        <div class="af-stack" data-af-return="<?= e($af_return['number']) ?>">
            <p class="af-row">
                <strong><?= e($af_return['number']) ?></strong>
                <span class="<?= e($af_return['class']) ?>" data-af-return-status><?= e($af_return['status']) ?></span>
                <span class="af-muted af-small"><?= e($af_return['type']) ?> · <?= e($af_return['date']) ?></span>
            </p>
            <p><?= e($af_return['text']) ?></p>
<?php if ($af_return['instructions'] !== '') : ?>
            <p class="af-prose"><?= nl2br(e($af_return['instructions'])) ?></p>
<?php endif; ?>
<?php if ($af_return['reject_reason'] !== '') : ?>
            <p><?= e(t('Reason: {reason}', ['reason' => $af_return['reject_reason']])) ?></p>
<?php endif; ?>
<?php if ($af_return['refunded'] !== '') : ?>
            <p><?= e(t('Refunded: {amount}', ['amount' => $af_return['refunded']])) ?></p>
<?php endif; ?>
<?php if ($af_return['can_cancel']) : ?>
            <form method="post" action="<?= e($access['cancel']) ?>">
                <input type="hidden" name="return" value="<?= e($af_return['number']) ?>">
                <button type="submit" class="af-btn af-btn-sm"><?= e(t('Cancel this return')) ?></button>
            </form>
<?php endif; ?>
        </div>
<?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (!empty($eligibility['ok'])) : ?>
    <section class="af-card af-stack" aria-labelledby="af-returns-form-h">
        <h2 id="af-returns-form-h"><?= e(t('Ask for a return')) ?></h2>
        <p class="af-muted">
<?php if (!empty($eligibility['return_open'])) : ?>
            <?= e(t('You can ask for a return until {date}.', ['date' => $dates['return_until']])) ?>
<?php endif; ?>
<?php if (!empty($unshipped)) : ?>
            <?= e(t('Your order has not been shipped yet. You can withdraw from the purchase now, without giving any reason: we will receive your request, try to stop the shipment and tell you what happens next.')) ?>
<?php elseif (!empty($eligibility['withdrawal_open'])) : ?>
            <?= e(t('You can withdraw from the purchase until {date}, without giving any reason.', ['date' => $dates['withdrawal_until']])) ?>
<?php endif; ?>
        </p>
        <form method="post" action="<?= e($access['action']) ?>" enctype="multipart/form-data" class="af-stack" data-af-return-form>
<?php foreach ($access['hidden'] as $af_name => $af_value) : ?>
            <input type="hidden" name="<?= e((string) $af_name) ?>" value="<?= e((string) $af_value) ?>">
<?php endforeach; ?>
            <fieldset class="af-stack">
                <legend><?= e(t('Products to return')) ?></legend>
<?php if (isset($errors['lines'])) : ?>
                <p class="af-alert af-alert-bad" role="alert"><?= e($errors['lines']) ?></p>
<?php endif; ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('Product')) ?></th>
                            <th scope="col"><?= e(t('You can return')) ?></th>
                            <th scope="col"><?= e(t('Units')) ?></th>
                        </tr></thead>
                        <tbody>
<?php foreach ($af_lines as $af_line) : ?>
                            <tr>
                                <td><?= e((string) $af_line['name']) ?><?php if ((string) $af_line['variant_label'] !== '') : ?><br><span class="af-muted af-small"><?= e((string) $af_line['variant_label']) ?></span><?php endif; ?>
<?php if ((string) $af_line['excluded_reason'] !== '') : ?>
                                    <br><span class="af-small af-muted"><?= e((string) $af_line['excluded_reason']) ?></span>
<?php endif; ?></td>
                                <td><?= e((int) $af_line['available']) ?></td>
                                <td>
<?php if (!empty($af_line['selectable'])) : ?>
                                    <input class="af-input" type="number" name="lines[<?= e((int) $af_line['id']) ?>]" min="0" max="<?= e((int) $af_line['available']) ?>" value="<?= e(isset($af_chosen[(int) $af_line['id']]) ? (int) $af_chosen[(int) $af_line['id']] : 0) ?>" aria-label="<?= e(t('Units of {name}', ['name' => (string) $af_line['name']])) ?>">
<?php else : ?>
                                    <span class="af-muted">—</span>
<?php endif; ?>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <div class="af-field">
                <label for="af-rt-reason"><?= e(t('Reason')) ?></label>
<?php if (isset($errors['reason'])) : ?>
                <p class="af-alert af-alert-bad" role="alert"><?= e($errors['reason']) ?></p>
<?php endif; ?>
                <select id="af-rt-reason" class="af-input" name="reason" required>
                    <option value=""><?= e(t('Choose a reason')) ?></option>
<?php foreach ($reasons as $af_code => $af_label) : ?>
                    <option value="<?= e((string) $af_code) ?>"<?= (string) $values['reason'] === (string) $af_code ? ' selected' : '' ?>><?= e($af_label) ?></option>
<?php endforeach; ?>
                </select>
<?php if (isset($reasons['withdrawal'])) : ?>
                <span class="af-hint"><?= e(t('If you choose the withdrawal, you do not need to explain anything.')) ?></span>
<?php endif; ?>
            </div>

            <div class="af-field">
                <label for="af-rt-comment"><?= e(t('Comment (optional)')) ?></label>
<?php if (isset($errors['comment'])) : ?>
                <p class="af-alert af-alert-bad" role="alert"><?= e($errors['comment']) ?></p>
<?php endif; ?>
                <textarea id="af-rt-comment" class="af-input" name="comment" rows="4" maxlength="<?= e((int) $maxLength) ?>"><?= e((string) $values['comment']) ?></textarea>
            </div>

<?php if ($photos) : ?>
            <fieldset class="af-stack">
                <legend><?= e(t('Photos of the defect (only if the product is defective)')) ?></legend>
<?php if (isset($errors['photos'])) : ?>
                <p class="af-alert af-alert-bad" role="alert"><?= e($errors['photos']) ?></p>
<?php endif; ?>
                <span class="af-hint"><?= e(t('Up to 3 photos, JPEG, PNG or WebP, 5 MB each.')) ?></span>
<?php foreach ([1, 2, 3] as $af_n) : ?>
                <input class="af-input" type="file" name="photo_<?= e($af_n) ?>" accept="image/jpeg,image/png,image/webp" aria-label="<?= e(t('Photo {n}', ['n' => $af_n])) ?>">
<?php endforeach; ?>
            </fieldset>
<?php endif; ?>

<?php if ($exclusions !== []) : ?>
            <div class="af-prose af-small">
<?php foreach ($exclusions as $af_text) : ?>
                <p><?= e($af_text) ?></p>
<?php endforeach; ?>
            </div>
<?php endif; ?>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send the request')) ?></button>
        </form>
    </section>
<?php elseif ($eligibility['code'] !== 'open_return' || $returns === []) : ?>
    <section class="af-card af-stack" data-af-return-closed>
        <p><?= e((string) $eligibility['message']) ?></p>
    </section>
<?php endif; ?>
    <p><a href="<?= e((string) $access['back']) ?>"><?= e((string) $access['back_label']) ?></a></p>
</div>
