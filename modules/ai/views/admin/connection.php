<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Modules\Ai\Ai;
use Aifec\Modules\Ai\AiAdmin;

$app = app();
?>
<section class="af-card">
    <div class="af-card-body">
        <p><?= e(t('This test uses the connection you saved. It counts as one AI request and never changes shop content.')) ?></p>
        <form method="post" action="<?= e($app->url()->plain('/admin/ai/connection')) ?>">
            <?= Admin::tokenField($app) ?>
            <div class="af-field">
                <label for="ai-test-tier"><?= e(t('AI tier')) ?></label>
                <select id="ai-test-tier" name="tier" class="af-input">
<?php foreach (Ai::TIERS as $tier) : ?>
                    <option value="<?= e($tier) ?>"><?= e(t(AiAdmin::tierLabel($tier))) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="af-actions">
                <button type="submit" class="af-btn af-btn-primary"><?= e(t('Test the saved connection')) ?></button>
                <a class="af-btn" href="<?= e($app->url()->plain('/admin/settings/ai')) ?>#tab=aimodels"><?= e(t('Provider and API key')) ?></a>
                <a class="af-btn af-btn-ghost" href="<?= e($app->url()->plain('/admin/ai/usage')) ?>"><?= e(t('See the usage')) ?></a>
            </div>
        </form>
    </div>
</section>
