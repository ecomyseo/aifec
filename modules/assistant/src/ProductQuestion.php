<?php
/** AIFEC — apartados verificables de una ficha y aclaración cuando hay más de un producto posible. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class ProductQuestion
{
    /** Identifica la pareja nombrada antes de recurrir a las tarjetas de otro turno. */
    public static function comparison(App $app, string $message, array $state): ?Answer
    {
        $pattern = '/^\s*[¿¡]?(?:(?:en qu[ée] (?:se )?diferencian|(?:cu[aá]l(?:es)? (?:es|son) (?:la|las) )?diferencias? entre|what(?: is| are|[’\']s) the differences? between|compare|comp[aá]ra(?:me)?|comparar)\s+)(.+?)[?!.]*$/iu';
        $named = preg_match($pattern, trim($message), $match);
        if (!$named && !empty($state['comparison_names']) && preg_match('/\s+(?:y|and|con|with|versus)\s+/iu', $message)) {
            $named = true;
            $match[1] = trim($message);
        }
        $pair = [];
        if ($named && preg_match_all('/\s+(?:y|e|con|versus|vs\.?|and|with)\s+/iu', $match[1], $joins, PREG_OFFSET_CAPTURE)) {
            $pair = self::namedPairs($app, $match[1], $joins[0]);
        } elseif (preg_match('/^(?:en que (?:se )?diferencian(?: estos dos| los dos)?|what is the difference(?: between (?:them|these two))?|how do they differ|compare (?:them|these two)|compara (?:ambos|los dos|estos dos))$/', RuleBrain::norm($message))) {
            $shown = array_values(array_unique(array_map('intval', array_column($state['cards'] ?? [], 'id'))));
            if (count($shown) === 2) { $pair[] = $shown; }
        } else {
            return null;
        }
        $answer = new Answer();
        if (count($pair) === 1) {
            $shop = new ShopTools($app);
            $result = $shop->compareProducts(['ids' => $pair[0]], []);
            if (!isset($result['error'])) {
                $answer->text = implode("\n", array_merge([t('This is how they compare:')], RuleBrain::comparison($result)));
                foreach ($shop->cardsFor($pair[0]) as $item) { $answer->addCard($item); }
                return $answer;
            }
        }
        $answer->remember['comparison_names'] = true;
        $answer->text = t('Which two products do you mean? Write both names as they appear on their product pages.');
        return $answer;
    }

    /** Los separadores también pueden formar parte de un nombre comercial. Resuelve todos los cortes en una consulta. */
    private static function namedPairs(App $app, string $text, array $joins): array
    {
        $cuts = [];
        $names = [];
        foreach ($joins as [$join, $at]) {
            $cut = [];
            foreach ([substr($text, 0, $at), substr($text, $at + strlen($join))] as $part) {
                $label = preg_replace('/^[\s"\'«»]+|[\s"\'«»]+$/u', '', $part);
                $key = mb_strtolower((string) $label, 'UTF-8');
                $cut[] = $key;
                $names[$key] = $label;
            }
            $cuts[] = $cut;
        }
        $db = $app->db();
        $slots = implode(', ', array_fill(0, count($names), '?'));
        $args = array_merge(array_values($names), array_keys($names), [$app->i18n()->lang(), $app->defaultLang()]);
        $rows = $db->select("SELECT DISTINCT p.id, i.name FROM {products} p JOIN {product_i18n} i ON i.product_id = p.id WHERE (i.name IN ($slots) OR LOWER(i.name) IN ($slots)) AND i.lang IN (?, ?) AND p.status = 'published' AND p.visibility IN ('everywhere','search','catalog')", $args);
        $ids = [];
        foreach ($rows as $row) {
            $ids[mb_strtolower($row['name'], 'UTF-8')][(int) $row['id']] = (int) $row['id'];
        }
        $pairs = [];
        foreach ($cuts as [$left, $right]) {
            if ($left !== $right && count($ids[$left] ?? []) === 1 && count($ids[$right] ?? []) === 1) {
                $a = reset($ids[$left]);
                $b = reset($ids[$right]);
                if ($a !== $b) { $pairs[$a . ':' . $b] = [$a, $b]; }
            }
        }
        return array_values($pairs);
    }

    private const TOPICS = [
        'ingredients' => ['ingredientes?|ingredients?|composicion|inci', 'Ingredients'],
        'material' => ['material(?:es)?|materials?|composicion textil|fabric composition', 'Material'],
        'usage' => ['como (?:se )?(?:usa|aplica|utiliza)|modo de (?:uso|empleo)|how (?:do i|to) (?:use|apply)|instructions for use', 'How to use'],
        'contents' => ['que (?:incluye|trae|contiene) (?:este|esta|el|la)|what (?:is included|comes in)', 'Package contents'],
        'size' => ['que medidas|cuanto mide|dimensions|measurements', 'Measurements'],
        'care' => ['como (?:se )?(?:lava|limpia|cuida)|cuidados|how (?:do i|to) (?:wash|clean)|care instructions', 'Care'],
    ];
    private const LABELS = [
        'ingredients' => 'ingredientes?|ingredients?|composicion|inci',
        'material' => 'material(?:es)?|materials?|tejido|fabric|composicion textil',
        'usage' => 'uso|empleo|aplicar|aplicacion|instrucciones|how to use|directions|apply|instructions',
        'contents' => 'incluye|contenido|contiene|incluido|includes|contents|contains',
        'size' => 'medidas?|dimensiones|ancho|largo|alto|width|length|height|dimensions|measurements',
        'care' => 'cuidados?|lavado|lavar|limpiar|care|wash|clean',
    ];

    /** Los títulos largos del catálogo pueden mencionar pedidos, transporte o pagos sin solicitar esas operaciones. */
    public static function namedListing(App $app, string $message, bool $digitalOnly = false): ?Answer
    {
        if (!preg_match('/^[¿¡\s]*(?:busco|muéstrame|me enseñas|estoy buscando|do you have|do you sell|where can i find|i am looking for|show me)\s+(.+?)\s*[?!.]*$/iu', trim($message), $m)) { return null; }
        $name = trim($m[1]);
        if (str_word_count(RuleBrain::norm($name)) < ($digitalOnly ? 6 : 2)) { return null; }
        $kind = $digitalOnly ? " AND p.kind = 'digital'" : '';
        $rows = $app->db()->column("SELECT DISTINCT p.id FROM {product_i18n} i JOIN {products} p ON p.id = i.product_id WHERE i.name = ? AND i.lang IN (?, ?) AND p.status = 'published' AND p.visibility IN ('everywhere','search','catalog') $kind LIMIT 2", [$name, $app->i18n()->lang(), $app->defaultLang()]);
        if (count($rows) !== 1) { return null; }
        $items = (new ShopTools($app))->cardsFor($rows);
        if (!$items) { return null; }
        $answer = new Answer();
        $answer->text = t('I found the product you named: {name}.', ['name' => $items[0]['name']]);
        $answer->addCard($items[0]);
        $answer->suggestions = [t('More information')];
        return $answer;
    }

    public static function topic(App $app, string $message): string
    {
        $n = RuleBrain::norm($message);
        if (preg_match('/\b(?:que|which|what) (?:productos|articulos|products|items)\b/', $n)
            || preg_match('/^(?:busco|quiero comprar|estoy buscando|i am looking for|show me)\b/', $n)) { return ''; }
        if ($app->settings()->str('search.sector') === 'fashion' && preg_match('/\b(?:es (?:de )?(?:piel|cuero)|is (?:it|this) leather)\b/', $n)) { return 'material'; }
        foreach (self::TOPICS as $key => $entry) {
            if (preg_match('/\b(?:' . $entry[0] . ')\b/', $n)) { return $key; }
        }
        return '';
    }

    public static function answer(App $app, string $message, array $state): ?Answer
    {
        $page = (int) ($state['linked_product'] ?? $state['page_product'] ?? 0);
        if ($page) {
            $product = $app->service('catalog')->product($page, $app->i18n()->lang());
            foreach ($product['faqs'] ?? [] as $faq) {
                if (RuleBrain::norm($faq['question']) === RuleBrain::norm($message)) {
                    $answer = new Answer();
                    $answer->text = trim(html_entity_decode(strip_tags($faq['answer']), ENT_QUOTES, 'UTF-8'));
                    return $answer;
                }
            }
        }
        $topic = self::topic($app, $message);
        if ($topic === '') { return null; }
        $n = ' ' . RuleBrain::norm($message) . ' ';
        $cards = $state['cards'] ?? [];
        $explicit = '';
        if (preg_match('/^(?:ingredientes?|ingredients?|material(?:es)?|cuidados?|care|medidas|measurements|modo de uso|how to use|contenido del paquete|package contents)\s*(?:[·:]|\b(?:de|del|of|for)\b)\s*(.+)$/iu', preg_replace('/^[\s¿¡]+|[\s?!]+$/u', '', $message), $m)) {
            $explicit = trim($m[1]);
        }
        $id = (int) ($state['linked_product'] ?? 0);
        if (!$id) {
            foreach ($cards as $card) {
                if (!empty($card['n']) && strpos($n, ' ' . RuleBrain::norm($card['n']) . ' ') !== false) {
                    $id = (int) $card['id'];
                    break;
                }
            }
        }
        if (!$id && $explicit !== '') {
            $matches = $app->db()->column("SELECT DISTINCT i.product_id FROM {product_i18n} i JOIN {products} p ON p.id = i.product_id WHERE i.name = ? AND p.status = 'published' LIMIT 2", [$explicit]);
            if (count($matches) === 1) { $id = (int) $matches[0]; }
            elseif (preg_match('/^(?:est[ea]|this|el producto|the product)\b/', RuleBrain::norm($explicit)) !== 1) {
                $state['page_product'] = 0;
                if (count($cards) === 1) { $cards = []; }
            }
        }
        if (!$id && !empty($state['page_product'])) { $id = (int) $state['page_product']; }
        if (!$id && count($cards) === 1) { $id = (int) $cards[0]['id']; }
        if (!$id && !$cards) { return null; }
        $answer = new Answer();
        $label = t(self::TOPICS[$topic][1]);
        if (!$id) {
            $answer->text = t('Which product should I check?');
            foreach (array_slice($cards, 0, 4) as $card) {
                $answer->suggestions[] = $label . ' · ' . $card['n'];
            }
            return $answer;
        }
        $shop = new ShopTools($app);
        $raw = $app->service('catalog')->product($id, $app->i18n()->lang());
        if (!$raw) { return null; }
        $lines = [];
        $match = '/\b(?:' . self::LABELS[$topic] . ')\b/';
        foreach (array_merge($raw['facts'] ?? [], $raw['attributes'] ?? []) as $fact) {
            $name = (string) ($fact['label'] ?? $fact['name'] ?? '');
            if (preg_match($match, RuleBrain::norm($name))) {
                $lines[] = trim(strip_tags($name . ': ' . $fact['value']));
            }
        }
        if (!$lines) {
            $plain = html_entity_decode(strip_tags(preg_replace('~</(?:p|li|h[1-6]|div)>|<br\s*/?>~i', "\n", ($raw['summary'] ?? '') . "\n" . ($raw['description'] ?? ''))), ENT_QUOTES, 'UTF-8');
            foreach (preg_split('/(?<=[.!?])\s+|\R/u', $plain) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && preg_match($match, RuleBrain::norm($line))) { $lines[] = $line; }
            }
        }
        $lines = array_values(array_unique($lines));
        $answer->text = $lines ? $raw['name'] . "\n" . implode("\n", array_slice($lines, 0, 3))
            : t('The product page does not specify this. The shop team can check it for you.');
        foreach ($shop->cardsFor([$id]) as $card) { $answer->addCard($card); }
        return $answer;
    }
}
