<?php
/**
 * AIFEC — el formulario del buscador de la documentación (método GET, sin JavaScript).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $search_url dirección del buscador
 * @var string $query      lo que se buscó, o vacío
 */

$af_id = 'af-docs-q-' . substr(md5(isset($query) ? (string) $query : ''), 0, 6);
?>
<form class="af-docs-search" role="search" action="<?= e(isset($search_url) ? (string) $search_url : url('/docs/buscar')) ?>" method="get">
    <label class="af-sr" for="<?= e($af_id) ?>"><?= e(t('Search the documentation')) ?></label>
    <input id="<?= e($af_id) ?>" class="af-input" type="search" name="q" value="<?= e(isset($query) ? (string) $query : '') ?>" placeholder="<?= e(t('Search the documentation')) ?>" autocomplete="off" maxlength="120">
    <button type="submit" class="af-btn"><?= e(t('Search')) ?></button>
</form>
