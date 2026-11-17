<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El panel de WebMCP: herramientas publicadas y formularios marcados, con su estado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool                           $enabled
 * @var array<int,array<string,mixed>> $tools   name, description, read_only
 * @var array<int,array<string,mixed>> $forms   name, description, fields, templates
 * @var string                         $url     dirección de la lista de herramientas
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><?php if (!$enabled) : ?>
<p class="af-alert af-alert-warn" role="status"><?= e(t('WebMCP is turned off: the pages carry no script, no form marks and no tool list.')) ?></p>
<?php else : ?>
<p class="af-alert af-alert-ok" role="status"><?= e(t('WebMCP is on.')) ?> <a href="<?= e($url) ?>"><?= e(t('Tool list')) ?></a></p>
<?php endif; ?>
<h2><?= e(t('Published tools')) ?></h2>
<div class="af-table-wrap">
    <table class="af-table">
        <caption class="af-sr"><?= e(t('Published tools')) ?></caption>
        <thead>
            <tr>
                <th scope="col"><?= e(t('Tool')) ?></th>
                <th scope="col" class="af-hide-sm"><?= e(t('What it does')) ?></th>
                <th scope="col"><?= e(t('Kind')) ?></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($tools as $tool) : ?>
            <tr>
                <td><code><?= e((string) $tool['name']) ?></code></td>
                <td class="af-hide-sm"><?= e((string) $tool['description']) ?></td>
                <td><?= !empty($tool['read_only']) ? Admin::badge(t('Reads'), 'info') : Admin::badge(t('Changes something'), 'warn') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<h2><?= e(t('Marked forms')) ?></h2>
<div class="af-table-wrap">
    <table class="af-table">
        <caption class="af-sr"><?= e(t('Marked forms')) ?></caption>
        <thead>
            <tr>
                <th scope="col"><?= e(t('Form')) ?></th>
                <th scope="col" class="af-hide-sm"><?= e(t('What it does')) ?></th>
                <th scope="col" class="af-num"><?= e(t('Fields')) ?></th>
                <th scope="col"><?= e(t('State')) ?></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($forms as $form) : ?>
            <tr>
                <td><code><?= e((string) $form['name']) ?></code></td>
                <td class="af-hide-sm"><?= e((string) $form['description']) ?></td>
                <td class="af-num"><?= (int) $form['fields'] ?></td>
                <td><?= $form['templates'] !== [] ? Admin::badge(t('In {n} templates', ['n' => count($form['templates'])]), 'ok') : Admin::badge(t('Not in any template yet'), '') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
