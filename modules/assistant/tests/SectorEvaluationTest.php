<?php
/**
 * AIFEC — evaluación funcional por sector sobre catálogos nativos de demostración.
 * @author Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Modules\Sites\CatalogLoader;
use Aifec\Modules\Sites\Package;

require_once __DIR__ . '/helpers.php';

function assistantSectorEvaluation(string $sector): array
{
    $app = assistantApp(['assistant.hours' => '09:15–17:45'], ['modules' => ['sites']]);
    $app->boot();
    $app->makeCurrent();
    $package = Package::load(Autoload::root() . '/demos', $sector, Autoload::root());
    $loader = new CatalogLoader($app, $package, static function (): int { return 0; });
    $loader->load();
    searchService($app)->reindexNow();
    $catalog = $app->service('catalog');
    $names = [];
    $prices = [];
    foreach ($package->data('products.json') as $product) {
        $id = (int) $app->db()->value('SELECT id FROM {products} WHERE sku = ?', [$product['sku']]);
        $card = $catalog->card($id);
        if (!$card || !isset($card['price']['amount'])) {
            throw new RuntimeException('Missing catalogue fixture: ' . $product['sku']);
        }
        $prices[$id] = (int) $card['price']['amount'];
        $names[$id] = $product['name'];
    }
    $patterns = [
        'es' => [
            'Busco %s', '¿Vendéis %s?', 'Quiero ver %s', '¿Me enseñas %s?', 'Por favor, busca %s',
            'Querría comprar %s', '¿Tenéis disponible %s?', 'Buscame %s porfa', 'En vuestro catálogo, ¿hay %s?',
            'Estoy mirando opciones. ¿Tenéis %s?', 'Solo te pregunto por %s', '¿Dónde encuentro %s?',
            'Necesito información de %s', 'Me gustaría ver %s', 'Estoy buscando algo concreto: %s',
            '¿Puedes enseñarme una ficha de %s?', '¿Me ayudas a encontrar %s?', '¿Hay existencias de %s?',
        ],
        'en' => [
            'Can you show me %s?', 'I am looking for %s', 'Do you have %s?', 'Please find this product for me: %s',
            'I want to buy %s', 'Could you check the price of %s?', 'Where can I find %s?', 'Can I order %s here?',
        ],
    ];
    $cases = [];
    foreach (array_slice($names, 0, 6, true) as $id => $name) {
        $typo = preg_replace('/([\p{L}]{3})[\p{L}]([\p{L}]{3,})/u', '$1$2', $name, 1);
        if ($typo && $typo !== $name) {
            foreach (['es' => 'Perdona, igual lo escribo mal, ¿tenéis %s?', 'en' => 'I may have misspelled it. Do you sell %s?'] as $lang => $question) {
                $cases[] = ['message' => sprintf($question, $typo), 'lang' => $lang, 'intent' => 'product', 'id' => $id];
            }
        }
        foreach ($patterns as $lang => $templates) {
            foreach ($templates as $template) {
                $cases[] = ['message' => sprintf($template, $name), 'lang' => $lang, 'intent' => 'product', 'id' => $id];
            }
        }
    }
    foreach ([
        ['¿Qué horario tenéis?', 'es', 'hours'], ['What are your opening hours?', 'en', 'hours'],
        ['Estoy embarazada, ¿qué producto me recomiendas?', 'es', 'health'],
        ['I have an allergy. What should I buy?', 'en', 'health'],
        ['¿Quién ganó el mundial?', 'es', 'outside'], ['Who won the world cup?', 'en', 'outside'],
        ['¿Cuándo me llegaría?', 'es', 'delivery'], ['When will my order arrive?', 'en', 'delivery'],
        ['¿Cómo puedo devolver un artículo?', 'es', 'returns'], ['How do I return an item?', 'en', 'returns'],
    ] as $service) {
        $cases[] = ['message' => $service[0], 'lang' => $service[1], 'intent' => $service[2]];
    }
    foreach ([
        ['¿Quién ganó el mundial?', 'What are your opening hours?', 'en', 'hours'],
        ['What are your opening hours?', '¿Cómo puedo devolver un artículo?', 'es', 'returns'],
        ['¿Cómo puedo devolver un artículo?', 'When will my order arrive?', 'en', 'delivery'],
        ['I am looking for a product', 'Estoy embarazada. ¿Qué me recomiendas?', 'es', 'health'],
    ] as $dialogue) {
        $cases[] = ['before' => $dialogue[0], 'message' => $dialogue[1], 'lang' => $dialogue[2], 'intent' => $dialogue[3]];
    }
    $scores = ['phrases' => count($cases), 'relevant' => 0, 'language' => 0, 'prices_checked' => 0, 'price_errors' => 0];
    $failures = [];
    foreach ($cases as $case) {
        // Distintos compradores, espaciados con el reloj simulado para respetar las cuotas de la tienda.
        $app->clock()->advance(15);
        $web = new TestClient($app);
        if (isset($case['before'])) { assistantSay($web, $case['before']); }
        $answer = assistantSay($web, $case['message']);
        if ($web->last->status !== 200 || !isset($answer['text'], $answer['cards'])) {
            throw new RuntimeException('Incomplete chat response: ' . $web->last->status . ' ' . $case['message']);
        }
        $text = $answer['text'];
        $ids = array_column($answer['cards'], 'id');
        $intent = $case['intent'];
        if ($intent === 'product') {
            $relevant = in_array($case['id'], $ids, true);
        } elseif ($intent === 'hours') {
            $relevant = strpos($text, '09:15') !== false && strpos($text, '17:45') !== false;
        } elseif ($intent === 'health') {
            $relevant = !$ids && preg_match('/médic|farmacéut|doctor|pharmacist/iu', $text) === 1;
        } elseif ($intent === 'delivery') {
            $relevant = preg_match('/postal|postcode|zip code|destino|destination|transportista|carrier/iu', $text) === 1;
        } elseif ($intent === 'returns') {
            $relevant = preg_match('/devol|devolver|return|refund/iu', $text) === 1;
        } else {
            $relevant = !$ids && preg_match('/tienda|catálogo|shop|catalog/iu', $text) === 1;
        }
        $hasEnglish = preg_match('/\b(the|you|here|our|found|your|have|can|these|this|we|are|opening hours)\b/iu', $text) === 1;
        $hasSpanish = preg_match('/\b(que|puedes|encontrado|estos|estas|los|las|tienda|tengo|tenemos|te|para|horario|buscas)\b/iu', $text) === 1;
        $language = $case['lang'] === 'en' ? $hasEnglish : $hasSpanish;
        $scores['relevant'] += (int) $relevant;
        $scores['language'] += (int) $language;
        foreach ($answer['cards'] as $card) {
            ++$scores['prices_checked'];
            if (!isset($prices[$card['id']]) || $prices[$card['id']] !== $card['price_cents']) {
                ++$scores['price_errors'];
            }
        }
        if (!$relevant || !$language) {
            $failures[] = ['message' => $case['message'], 'intent' => $intent, 'expected_language' => $case['lang'],
                'expected_id' => $case['id'] ?? null, 'returned_ids' => $ids, 'relevant' => $relevant,
                'language_detected' => $language, 'excerpt' => mb_substr($text, 0, 160)];
        }
    }
    return ['sector' => $sector, 'scores' => $scores, 'failures' => $failures];
}

foreach (['minimo', 'moda', 'cafe', 'metrica'] as $sector) {
    test('evaluación de conversación y precio en el catálogo ' . $sector, static function () use ($sector): void {
        $result = assistantSectorEvaluation($sector);
        $scores = $result['scores'];
        $dest = getenv('AIFEC_EVALUATION_DIR');
        if (is_string($dest) && $dest !== '') {
            $path = $dest . DIRECTORY_SEPARATOR . $sector . '.json';
            if (file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot save evaluation results');
            }
        }
        echo $sector . ' ' . json_encode($scores) . PHP_EOL;
        check($scores['phrases'] > 150 && $scores['prices_checked'] > 0);
        same(0, $scores['price_errors']);
        check($scores['relevant'] / $scores['phrases'] >= 0.9, 'relevancia inferior al 90 %');
        check($scores['language'] / $scores['phrases'] >= 0.98, 'idioma inferior al 98 %');
    });
}
