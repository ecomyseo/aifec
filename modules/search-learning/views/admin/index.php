<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\SearchLearning\Admin\LearningLabels;
?>
<div class="af-stack">
    <section class="af-card af-stack">
        <h2><?= e(t('Search experiments requested by the superadministrator')) ?></h2>
        <p><?= e(t('The configured AI can suggest fictitious profiles, queries and improvements anchored in the real catalogue. The real search engine runs the queries and deterministic checks decide whether a proposal improves them without regressions.')) ?></p>
        <p><?= e(t('This is stored application knowledge, not training of model weights. Profiles and evaluations are simulated, and an AI score does not certify the shop. Opening this screen never starts calls or applies changes.')) ?></p>
        <dl class="af-stack">
            <div><dt><?= e(t('Current shop')) ?></dt><dd><?= e($shopName) ?></dd></div>
            <div><dt><?= e(t('Shop URL')) ?></dt><dd><code><?= e($context['url']) ?></code></dd></div>
            <div><dt><?= e(t('Expected memory version')) ?></dt><dd><?= (int) $memoryVersion ?></dd></div>
        </dl>
        <p class="af-small af-muted"><?= e(t('To work on another shop, switch it in Sites and open its own administration panel. A round never shares catalogues, credentials or memory between shops.')) ?></p>
        <form method="get" action="<?= e(route('admin.search_learning')) ?>" class="af-cluster">
            <label for="learning-language-preview"><?= e(t('Language')) ?></label>
            <select id="learning-language-preview" name="lang">
<?php foreach ($languages as $code) : ?>
                <option value="<?= e($code) ?>"<?= $lang === $code ? ' selected' : '' ?>><?= e(strtoupper($code)) ?></option>
<?php endforeach; ?>
            </select>
            <button type="submit" class="af-btn"><?= e(t('View this language without starting a round')) ?></button>
        </form>
    </section>
<?php if (!$canManage) : ?>
    <div class="af-alert af-alert-info" role="status"><?= e(t('This profile can read the explanation and recorded reports. Only an active superadministrator can start, resume, validate, apply or reverse learning. Public demo administrators cannot authorise paid learning.')) ?></div>
<?php else : ?>
    <section class="af-card af-stack">
        <h2><?= e(t('Authorise one learning round')) ?></h2>
        <p><?= e(t('The round uses this shop AI provider, model and encrypted key. The explicit budget below and the global AI limits both apply. Pending proposals require a separate review before application.')) ?></p>
        <p><a href="<?= e(url('/admin/settings/ai')) ?>"><?= e(t('Review the configured AI provider, models and token prices')) ?></a></p>
        <form method="post" action="<?= e(route('admin.search_learning.create')) ?>" class="af-stack">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <input type="hidden" name="expected_context" value="<?= e($context['hash']) ?>">
            <input type="hidden" name="expected_version" value="<?= (int) $memoryVersion ?>">
            <input type="hidden" name="lang" value="<?= e($lang) ?>">
            <div class="af-grid af-cols-2">
                <label><?= e(t('Learning source')) ?>
                    <select name="mode" required>
                        <option value="simulated"><?= e(t('Fictitious profiles using the real catalogue')) ?></option>
                        <option value="history"><?= e(t('Recorded queries, evaluated as simulated cases')) ?></option>
                    </select>
                </label>
                <label><?= e(t('Number of profiles or recorded query cases')) ?>
                    <input type="number" name="profiles" min="1" max="20" value="<?= (int) $profiles ?>" required>
                </label>
                <label><?= e(t('History starts on')) ?><input type="date" name="from" value="<?= e($from) ?>"></label>
                <label><?= e(t('History ends on')) ?><input type="date" name="to" value="<?= e($to) ?>"></label>
                <label><?= e(t('AI tier for this round')) ?>
                    <select name="tier" required>
<?php foreach (['economy', 'standard', 'advanced'] as $level) : ?>
                        <option value="<?= e($level) ?>"<?= $tier === $level ? ' selected' : '' ?>><?= e(LearningLabels::tier($level)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label><?= e(t('Maximum cost for this round ({currency})', ['currency' => $currency])) ?>
                    <input type="text" name="budget" inputmode="decimal" placeholder="0,00" autocomplete="off" required>
                </label>
            </div>
            <p class="af-small af-muted"><?= e(t('Dates are used only for recorded queries. No historical visitor is contacted. Queries containing personal data or secrets are excluded or redacted before the AI sees them.')) ?></p>
            <p class="af-small af-muted"><?= e(t('For a paid provider, configured input and output prices are required. A small batch reserves its upper cost before the call. Unknown outcomes retain the reservation and pause for review instead of being retried automatically.')) ?></p>
            <p class="af-small af-muted"><?= e(t('The maximum authorised budget per round is {amount}; choose a smaller amount if appropriate.', ['amount' => $app->i18n()->money(100000, $currency)])) ?></p>
            <label><input type="checkbox" name="authorise" value="1" required> <?= e(t('I authorise this single round in the displayed shop and language, using its configured AI and the budget entered above.')) ?></label>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Start the authorised round')) ?></button>
        </form>
    </section>
<?php endif; ?>
    <section class="af-card af-stack">
        <h2><?= e(t('Learning round history')) ?></h2>
<?php if ($runs === []) : ?>
        <p><?= e(t('There are no authorised learning rounds yet. No learning calls have been started by opening this page.')) ?></p>
<?php else : ?>
        <div class="af-table-wrap"><table class="af-table">
            <thead><tr><th><?= e(t('Learning round')) ?></th><th><?= e(t('Language')) ?></th><th><?= e(t('Learning source')) ?></th><th><?= e(t('Learning status')) ?></th><th><?= e(t('Recorded AI calls')) ?></th><th><?= e(t('Calculated cost')) ?></th></tr></thead>
            <tbody>
<?php foreach ($runs as $run) : ?>
                <tr>
                    <td><a href="<?= e(route('admin.search_learning.show', ['id' => (int) $run['id']])) ?>">#<?= (int) $run['id'] ?></a></td>
                    <td><?= e($run['context']['lang'] ?? ($run['lang'] ?? '')) ?></td>
                    <td><?= e(LearningLabels::mode((string) $run['mode'])) ?></td>
                    <td><?= e(LearningLabels::state((string) $run['status'])) ?></td>
                    <td><?= e(LearningLabels::value($run['calls'] ?? null)) ?></td>
                    <td><?= e(LearningLabels::runCost($app, $run)) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php endif; ?>
    </section>
</div>
