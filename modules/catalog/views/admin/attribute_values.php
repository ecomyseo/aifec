<?php
/**
 * AIFEC — valores de un atributo de lista: el formulario con la tabla editable, o la lista de solo lectura.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $form    formulario ya pintado (HTML de confianza); vacío si la persona solo puede ver
 * @var bool   $canEdit puede cambiar los valores
 * @var array<int,array<string,mixed>> $rows valores: code, color y label_<idioma>
 * @var string $lang    idioma principal
 * @var bool   $isColor el atributo es de color
 */

declare(strict_types=1);

?><?php if ($canEdit) : ?>
<?= $form ?>
<?php elseif ($rows === []) : ?>
<div class="af-card"><div class="af-empty"><h3><?= e(t('Nothing here yet')) ?></h3></div></div>
<?php else : ?>
<div class="af-card">
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th scope="col"><?= e(t('Label')) ?></th>
                <th scope="col"><?= e(t('Code')) ?></th>
                <?php if ($isColor) : ?><th scope="col"><?= e(t('Colour')) ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?= e(isset($row['label_' . $lang]) ? $row['label_' . $lang] : $row['code']) ?></strong></td>
                    <td class="af-mono"><?= e($row['code']) ?></td>
                    <?php if ($isColor) : ?><td class="af-mono"><?= e(isset($row['color']) ? $row['color'] : '') ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
