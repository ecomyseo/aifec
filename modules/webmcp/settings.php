<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'WebMCP',
    'description' => 'Lets an AI agent that browses the shop use its tools',
    'icon' => 'chat',
    'cap' => 'webmcp.edit',
    'help' => "WebMCP is the way a browser tells an AI agent what a page can do. The shop publishes its search, cart and checkout tools, and marks its forms so the agent knows what each one is for.\nThe agent works on the visitor's own cart. It takes the visitor up to the Place order button: the order is always confirmed by the person on the page.\nTurning it off removes the script, the form marks and the tool list from every page.",
    'tabs' => [
        'wmgeneral' => ['label' => 'General', 'fields' => [
            'webmcp.enabled' => ['type' => 'toggle', 'label' => 'Offer the shop tools to AI agents in the browser', 'default' => true, 'hint' => 'Marks the forms and loads a small script that only runs when the browser offers WebMCP.'],
        ]],
    ],
];
