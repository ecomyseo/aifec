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
use Aifec\Modules\SearchLearning\Admin\LearningReports;

$progress = isset($run['progress']) && is_array($run['progress']) ? $run['progress'] : [];
$total = max(0, (int) ($progress['total'] ?? 0));
$done = max(0, (int) ($progress['done'] ?? 0));
$expected = (int) $run['version'];
$base = ['id' => (int) $run['id']];
?>
<div class="af-stack">
    <p><a href="<?= e(route('admin.search_learning')) ?>"><?= e(t('Back to learning history')) ?></a> · <a href="<?= e(route('admin.search_learning.show', $base)) ?>"><?= e(t('Refresh recorded progress')) ?></a></p>
    <section class="af-card af-stack">
        <h2><?= e(t('Learning round {number}', ['number' => $run['id']])) ?></h2>
        <p><strong><?= e($shopName) ?></strong> · <code><?= e($run['context']['url'] ?? '') ?></code> · <?= e($run['context']['lang'] ?? ($run['lang'] ?? '')) ?></p>
        <p><?= e(LearningLabels::mode((string) $run['mode'])) ?> · <strong><?= e(LearningLabels::state((string) $run['status'])) ?></strong></p>
        <p><?= e(t('Profiles and evaluations are simulated. The search results and catalogue checks below are recorded facts; an AI score does not approve the shop.')) ?></p>
<?php if (!$contextCurrent) : ?>
        <div class="af-alert af-alert-warn"><?= e(t('The current shop URL or language context differs from this round. Resume, validation, application, reversal and report downloads are blocked. Cancellation remains available to stop the old round.')) ?></div>
<?php endif; ?>
        <label for="learning-progress"><?= e(t('Recorded progress: {done} of {total}', ['done' => $done, 'total' => $total])) ?></label>
        <progress id="learning-progress" value="<?= min($done, max(1, $total)) ?>" max="<?= max(1, $total) ?>"></progress>
        <dl class="af-grid af-cols-3">
            <div><dt><?= e(t('Authorised round budget')) ?></dt><dd><?= e(LearningLabels::budget($app, $run)) ?></dd></div>
            <div><dt><?= e(t('Calculated cost with configured prices')) ?></dt><dd><?= e(LearningLabels::runCost($app, $run)) ?></dd></div>
            <div><dt><?= e(t('Amount still reserved')) ?></dt><dd><?= e(LearningLabels::cost($app, $run['reserved_micro'] ?? null, $run)) ?></dd></div>
            <div><dt><?= e(t('Recorded AI calls')) ?></dt><dd><?= e(LearningLabels::value($run['calls'] ?? null)) ?></dd></div>
            <div><dt><?= e(t('Recorded input tokens')) ?></dt><dd><?= e(LearningLabels::value($run['tokens_in'] ?? null)) ?></dd></div>
            <div><dt><?= e(t('Recorded output tokens')) ?></dt><dd><?= e(LearningLabels::value($run['tokens_out'] ?? null)) ?></dd></div>
        </dl>
<?php if (!empty($run['uncertain_calls']) || (isset($run['cost_known']) && $run['cost_known'] === false)) : ?>
        <div class="af-alert af-alert-warn"><?= e(t('Provider calls with an unknown outcome: {count}. The total cost is not settled; the reservation is retained and automatic retries are blocked.', ['count' => $run['uncertain_calls'] ?? t('Not recorded')])) ?></div>
<?php endif; ?>
        <p class="af-small af-muted"><?= e(t('Costs are calculated from recorded usage and the configured provider prices; they are not an invoice from the provider. Unknown calls keep their reservation until reviewed.')) ?></p>
<?php if (!empty($run['error'])) : ?>
        <div class="af-alert af-alert-warn"><strong><?= e(t('Recorded problem')) ?>:</strong> <?= e(LearningLabels::problem((string) $run['error'])) ?></div>
<?php endif; ?>
        <p><?= e(t('Expected memory version')) ?>: <?= $expected ?> · <?= e(t('Current memory version')) ?>: <?= (int) $memoryVersion ?> · <?= e(t('Applied memory version')) ?>: <?= e(LearningLabels::value($run['applied_version'] ?? null)) ?></p>
