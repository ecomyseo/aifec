<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página pública del formulario legal de desistimiento: el texto (modelo o el de la tienda) y el formulario con el número de pedido
 * y el correo, que lleva al mismo flujo que la página del pedido. Se puede enlazar desde el pie y desde las condiciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $page
 * @var array<string,string> $values     number y email escritos antes
 * @var string               $error      aviso de que no se encuentra el pedido ('' si no)
 * @var string[]             $paragraphs el texto del desistimiento, un párrafo por elemento
 * @var string[]             $exclusions el texto de los productos excluidos por ley
 * @var int                  $days       días del plazo de desistimiento
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section" data-af-withdrawal>
    <h1 class="af-title"><?= e(t('Right of withdrawal')) ?></h1>
    <section class="af-prose af-stack" data-af-withdrawal-text>
<?php foreach ($paragraphs as $af_text) : ?>
        <p><?= e($af_text) ?></p>
<?php endforeach; ?>
<?php foreach ($exclusions as $af_text) : ?>
        <p><?= e($af_text) ?></p>
<?php endforeach; ?>
    </section>
    <section class="af-card af-stack" aria-labelledby="af-wd-form-h">
        <h2 id="af-wd-form-h"><?= e(t('Withdraw from your order')) ?></h2>
        <p class="af-muted"><?= e(t('Enter the number of your order and the email you used to buy.')) ?></p>
<?php if ($error !== '') : ?>
        <p class="af-alert af-alert-bad" role="alert" data-af-withdrawal-error><?= e($error) ?></p>
<?php endif; ?>
        <form method="post" action="<?= e(route('returns.lookup')) ?>" class="af-stack">
            <div class="af-field">
                <label for="af-wd-number"><?= e(t('Order number')) ?></label>
                <input id="af-wd-number" class="af-input" type="text" name="number" value="<?= e($values['number']) ?>" maxlength="32" required autocomplete="off">
            </div>
            <div class="af-field">
                <label for="af-wd-email"><?= e(t('Email')) ?></label>
                <input id="af-wd-email" class="af-input" type="email" name="email" value="<?= e($values['email']) ?>" maxlength="191" required autocomplete="email">
            </div>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Continue')) ?></button>
        </form>
    </section>
</div>
