<?php
/**
 * AIFEC — cabecera de Métrica, «placa y banco». Arriba, la franja de avisos (si la opción trae texto). Debajo, dos columnas:
 * a la izquierda la placa de la marca, amarilla, que ocupa todo el alto; a la derecha dos filas apiladas: las secciones en
 * línea con las herramientas (buscar, cuenta y carrito) y, en tinta, el banco de trabajos (el menú «jobs», cada trabajo con
 * su pictograma). Sin menú de trabajos no hay banco. En el móvil queda una sola fila pegajosa: menú, marca y carrito.
 *
 * Sale en todas las páginas: su HTML va con poca sangría a propósito.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$mt_main = metrica_clean_menu(menu('main'));
$mt_all_jobs = metrica_clean_menu(menu('jobs'));
$mt_jobs = (int) theme_option('bench_show', 1) === 1 ? $mt_all_jobs : [];
$mt_strip = trim((string) theme_option('strip_text', ''));
$mt_langs = app()->languages();
$mt_path = app()->request()->path;
// Sin imagen de logotipo, shop_logo() da el nombre de la tienda: entonces la placa lleva delante la tuerca del tema.
$mt_logo_is_text = metrica_logo_is_text();
?>
<header class="af-header mt-header<?= ($mt_strip !== '' ? ' mt-has-strip' : '') ?>">
<?php if ($mt_strip !== '') : ?>
<p class="mt-strip"><?= e($mt_strip) ?></p>
<?php endif; ?>
<div class="mt-head">
 <div class="mt-plate">
<?php if ($mt_main !== [] || $mt_all_jobs !== []) : ?>
  <button type="button" class="mt-burger af-show-sm" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>"><?= metrica_svg('menu', 24) ?></button>
<?php endif; ?>
  <a class="af-logo mt-brand" href="<?= e(url('/')) ?>"><?= ($mt_logo_is_text ? metrica_svg('nut', 44) : '') ?><?= shop_logo() ?></a>
 </div>
 <div class="mt-desk">
  <div class="mt-desk-row">
<?php if ($mt_main !== []) : ?>
   <nav class="af-nav mt-nav af-hide-sm" aria-label="<?= e(t('Main menu')) ?>">
    <ul class="mt-nav-list">
<?php foreach ($mt_main as $mt_item) : ?>
     <li class="mt-nav-item">
<?php if ($mt_item['url'] !== '') : ?>
      <a class="mt-nav-link" href="<?= e($mt_item['url']) ?>"<?= ($mt_item['current'] ? ' aria-current="page"' : '') ?>><?= e($mt_item['label']) ?></a>
<?php else : ?>
      <span class="mt-nav-link" tabindex="0"><?= e($mt_item['label']) ?></span>
<?php endif; ?>
<?php if ($mt_item['children'] !== []) : ?>
      <ul class="mt-nav-sub">
<?php foreach ($mt_item['children'] as $mt_kid) : ?>
<?php if ($mt_kid['url'] !== '') : ?>
       <li><a href="<?= e($mt_kid['url']) ?>"><?= e($mt_kid['label']) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
     </li>
<?php endforeach; ?>
    </ul>
   </nav>
<?php endif; ?>
   <ul class="mt-tools">
    <li class="mt-tools-hook"><?= hook('header.actions') ?></li>
<?php foreach (count($mt_langs) > 1 ? $mt_langs : [] as $mt_code) : ?>
    <li class="af-hide-sm"><a class="mt-tool mt-tool-lang" href="<?= e(app()->url()->to($mt_path, [], $mt_code)) ?>" hreflang="<?= e($mt_code) ?>" lang="<?= e($mt_code) ?>"<?= ($mt_code === app()->lang() ? ' aria-current="true"' : '') ?>><?= e(strtoupper($mt_code)) ?></a></li>
<?php endforeach; ?>
<?php if (base_theme_can('search')) : ?>
    <li class="af-hide-sm"><a class="mt-tool" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search" aria-label="<?= e(t('Search')) ?>"><?= metrica_svg('search') ?></a></li>
<?php endif; ?>
<?php if (base_theme_can('account')) : ?>
    <li class="af-hide-sm"><a class="mt-tool" href="<?= e(base_theme_link('account')) ?>" aria-label="<?= e(t('My account')) ?>" data-af-account><?= metrica_svg('user') ?></a></li>
<?php endif; ?>
<?php if (base_theme_can('cart')) : ?>
    <li><a class="mt-tool mt-tool-cart" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart"><?= metrica_svg('cart') ?><b class="af-cart-count" data-af-cart-count hidden></b><span class="mt-tool-label"><?= e(t('Cart')) ?></span></a></li>
<?php endif; ?>
   </ul>
  </div>
<?php if ($mt_jobs !== []) : ?>
  <nav class="mt-bench af-hide-sm" aria-label="<?= e(t('Jobs')) ?>">
   <p class="mt-bench-ask"><?= e(t('What are you going to do?')) ?></p>
   <ul class="mt-bench-list">
<?php foreach ($mt_jobs as $mt_job) : ?>
<?php if ($mt_job['url'] !== '') : ?>
    <li><a class="mt-job" href="<?= e($mt_job['url']) ?>"<?= ($mt_job['current'] ? ' aria-current="page"' : '') ?>><?= metrica_svg(metrica_job_icon($mt_job['label']), 20) ?><span><?= e($mt_job['label']) ?></span></a></li>
<?php endif; ?>
<?php endforeach; ?>
   </ul>
  </nav>
<?php endif; ?>
 </div>
</div>
</header>
