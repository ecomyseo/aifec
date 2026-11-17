<?php
/**
 * AIFEC — GM AI notice: el recuadro del aviso (ficha de producto y páginas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $where  sitio donde sale: product-summary, product-bottom o page
 * @var string $title
 * @var string $text
 * @var string $url    página que explica cómo se hace ('' si no hay)
 * @var string $label  texto del enlace
 */

declare(strict_types=1);

?>
<aside class="af-alert af-alert-info gm-aviso-ia gm-aviso-ia--<?= e($where) ?>" role="note">
    <p><span class="af-badge"><?= e(t('AI')) ?></span> <strong><?= e($title) ?></strong></p>
    <p class="af-small"><?= e($text) ?></p>
<?php if ($url !== '') : ?>
    <p class="af-small"><a href="<?= e($url) ?>"><?= e($label) ?></a></p>
<?php endif; ?>
</aside>
