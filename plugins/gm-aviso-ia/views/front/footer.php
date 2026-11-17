<?php
/**
 * AIFEC — GM AI notice: el aviso corto del pie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $text
 * @var string $url
 * @var string $label
 */

declare(strict_types=1);

?>
<div class="af-container af-small af-muted gm-aviso-ia-footer" role="note"><?= e($text) ?><?php if ($url !== '') : ?> <a href="<?= e($url) ?>"><?= e($label) ?></a><?php endif; ?></div>
