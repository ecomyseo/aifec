<?php
/**
 * AIFEC — GM legal texts per product: los textos legales y la casilla de la renuncia al desistimiento, dentro del formulario de la caja.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{id:int,title:string,kind:string,paragraphs:string[]}> $blocks
 * @var bool                                                                  $waiver
 * @var string                                                                $field
 * @var string                                                                $waiver_text
 */

declare(strict_types=1);

?>
<div class="af-stack gm-legal-checkout">
<?php if ($blocks !== []) : ?>
    <?= $this->partial('gm-legal-producto::front/blocks', ['blocks' => $blocks]) ?>
<?php endif; ?>
<?php if ($waiver) : ?>
    <label class="af-check" for="gm-legal-waiver">
        <input id="gm-legal-waiver" type="checkbox" name="<?= e($field) ?>" value="1">
        <span><?= e($waiver_text) ?></span>
    </label>
    <p class="af-small af-muted"><?= e(t('This box is optional. If you do not tick it, you keep your right of withdrawal.')) ?></p>
<?php endif; ?>
</div>
