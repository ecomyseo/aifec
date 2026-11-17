<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La pantalla del copiloto: la caja donde se escribe la orden, ejemplos y la zona que se rehace sin recargar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string               $stage    la zona «#cp-stage» ya pintada (copilot::admin/stage)
 * @var string[]             $examples frases de ejemplo, ya traducidas
 * @var array<string,string> $urls     ask
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
$this->push('admin.head', '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'copilot', 'copilot.css')) . '">');

?><form class="af-card cp-ask" method="post" action="<?= e($urls['ask']) ?>" data-af-ajax data-af-target="#cp-stage">
    <?= Admin::tokenField($app) ?>
    <div class="af-card-body">
        <label class="cp-label" for="cp-text"><?= e(t('What do you want to do?')) ?></label>
        <textarea id="cp-text" name="text" class="af-input" rows="2" maxlength="1000" required placeholder="<?= e(t('Mark order 12 as shipped with tracking 4455')) ?>"></textarea>
        <div class="cp-ask-row">
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('sparkles') ?><?= e(t('Prepare it')) ?></button>
<?php if ($examples !== []) : ?>
            <span class="af-small af-muted"><?= e(t('Examples')) ?>:</span>
<?php foreach ($examples as $example) : ?>
            <button type="button" class="af-btn af-btn-ghost af-btn-sm" data-cp-example="<?= e($example) ?>"><?= e($example) ?></button>
<?php endforeach; ?>
<?php endif; ?>
        </div>
    </div>
</form>
<div id="cp-stage" aria-live="polite"><?= $stage ?></div>
