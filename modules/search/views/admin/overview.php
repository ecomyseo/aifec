<?php
/**
 * AIFEC — pantalla del buscador en el panel: tres pestañas (sin resultado, más buscadas y estado del índice).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int                                $days       7 o 30
 * @var array<int,array<string,mixed>>     $missed     búsquedas sin resultado
 * @var array<int,array<string,mixed>>     $popular    búsquedas más frecuentes
 * @var array<string,int>                  $counts     documentos indexados por tipo
 * @var string|null                        $last       cuándo se indexó algo por última vez
 * @var array<string,int>                  $filters    tipos de filtro entendidos y veces (el panel «Entendido»)
 * @var array<string,int>                  $outcomes   búsquedas de los últimos 30 días por cómo acabaron: exact, relaxed, related, none
 * @var array<int,array<string,mixed>>     $unanswered las consultas sin nada que enseñar más repetidas (20 como mucho)
 * @var bool                               $meaningHint la IA está encendida y los vectores de significado apagados
 * @var string                             $synonyms   el ajuste «search.synonyms»
 * @var int                                $pending    trabajos del buscador que esperan
 * @var bool                               $canEdit
 * @var callable                           $synonymUrl (App, string): dirección del alta de sinónimo con la consulta ya escrita
 */

use Aifec\Admin\Admin;

$af_app = app();
$af_tabs = ['srmissed' => t('No results'), 'srpopular' => t('Most searched'), 'srunderstood' => t('Understood'), 'srindex' => t('Index')];
$af_kinds = [
    'price' => t('Price'), 'attribute' => t('Attributes'), 'number' => t('Measures'), 'category' => t('Category'), 'brand' => t('Brand'),
    'stock' => t('In stock'), 'sale' => t('On sale'), 'sort' => t('Sorting'), 'without' => t('Exclusions'), 'ai' => t('Helped by the AI'),
];
$af_typeNames = ['product' => t('Products'), 'page' => t('Pages'), 'post' => t('Posts')];
$af_outcomeNames = ['exact' => t('Exact results'), 'relaxed' => t('Proposal without a filter'), 'related' => t('Related by meaning'), 'none' => t('Nothing')];
?>
<?php if ($meaningHint) : ?>
    <p class="af-alert af-alert-info" role="status" data-af-search-meaning-hint>
        <?= e(t('Turn on «Rank also by meaning» to find things by meaning.')) ?>
        <?php if ($canEdit) : ?><a href="<?= e($af_app->url()->plain('/admin/settings/search') . '#tab=srai') ?>"><?= e(t('Open the settings')) ?></a><?php endif; ?>
    </p>
<?php endif; ?>
<div class="af-card" data-af-search-outcomes>
    <div class="af-card-body af-stack">
        <h3><?= e(t('How the last 30 days of searches ended')) ?></h3>
        <dl class="af-kv">
            <?php foreach ($af_outcomeNames as $af_key => $af_label) : ?>
                <dt><?= e($af_label) ?></dt>
                <dd data-af-search-outcome="<?= e($af_key) ?>"><?= e((int) (isset($outcomes[$af_key]) ? $outcomes[$af_key] : 0)) ?></dd>
            <?php endforeach; ?>
        </dl>
        <?php if ($unanswered !== []) : ?>
            <h3><?= e(t('Searches that ended with nothing')) ?></h3>
            <ol data-af-search-unanswered>
                <?php foreach ($unanswered as $af_row) : ?>
                    <li><strong><?= e($af_row['phrase']) ?></strong> <span class="af-muted">× <?= e((int) $af_row['hits']) ?></span></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>
<div class="af-toolbar">
    <strong><?= e(t('Period')) ?></strong>
    <a class="af-btn af-btn-sm<?= $days === 7 ? ' af-btn-primary' : ' af-btn-ghost' ?>" href="<?= e($af_app->url()->plain('/admin/search', ['days' => 7])) ?>"><?= e(t('7 days')) ?></a>
    <a class="af-btn af-btn-sm<?= $days === 30 ? ' af-btn-primary' : ' af-btn-ghost' ?>" href="<?= e($af_app->url()->plain('/admin/search', ['days' => 30])) ?>"><?= e(t('30 days')) ?></a>
</div>
<div class="af-tabs" role="tablist" data-af-tabs data-af-active="srmissed">
    <?php foreach ($af_tabs as $af_key => $af_label) : ?>
        <button type="button" class="af-tab" role="tab" data-af-tab="<?= e($af_key) ?>" aria-selected="<?= $af_key === 'srmissed' ? 'true' : 'false' ?>"><?= e($af_label) ?></button>
    <?php endforeach; ?>
