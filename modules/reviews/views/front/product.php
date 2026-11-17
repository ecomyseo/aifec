<?php
/**
 * AIFEC — valoraciones en la ficha de producto, en poco sitio: una línea de resumen (estrellas, media, número y el botón de escribir),
 * el reparto por estrellas pequeño, una lista ligera paginada de cinco en cinco (las de compra verificada primero, con la respuesta de
 * la tienda) y el formulario plegado en un <details>. Todo texto de una persona se pinta escapado.
 *
 * Hueco: product.bottom. Va dentro de la página de la ficha (caché de página), por eso no lleva nada personal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $product ProductData
 * @var array<string,mixed>  $data    Reviews::forProduct()
 * @var string               $notice  '', 'sent' o 'published' (lo que se acaba de enviar)
 * @var bool                 $open    abrir el formulario (?valorar=1)
 * @var string               $action  dirección del formulario
 * @var array<string,string> $values
 * @var array<string,string> $errors
 */

declare(strict_types=1);

use Aifec\Kernel\Clock;

$af_app = app();
$af_tz = $af_app->site()->timezone();
$af_avg = max(0.0, min(5.0, (float) $data['average']));
$af_count = (int) $data['count'];
$af_base = (string) $product['url'];
// El formulario sale abierto con errores, con algo ya escrito o si la dirección trae ?valorar=1.
$af_open = (isset($open) && $open) || $errors !== [] || $values !== [];
$af_has_long = false;
// El reparto por estrellas, una sola vez: se pinta en la columna (escritorio) y dentro del desplegable (móvil).
$af_bars = '';
if ($af_count > 0) {
    for ($af_star = 5; $af_star >= 1; --$af_star) {
        $af_n = (int) $data['distribution'][$af_star];
        $af_bars .= '<li><span>' . e(tn('{n} star', '{n} stars', $af_star)) . '</span><progress max="' . $af_count . '" value="' . $af_n . '"></progress><span class="af-muted">' . $af_n . '</span></li>';
    }
}
?>
<?= \Aifec\Front\ThemeVars::styleTag($af_app, 'm', 'reviews', 'reviews.css') ?>

<section class="af-section af-rv" id="valoraciones" aria-labelledby="af-reviews-h">
    <h2 id="af-reviews-h"><?= e(t('Customer reviews')) ?></h2>
<?php if ($notice !== '') : ?>
    <p class="af-alert af-alert-ok" role="status"><?= e($notice === 'published' ? t('Thank you! Your review is now published.') : t('Thank you! Your review will appear once we have checked it.')) ?></p>
<?php endif; ?>
    <div class="af-rv-head">
<?php if ($af_count > 0) : ?>
        <p class="af-rv-sum">
            <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => $af_app->i18n()->number($af_avg, 1)])) ?>" style="--rating:<?= e(number_format($af_avg, 1, '.', '')) ?>"></span>
            <strong><?= e($af_app->i18n()->number($af_avg, 1)) ?></strong>
            <span class="af-muted">(<?= e(tn('{n} review', '{n} reviews', $af_count)) ?>)</span>
        </p>
        <ul class="af-rv-bars af-rv-bars-d" aria-label="<?= e(t('Reviews by rating')) ?>"><?= $af_bars ?></ul>
        <details class="af-rv-dist">
            <summary><?= e(t('View the breakdown')) ?></summary>
            <ul class="af-rv-bars" aria-label="<?= e(t('Reviews by rating')) ?>"><?= $af_bars ?></ul>
        </details>
<?php else : ?>
        <p class="af-rv-sum af-muted"><?= e(t('No reviews yet')) ?></p>
<?php endif; ?>
        <details class="af-rv-write" id="af-rv-write"<?= $af_open ? ' open' : '' ?>>
            <summary aria-controls="af-rv-form"><?= e(t('Write a review')) ?></summary>
            <?= $this->partial('reviews::front/_form', ['action' => $action, 'values' => $values, 'errors' => $errors, 'locked' => false]) ?>
        </details>
    </div>
<?php if ($af_count > 0) : ?>
    <ol class="af-rv-list" data-af-reviews>
<?php foreach ($data['items'] as $af_review) : ?>
<?php
        $af_long = mb_strlen((string) $af_review['body']) > 280 || substr_count((string) $af_review['body'], "
") >= 4;
        $af_has_long = $af_has_long || $af_long;
        $af_body_id = 'af-rv-body-' . (int) $af_review['id'];
?>
        <li class="af-rv-item" id="valoracion-<?= (int) $af_review['id'] ?>">
            <p>
                <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => (string) (int) $af_review['rating']])) ?>" style="--rating:<?= (int) $af_review['rating'] ?>"></span>
<?php if ($af_review['title'] !== '') : ?>
                <strong><?= e($af_review['title']) ?></strong>
<?php endif; ?>
            </p>
            <p class="af-rv-m">
                <?= e($af_review['name']) ?>
<?php if ($af_review['published_at'] !== null) : ?>
                · <?= e($af_app->i18n()->date(Clock::parse((string) $af_review['published_at']), 'medium', $af_tz)) ?>
<?php endif; ?>
<?php if ($af_review['verified']) : ?>
                · <span class="af-rv-ok"><?= e(t('Verified purchase')) ?></span>
<?php endif; ?>
            </p>
            <p class="af-rv-body<?= $af_long ? ' af-rv-clamp' : '' ?>"<?= $af_long ? ' id="' . e($af_body_id) . '"' : '' ?>><?= nl2br(e($af_review['body'])) ?></p>
<?php if ($af_long) : ?>
            <button class="af-rv-more" type="button" data-af-more hidden aria-expanded="false" aria-controls="<?= e($af_body_id) ?>" data-more="<?= e(t('Read more')) ?>" data-less="<?= e(t('Read less')) ?>"><?= e(t('Read more')) ?></button>
<?php endif; ?>
<?php if ($af_review['reply'] !== '') : ?>
            <p class="af-rv-reply"><strong><?= e(t('Reply from the shop')) ?></strong><br><?= nl2br(e($af_review['reply'])) ?></p>
<?php endif; ?>
        </li>
<?php endforeach; ?>
    </ol>
    <?= $this->partial('partials/pagination', [
        'page' => (int) $data['page'], 'pages' => (int) $data['pages'],
        'url' => static function (int $af_n) use ($af_base): string {
            return $af_base . ($af_n > 1 ? '?valoraciones=' . $af_n : '') . '#valoraciones';
        },
    ]) ?>
<?php if ($af_has_long) : ?>
    <script src="<?= e($af_app->url()->asset('m', 'reviews', 'reviews.js')) ?>" defer></script>
<?php endif; ?>
<?php endif; ?>
</section>
