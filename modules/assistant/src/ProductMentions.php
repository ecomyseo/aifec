<?php
/** AIFEC — menciones literales en el contenido público completo del catálogo. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;
use Aifec\Modules\Search\Synonyms;

final class ProductMentions
{
    public static function answer(App $app, string $message, array $state): ?Answer
    {
        $next = t('More products mentioning this term');
        $cursor = 0;
        if (isset($state['mentions']) && RuleBrain::norm($message) === RuleBrain::norm($next)) {
            [$term, $cursor] = $state['mentions'];
        } elseif (preg_match('/(?:qué|que|cuáles de (?:tus|vuestros)|which|what)\s+(?:productos|artículos|products|items)\s+(?:llevan|contienen|incluyen|mencionan|tienen|contain|include|mention)\s+([^?!¿\n]{2,80})[?!.]*$/iu', trim($message), $m)) {
            $term = trim($m[1], " .\t");
        } else {
            return null;
        }
        $original = $term;
        $forms = [RuleBrain::norm($term)];
        $rules = $app->has('search') ? (new Synonyms($app))->rules($app->i18n()->lang()) : [];
        foreach ($rules as $rule) {
            $variants = array_map([RuleBrain::class, 'norm'], $rule['terms']);
            if ($rule['mode'] === 'oneway' ? RuleBrain::norm($rule['source']) === $forms[0] : in_array($forms[0], $variants, true)) {
                $forms = array_unique(array_merge($forms, $variants));
                $term = $rule['terms'][0];
                break;
            }
        }
        $answer = new Answer();
        $catalog = $app->service('catalog');
        $found = [];
        $more = false;
        // Avanza por clave estable. Las fichas completas se cargan juntas; el cursor dura solo hasta otra pregunta.
        for ($batch = 0; $batch < 4; ++$batch) {
            $ids = $app->db()->column("SELECT id FROM {products} WHERE id > ? AND status = 'published' AND visibility IN ('everywhere','search','catalog') ORDER BY id LIMIT 32", [$cursor]);
            $products = $catalog->products(array_map('intval', $ids), $app->i18n()->lang());
            foreach ($ids as $id) {
                $p = $products[$id] ?? null;
                if ($p && self::matches($p, $forms)) {
                    if (count($found) === Answer::MAX_CARDS) { $more = true; break 2; }
                    $found[] = (int) $id;
                }
                $cursor = (int) $id;
            }
            $more = count($ids) === 32;
            if (!$more) { break; }
        }
        foreach ((new ShopTools($app))->cardsFor($found) as $card) { $answer->addCard($card); }
        $answer->text = t($found ? 'These product pages mention {term}.' : ($more ? 'I can check the next product pages for {term}.' : 'No further product pages mention {term}.'), ['term' => $term]);
        if ($more) {
            $answer->suggestions = [$next];
            $answer->remember['mentions'] = [$original, $cursor];
        }
        return $answer;
    }

    private static function matches(array $product, array $forms): bool
    {
        $parts = array_intersect_key($product, array_flip(['name','summary','description','facts','faqs','tags','attributes','brand','categories']));
        $text = [];
        array_walk_recursive($parts, static function ($value, $key) use (&$text): void {
            if (is_string($value) && !in_array($key, ['url','url_abs','code','slug'], true)) {
                $text[] = ' ' . RuleBrain::norm(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8')) . ' ';
            }
        });
        foreach ($text as $part) {
            foreach ($forms as $form) {
                if ($form !== '' && strpos($part, ' ' . $form . ' ') !== false) { return true; }
            }
        }
        return false;
    }
}
