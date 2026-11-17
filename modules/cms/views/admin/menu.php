<?php
/**
 * AIFEC — editor de un menú: lista de elementos ordenable y anidable en dos niveles, con su etiqueta por idioma. Se guarda todo
 * de una vez. El JavaScript (assets/menus.js) añade, mueve, anida y quita filas por delegación; el orden de los campos en el
 * envío es el orden de las filas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $code
 * @var string                         $name
 * @var array<int,array<string,mixed>> $items       type, ref, url, blank, level, labels, text
 * @var string[]                       $languages
 * @var string                         $defaultLang
 * @var array<string,string>           $kinds       tipo => nombre
 * @var bool                           $canEdit
 * @var string                         $saveUrl
 * @var string                         $searchUrl
 * @var string                         $script
 */

use Aifec\Admin\Admin;

$this->push('admin.head', '<link rel="stylesheet" href="' . e(app()->url()->asset('m', 'cms', 'menus.css')) . '">');
$this->push('admin.foot', '<script src="' . e($script) . '" defer></script>');

/**
 * Una fila del editor. $index es el número de la fila o «__i__» en la plantilla.
 *
 * @param array<string,mixed> $item
 */
$row = static function (string $index, array $item) use ($languages, $kinds, $canEdit): string {
    $name = 'items[' . $index . ']';
    $type = (string) $item['type'];
    $html = '<li class="af-menu-row" data-cms-row data-level="' . (int) $item['level'] . '">'
        . '<input type="hidden" name="' . e($name) . '[type]" value="' . e($type) . '">'
        . '<input type="hidden" name="' . e($name) . '[ref]" value="' . e((string) $item['ref']) . '">'
        . '<input type="hidden" name="' . e($name) . '[level]" value="' . (int) $item['level'] . '" data-cms-level>'
        . '<div class="af-menu-main"><span class="af-badge">' . e(isset($kinds[$type]) ? $kinds[$type] : $type) . '</span> ';
    if ($type === 'url') {
        $html .= '<input class="af-input" type="text" name="' . e($name) . '[url]" value="' . e((string) $item['url']) . '" placeholder="https://" aria-label="' . e(t('Address')) . '">';
    } else {
        $html .= '<strong>' . e((string) $item['text']) . '</strong>';
    }
    $html .= '</div><div class="af-menu-labels">';
    foreach ($languages as $lang) {
        $label = isset($item['labels'][$lang]) && is_string($item['labels'][$lang]) ? $item['labels'][$lang] : '';
        $html .= '<input class="af-input" type="text" name="' . e($name) . '[labels][' . e($lang) . ']" value="' . e($label) . '" maxlength="120" placeholder="'
            . e(count($languages) > 1 ? t('Text of the link') . ' (' . strtoupper($lang) . ')' : t('Text of the link')) . '" aria-label="' . e(t('Text of the link') . ' ' . strtoupper($lang)) . '">';
    }
    $html .= '</div><label class="af-check"><input type="hidden" name="' . e($name) . '[blank]" value="0"><input type="checkbox" name="' . e($name) . '[blank]" value="1"'
        . (!empty($item['blank']) ? ' checked' : '') . '><span>' . e(t('New tab')) . '</span></label>';
    if ($canEdit) {
        $html .= '<div class="af-menu-tools">'
            . '<button type="button" class="af-btn af-btn-sm af-btn-ghost" data-cms-up aria-label="' . e(t('Move up')) . '">↑</button>'
            . '<button type="button" class="af-btn af-btn-sm af-btn-ghost" data-cms-down aria-label="' . e(t('Move down')) . '">↓</button>'
            . '<button type="button" class="af-btn af-btn-sm af-btn-ghost" data-cms-indent aria-label="' . e(t('Make it a sub-link')) . '">→</button>'
            . '<button type="button" class="af-btn af-btn-sm af-btn-ghost" data-cms-outdent aria-label="' . e(t('Make it a main link')) . '">←</button>'
            . '<button type="button" class="af-btn af-btn-sm af-btn-ghost af-link-danger" data-cms-remove aria-label="' . e(t('Remove')) . '">×</button></div>';
    }

    return $html . '</li>';
};
?>
<form method="post" action="<?= e($saveUrl) ?>" class="af-form" data-cms-menu data-cms-search="<?= e($searchUrl) ?>" data-cms-next="<?= count($items) ?>">
    <?= Admin::tokenField(app()) ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Links')) ?></h2></div>
        <div class="af-card-body">
            <p class="af-small af-muted"><?= e(t('The order of this list is the order of the menu. A sub-link hangs from the link above it; there are two levels at most.')) ?></p>
            <ul class="af-menu-list" data-cms-list>
                <?php foreach ($items as $i => $item) : ?>
                    <?= $row((string) $i, $item) ?>
                <?php endforeach; ?>
            </ul>
            <p class="af-muted" data-cms-empty<?= $items !== [] ? ' hidden' : '' ?>><?= e(t('This menu has no links yet.')) ?></p>
        </div>
    </div>

    <?php if ($canEdit) : ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Add a link')) ?></h2></div>
            <div class="af-card-body af-menu-add">
                <label class="af-field">
                    <span class="af-label"><?= e(t('What it points to')) ?></span>
                    <select class="af-select" data-cms-type>
                        <?php foreach ($kinds as $kind => $label) : ?>
                            <option value="<?= e($kind) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="af-field" data-cms-find>
                    <label class="af-label" for="cms-q"><?= e(t('Search')) ?></label>
                    <input class="af-input" id="cms-q" type="search" data-cms-q autocomplete="off" placeholder="<?= e(t('Type to search')) ?>">
                    <select class="af-select" size="5" data-cms-pick aria-label="<?= e(t('Results')) ?>"></select>
                </div>
                <div class="af-field" data-cms-free hidden>
                    <label class="af-label" for="cms-url"><?= e(t('Address')) ?></label>
                    <input class="af-input" id="cms-url" type="text" data-cms-url placeholder="https://">
                </div>
                <button type="button" class="af-btn" data-cms-add><?= Admin::icon('plus') ?><?= e(t('Add to the menu')) ?></button>
            </div>
        </div>
        <div class="af-form-actions">
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button>
            <a class="af-btn" href="<?= e(app()->url()->plain('/admin/cms/menus')) ?>"><?= e(t('Cancel')) ?></a>
        </div>
    <?php endif; ?>

    <template data-cms-template><?= $row('__i__', ['type' => 'url', 'ref' => '', 'url' => '', 'blank' => 0, 'level' => 0, 'labels' => [], 'text' => '']) ?></template>
    <div hidden data-cms-kinds data-json="<?= e((string) json_encode($kinds, JSON_UNESCAPED_UNICODE)) ?>"></div>
</form>