<?php if ($contextCurrent) : ?>
        <div class="af-cluster">
            <a class="af-btn" href="<?= e(route('admin.search_learning.report', $base + ['kind' => 'experience'])) ?>"><?= e(t('Download simulated experience PDF')) ?></a>
            <a class="af-btn" href="<?= e(route('admin.search_learning.report', $base + ['kind' => 'improvements'])) ?>"><?= e(t('Download proposals and verification PDF')) ?></a>
        </div>
<?php endif; ?>
    </section>
<?php if ($canManage) : ?>
    <section class="af-card af-stack">
        <h2><?= e(t('Authorised round controls')) ?></h2>
        <div class="af-cluster">
<?php if (!in_array($run['status'], ['cancelled', 'applied', 'reverted'], true)) : ?>
            <form method="post" action="<?= e(route('admin.search_learning.action', $base + ['action' => 'cancel'])) ?>">
                <input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="expected_context" value="<?= e($run['context_hash']) ?>"><input type="hidden" name="expected_version" value="<?= $expected ?>">
                <button type="submit" class="af-btn"><?= e(t('Cancel this round and prevent new calls')) ?></button>
            </form>
<?php endif; ?>
<?php if ($contextCurrent && $memoryVersion === $expected) : ?>
<?php $actions = []; if ($run['status'] === 'paused' && empty($run['reserved_micro']) && empty($run['uncertain_calls'])) { $actions['resume'] = t('Explicitly resume this round'); } if (in_array($run['status'], ['ready', 'validated', 'rejected'], true)) { $actions['validate'] = t('Validate again against the current catalogue'); } foreach ($actions as $action => $text) : ?>
            <form method="post" action="<?= e(route('admin.search_learning.action', $base + ['action' => $action])) ?>">
                <input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="expected_context" value="<?= e($run['context_hash']) ?>"><input type="hidden" name="expected_version" value="<?= $expected ?>">
                <button type="submit" class="af-btn"><?= e($text) ?></button>
            </form>
<?php endforeach; endif; ?>
        </div>
        <p class="af-small af-muted"><?= e(t('Cancellation prevents new calls and later application. If a provider call is already in flight, its known usage or unresolved reservation remains visible.')) ?></p>
    </section>
<?php endif; ?>
    <section class="af-card af-stack">
        <h2><?= e(t('Before and after recorded searches')) ?></h2>
<?php if ($items === []) : ?>
        <p><?= e(t('There are no recorded cases yet. No result or success is implied.')) ?></p>
<?php endif; ?>
<?php foreach ($items as $item) : $before = is_array($item['before'] ?? null) ? $item['before'] : []; $after = is_array($item['after'] ?? null) ? $item['after'] : []; ?>
        <details class="af-card">
            <summary><?= e(LearningReports::safeText($item['query'] ?? null)) ?> · <?= e(t('Verifiable against catalogue data')) ?>: <?= e(isset($item['verifiable']) ? t(!empty($item['verifiable']) ? 'Yes' : 'No') : t('Not recorded')) ?></summary>
            <p><strong><?= e(t('Fictitious profile or recorded source')) ?>:</strong> <?= e(LearningReports::safeText(LearningLabels::profile($item))) ?></p>
            <p><strong><?= e(t('Expected catalogue product IDs')) ?>:</strong> <?= e(LearningLabels::value($item['target_ids'] ?? null)) ?></p>
            <div class="af-table-wrap"><table class="af-table">
                <thead><tr><th><?= e(t('Recorded facts')) ?></th><th><?= e(t('Before')) ?></th><th><?= e(t('After')) ?></th></tr></thead>
                <tbody>
<?php foreach (['ids' => t('Returned catalogue product IDs'), 'positions' => t('Recorded positions'), 'total' => t('Total results'), 'filters' => t('Applied deterministic filters'), 'score' => t('Recorded deterministic score')] as $key => $text) : ?>
                    <tr><th><?= e($text) ?></th><td><?= e(LearningReports::safeText($before[$key] ?? null)) ?></td><td><?= e(LearningReports::safeText($after[$key] ?? null)) ?></td></tr>
<?php endforeach; ?>
                </tbody>
            </table></div>
            <p><strong><?= e(t('Recorded evaluation')) ?>:</strong> <?= e(LearningLabels::verdict((string) ($item['verdict'] ?? ''))) ?></p>
            <p><strong><?= e(t('Catalogue evidence')) ?>:</strong> <?= e(LearningReports::safeText($item['evidence'] ?? null)) ?></p>
        </details>
