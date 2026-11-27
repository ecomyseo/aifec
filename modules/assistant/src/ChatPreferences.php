<?php
/** AIFEC — criterios temporales del chat y presupuesto que la persona decide guardar en su cuenta. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;

final class ChatPreferences
{
    private static function customer(App $app): int
    {
        $row = $app->has('customers') ? $app->service('customers')->current() : null;
        return $row && empty($row['guest']) ? (int) $row['id'] : 0;
    }

    public static function budget(App $app): int
    {
        $id = self::customer($app);
        return $id ? (int) $app->db()->value('SELECT max_price FROM {assistant_preferences} WHERE customer_id = ? AND currency = ?', [$id, $app->settings()->str('shop.currency', 'EUR')]) : 0;
    }

    public static function read(App $app, Request $request, int $activeConversation = 0): array
    {
        $repo = $app->service('assistant')->conversations();
        $row = $activeConversation ? $repo->find($activeConversation) : $repo->findByToken((string) $request->cookie($repo->cookieName(), ''));
        $state = $row ? $repo->state($row) : [];
        $need = Need::clean($state['need'] ?? []);
        $chips = [];
        if (!empty($need['s'])) { $chips['query'] = $need['s']; }
        if (!empty($need['for'])) { $chips['for'] = $need['for']; }
        if (!empty($need['max'])) { $chips['max'] = t('Up to {amount}', ['amount' => money((int) $need['max'])]); }
        foreach ($need['more'] ?? [] as $term) {
            $chips['term-' . substr(hash('sha256', $term), 0, 12)] = $term;
        }
        $budget = self::budget($app);
        if ($budget > 0) { $chips['account'] = t('Saved budget: {amount}', ['amount' => money($budget)]); }
        return ['chips' => $chips, 'signed_in' => self::customer($app) > 0, 'budget' => $budget];
    }

    public static function edit(App $app, Request $request): array
    {
        $key = $request->str('remove');
        $id = self::customer($app);
        if ($key === 'account' || $request->bool('save_budget')) {
            if (!$id) { throw HttpError::notFound(); }
            if ($key === 'account') {
                $app->db()->delete('assistant_preferences', ['customer_id' => $id]);
            } else {
                $max = $request->int('max_price');
                if ($max < 1 || $max > 100000000) { throw HttpError::badRequest(); }
                $app->db()->upsert('assistant_preferences', ['customer_id' => $id, 'currency' => $app->settings()->str('shop.currency', 'EUR'), 'max_price' => $max], ['customer_id']);
            }
        } else {
            $repo = $app->service('assistant')->conversations();
            $row = $repo->findByToken((string) $request->cookie($repo->cookieName(), ''));
            if (!$row) { throw HttpError::notFound(); }
            $state = $repo->state($row);
            $need = Need::clean($state['need'] ?? []);
            if ($key === 'query') { $need = []; }
            elseif ($key === 'for') { unset($need['for'], $need['group']); }
            elseif ($key === 'max') { unset($need['max'], $need['cheaper']); }
            elseif (strpos($key, 'term-') === 0) {
                $need['more'] = array_values(array_filter($need['more'] ?? [], static function ($term) use ($key): bool {
                    return 'term-' . substr(hash('sha256', $term), 0, 12) !== $key;
                }));
            } else { throw HttpError::badRequest(); }
            $state['need'] = $need;
            $repo->update((int) $row['id'], ['state' => $state]);
        }
        return self::read($app, $request);
    }
}
