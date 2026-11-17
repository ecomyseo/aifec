<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las líneas de una dirección, una debajo de otra.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string[] $lines líneas ya preparadas (\Aifec\Modules\Customers\Front\Account::addressLines())
 */

declare(strict_types=1);

?>
<address>
<?php foreach ($lines as $af_line) : ?>
    <?= e($af_line) ?><br>
<?php endforeach; ?>
</address>
