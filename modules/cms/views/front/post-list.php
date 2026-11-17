<?php
/**
 * AIFEC — lista de entradas con su paginación: la comparten el índice del blog y las categorías y etiquetas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $result lo que da Cms::posts(): items, total, page, pages
 * @var string              $base   dirección de la primera página del listado
 */

$af_base = (string) $base;
$af_url = static function (int $n) use ($af_base): string {
    return $af_base . ($n > 1 ? (strpos($af_base, '?') === false ? '?' : '&') . 'page=' . $n : '');
};
?>
<?php if ($result['items'] === []) : ?>
    <div class="af-empty">
        <p class="af-empty-title"><?= e(t('There are no posts here yet.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-grid af-cols-3">
        <?php foreach ($result['items'] as $af_post) : ?>
            <?= $this->partial('cms::front/post-card', ['post' => $af_post]) ?>
        <?php endforeach; ?>
    </div>
    <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $af_url]) ?>
<?php endif; ?>