<?php endforeach; ?>
    </section>
    <section class="af-card af-stack">
        <h2><?= e(t('Pending proposals and measured checks')) ?></h2>
        <p><?= e(t('A proposal is not an applied improvement. Only explicit approval after deterministic validation changes the stored search memory.')) ?></p>
<?php if ($proposals === []) : ?>
        <p><?= e(t('There are no recorded proposals. No improvement is implied.')) ?></p>
<?php endif; ?>
<?php foreach ($proposals as $proposal) : ?>
        <article class="af-card af-stack">
            <h3><?= e(t('Learning proposal {number}', ['number' => $proposal['id']])) ?></h3>
            <p><?= e(LearningLabels::proposal((string) ($proposal['status'] ?? ''))) ?></p>
            <p><strong><?= e(t('Source query or residual phrase')) ?>:</strong> <?= e(LearningReports::safeText($proposal['source'] ?? null)) ?></p>
            <p><strong><?= e(t('Proposed intent or ranking')) ?>:</strong> <?= e(LearningReports::safeText($proposal['target'] ?? null)) ?></p>
            <p><strong><?= e(t('Catalogue product IDs supporting the proposal')) ?>:</strong> <?= e(LearningLabels::value($proposal['product_ids'] ?? null)) ?></p>
            <p><strong><?= e(t('Reason')) ?>:</strong> <?= e(LearningReports::safeText($proposal['reason'] ?? null)) ?></p>
            <p><strong><?= e(t('Catalogue evidence')) ?>:</strong> <?= e(LearningReports::safeText($proposal['evidence'] ?? null)) ?></p>
        </article>
<?php endforeach; ?>
<?php $validation = is_array($run['validation'] ?? null) ? LearningLabels::validation($run['validation']) : []; ?>
<?php if ($validation === []) : ?>
        <p><?= e(t('No deterministic validation is recorded yet. No improvement has been certified.')) ?></p>
<?php else : ?>
        <dl class="af-grid af-cols-2">
<?php foreach ($validation as $label => $value) : ?>
            <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
<?php endforeach; ?>
        </dl>
<?php endif; ?>
<?php if ($canManage && $contextCurrent && $memoryVersion === $expected && $proposals !== [] && $run['status'] === 'validated' && !empty($run['validation']['passed'])) : ?>
        <form method="post" action="<?= e(route('admin.search_learning.action', $base + ['action' => 'apply'])) ?>" class="af-stack">
            <input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="expected_context" value="<?= e($run['context_hash']) ?>"><input type="hidden" name="expected_version" value="<?= $expected ?>">
            <label><input type="checkbox" name="reviewed" value="1" required> <?= e(t('I reviewed the proposals and their catalogue evidence. Apply only if the deterministic validation still passes without regressions.')) ?></label>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Apply reviewed learning')) ?></button>
        </form>
<?php endif; ?>
    </section>
    <section class="af-card af-stack">
        <h2><?= e(t('Application and reversal history')) ?></h2>
<?php if ($applications === []) : ?>
        <p><?= e(t('No applied changes are recorded for this round.')) ?></p>
<?php endif; ?>
<?php foreach ($applications as $application) : ?>
        <article class="af-card af-stack">
            <p><?= e(t('Memory version before')) ?>: <?= (int) $application['version_before'] ?> · <?= e(t('Memory version after')) ?>: <?= (int) $application['version_after'] ?></p>
            <p><?= e(LearningLabels::application((string) ($application['status'] ?? ''))) ?></p>
            <p><?= e(t('Applied at')) ?>: <?= e(LearningLabels::value($application['created_at'] ?? null)) ?> · <?= e(t('Reverted at')) ?>: <?= e(LearningLabels::value($application['reverted_at'] ?? null)) ?></p>
<?php if ($canManage && $contextCurrent && empty($application['reverted_at']) && $memoryVersion === (int) $application['version_after']) : ?>
            <form method="post" action="<?= e(route('admin.search_learning.action', $base + ['action' => 'revert'])) ?>" class="af-stack">
                <input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="expected_context" value="<?= e($run['context_hash']) ?>"><input type="hidden" name="expected_version" value="<?= (int) $memoryVersion ?>"><input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                <label><input type="checkbox" name="reviewed" value="1" required> <?= e(t('I confirm reversal of this application at the displayed current version. Later applications must be reversed first.')) ?></label>
                <button type="submit" class="af-btn"><?= e(t('Reverse this learning application')) ?></button>
            </form>
<?php endif; ?>
        </article>
<?php endforeach; ?>
    </section>
</div>
