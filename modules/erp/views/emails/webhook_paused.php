<?php
/**
 * AIFEC — correo «erp_webhook_paused»: los avisos a un ERP se han pausado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $connection nombre de la conexión
 * @var string $url        dirección a la que se enviaba
 * @var string $reason     por qué se ha pausado
 * @var string $panel_url  enlace a la conexión en el panel
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('The messages to the ERP «{name}» failed five times in a row, so they are paused.', ['name' => $connection])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Address: {url}', ['url' => $url])) ?><br><?= e($reason) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Nothing is lost: the messages wait. Fix the problem and switch the messages on again from the panel.')) ?></p>
<p style="margin:0 0 8px 0;"><a href="<?= e($panel_url) ?>" style="color:#2f5d8a;"><?= e(t('Open the connection')) ?></a></p>
