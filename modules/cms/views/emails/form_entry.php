<?php
/**
 * AIFEC — correo «form_entry»: aviso a la tienda de un mensaje nuevo en un formulario, con todo lo que escribió la persona.
 * Todo lo que escribe una persona se escapa; los saltos de línea de un texto largo se respetan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                            $form      nombre del formulario
 * @var array<int,array{label:string,value:string}>       $fields    lo escrito, en el orden del formulario
 * @var string                                            $entry_url dirección del mensaje en el panel
 * @var string                                            $shop      nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Someone has sent the form {form} of {shop}.', ['form' => $form, 'shop' => $shop])) ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px 0;">
<?php foreach (is_array($fields) ? $fields : [] as $af_field) : ?>
    <tr>
        <td style="padding:8px 12px 8px 0;vertical-align:top;font-weight:bold;white-space:nowrap;border-bottom:1px solid #e5e5e5;"><?= e($af_field['label']) ?></td>
        <td style="padding:8px 0;vertical-align:top;border-bottom:1px solid #e5e5e5;"><?= nl2br(e($af_field['value']), false) ?></td>
    </tr>
<?php endforeach; ?>
</table>
<p style="margin:0;"><a href="<?= e($entry_url) ?>" style="color:#2f5d8a;"><?= e(t('Open the message in the panel')) ?></a></p>