</div>

<?php foreach (['srmissed' => $missed, 'srpopular' => $popular] as $af_key => $af_rows) : ?>
    <div class="af-tabpanel" role="tabpanel" data-af-panel="<?= e($af_key) ?>"<?= $af_key === 'srmissed' ? '' : ' hidden' ?>>
        <div class="af-card">
            <?php if ($af_rows === []) : ?>
                <div class="af-empty"><h3><?= e($af_key === 'srmissed' ? t('Every search found something') : t('Nobody has searched yet')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead>
                            <tr>
                                <th><?= e(t('Search')) ?></th>
                                <th class="af-right"><?= e(t('Times')) ?></th>
                                <th class="af-right af-hide-sm"><?= e(t('Results')) ?></th>
                                <th class="af-hide-sm"><?= e(t('Language')) ?></th>
                                <?php if ($canEdit) : ?><th class="af-right"><?= e(t('Actions')) ?></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($af_rows as $af_row) : ?>
                                <tr>
                                    <td>
                                        <strong><?= e($af_row['phrase']) ?></strong>
                                        <?php if ($af_row['missed'] && $af_key === 'srpopular') : ?><?= Admin::badge(t('Not found'), 'warn') ?><?php endif; ?>
                                    </td>
                                    <td class="af-right af-num"><?= e((int) $af_row['hits']) ?></td>
                                    <td class="af-right af-num af-hide-sm"><?= e((int) $af_row['found']) ?></td>
                                    <td class="af-hide-sm"><?= e(strtoupper($af_row['lang'])) ?></td>
                                    <?php if ($canEdit) : ?>
                                        <td class="af-right">
                                            <a class="af-btn af-btn-sm" href="<?= e($synonymUrl($af_app, $af_row['phrase'])) ?>"><?= Admin::icon('plus') ?><?= e(t('Create synonym')) ?></a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="af-tabpanel" role="tabpanel" data-af-panel="srunderstood" hidden>
    <div class="af-card">
        <div class="af-card-body af-stack">
            <?php if ($filters === []) : ?>
                <div class="af-empty"><h3><?= e(t('Nobody has searched with filters yet')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead>
                            <tr>
                                <th><?= e(t('What the search understood')) ?></th>
                                <th class="af-right"><?= e(t('Times')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filters as $af_kind => $af_hits) : ?>
                                <tr>
                                    <td><?= e(isset($af_kinds[$af_kind]) ? $af_kinds[$af_kind] : $af_kind) ?></td>
                                    <td class="af-right af-num"><?= e((int) $af_hits) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <h3><?= e(t('Words that mean the same')) ?></h3>
            <?php if ($synonyms === '') : ?>
                <p class="af-muted"><?= e(t('No words set yet.')) ?></p>
            <?php else : ?>
                <pre><?= e($synonyms) ?></pre>
            <?php endif; ?>
            <?php if ($canEdit) : ?>
                <p><a class="af-btn af-btn-sm" href="<?= e($af_app->url()->plain('/admin/settings/search')) ?>"><?= e(t('Change them in the settings')) ?></a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="srindex" hidden>
    <div class="af-card">
        <div class="af-card-body af-stack">
            <dl class="af-kv">
                <?php foreach ($af_typeNames as $af_type => $af_name) : ?>
                    <?php if (isset($counts[$af_type])) : ?>
                        <dt><?= e($af_name) ?></dt>
                        <dd><?= e((int) $counts[$af_type]) ?></dd>
                    <?php endif; ?>
                <?php endforeach; ?>
                <dt><?= e(t('Last indexed')) ?></dt>
                <dd><?= $last !== null ? e($last . ' UTC') : e(t('Never')) ?></dd>
                <dt><?= e(t('Waiting in the queue')) ?></dt>
                <dd><?= e((int) $pending) ?></dd>
            </dl>
            <?php if ($counts === []) : ?>
                <p class="af-alert af-alert-warn"><?= e(t('The index is empty. Press Reindex to build it.')) ?></p>
            <?php endif; ?>
            <?php if ($canEdit) : ?>
                <form method="post" action="<?= e($af_app->url()->plain('/admin/search/reindex')) ?>">
                    <?= Admin::tokenField($af_app) ?>
                    <button type="submit" class="af-btn af-btn-primary" data-af-confirm="<?= e(t('Rebuild the whole search index?')) ?>"><?= Admin::icon('refresh') ?><?= e(t('Reindex')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
