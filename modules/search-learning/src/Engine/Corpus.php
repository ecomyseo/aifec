<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Engine;

use Aifec\Kernel\App;

/** Evidencia de catálogo y búsquedas reales, sin llamadas a IA ni estadísticas de visitantes. */
final class Corpus
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function sample(string $lang, int $limit): array
    {
        $listing = $this->app->service('catalog')->listing(['lang' => $lang, 'per_page' => min(40, max(5, $limit)), 'sort' => 'id_asc']);
        $out = [];
        foreach ($listing['items'] as $card) {
            $product = $this->product((int) $card['id'], $lang);
            if ($product === null) {
                continue;
            }
            try {
                Scope::text($product['name'], 200);
                Scope::text($product['text'], 2500);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            $out[(string) $product['id']] = $product;
        }
        if ($out === []) {
            throw new \InvalidArgumentException('learning.no_safe_catalogue');
        }

        return $out;
    }

    public function product(int $id, string $lang): ?array
    {
        $this->app->invalidate(['product:' . $id]);
        $data = $this->app->service('catalog')->product($id, $lang);
        if (!is_array($data)) {
            return null;
        }
        // La pertenencia a un listado público también excluye productos ocultos que sí admiten una ficha directa.
        $visible = $this->app->service('catalog')->listing(['lang' => $lang, 'ids' => [$id], 'per_page' => 1]);
        if ((int) $visible['total'] !== 1) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $data['name'] . ' ' . (string) $data['summary'] . ' ' . (string) $data['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $proof = ['id' => $id, 'name' => (string) $data['name'], 'text' => mb_substr($text, 0, 2400),
            'price' => $data['price'], 'availability' => $data['availability'], 'attributes' => $data['attributes'],
            'dimensions' => $data['dimensions'], 'categories' => $data['categories'], 'facts' => $data['facts'], 'options' => $data['options']];
        $proof['hash'] = hash('sha256', Scope::json($proof));

        return $proof;
    }

    public function unchanged(array $catalogue, string $lang): bool
    {
        foreach ($catalogue as $id => $old) {
            $fresh = $this->product((int) $id, $lang);
            if ($fresh === null || !hash_equals((string) $old['hash'], $fresh['hash'])) {
                return false;
            }
        }

        return true;
    }

    public function ask(string $query, string $lang): array
    {
        return $this->app->service('search')->withoutAiSideEffects(function () use ($query, $lang): array {
            return $this->search($query, $lang);
        });
    }

    private function search(string $query, string $lang): array
    {
        $search = $this->app->service('search');
        $search->forget();
        try {
            $result = $search->ask($query, ['lang' => $lang, 'limit' => 20, 'ai' => false, 'log' => false]);
            $ids = [];
            foreach ($result['products'] as $product) {
                $ids[] = (int) $product['id'];
            }
            $positions = [];
            foreach ($ids as $index => $id) {
                $positions[(string) $id] = $index + 1;
            }
            $plan = $search->asker()->plan($query, $lang, false, false);

            return ['ids' => $ids, 'positions' => $positions,
                'total' => (int) $result['total'], 'filters' => $result['understood']['filters'],
                'text' => $result['understood']['text'], 'kind' => $result['answer_kind'],
                'hard_plan' => ['catalog' => $plan['catalog'], 'restrict' => $plan['restrict']],
                'score' => null];
        } finally {
            $search->forget();
        }
    }

    /** La evidencia es literal; todos los términos significativos del texto residual tienen que estar respaldados. */
    public function verifiable(string $query, string $lang, int $target, string $evidence, array $catalogue): bool
    {
        return $this->app->service('search')->withoutAiSideEffects(function () use ($query, $lang, $target, $evidence, $catalogue): bool {
            return $this->verify($query, $lang, $target, $evidence, $catalogue);
        });
    }

    private function verify(string $query, string $lang, int $target, string $evidence, array $catalogue): bool
    {
        $product = $catalogue[(string) $target] ?? null;
        if (!is_array($product) || mb_strlen($evidence) < 8 || mb_strlen($evidence) > 300
            || mb_stripos($product['text'], $evidence, 0, 'UTF-8') === false) {
            return false;
        }
        $plan = $this->app->service('search')->asker()->plan($query, $lang, false, false);
        $tokens = self::words((string) $plan['text']);
        $facts = self::words($evidence);
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            $found = false;
            foreach ($facts as $fact) {
                if (self::sameWord($token, $fact)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        // Se comprueban los filtros originales con el servicio de catálogo, no con la nota del modelo.
        $plan['text'] = '';
        $filters = $this->app->service('search')->asker()->catalogQuery($plan);
        unset($filters['q']);
        $allowed = isset($filters['ids']) ? array_values(array_intersect(array_map('intval', $filters['ids']), [$target])) : [$target];
        if ($allowed === []) {
            return false;
        }
        $filters['ids'] = $allowed;
        $filters['per_page'] = 1;

        return (int) $this->app->service('catalog')->listing($filters)['total'] === 1;
    }

    /** Alternativas comprobadas independientemente del ID que la IA eligió: no fabrica un fallo eligiendo la última. */
    public function targets(string $query, string $lang, array $catalogue): array
    {
        return $this->app->service('search')->withoutAiSideEffects(function () use ($query, $lang, $catalogue): array {
            return $this->deriveTargets($query, $lang, $catalogue);
        });
    }

    private function deriveTargets(string $query, string $lang, array $catalogue): array
    {
        $plan = $this->app->service('search')->asker()->plan($query, $lang, false, false);
        $tokens = self::words((string) $plan['text']);
        if ($tokens === []) { return []; }
        $ids = [];
        foreach ($catalogue as $product) {
            $facts = self::words($product['text']);
            $all = true;
            foreach ($tokens as $token) {
                $found = false;
                foreach ($facts as $fact) { if (self::sameWord($token, $fact)) { $found = true; break; } }
                if (!$found) { $all = false; break; }
            }
            if ($all) { $ids[] = (int) $product['id']; }
        }
        if ($ids === []) { return []; }
        $plan['text'] = '';
        $filters = $this->app->service('search')->asker()->catalogQuery($plan);
        unset($filters['q']);
        if (isset($filters['ids'])) { $ids = array_values(array_intersect($ids, array_map('intval', $filters['ids']))); }
        if ($ids === []) { return []; }
        $filters['ids'] = $ids; $filters['per_page'] = 96;
        $result = [];
        foreach ($this->app->service('catalog')->listing($filters)['items'] as $item) { $result[] = (int) $item['id']; }
        return $result;
    }

    private static function sameWord(string $a, string $b): bool
    {
        return $a === $b || (mb_strlen($a) >= 7 && mb_strlen($b) >= 7 && mb_substr($a, 0, 7) === mb_substr($b, 0, 7));
    }

    public static function words(string $text): array
    {
        $words = preg_split('/[^\pL\pN]+/u', Scope::normalize($text)) ?: [];
        $stop = ['para', 'quiero', 'necesito', 'algo', 'donde', 'tengo', 'busco', 'unos', 'unas', 'como', 'with', 'some', 'want', 'need', 'looking', 'mueble', 'sitio'];

        return array_values(array_unique(array_filter($words, static function (string $word) use ($stop): bool {
            return mb_strlen($word) >= 4 && !is_numeric($word) && !in_array($word, $stop, true);
        })));
    }

    public static function score(array $result, array $targets): int
    {
        foreach ($result['ids'] as $index => $id) {
            if (in_array((int) $id, $targets, true)) {
                return $index < 3 ? 10 : ($index < 10 ? 9 : 7);
            }
        }

        return 0;
    }
}
