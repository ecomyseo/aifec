<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Auditoría AIFEC: la nota del sitio en grande, en una pestaña las comprobaciones del sitio y en otra los productos
 * (distribución de notas y los 50 peores, con filtro por lo que falta).
 *
 * @var array<string,mixed>                                                   $site     score y checks (id, ok, label, fix_url, weight)
 * @var int                                                                   $measured productos con nota
 * @var string|null                                                           $last     última vez que se puntuó algo (UTC)
 * @var array<int,array{from:int,to:int,kind:string,count:int}>               $bands    productos por tramo de nota
 * @var array<int,array<string,mixed>>                                        $rows     los peores: id, name, score, url, first, fix, more
 * @var array<string,string>                                                  $options  comprobaciones de producto: código => nombre
 * @var string                                                                $missing  filtro elegido
 * @var string                                                                $action   dirección de esta pantalla
 */

use Aifec\Admin\Admin;

$af_score = (int) $site['score'];
$af_kind = $af_score >= 80 ? 'ok' : ($af_score >= 50 ? 'warn' : 'bad');
$af_failed = 0;
foreach ($site['checks'] as $af_check) {
    if (!$af_check['ok']) {
        ++$af_failed;
    }
}
$af_tab = $missing !== '' ? 'smproducts' : 'smsite';
$af_tabs = ['smsite' => t('The site'), 'smproducts' => t('Products')];
?>
<div class="af-card">
    <div class="af-stat">
        <div class="af-stat-label"><?= e(t('AIFEC score of the shop')) ?></div>
        <div class="af-stat-value af-tone-<?= e($af_kind) ?>"><?= e($af_score) ?><span class="af-muted" style="font-size: var(--f3)"> / 100</span></div>
        <div class="af-stat-note">
            <?= $af_failed === 0 ? Admin::badge(t('Nothing to fix'), 'ok') : Admin::badge(tn('{n} check to fix', '{n} checks to fix', $af_failed), $af_kind) ?>
        </div>
    </div>
</div>

<div class="af-tabs" role="tablist" data-af-tabs data-af-active="<?= e($af_tab) ?>">
    <?php foreach ($af_tabs as $af_key => $af_label) : ?>
        <button type="button" class="af-tab" role="tab" data-af-tab="<?= e($af_key) ?>" aria-selected="<?= $af_key === $af_tab ? 'true' : 'false' ?>"><?= e($af_label) ?></button>
    <?php endforeach; ?>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smsite"<?= $af_tab === 'smsite' ? '' : ' hidden' ?>>
    <div class="af-card">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Check')) ?></th>
                        <th><?= e(t('Result')) ?></th>
                        <th class="af-right af-hide-sm"><?= e(t('Weight')) ?></th>
                        <th class="af-right"><?= e(t('Where to fix it')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($site['checks'] as $af_check) : ?>
                    <tr>
                        <td><?= e((string) $af_check['label']) ?></td>
                        <td><?= Admin::badge($af_check['ok'] ? t('Met') : t('Missing'), $af_check['ok'] ? 'ok' : 'warn') ?></td>
                        <td class="af-right af-num af-hide-sm"><?= e((int) $af_check['weight']) ?></td>
                        <td class="af-right">
                            <?php if (!$af_check['ok']) : ?>
                                <a class="af-btn af-btn-sm" href="<?= e((string) $af_check['fix_url']) ?>"><?= e(t('Fix it')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smproducts"<?= $af_tab === 'smproducts' ? '' : ' hidden' ?>>
    <?php if ($measured === 0) : ?>
        <div class="af-card">
            <div class="af-empty">
                <h3><?= e(t('The products have not been measured yet')) ?></h3>
                <p><?= e(t('Press «Measure the products now»: it takes a few minutes and runs in the background.')) ?></p>
            </div>
        </div>
    <?php else : ?>
        <div class="af-grid">
            <?php foreach ($bands as $af_band) : ?>
                <div class="af-col-3">
                    <div class="af-card">
                        <div class="af-stat">
                            <div class="af-stat-label"><?= e(t('Score {from} to {to}', ['from' => $af_band['from'], 'to' => $af_band['to']])) ?></div>
                            <div class="af-stat-value af-tone-<?= e($af_band['kind']) ?>"><?= e($af_band['count']) ?></div>
                            <div class="af-stat-note"><?= e(tn('{n} product', '{n} products', (int) $af_band['count'])) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($last !== null) : ?>
            <p class="af-muted af-small"><?= e(t('Last measured: {date} UTC', ['date' => $last])) ?></p>
        <?php endif; ?>

        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('The 50 worst products')) ?></h2></div>
            <form class="af-toolbar" method="get" action="<?= e($action) ?>#tab=smproducts">
                <select class="af-select" name="missing" aria-label="<?= e(t('What is missing')) ?>" data-af-autosubmit>
                    <option value=""><?= e(t('Everything that is missing')) ?></option>
                    <?php foreach ($options as $af_id => $af_name) : ?>
                        <option value="<?= e($af_id) ?>"<?= $af_id === $missing ? ' selected' : '' ?>><?= e($af_name) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Filter')) ?></button>
                <?php if ($missing !== '') : ?><a class="af-btn af-btn-ghost" href="<?= e($action) ?>#tab=smproducts"><?= e(t('Clear')) ?></a><?php endif; ?>
            </form>
            <?php if ($rows === []) : ?>
                <div class="af-empty"><h3><?= e(t('No product misses this')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead>
                            <tr>
                                <th><?= e(t('Product')) ?></th>
                                <th class="af-right"><?= e(t('Score')) ?></th>
                                <th><?= e(t('First thing missing')) ?></th>
                                <th class="af-right"><?= e(t('Where to fix it')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $af_row) : ?>
                            <?php $af_rowKind = (int) $af_row['score'] >= 90 ? 'ok' : ((int) $af_row['score'] >= 70 ? 'info' : ((int) $af_row['score'] >= 50 ? 'warn' : 'bad')); ?>
                            <tr>
                                <td><a href="<?= e((string) $af_row['url']) ?>"><strong><?= e((string) $af_row['name']) ?></strong></a></td>
                                <td class="af-right af-num"><?= Admin::badge((string) (int) $af_row['score'], $af_rowKind) ?></td>
                                <td>
                                    <?= e((string) $af_row['first']) ?>
                                    <?php if ((int) $af_row['more'] > 0) : ?><span class="af-muted af-small"><?= e(t('and {n} more', ['n' => (int) $af_row['more']])) ?></span><?php endif; ?>
                                </td>
                                <td class="af-right">
                                    <?php if ((string) $af_row['fix'] !== '') : ?><a class="af-btn af-btn-sm" href="<?= e((string) $af_row['fix']) ?>"><?= e(t('Fix it')) ?></a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
