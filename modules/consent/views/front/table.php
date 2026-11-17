<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La tabla de cookies de la política (bloque «consent-cookies»): una tabla por categoría con el nombre, quién la pone, para qué
 * sirve y cuánto dura. Es la misma para todo el mundo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{label:string,cookies:array<int,array<string,string>>}> $groups
 */

declare(strict_types=1);

?><div class="af-consent-table">
<?php foreach ($groups as $group) : ?>
<?php if ($group['cookies'] === []) : ?>
    <?php continue; ?>
<?php endif; ?>
    <h3><?= e($group['label']) ?></h3>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Set by')) ?></th><th scope="col"><?= e(t('Purpose')) ?></th><th scope="col"><?= e(t('Duration')) ?></th></tr></thead>
            <tbody>
<?php foreach ($group['cookies'] as $cookie) : ?>
                <tr><td><code><?= e($cookie['name']) ?></code></td><td><?= e($cookie['provider']) ?></td><td><?= e($cookie['purpose']) ?></td><td><?= e($cookie['duration']) ?></td></tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
</div>
