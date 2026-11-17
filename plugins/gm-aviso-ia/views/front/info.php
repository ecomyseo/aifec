<?php
/**
 * AIFEC — GM AI notice: la página que explica cómo se redactan los textos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var string              $title
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e($title) ?></h1>
    <div class="af-prose">
        <p><?= e(t('We want to be clear with you: the descriptions of the products were written with the help of artificial intelligence. Here we explain what the AI does, what a person does and how we check that what you read is true.')) ?></p>
        <h2><?= e(t('What the AI writes')) ?></h2>
        <ul>
            <li><?= e(t('The short description of each product, the one next to the price.')) ?></li>
            <li><?= e(t('The long description of each product.')) ?></li>
        </ul>
        <p><?= e(t('The AI does not decide what a product does. It writes from the technical documentation. What is not in that documentation, such as the price or the support period, is not invented: a person writes it.')) ?></p>
        <h2><?= e(t('How each product is checked')) ?></h2>
        <p><?= e(t('Before a description is published, the product is installed and tried in test environments, never in the shop of a customer.')) ?></p>
        <h2><?= e(t('Everything is reviewed by a person')) ?></h2>
        <p><?= e(t('No text is published exactly as the AI wrote it. A person reads each description, compares it with the real product and corrects whatever does not fit.')) ?></p>
        <h2><?= e(t('If something does not add up')) ?></h2>
        <p><?= e(t('If a description says something the product does not do, write to us from the contact form of the shop. We will check it and correct the page.')) ?></p>
    </div>
</div>
