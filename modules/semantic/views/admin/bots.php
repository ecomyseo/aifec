<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Robots y asistentes: en pestañas, el resumen con la gráfica diaria, los robots, los orígenes de IA con sus pedidos y lo que
 * más leen los asistentes.
 *
 * @var array<string,mixed>  $report    datos de BotsReport::build()
 * @var string               $chart     la gráfica en SVG (ya escapada)
 * @var array<string,string> $classes   clase de página => nombre
 * @var array<string,string> $families  familia de robots => nombre
 * @var string               $robotsUrl dirección de los ajustes de robots.txt ('' si no hay módulo SEO)
 * @var callable             $money     céntimos => importe con su moneda
 */

$af_tabs = ['smbsummary' => t('Summary'), 'smbrobots' => t('Robots'), 'smbsources' => t('Assistants and sales'), 'smbpages' => t('What assistants read')];
$af_totals = $report['totals'];
?>
<?php if (!empty($report['blocked'])) : ?>
    <div class="af-alert af-alert-warn" role="status">
        <span><?= e(t('The AI assistant robots are blocked in robots.txt: they cannot read the shop.')) ?>
            <?php if ($robotsUrl !== '') : ?><a href="<?= e($robotsUrl) ?>"><?= e(t('Change it')) ?></a><?php endif; ?></span>
    </div>
<?php endif; ?>

<div class="af-tabs" role="tablist" data-af-tabs data-af-active="smbsummary">
    <?php foreach ($af_tabs as $af_key => $af_label) : ?>
        <button type="button" class="af-tab" role="tab" data-af-tab="<?= e($af_key) ?>" aria-selected="<?= $af_key === 'smbsummary' ? 'true' : 'false' ?>"><?= e($af_label) ?></button>
    <?php endforeach; ?>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smbsummary">
    <div class="af-grid">
        <?php foreach ([
            [t('Robot visits, 7 days'), $af_totals['bot7']],
            [t('Robot visits, 30 days'), $af_totals['bot30']],
            [t('Visits from assistants, 7 days'), $af_totals['ref7']],
            [t('Visits from assistants, 30 days'), $af_totals['ref30']],
        ] as $af_stat) : ?>
            <div class="af-col-3">
                <div class="af-card">
                    <div class="af-stat">
                        <div class="af-stat-label"><?= e($af_stat[0]) ?></div>
                        <div class="af-stat-value"><?= e((int) $af_stat[1]) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Visits per day')) ?></h2></div>
        <div class="af-card-body">
            <?= $chart ?>
            <p class="af-small af-muted">
                <span style="color:var(--accent)">&#9632;</span> <?= e(t('Robots')) ?>
                &nbsp; <span style="color:var(--muted)">&#9632;</span> <?= e(t('From assistants')) ?>
            </p>
        </div>
    </div>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smbrobots" hidden>
    <div class="af-card">
        <?php if ($report['bots'] === []) : ?>
            <div class="af-empty"><h3><?= e(t('No robot has visited the shop yet')) ?></h3><p><?= e(t('The visits appear here within an hour of the first one.')) ?></p></div>
        <?php else : ?>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead>
                        <tr>
                            <th><?= e(t('Robot')) ?></th>
                            <th><?= e(t('Family')) ?></th>
                            <th class="af-right"><?= e(t('Visits (30 days)')) ?></th>
                            <th class="af-hide-sm"><?= e(t('Last visit')) ?></th>
                            <th class="af-hide-sm"><?= e(t('What it reads')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['bots'] as $af_bot) : ?>
                        <?php
                        $af_reads = [];
                        foreach ($af_bot['reads'] as $af_class => $af_n) {
                            $af_reads[] = (isset($classes[$af_class]) ? $classes[$af_class] : $af_class) . ' ' . $af_n;
                        }
                        ?>
                        <tr>
                            <td><strong><?= e($af_bot['name']) ?></strong></td>
                            <td><?= e(isset($families[$af_bot['family']]) ? $families[$af_bot['family']] : $af_bot['family']) ?></td>
                            <td class="af-right af-num"><?= e((int) $af_bot['visits']) ?></td>
                            <td class="af-hide-sm af-nowrap"><?= e($af_bot['last']) ?> UTC</td>
                            <td class="af-hide-sm af-small"><?= e(implode(' · ', $af_reads)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smbsources" hidden>
    <div class="af-card">
        <?php if ($report['sources'] === []) : ?>
            <div class="af-empty"><h3><?= e(t('Nobody has arrived from an assistant yet')) ?></h3><p><?= e(t('ChatGPT, Perplexity, Gemini, Copilot, Claude and others show up here when they send a visitor.')) ?></p></div>
        <?php else : ?>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead>
                        <tr>
                            <th><?= e(t('Assistant')) ?></th>
                            <th class="af-right"><?= e(t('Visits (30 days)')) ?></th>
                            <th class="af-right"><?= e(t('Orders')) ?></th>
                            <th class="af-right"><?= e(t('Amount')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['sources'] as $af_source) : ?>
                        <tr>
                            <td><strong><?= e($af_source['name']) ?></strong></td>
                            <td class="af-right af-num"><?= e((int) $af_source['visits']) ?></td>
                            <td class="af-right af-num"><?= e((int) $af_source['orders']) ?></td>
                            <td class="af-right af-num af-nowrap"><?= e($money((int) $af_source['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="af-tabpanel" role="tabpanel" data-af-panel="smbpages" hidden>
    <div class="af-card">
        <?php if ($report['pages'] === []) : ?>
            <div class="af-empty"><h3><?= e(t('Assistants have not read anything yet')) ?></h3></div>
        <?php else : ?>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead>
                        <tr>
                            <th><?= e(t('Pages')) ?></th>
                            <th class="af-right"><?= e(t('Read by assistant robots')) ?></th>
                            <th class="af-right"><?= e(t('Opened by visitors from assistants')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['pages'] as $af_page) : ?>
                        <tr>
                            <td><?= e(isset($classes[$af_page['class']]) ? $classes[$af_page['class']] : $af_page['class']) ?></td>
                            <td class="af-right af-num"><?= e((int) $af_page['bots']) ?></td>
                            <td class="af-right af-num"><?= e((int) $af_page['refs']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
