<?php
/**
 * AIFEC — los textos de la interfaz de un idioma: buscador, filtro («todo», «lo que falta», «propuesto por la IA») y de 50 en 50;
 * cada fila con su origen, el texto inglés y un campo. Se guarda por página; una propuesta de la IA se aprueba o se descarta fila a
 * fila (o con «Aprobar todo» en la tabla de cobertura).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $lang
 * @var string                         $area    shop o panel
 * @var array<int,array<string,mixed>> $rows    key, origin, code (traducción de serie), site (la del sitio), proposal (la de la IA)
 * @var int                            $total
 * @var int                            $page
 * @var int                            $pages
 * @var string                         $q
 * @var string                         $filter  all, missing o proposed
 * @var bool                           $canEdit
 * @var string                         $url     dirección de esta lista
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
$filters = ['all' => t('All'), 'missing' => t('Only what is missing'), 'proposed' => t('Proposed by AI')];
$link = static function (int $target) use ($url, $q, $filter): string {
    $params = [];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($filter !== 'all') {
        $params['f'] = $filter;
    }
    if ($target > 1) {
        $params['page'] = $target;
    }

    return $url . ($params !== [] ? '?' . http_build_query($params) : '');
};
?><form method="get" action="<?= e($url) ?>" class="af-toolbar" role="search">
    <input class="af-input" type="search" name="q" value="<?= e($q) ?>" aria-label="<?= e(t('Search in English or in the translation')) ?>">
    <select class="af-select" name="f" aria-label="<?= e(t('Show')) ?>">
<?php foreach ($filters as $key => $label) : ?>
        <option value="<?= e($key) ?>"<?= $key === $filter ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="af-btn"><?= e(t('Search')) ?></button>
    <span class="af-muted af-small" data-tr-total><?= e(tn('{n} text', '{n} texts', $total)) ?></span>
</form>
<?php if ($rows === []) : ?>
<div class="af-empty"><h3><?= e(t('Nothing to show')) ?></h3></div>
<?php else : ?>
<form method="post" action="<?= e($url) ?>" class="af-form" novalidate data-tr-strings>
    <?= Admin::tokenField($app) ?>
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="f" value="<?= e($filter) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
<?php if ($canEdit) : ?>
    <?php // El botón que se pulsa al dar a Intro es el primero del formulario: aquí, guardar. ?>
    <button type="submit" name="action" value="save" tabindex="-1" aria-hidden="true" class="af-sr"><?= e(t('Save')) ?></button>
<?php endif; ?>
    <div class="af-table-wrap"><table class="af-table">
        <thead><tr>
            <th scope="col"><?= e(t('Original (English)')) ?></th>
            <th scope="col"><?= e(t('Translation')) ?> (<?= e(strtoupper($lang)) ?>)</th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $i => $row) : ?>
<?php
    $isProposal = (string) $row['site'] === '' && (string) $row['proposal'] !== '';
    $value = (string) $row['site'] !== '' ? (string) $row['site'] : (string) $row['proposal'];
    $long = mb_strlen((string) $row['key']) > 80 || strpos((string) $row['key'], "\n") !== false;
?>
            <tr data-tr-string="<?= (int) $i ?>">
                <td>
                    <span class="af-mono"><?= e((string) $row['key']) ?></span>
                    <div class="af-small af-muted"><?= e((string) $row['origin']) ?></div>
                </td>
                <td>
                    <input type="hidden" name="k[<?= (int) $i ?>]" value="<?= e((string) $row['key']) ?>">
                    <input type="hidden" name="o[<?= (int) $i ?>]" value="<?= e($value) ?>">
                    <input type="hidden" name="p[<?= (int) $i ?>]" value="<?= $isProposal ? '1' : '0' ?>">
<?php if ($long) : ?>
                    <textarea class="af-textarea" name="v[<?= (int) $i ?>]" rows="3" aria-label="<?= e(t('Translation')) ?>"<?= $canEdit ? '' : ' readonly' ?>><?= e($value) ?></textarea>
<?php else : ?>
                    <input class="af-input" type="text" name="v[<?= (int) $i ?>]" value="<?= e($value) ?>" aria-label="<?= e(t('Translation')) ?>" autocomplete="off"<?= $canEdit ? '' : ' readonly' ?>>
<?php endif; ?>
<?php if ($isProposal) : ?>
                    <div class="af-small"><?= Admin::badge(t('AI proposal'), 'info') ?>
<?php if ($canEdit) : ?>
                        <button type="submit" name="action" value="approve:<?= (int) $i ?>" class="af-btn af-btn-sm af-btn-primary" data-tr-approve-one><?= e(t('Approve')) ?></button>
                        <button type="submit" name="action" value="reject:<?= (int) $i ?>" class="af-btn af-btn-sm" data-tr-reject-one><?= e(t('Discard')) ?></button>
<?php endif; ?>
                    </div>
<?php endif; ?>
<?php if ((string) $row['code'] !== '' && (string) $row['code'] !== $value) : ?>
                    <div class="af-hint"><?= e(t('Default:')) ?> <span class="af-mono"><?= e((string) $row['code']) ?></span></div>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php if ($canEdit) : ?>
    <div class="af-form-actions">
        <button type="submit" name="action" value="save" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save this page')) ?></button>
    </div>
<?php endif; ?>
</form>
<?php if ($pages > 1) : ?>
<div class="af-pagination">
<?php if ($page > 1) : ?>
    <a class="af-btn af-btn-sm" href="<?= e($link($page - 1)) ?>"><?= e(t('Previous')) ?></a>
<?php endif; ?>
    <span class="af-muted af-small"><?= e(t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
<?php if ($page < $pages) : ?>
    <a class="af-btn af-btn-sm" href="<?= e($link($page + 1)) ?>" data-tr-next><?= e(t('Next')) ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
