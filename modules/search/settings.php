<?php
/**
 * AIFEC — ajustes del buscador: lo que pesa cada campo, cuándo se da algo por encontrado y las dos ayudas opcionales de la IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

$weight = static function (string $label, float $default): array {
    return ['type' => 'number', 'label' => $label, 'default' => $default, 'decimal' => 1, 'min' => 0, 'max' => 20, 'step' => 0.5, 'rules' => 'required|number|min:0|max:20', 'width' => 'sm'];
};

return [
    'title' => 'Search',
    'description' => 'How the search ranks products and what it asks of the AI',
    'icon' => 'search',
    'cap' => 'search.edit',
    'help' => "The weights say how much a word counts depending on where it is found: a word in the name counts more than the same word in the description.\nThe search works without the AI. The two AI options are off by default and never make a search wait.",
    'tabs' => [
        'srranking' => ['label' => 'Ranking', 'fields' => [
            'search.w_name' => $weight('Name', 6.0),
            'search.w_ref' => $weight('Reference and barcode', 4.0),
            'search.w_brand' => $weight('Brand', 3.0),
            'search.w_cats' => $weight('Categories', 2.5),
            'search.w_tags' => $weight('Tags', 2.0),
            'search.w_attrs' => $weight('Attributes and key facts', 2.0),
            'search.w_summary' => $weight('Summary', 1.5),
            'search.w_desc' => $weight('Description', 1.0),
            'search.w_extra' => $weight('Extra search words', 0.8),
            'search.min_score' => ['type' => 'number', 'label' => 'Minimum score', 'default' => 0.35, 'decimal' => 2, 'min' => 0, 'max' => 1, 'step' => 0.05, 'rules' => 'required|number|min:0|max:1', 'width' => 'sm',
                'hint' => 'From 0 to 1. Below it the shop says it found nothing instead of showing weak results.'],
        ]],
        'srwords' => ['label' => 'Understanding', 'fields' => [
            'search.sector' => ['type' => 'select', 'label' => 'Shop sector', 'default' => '', 'options' => \Aifec\Modules\Search\Synonyms::SECTORS, 'rules' => 'nullable|in:beauty,fashion,food,hardware'],
            'search.synonyms' => ['type' => 'textarea', 'label' => 'Words that mean the same', 'rows' => 5, 'default' => '', 'rules' => 'nullable|max:2000',
                'hint' => 'One group per line: «cheap = bargain, steal». Customers can use any of them to say the first one.'],
            'search.default_synonyms' => ['type' => 'toggle', 'label' => 'Common shop synonyms', 'default' => true,
                'hint' => 'Words that mean the same in any shop («colonia» and «perfume», «nevera» and «frigorífico»), on top of yours.'],
        ]],
        'srai' => ['label' => 'AI help', 'fields' => [
            'search.ai_understand' => ['type' => 'toggle', 'label' => 'Ask the AI to understand long phrases', 'default' => true,
                'hint' => 'Needs the AI module. Only when the rules do not understand a phrase of five words or more, or when nothing is found. It also proposes shop words when nothing matches.'],
            'search.vectors' => ['type' => 'toggle', 'label' => 'Rank also by meaning', 'default' => false,
                'hint' => 'Needs the AI module. Keeps a meaning vector of each product, reorders the first results and finds related products when nothing matches the words.'],
            'search.meaning_threshold' => ['type' => 'number', 'label' => 'Similarity needed to offer a product by meaning', 'default' => 0.6, 'decimal' => 2, 'min' => 0, 'max' => 1, 'step' => 0.05, 'rules' => 'required|number|min:0|max:1', 'width' => 'sm',
                'hint' => 'From 0 to 1. Only used when nothing matches the words and «Rank also by meaning» is on. Higher shows fewer, closer products.'],
            'search.ai_expand' => ['type' => 'toggle', 'label' => 'Ask the AI for more ways to search each product', 'default' => false,
                'hint' => 'Needs the AI module. Runs in the background when a product is indexed, never while someone searches.'],
        ]],
    ],
];
