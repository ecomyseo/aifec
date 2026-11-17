<?php
/**
 * AIFEC — ajustes de las valoraciones: publicar solas las de compra verificada y la petición de opinión por correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Reviews',
    'description' => 'Moderation of reviews and the email that asks buyers for one',
    'icon' => 'star',
    'cap' => 'reviews.edit',
    'help' => "Reviews: a new review waits for you to publish it, unless it comes from a verified purchase and you turn the first option on.\nVerified purchase: there is a paid order from that email address with that product.\nThe request email is sent once, a few days after the order is completed, with one link per product. Each link can be used once. Orders completed before you installed this module never get one.",
    'tabs' => [
        'rvgeneral' => ['label' => 'Reviews', 'fields' => [
            'reviews.auto_publish_verified' => ['type' => 'toggle', 'label' => 'Publish verified purchases without moderation', 'default' => false],
        ]],
        'rvrequest' => ['label' => 'Request by email', 'fields' => [
            'reviews.ask' => ['type' => 'toggle', 'label' => 'Ask buyers for a review by email', 'default' => true],
            'reviews.ask_days' => ['type' => 'number', 'label' => 'Days after the order is completed', 'default' => 7, 'rules' => 'int|min:1|max:60', 'width' => 'sm'],
        ]],
    ],
];
