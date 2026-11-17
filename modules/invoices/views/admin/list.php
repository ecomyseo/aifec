<?php
/**
 * AIFEC — listado de facturas del panel: emitir la de un pedido a mano y la tabla con búsqueda, filtros por fecha, serie y tipo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $table    tabla ya pintada (HTML de confianza)
 * @var bool   $canIssue puede emitir facturas
 * @var string $issueUrl dirección del envío de «emitir»
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<?php if ($canIssue) : ?>
<form method="post" action="<?= e($issueUrl) ?>" class="af-toolbar">
    <?= Admin::tokenField(app()) ?>
    <label class="af-small af-muted" for="af-iv-order"><?= e(t('Issue the invoice of an order')) ?></label>
    <input class="af-input" id="af-iv-order" type="text" name="order" placeholder="<?= e(t('Order number')) ?>" required>
    <button type="submit" class="af-btn"><?= e(t('Issue')) ?></button>
</form>
<?php endif; ?>
<?= $table ?>
