<?php
/**
 * AIFEC — índice de ajustes: una tarjeta por cada grupo que declara un módulo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $groups
 */

use Aifec\Admin\Admin;

?>
<?php if ($groups === []) : ?>
    <div class="af-card"><div class="af-empty"><h3><?= e(t('Nothing to configure here')) ?></h3></div></div>
<?php else : ?>
    <div class="af-grid">
        <?php foreach ($groups as $group) : ?>
            <a class="af-card af-col-4 af-tile" href="<?= e($group['url']) ?>">
                <div class="af-card-body">
                    <h2><?= Admin::icon((string) $group['icon']) ?> <?= e($group['title']) ?></h2>
                    <?php if ($group['description'] !== '') : ?>
                        <p class="af-muted af-small" style="margin: var(--s2) 0 0"><?= e($group['description']) ?></p>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
