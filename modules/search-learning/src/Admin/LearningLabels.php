<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Admin;

use Aifec\Kernel\App;
use Aifec\Modules\Ai\Usage;

final class LearningLabels
{
    public static function state(string $state): string
    {
        $labels = ['queued' => t('Learning round queued'), 'running' => t('Learning round running'), 'paused' => t('Learning round paused'),
            'completed' => t('Learning round completed'), 'done' => t('Learning round completed'), 'cancelled' => t('Learning round cancelled'),
            'failed' => t('Learning round failed'), 'applied' => t('Learning round applied'), 'reverted' => t('Learning round reverted'),
            'validated' => t('Learning round validated'), 'ready' => t('Learning round ready for validation'), 'rejected' => t('Learning round did not pass validation'),
            'budget' => t('Learning budget reached'), 'review' => t('Learning round needs review')];
        return $labels[$state] ?? t('Learning status not recorded');
    }

    public static function mode(string $mode): string
    {
        return $mode === 'history' ? t('Recorded queries, evaluated as simulated cases') : ($mode === 'simulated' ? t('Fictitious profiles using the real catalogue') : t('Learning source not recorded'));
    }

    public static function value($value): string
    {
        if ($value === null || $value === '') {
            return t('Not recorded');
        }
        if (is_bool($value)) {
            return $value ? t('Yes') : t('No');
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function cost(App $app, $micro, array $run): string
    {
        if (!is_int($micro) && !(is_string($micro) && preg_match('/^\d+$/', $micro) === 1)) {
            return t('Not recorded');
        }
        $currency = trim((string) ($run['currency'] ?? ($run['provider_config']['currency'] ?? '')));
        if ($currency === '') {
            return t('Not recorded');
        }
        $symbol = trim((string) ($run['currency_symbol'] ?? ($run['provider_config']['currency_symbol'] ?? '')));
        $micro = max(0, (int) $micro);
        $cents = intdiv($micro + intdiv(Usage::MICRO, 2), Usage::MICRO);
        if ($cents === 0 && $micro > 0) {
            $text = '0.' . str_pad((string) max(1, intdiv($micro + 5000, 10000)), 4, '0', STR_PAD_LEFT);
        } else {
            $text = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        }
        if (strpos($app->i18n()->number(1.5, 1), ',') !== false) {
            $text = str_replace('.', ',', $text);
        }
        return $text . "\u{a0}" . ($symbol === '' ? $currency : $symbol . ' ' . $currency);
    }

    public static function budget(App $app, array $run): string
    {
        if (!isset($run['budget_cents']) || !is_int($run['budget_cents']) || $run['budget_cents'] < 0 || $run['budget_cents'] > 100000) {
            return t('Not recorded');
        }
        return self::cost($app, $run['budget_cents'] * Usage::MICRO, $run);
    }

    public static function runCost(App $app, array $run): string
    {
        $amount = self::cost($app, $run['spent_micro'] ?? null, $run);
        if (!empty($run['uncertain_calls']) || (array_key_exists('cost_known', $run) && $run['cost_known'] === false)) {
            return t('Unknown total; reconciled cost: {amount}', ['amount' => $amount]);
        }
        return $amount;
    }

    public static function tier(string $tier): string
    {
        return ['economy' => t('Economy'), 'standard' => t('Standard'), 'advanced' => t('Advanced')][$tier] ?? t('Not recorded');
    }

    public static function verdict(string $verdict): string
    {
        return ['improved' => t('Measured improvement'), 'regression' => t('Detected regression'), 'unchanged' => t('No measured change'),
            'unverifiable' => t('Not verifiable against catalogue data')][$verdict] ?? t('Not evaluated yet');
    }

    public static function proposal(string $status): string
    {
        return ['pending' => t('Pending learning proposal'), 'applied' => t('Applied learning proposal'), 'rejected' => t('Rejected learning proposal')][$status] ?? t('Not recorded');
    }

    public static function application(string $status): string
    {
        return ['active' => t('Learning application currently active'), 'reverted' => t('Learning application reversed')][$status] ?? t('Not recorded');
    }

    public static function caseKind(string $kind): string
    {
        return ['simulated' => t('Fictitious profile case'), 'history' => t('Recorded query case'), 'regression' => t('Regression case')][$kind] ?? t('Not recorded');
    }

    public static function profile(array $item): string
    {
        if (($item['kind'] ?? '') === 'regression') {
            return t('Deterministic catalogue control');
        }
        return isset($item['profile']) ? self::value($item['profile']) : self::caseKind((string) ($item['kind'] ?? ''));
    }

    public static function validation(array $validation): array
    {
        $labels = ['passed' => t('Deterministic validation passed'), 'improvements' => t('Measured improvements'), 'regressions' => t('Detected regressions'),
            'verifiable' => t('Verifiable cases'), 'unverifiable' => t('Unverifiable cases'), 'version' => t('Validated memory version'), 'validated_at' => t('Validation recorded at (UTC)')];
        $out = [];
        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $validation)) {
                $out[$label] = self::value($validation[$key]);
            }
        }
        return $out;
    }

    public static function problem(string $code): string
    {
        $messages = [
            'learning.ai_disabled' => t('The configured AI is disabled. Enable and configure it before authorising a round.'),
            'learning.tariffs_required' => t('Set both input and output token prices for the selected paid AI tier before starting.'),
            'learning.invalid_tariffs' => t('The configured AI token prices are not valid.'),
            'learning.budget' => t('The round cannot reserve the next batch within its authorised budget.'),
            'learning.uncertain_call' => t('A provider call has an unknown outcome. Its reservation remains held and automatic retries are blocked.'),
            'learning.worker_stopped' => t('The previous worker stopped. Review outstanding calls and reservations before explicitly resuming.'),
            'learning.invalid_response' => t('The AI response could not be verified. Review the recorded usage before continuing.'),
            'learning.provider_changed' => t('The AI provider, model, key or prices changed. This round cannot continue with different credentials or tariffs.'),
            'learning.catalogue_changed' => t('The catalogue changed. Authorise a new round against the current product facts.'),
            'learning.context_changed' => t('The shop URL or language context changed. This round cannot continue in the new context.'),
            'learning.stale_review' => t('The context or memory version no longer matches this review. Reload the round.'),
            'learning.stale_version' => t('The stored memory version changed. Reload before applying or reversing learning.'),
            'learning.reverse_order_required' => t('Reverse later applications first. An older reversal cannot erase newer learning.'),
            'learning.no_verified_improvement' => t('No measurable improvement without regressions was verified. Nothing can be applied.'),
            'learning.validation_required' => t('Run and pass deterministic validation before explicitly applying proposals.'),
            'learning.no_safe_history' => t('No safe recorded queries were found for that period.'),
            'learning.cannot_resume' => t('This round cannot resume in its current state. Review outstanding reservations and calls.'),
            'learning.real_admin_required' => t('Only an active superadministrator in a real shop can authorise learning.'),
            'learning.cancelled' => t('This learning round was cancelled. It cannot make new calls or apply changes.'),
        ];
        return $messages[$code] ?? t('The learning action was not confirmed. Check the round status, configured AI prices and available budget before trying again.');
    }
}
