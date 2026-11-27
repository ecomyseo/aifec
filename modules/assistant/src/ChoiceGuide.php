<?php
/** AIFEC — dos preguntas para elegir un regalo o encontrar estilo y precio dentro del catálogo. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class ChoiceGuide
{
    public static function answer(App $app, string $message, array $state): ?Answer
    {
        $n = RuleBrain::norm($message);
        $guide = $state['choice_guide'] ?? [];
        $start = in_array($n, ['ayudame a elegir un regalo','buscar un regalo','help me choose a gift','find a gift','asesor de estilo','style advisor','ayudame a elegir paso a paso','help me choose step by step'], true);
        if (!$start && !$guide) { return null; }
        $answer = new Answer();
        if (!$start && in_array($n, ['cancelar','cancel','salir','stop'], true)) {
            $answer->remember['choice_guide'] = [];
            $answer->text = t('We can continue here.');
            return $answer;
        }
        $catalog = $app->service('catalog');
        if ($start) {
            $categories = $catalog->tree();
            if (count($categories) === 1 && !empty($categories[0]['children'])) { $categories = $categories[0]['children']; }
            $options = [];
            foreach (array_slice($categories, 0, 5) as $cat) {
                $options[(int) $cat['id']] = (string) $cat['name'];
            }
            $answer->remember['choice_guide'] = ['step' => 'category', 'categories' => $options];
            $answer->text = t('Which category would you like to explore?');
            $answer->suggestions = array_merge(array_values($options), [t('Any category')]);
            return $answer;
        }
        if ($guide['step'] === 'category') {
            $category = null;
            foreach ($guide['categories'] as $id => $name) {
                if (RuleBrain::norm($name) === $n) { $category = (int) $id; break; }
            }
            if (in_array($n, ['cualquier categoria','any category'], true)) { $category = 0; }
            if ($category === null) { return null; }
            $answer->remember['choice_guide'] = ['step' => 'budget', 'category' => $category];
            $answer->text = t('What is your maximum price per item?');
            $list = $catalog->listing(['category_id' => $category, 'in_stock' => true, 'sort' => 'price_asc', 'per_page' => 12]);
            $items = $list['items'];
            if ($items) {
                foreach ([$items[0], $items[intdiv(count($items), 2)], end($items)] as $item) {
                    $answer->suggestions[] = t('Up to {amount}', ['amount' => money((int) $item['price']['amount'])]);
                }
            }
            $answer->suggestions[] = t('No price limit');
            return $answer;
        }
        $noLimit = in_array($n, ['sin limite de precio','no price limit'], true);
        if (!$noLimit && !preg_match('/^(?:(?:hasta|up to|menos de|under)\s+)?(?:[€$£]\s*)?([0-9][0-9.,\h]*)\s*(?:[€$£]|eur|euros?|usd|dollars?|gbp)?$/iu', trim($message), $m)) {
            return null;
        }
        $max = null;
        if (!$noLimit) {
            $amount = preg_replace('/\h+/u', '', trim($m[1]));
            if (preg_match('/[.,][0-9]{2}$/', $amount)) {
                $max = (int) preg_replace('/\D/', '', $amount);
            } else {
                $max = (int) round(100 * (float) str_replace(',', '.', $amount));
            }
            if ($max <= 0 || $max > 100000000) {
                $answer->text = t('Enter a positive amount or choose no price limit.');
                return $answer;
            }
        }
        $saved = ChatPreferences::budget($app);
        if ($saved > 0) { $max = $max === null ? $saved : min($max, $saved); }
        $list = $catalog->listing(['category_id' => (int) $guide['category'], 'in_stock' => true, 'price_max' => $max, 'per_page' => 3]);
        $shop = new ShopTools($app);
        foreach ($shop->cardsFor(array_column($list['items'], 'id')) as $item) {
            if (!empty($item['available']) && ($max === null || $item['price_cents'] <= $max)) { $answer->addCard($item); }
        }
        $answer->remember['choice_guide'] = [];
        $answer->text = $answer->cards ? t('These items are available and match your category and price selection.') : t('There are no available items for that combination. Would you like to try another category or price?');
        $answer->suggestions = [t('Help me choose step by step')];
        return $answer;
    }
}
