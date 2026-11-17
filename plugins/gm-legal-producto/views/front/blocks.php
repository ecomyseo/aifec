<?php
/**
 * AIFEC — GM legal texts per product: los bloques legales de la ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{id:int,title:string,kind:string,paragraphs:string[]}> $blocks
 */

declare(strict_types=1);

?>
<section class="af-card af-stack gm-legal" aria-label="<?= e(t('Legal information')) ?>">
<?php foreach ($blocks as $af_block) : ?>
    <div class="gm-legal-block gm-legal-block--<?= e($af_block['kind']) ?>">
        <h2 class="af-small"><strong><?= e($af_block['title']) ?></strong></h2>
<?php foreach ($af_block['paragraphs'] as $af_line) : ?>
        <p class="af-small"><?= e($af_line) ?></p>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
</section>
