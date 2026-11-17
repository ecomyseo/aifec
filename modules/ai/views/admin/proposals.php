<?php
/**
 * AIFEC — bandeja de propuestas de la IA: pestañas por estado con su número y la tabla con búsqueda, filtros y acciones en bloque.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs  cada una: label, url, count, kind, active
 * @var string $table tabla ya pintada (HTML de confianza)
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><nav class="af-tabs" aria-label="<?= e(t('Quick views')) ?>">
<?php foreach ($tabs as $tab) : ?>
    <a class="af-tab" href="<?= e($tab['url']) ?>"<?= !empty($tab['active']) ? ' aria-selected="true" aria-current="page"' : '' ?>><?= e($tab['label']) ?><?= Admin::badge((string) (int) $tab['count'], (int) $tab['count'] > 0 ? (string) $tab['kind'] : '') ?></a>
<?php endforeach; ?>
</nav>
<?= $table ?>
