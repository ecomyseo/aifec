<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * Ajustes de SEO: todos son por sitio. Un solo formulario con cuatro pestañas (las claves llevan las iniciales del módulo).
 *
 * Las plantillas de título y de descripción (seo.title.<tipo>, seo.description.<tipo>) solo rellenan lo que el contenido no trae
 * escrito a mano. Los robots se deciden por familia: seo.bots.<familia> (el módulo también lee un ajuste «seo.bots» con la
 * forma familia => allow|disallow, y gana el bloqueo).
 */
return [
    'title' => 'SEO',
    'description' => 'Titles, descriptions, robots and site verification',
    'icon' => 'search',
    'cap' => 'seo.edit',
    'help' => "General: the switch that hides the whole site from search engines while you are building it, and how many pages of a listing can be indexed.\nTitles: a template for the title and the description of each kind of page. It only fills what a page does not have written by hand.\nRobots: who can read the shop. Search engines bring visitors; AI assistants read a page to answer a person; AI training crawlers collect pages to train models.\nVerification: the codes that Google and Bing give you to prove that the site is yours.",
    'tabs' => [
        'seogeneral' => ['label' => 'General', 'fields' => [
            'seo.noindex_site' => ['type' => 'toggle', 'label' => 'Hide the whole site from search engines', 'hint' => 'Adds «noindex» to every page and blocks search engines and training robots. An assistant that reads one page for a person at that moment can still read it (it indexes nothing): close that below, in «AI assistants». Turn it off when the shop is ready.', 'default' => false],
            'seo.noindex_allow' => ['type' => 'text', 'label' => 'Robots that can read the hidden site', 'default' => '', 'rules' => ['nullable', 'max:300', 'regex:/^[A-Za-z0-9._\\- ,]*$/'],
                'hint' => 'Separated by commas, for example «OAI-SearchBot» so that ChatGPT can open the shop. Only with the site hidden; search engines stay out.'],
            'seo.index_products' => ['type' => 'toggle', 'label' => 'With the site hidden, open the product pages to search engines', 'default' => false,
                'hint' => 'Only the product pages and their sitemap (not the images): the rest stays hidden. Never in a demo site.'],
            'seo.indexnow_key' => ['type' => 'text', 'label' => 'IndexNow key', 'default' => '', 'rules' => ['nullable', 'max:128', 'regex:/^[A-Za-z0-9-]*$/'],
                'hint' => 'Published at /indexnow.txt. With it, the console command seo:indexnow tells Bing and the other IndexNow search engines about the product pages. Empty = it is created the first time.'],
            'seo.demo_notice' => ['type' => 'toggle', 'label' => 'Mark the shop as a demo', 'default' => false,
                'hint' => 'A band on every page and «demo» in the titles and descriptions, so that nobody (and no search engine) takes it for a real shop.'],
            'seo.max_indexed_page' => ['type' => 'number', 'label' => 'Last page of a listing that can be indexed', 'hint' => 'The following pages are marked «noindex». 0 = no limit.', 'default' => 0, 'rules' => 'between:0,1000', 'width' => 'sm'],
        ]],
        'seotitles' => ['label' => 'Titles', 'fields' => [
            'seo.titles_help' => ['type' => 'heading', 'label' => 'Variables: {name}, {shop}, {category}, {brand}, {price} and {page}', 'hint' => 'A page with its own meta title or description keeps it. A variable that is empty leaves no stray separators. Titles are cut at 65 characters and descriptions at 160.'],
            'seo.title.home' => ['type' => 'text', 'label' => 'Title of the home page', 'default' => '{shop}', 'rules' => 'max:200'],
            'seo.description.home' => ['type' => 'text', 'label' => 'Description of the home page', 'default' => '', 'rules' => 'max:400'],
            'seo.title.product' => ['type' => 'text', 'label' => 'Title of a product', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.product' => ['type' => 'text', 'label' => 'Description of a product', 'default' => '', 'rules' => 'max:400'],
            'seo.title.category' => ['type' => 'text', 'label' => 'Title of a category', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.category' => ['type' => 'text', 'label' => 'Description of a category', 'default' => '', 'rules' => 'max:400'],
            'seo.title.brand' => ['type' => 'text', 'label' => 'Title of a brand', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.brand' => ['type' => 'text', 'label' => 'Description of a brand', 'default' => '', 'rules' => 'max:400'],
            'seo.title.page' => ['type' => 'text', 'label' => 'Title of a page', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.page' => ['type' => 'text', 'label' => 'Description of a page', 'default' => '', 'rules' => 'max:400'],
            'seo.title.post' => ['type' => 'text', 'label' => 'Title of a post', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.post' => ['type' => 'text', 'label' => 'Description of a post', 'default' => '', 'rules' => 'max:400'],
            'seo.title.blog' => ['type' => 'text', 'label' => 'Title of the blog', 'default' => '{name} | {shop}', 'rules' => 'max:200'],
            'seo.description.blog' => ['type' => 'text', 'label' => 'Description of the blog', 'default' => '', 'rules' => 'max:400'],
        ]],
        'seobots' => ['label' => 'Robots', 'fields' => [
            'seo.bots.search' => ['type' => 'select', 'label' => 'Search engines', 'hint' => 'Googlebot, Bingbot and the like: they send visitors from their results.', 'options' => ['allow' => 'Allow', 'disallow' => 'Block'], 'default' => 'allow', 'width' => 'sm'],
            'seo.bots.assistant' => ['type' => 'select', 'label' => 'AI assistants', 'hint' => 'ChatGPT, Claude, Perplexity and others when they read a page to answer a person.', 'options' => ['allow' => 'Allow', 'disallow' => 'Block'], 'default' => 'allow', 'width' => 'sm'],
            'seo.bots.training' => ['type' => 'select', 'label' => 'AI training', 'hint' => 'Crawlers that collect pages to train models.', 'options' => ['allow' => 'Allow', 'disallow' => 'Block'], 'default' => 'allow', 'width' => 'sm'],
            'seo.robots_extra' => ['type' => 'textarea', 'label' => 'Extra lines for robots.txt', 'hint' => 'One per line. Only User-agent, Allow, Disallow, Crawl-delay, Sitemap and comments are kept.', 'default' => '', 'rules' => 'max:2000'],
        ]],
        'seoverify' => ['label' => 'Verification', 'fields' => [
            'seo.verify_google' => ['type' => 'text', 'label' => 'Google Search Console code', 'hint' => 'Only the code, not the whole tag.', 'default' => '', 'rules' => 'nullable|max:100|regex:/^[A-Za-z0-9_-]{8,100}\z/', 'width' => 'md'],
            'seo.verify_bing' => ['type' => 'text', 'label' => 'Bing Webmaster Tools code', 'hint' => 'Only the code, not the whole tag.', 'default' => '', 'rules' => 'nullable|max:100|regex:/^[A-Za-z0-9_-]{8,100}\z/', 'width' => 'md'],
        ]],
    ],
];
