<?php
/**
 * AIFEC — «Ver y editar» de una fila de contenido (productos, categorías, atributos, páginas, menús): cada elemento con el estado de
 * cada campo en ese idioma y el enlace a su ficha, que es donde se edita. Las propuestas de la IA se revisan en su bandeja.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string              $lang
 * @var string              $label        nombre de la fila
 * @var string              $blocked      por qué la IA no rellena esta fila ('' = sí la rellena)
 * @var array<string,mixed> $list         Scope::rows(): fields, rows, total
 * @var int                 $page
 * @var int                 $pages
 * @var string              $url          dirección de esta lista
 * @var string              $proposalsUrl bandeja de la IA ('' = sin ella)
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$states = [
    'ok' => [t('Translated'), 'ok'],
    'proposed' => [t('Proposed by AI'), 'info'],
    'missing' => [t('Missing'), 'warn'],
];
?><div class="af-toolbar">
    <span class="af-muted af-small"><?= e(tn('{n} item', '{n} items', (int) $list['total'])) ?></span>
<?php if ($proposalsUrl !== '') : ?>
    <a class="af-btn af-btn-sm" href="<?= e($proposalsUrl) ?>"><?= e(t('Review the AI proposals')) ?></a>
<?php endif; ?>
</div>
<?php if ($blocked !== '') : ?>
<div class="af-alert af-alert-info" role="status"><?= e($blocked) ?></div>
<?php endif; ?>
<?php if ($list['rows'] === []) : ?>
<div class="af-empty"><h3><?= e(t('Nothing to show')) ?></h3></div>
<?php else : ?>
<div class="af-table-wrap"><table class="af-table" data-tr-content>
    <thead><tr>
        <th scope="col"><?= e($label) ?></th>
<?php foreach ($list['fields'] as $name) : ?>
        <th scope="col"><?= e((string) $name) ?></th>
<?php endforeach; ?>
    </tr></thead>
    <tbody>
<?php foreach ($list['rows'] as $row) : ?>
        <tr>
            <td><a href="<?= e((string) $row['url']) ?>"><?= e((string) $row['label'] !== '' ? (string) $row['label'] : '—') ?></a></td>
<?php foreach (array_keys($list['fields']) as $field) : ?>
<?php $state = isset($row['fields'][$field]) ? (string) $row['fields'][$field] : 'none'; ?>
            <td data-tr-state="<?= e($state) ?>"><?= isset($states[$state]) ? Admin::badge($states[$state][0], $states[$state][1]) : '<span class="af-muted">—</span>' ?></td>
<?php endforeach; ?>
        </tr>
<?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($pages > 1) : ?>
<div class="af-pagination">
<?php if ($page > 1) : ?>
    <a class="af-btn af-btn-sm" href="<?= e($url . ($page - 1 > 1 ? '?page=' . ($page - 1) : '')) ?>"><?= e(t('Previous')) ?></a>
<?php endif; ?>
    <span class="af-muted af-small"><?= e(t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
<?php if ($page < $pages) : ?>
    <a class="af-btn af-btn-sm" href="<?= e($url . '?page=' . ($page + 1)) ?>"><?= e(t('Next')) ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
