<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Auditoría SEO: una fila por comprobación, con su número y, si hay problemas, unos casos con su enlace.
 *
 * @var array<int,array<string,mixed>> $checks id, label, count, items (label, url), url
 * @var int                            $total
 * @var bool                           $closed
 * @var string                         $sitemap
 * @var string                         $robots
 */

use Aifec\Admin\Admin;

?>
<?php if ($closed) : ?>
    <div class="af-alert af-alert-bad" role="status"><span><?= e(t('The whole site is hidden from search engines. Turn it off when the shop is ready.')) ?></span></div>
<?php endif; ?>

<div class="af-card">
    <div class="af-card-head">
        <h2><?= e(tn('{n} problem found', '{n} problems found', $total)) ?></h2>
    </div>
    <?php if ($checks === []) : ?>
        <div class="af-empty"><h3><?= e(t('Nothing to check yet')) ?></h3><p><?= e(t('Add products, categories or pages and their problems will show up here.')) ?></p></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Check')) ?></th>
                        <th class="af-num"><?= e(t('Problems')) ?></th>
                        <th><?= e(t('Where to fix them')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check) : ?>
                    <tr>
                        <td><?= e((string) $check['label']) ?></td>
                        <td class="af-num"><?= Admin::badge((string) (int) $check['count'], (int) $check['count'] > 0 ? 'warn' : 'ok') ?></td>
                        <td>
                            <?php if ((int) $check['count'] > 0) : ?>
                                <details>
                                    <summary><?= e(t('Show some')) ?></summary>
                                    <ul>
                                    <?php foreach ($check['items'] as $item) : ?>
                                        <li><a href="<?= e((string) $item['url']) ?>"><?= e((string) $item['label']) ?></a></li>
                                    <?php endforeach; ?>
                                    </ul>
                                    <a class="af-btn af-btn-sm" href="<?= e((string) $check['url']) ?>"><?= e(t('Open the list')) ?></a>
                                </details>
                            <?php else : ?>
                                <span class="af-muted af-small"><?= e(t('All good')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('What the robots see')) ?></h2></div>
    <div class="af-card-body">
        <ul>
            <li><a href="<?= e($sitemap) ?>" target="_blank" rel="noopener"><?= e(t('Sitemap')) ?></a> <span class="af-muted af-mono af-small"><?= e($sitemap) ?></span></li>
            <li><a href="<?= e($robots) ?>" target="_blank" rel="noopener">robots.txt</a> <span class="af-muted af-mono af-small"><?= e($robots) ?></span></li>
        </ul>
    </div>
</div>
