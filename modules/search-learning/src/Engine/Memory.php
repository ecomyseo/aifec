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

/** Memoria por ámbito; las previsualizaciones viven únicamente dentro de una llamada y se reponen con finally. */
final class Memory
{
    private App $app;
    private ?array $preview = null;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function state(string $lang, bool $fresh = false): array
    {
        $scope = Scope::capture($this->app, $lang, $fresh);
        $row = $this->app->db()->row('SELECT * FROM {learning_states} WHERE context_hash = ?', [$scope['hash']]);

        return ['context_hash' => $scope['hash'], 'version' => $row !== null ? (int) $row['version'] : 0, 'memory' => $row !== null ? Scope::decode($row['memory']) : []];
    }

    public function preview(string $lang, array $rules, callable $callback)
    {
        $previous = $this->preview;
        $scope = Scope::capture($this->app, $lang);
        $this->preview = ['context_hash' => $scope['hash'], 'rules' => $rules];
        $this->app->service('search')->forget();
        try {
            return $callback();
        } finally {
            $this->preview = $previous;
            $this->app->service('search')->forget();
        }
    }

    private function rules(string $lang): array
    {
        $scope = Scope::capture($this->app, $lang);
        if ($this->preview !== null) {
            return $this->preview['context_hash'] === $scope['hash'] ? $this->preview['rules'] : [];
        }

        return $this->state($lang)['memory'];
    }

    public function rewrite(string $text, array $plan): string
    {
        $lang = isset($plan['lang']) ? (string) $plan['lang'] : $this->app->lang();
        foreach ($this->rules($lang) as $rule) {
            if (($rule['kind'] ?? '') === 'intent' && Scope::normalize($text) === ($rule['source'] ?? '') && $this->usable($rule, $lang)) {
                return (string) $rule['target'];
            }
        }

        return $text;
    }

    public function rerank(array $ids, string $query, string $lang, string $type): array
    {
        if ($type !== 'product') {
            return $ids;
        }
        foreach ($this->rules($lang) as $rule) {
            if (($rule['kind'] ?? '') !== 'rank' || Scope::normalize($query) !== ($rule['source'] ?? '') || !$this->usable($rule, $lang)) {
                continue;
            }
            $ordered = [];
            foreach ($rule['product_ids'] as $id) {
                if (in_array($id, $ids, true)) {
                    $ordered[] = $id;
                }
            }
            return array_merge($ordered, array_values(array_diff($ids, $ordered)));
        }

        return $ids;
    }

    private function usable(array $rule, string $lang): bool
    {
        $id = (int) ($rule['evidence']['product_id'] ?? 0);
        $hash = (string) ($rule['evidence']['hash'] ?? '');
        if ($id <= 0 || $hash === '') { return false; }
        $product = (new Corpus($this->app))->product($id, $lang);

        return $product !== null && hash_equals($hash, $product['hash']);
    }
}
