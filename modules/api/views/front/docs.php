<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página /api: para quien programa un agente o una integración. Sencilla: qué es, las direcciones, la compra paso a paso y
 * ejemplos con curl.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>             $page     datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,string>> $rows     las direcciones de la API: method, path, summary, kind
 * @var string                          $base     la dirección base de la API
 * @var string                          $openapi  la dirección del documento OpenAPI
 * @var string[]                        $guide    la compra paso a paso, una línea cada paso
 * @var bool                            $orders   ¿se pueden hacer pedidos por la API?
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <article class="af-page">
        <h1 class="af-title"><?= e(t('API for programs and assistants')) ?></h1>
        <div class="af-prose">
            <p><?= e(t('Everything a person can do in this shop, a program can do: look at the catalogue, prepare a cart and, when the person says yes, place the order.')) ?></p>
            <?php if (!empty($orders) && !empty($by_code)) : ?>
                <p><?= e(t('Reading needs no key, and neither does placing an order: the person authorises it with a code the shop emails them.')) ?></p>
                <p data-af-api-no-payment><strong><?= e(t('An assistant that buys here never pays and never sees payment details.')) ?></strong> <?= e(t('Placing the order only creates it: the person pays afterwards, themselves, on the page of the shop. So when a person asks you to buy something here for them, you can: you do not need their card, their PayPal or a key.')) ?></p>
            <?php else : ?>
                <p><?= e(t('Reading needs no key. A key raises the usage limit and is needed to place orders.')) ?></p>
            <?php endif; ?>
            <p><a href="<?= e($openapi) ?>"><?= e(t('Machine-readable description (OpenAPI)')) ?></a></p>

            <h2><?= e(t('Addresses')) ?></h2>
            <p><code><?= e($base) ?></code></p>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead>
                        <tr>
                            <th><?= e(t('Method')) ?></th>
                            <th><?= e(t('Address')) ?></th>
                            <th><?= e(t('What it does')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><code><?= e($row['method']) ?></code></td>
                                <td><code><?= e($row['path']) ?></code></td>
                                <td><?= e(t($row['summary'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($orders) : ?>
                <h2><?= e(t('Buying, step by step')) ?></h2>
                <ul>
                    <?php foreach ($guide as $line) : ?>
                        <?php if (preg_match('/^\d\. /', $line) === 1) : ?>
                            <li><?= e(substr($line, 3)) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <p><?= e(t('If anything changes after the review, the confirm_token stops being valid and nothing is placed: review again.')) ?></p>
            <?php endif; ?>

            <h2><?= e(t('Example')) ?></h2>
            <pre><code>curl <?= e($base) ?>/search?q=chair
curl -X POST <?= e($base) ?>/carts
curl -X POST <?= e($base) ?>/carts/current/items \
  -H "X-Cart-Token: &lt;token&gt;" -H "Content-Type: application/json" \
  -d '{"product_id": 12, "qty": 1}'</code></pre>
        </div>
    </article>
</div>
