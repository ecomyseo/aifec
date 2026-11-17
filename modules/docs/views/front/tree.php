<?php
/**
 * AIFEC — el árbol de la documentación (secciones y páginas) con la página actual marcada. Lo usan el índice, las páginas y el buscador.
 * Las páginas de una subcarpeta de la sección (la referencia generada) salen agrupadas bajo su rótulo, plegadas si son más de cinco.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tree    secciones con sus páginas (slug, title, pages)
 * @var string                         $current dirección de la página actual (`guia/instalar`), o vacío
 */

$af_tree = isset($tree) && is_array($tree) ? $tree : [];
$af_current = isset($current) ? (string) $current : '';
?>
<?php foreach ($af_tree as $af_section) : ?>
    <?php
    // Las páginas seguidas del mismo grupo van juntas: el grupo vacío son las propias de la sección.
    $af_runs = [];
    foreach ($af_section['pages'] as $af_item) {
        $af_group = isset($af_item['group']) ? (string) $af_item['group'] : '';
        $af_last = count($af_runs) - 1;
        if ($af_last < 0 || $af_runs[$af_last]['group'] !== $af_group) {
            $af_runs[] = ['group' => $af_group, 'title' => isset($af_item['group_title']) ? (string) $af_item['group_title'] : '', 'pages' => []];
            $af_last = count($af_runs) - 1;
        }
        $af_runs[$af_last]['pages'][] = $af_item;
    }
    ?>
    <div class="af-docs-group" id="s-<?= e((string) $af_section['slug']) ?>">
        <?php if ((string) $af_section['title'] !== '') : ?>
            <p class="af-docs-group-title"><?= e((string) $af_section['title']) ?></p>
        <?php endif; ?>
        <?php foreach ($af_runs as $af_run) : ?>
            <?php
            $af_folded = $af_run['group'] !== '' && count($af_run['pages']) > 5;
            $af_open = false;
            foreach ($af_run['pages'] as $af_item) {
                if ($af_item['path'] === $af_current) {
                    $af_open = true;
                }
            }
            ?>
            <?php if ($af_run['group'] !== '' && $af_folded) : ?>
                <details class="af-docs-sub" data-docs-folded<?= $af_open ? ' open' : '' ?>>
                    <summary><?= e($af_run['title']) ?> <span class="af-small af-muted">(<?= (int) count($af_run['pages']) ?>)</span></summary>
            <?php elseif ($af_run['group'] !== '') : ?>
                <p class="af-docs-subtitle"><?= e($af_run['title']) ?></p>
            <?php endif; ?>
            <ul class="af-docs-list">
                <?php foreach ($af_run['pages'] as $af_item) : ?>
                    <li>
                        <a href="<?= e(url('/docs/' . $af_item['path'])) ?>"<?= $af_item['path'] === $af_current ? ' aria-current="page"' : '' ?>><?= e((string) $af_item['title']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($af_folded) : ?>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
