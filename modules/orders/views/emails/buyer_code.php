<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Correo con el código con el que la persona confirma un pedido que le prepara un asistente de IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $code    el código de seis cifras
 * @var string $total   el total del pedido, con su formato
 * @var int    $minutes minutos que vale el código
 * @var string $shop    nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('An assistant is preparing an order for you at {shop}. Total: {total}.', ['shop' => $shop, 'total' => $total])) ?></p>
<p style="margin:0 0 8px 0;"><?= e(t('If you want to place it, give this code to the assistant:')) ?></p>
<p style="margin:0 0 20px 0;font-size:30px;font-weight:bold;letter-spacing:6px;"><?= e($code) ?></p>
<p style="margin:0 0 8px 0;"><?= e(t('The code works for {minutes} minutes and only for this order with this total.', ['minutes' => $minutes])) ?></p>
<p style="margin:0;"><?= e(t('If you are not buying anything, ignore this message: nothing is ordered without this code.')) ?></p>
