<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El aviso de cookies (hueco «body.end» de la tienda): la primera capa y la segunda, por categorías. Es el mismo HTML para todo el
 * mundo —no sabe si el visitante ya decidió—, así que la página sigue siendo cacheable; consent.js lo enseña o lo deja oculto. Sin
 * JavaScript no sale nada (empieza oculto) y no se carga ningún guion de terceros. «Aceptar» y «Rechazar» tienen el mismo aspecto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $title
 * @var string $message
 * @var string $policyUrl
 * @var array<int,array<string,mixed>> $groups  categorías con sus cookies
 * @var string $cookie   nombre de la cookie de la decisión
 * @var int    $version  versión de la política
 * @var string $path     ruta de las cookies del sitio
 * @var string $record   dirección donde se apunta la prueba ('' = no se apunta)
 * @var string $css      hoja de estilos del aviso
 */

declare(strict_types=1);

?><?= \Aifec\Front\ThemeVars::styleTag(app(), 'm', 'consent', 'consent.css') ?>

<div class="af-consent" data-af-consent hidden data-cookie="<?= e($cookie) ?>" data-version="<?= e($version) ?>" data-path="<?= e($path) ?>"<?= $record !== '' ? ' data-record="' . e($record) . '"' : '' ?> data-t-ph="<?= e(t('Accept cookies to see this content')) ?>">
    <section class="af-consent-card" data-l="1" role="region" aria-labelledby="af-consent-title" hidden>
        <h2 id="af-consent-title" class="af-consent-title"><?= e($title) ?></h2>
        <p class="af-consent-text"><?= e($message) ?><?php if ($policyUrl !== '') : ?> <a href="<?= e($policyUrl) ?>"><?= e(t('Cookie policy')) ?></a><?php endif; ?></p>
        <div class="af-consent-actions">
            <button type="button" class="af-btn af-btn-primary" data-act="reject"><?= e(t('Reject')) ?></button>
            <button type="button" class="af-btn af-btn-primary" data-act="accept"><?= e(t('Accept')) ?></button>
            <button type="button" class="af-btn af-btn-ghost" data-act="config"><?= e(t('Configure')) ?></button>
        </div>
    </section>
    <div class="af-consent-back" data-l="2" hidden>
        <section class="af-consent-prefs" role="dialog" aria-modal="true" aria-labelledby="af-consent-prefs-title">
            <h2 id="af-consent-prefs-title" class="af-consent-title"><?= e(t('Cookie preferences')) ?></h2>
<?php foreach ($groups as $group) : ?>
            <div class="af-consent-group">
                <label class="af-consent-cat">
                    <input type="checkbox" data-cat="<?= e($group['letter']) ?>"<?= !empty($group['locked']) ? ' checked disabled' : '' ?>>
                    <span><?= e($group['label']) ?></span>
                </label>
                <p class="af-consent-desc"><?= e($group['description']) ?></p>
<?php if ($group['cookies'] !== []) : ?>
                <details class="af-consent-list">
                    <summary><?= e(tn('{n} cookie', '{n} cookies', count($group['cookies']))) ?></summary>
                    <ul>
<?php foreach ($group['cookies'] as $cookieRow) : ?>
                        <li><strong><?= e($cookieRow['name']) ?></strong> <?= e($cookieRow['provider']) ?>: <?= e($cookieRow['purpose']) ?> (<?= e($cookieRow['duration']) ?>)</li>
<?php endforeach; ?>
                    </ul>
                </details>
<?php endif; ?>
            </div>
<?php endforeach; ?>
            <div class="af-consent-actions">
                <button type="button" class="af-btn af-btn-primary" data-act="reject"><?= e(t('Reject all')) ?></button>
                <button type="button" class="af-btn af-btn-primary" data-act="accept"><?= e(t('Accept all')) ?></button>
                <button type="button" class="af-btn" data-act="save"><?= e(t('Save my choices')) ?></button>
            </div>
        </section>
    </div>
</div>
