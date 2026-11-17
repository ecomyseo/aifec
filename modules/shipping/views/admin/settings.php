<?php
/**
 * AIFEC — pestaña «Ajustes» de envío: el formulario de siempre, que se guarda en la dirección de los ajustes del módulo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs
 * @var string $form formulario ya pintado
 */

declare(strict_types=1);

?><?= $this->partial('shipping::admin/tabs', ['tabs' => $tabs]) ?>
<?= $form ?>
