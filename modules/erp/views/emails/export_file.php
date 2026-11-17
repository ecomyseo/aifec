<?php
/**
 * AIFEC — correo «erp_export_file»: el fichero de una exportación, adjunto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $profile nombre del perfil de exportación
 * @var string $file    nombre del fichero adjunto
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Here is the file of the export «{profile}».', ['profile' => $profile])) ?></p>
<p style="margin:0;"><?= e(t('Attached file: {file}', ['file' => $file])) ?></p>
