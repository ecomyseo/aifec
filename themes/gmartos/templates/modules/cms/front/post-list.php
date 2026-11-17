<?php
/**
 * AIFEC — lista de entradas de gmartos: dos columnas de tarjetas con imagen grande y la paginación en cajas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $result lo que da Cms::posts() (items, page, pages)
 * @var string              $base   dirección de la lista sin página
 */

$gm_base = (string) $base;
$gm_url = static function (int $n) use ($gm_base): string {
    return $gm_base . ($n > 1 ? (strpos($gm_base, '?') === false ? '?' : '&') . 'page=' . $n : '');
};
?>
<?php if ($result['items'] === []) : ?>
    <div class="af-empty">
        <p class="af-empty-title"><?= e(t('There are no posts here yet.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-grid af-cols-2 gm-articles">
        <?php foreach ($result['items'] as $gm_post) : ?>
            <?= $this->partial('cms::front/post-card', ['post' => $gm_post]) ?>
        <?php endforeach; ?>
    </div>
    <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $gm_url]) ?>
<?php endif; ?>
