<?php
/**
 * AIFEC — las líneas de confianza (P100): envío, devolución y pago, con su icono, debajo del botón de compra de la ficha,
 * bajo «Finalizar compra» en el carrito y en la franja de ventajas de la portada del tema base. Los datos salen de la tienda
 * (Aifec\Front\StoreInfo::trust); una línea sin dato no sale y, sin ninguna, no se pinta nada.
 *
 * Cada tema la llama con su clase propia y la viste con sus variables (la hoja es la del catálogo, store.css).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,string>>|null $lines code, text, detail, url (sin ellas, las de la tienda)
 * @var string                               $class clase propia del tema, además de «af-trust»
 */

$af_lines = isset($lines) && is_array($lines) ? $lines : \Aifec\Front\StoreInfo::data(app(), true)['trust'];
$af_class = isset($class) && is_string($class) ? trim($class) : '';
if ($af_lines === []) {
    return;
}
// Iconos de trazo, dibujados aquí: nada de otra web ni de fuentes de iconos.
$af_icons = [
    'shipping' => 'M3 6h11v10H3zM14 10h4l3 3v3h-7M7.5 18.5a1.5 1.5 0 1 0 0-.01M17.5 18.5a1.5 1.5 0 1 0 0-.01',
    'returns' => 'M4 12a8 8 0 1 0 2.4-5.7M4 4v5h5',
    'payment' => 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M7 15h4',
];
?>
<ul class="af-trust<?= $af_class !== '' ? ' ' . e($af_class) : '' ?>" data-af-trust>
<?php foreach ($af_lines as $af_line) : ?>
    <?php
    if (!is_array($af_line) || !isset($af_line['text']) || trim((string) $af_line['text']) === '') {
        continue;
    }
    $af_code = isset($af_line['code']) ? (string) $af_line['code'] : '';
    $af_url = isset($af_line['url']) ? (string) $af_line['url'] : '';
    $af_detail = isset($af_line['detail']) ? trim((string) $af_line['detail']) : '';
    ?>
    <li class="af-trust-<?= e($af_code !== '' ? $af_code : 'item') ?>" data-af-trust-line="<?= e($af_code) ?>">
        <svg class="af-trust-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="<?= e(isset($af_icons[$af_code]) ? $af_icons[$af_code] : 'M5 12l4 4 10-10') ?>"/></svg>
        <span><?php if ($af_url !== '') : ?><a href="<?= e($af_url) ?>"><?= e((string) $af_line['text']) ?></a><?php else : ?><?= e((string) $af_line['text']) ?><?php endif; ?><?php if ($af_detail !== '') : ?><span class="af-trust-detail"><?= e($af_detail) ?></span><?php endif; ?></span>
    </li>
<?php endforeach; ?>
</ul>
