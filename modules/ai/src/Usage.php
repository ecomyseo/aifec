<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Lo que se ha gastado: el día UTC, la suma de hoy, el resumen de 30 días por día y por tarea y la gráfica.
 * El coste se guarda en millonésimas de céntimo (cost_micro), todo en enteros.
 */
final class Usage
{
    /** Millonésimas de céntimo que tiene un céntimo. */
    public const MICRO = 1000000;

    /** Día UTC (Y-m-d) del reloj de la aplicación. */
    public static function day(App $app, ?int $timestamp = null): string
    {
        return substr($app->clock()->sql($timestamp), 0, 10);
    }

    /** Coste estimado de un día, en millonésimas de céntimo. */
    public static function spentMicro(App $app, ?string $day = null): int
    {
        $day = $day !== null ? $day : self::day($app);

        return (int) $app->db()->value('SELECT COALESCE(SUM(cost_micro), 0) FROM {ai_usage} WHERE day = ?', [$day]);
    }

    /** Primer día y fin exclusivo del mes natural UTC, no una ventana móvil de treinta días. */
    public static function monthBounds(App $app): array
    {
        $start = new \DateTimeImmutable(substr(self::day($app), 0, 7) . '-01', new \DateTimeZone('UTC'));

        return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    }

    /** Gasto estimado del mes natural UTC, en millonésimas de céntimo. */
    public static function spentMonthMicro(App $app): int
    {
        return (int) $app->db()->value('SELECT COALESCE(SUM(cost_micro), 0) FROM {ai_usage} WHERE day >= ? AND day < ?', self::monthBounds($app));
    }

    /** Tope mensual opcional: cero conserva el comportamiento anterior, sin tope mensual. */
    public static function limitMonthMicro(App $app): int
    {
        return min(1000000000, max(0, $app->settings()->int('ai.monthly_limit_cents', 0))) * self::MICRO;
    }

    /** Fichas de entrada y salida comunicadas por el proveedor; no son una estimación de caracteres. */
    public static function tokens(App $app, ?string $day = null): int
    {
        return (int) $app->db()->value('SELECT COALESCE(SUM(tokens_in + tokens_out), 0) FROM {ai_usage} WHERE day = ?', [$day !== null ? $day : self::day($app)]);
    }

    /** Tope opcional de fichas diarias. La llamada que lo alcanza se registra y se bloquean las siguientes. */
    public static function limitTokens(App $app): int
    {
        return max(0, $app->settings()->int('ai.daily_limit_tokens', 0));
    }

    /** Motivo efectivo del bloqueo. Las comprobaciones usan los mismos datos que la pantalla de uso. */
    public static function limitReason(App $app): string
    {
        if (self::spentMicro($app) >= self::limitMicro($app) || self::calls($app) >= self::limitCalls($app)) {
            return 'Daily AI limit reached';
        }
        $month = self::limitMonthMicro($app);
        if ($month > 0 && self::spentMonthMicro($app) >= $month) {
            return 'Monthly AI limit reached';
        }
        $tokens = self::limitTokens($app);
        if ($tokens > 0 && self::tokens($app) >= $tokens) {
            return 'Daily AI token limit reached';
        }

        return '';
    }

    /** El tope diario, en millonésimas de céntimo. 0 = no se permite gastar nada. */
    public static function limitMicro(App $app): int
    {
        $cents = max(0, $app->settings()->int('ai.daily_limit_cents', 200));
        if ($app->site()->isDemo()) {
            // En una demostración pública cualquiera entra al panel: el tope no puede subir de aquí, se ponga lo que se ponga.
            $cents = min($cents, self::DEMO_CENTS);
        }

        return $cents * self::MICRO;
    }

    /** Topes fijos de una demostración pública (céntimos y llamadas al día). */
    public const DEMO_CENTS = 100;
    public const DEMO_CALLS = 200;

    /** Llamadas hechas en un día (las fallidas también: el proveedor puede cobrarlas). */
    public static function calls(App $app, ?string $day = null): int
    {
        $day = $day !== null ? $day : self::day($app);

        return (int) $app->db()->value('SELECT COUNT(*) FROM {ai_usage} WHERE day = ?', [$day]);
    }

    /**
     * El tope diario de llamadas. No depende de los precios: sin ellos el gasto estimado es siempre cero y el tope en
     * dinero no pararía nada. 0 = ninguna llamada.
     */
    public static function limitCalls(App $app): int
    {
        $calls = max(0, $app->settings()->int('ai.daily_limit_calls', 500));

        return $app->site()->isDemo() ? min($calls, self::DEMO_CALLS) : $calls;
    }

    /**
     * Millonésimas de céntimo → «1,23» (céntimos con dos decimales), con la coma o el punto del idioma. Solo enteros.
     */
    public static function cents(App $app, int $micro): string
    {
        $hundredths = intdiv(max(0, $micro) + 5000, 10000);
        $text = intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
        $comma = strpos($app->i18n()->number(1.5, 1), ',') !== false;

        return $comma ? str_replace('.', ',', $text) : $text;
    }

    /** Símbolo de la moneda de la tienda (ajuste «shop.currency_symbol»). Vacío si no hay ninguno: entonces los importes salen sin símbolo. */
    public static function symbol(App $app): string
    {
        return trim($app->settings()->str('shop.currency_symbol'));
    }

    /**
     * Millonésimas de céntimo → importe en unidades de la moneda: «2,00» (200 céntimos) y, si hay símbolo, «2,00 €». Con la coma o el
     * punto del idioma. Dos decimales; un importe que no llega al céntimo pero no es cero sale con cuatro («0,0003») para que una
     * llamada barata no parezca gratis. Todo con enteros, sin coma flotante.
     */
    public static function amount(App $app, int $micro): string
    {
        $micro = max(0, $micro);
        $hundredths = intdiv($micro + intdiv(self::MICRO, 2), self::MICRO);
        if ($hundredths === 0 && $micro > 0) {
            $text = '0.' . str_pad((string) max(1, intdiv($micro + 5000, 10000)), 4, '0', STR_PAD_LEFT);
        } else {
            $text = intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
        }
        $comma = strpos($app->i18n()->number(1.5, 1), ',') !== false;
        if ($comma) {
            $text = str_replace('.', ',', $text);
        }
        $symbol = self::symbol($app);
        if ($symbol === '') {
            return $text;
        }

        return $comma ? $text . "\u{a0}" . $symbol : $symbol . $text;
    }

    /**
     * Los últimos $days días, uno por uno (también los que no tienen nada).
     *
     * @return array<int,array{day:string,calls:int,failed:int,tokens_in:int,tokens_out:int,cost_micro:int}>
     */
    public static function byDay(App $app, int $days = 30): array
    {
        $now = $app->clock()->now();
        $from = self::day($app, $now - ($days - 1) * 86400);
        $found = [];
        $rows = $app->db()->select(
            'SELECT day, COUNT(*) AS calls, SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout, SUM(cost_micro) AS cost '
            . 'FROM {ai_usage} WHERE day >= ? GROUP BY day ORDER BY day',
            [$from]
        );
        foreach ($rows as $row) {
            $found[(string) $row['day']] = $row;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $day = self::day($app, $now - $i * 86400);
            $row = isset($found[$day]) ? $found[$day] : [];
            $out[] = [
                'day' => $day,
                'calls' => isset($row['calls']) ? (int) $row['calls'] : 0,
                'failed' => isset($row['failed']) ? (int) $row['failed'] : 0,
                'tokens_in' => isset($row['tin']) ? (int) $row['tin'] : 0,
                'tokens_out' => isset($row['tout']) ? (int) $row['tout'] : 0,
                'cost_micro' => isset($row['cost']) ? (int) $row['cost'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Los últimos $days días agrupados por tarea, de más a menos gasto.
     *
     * @return array<int,array{task:string,calls:int,failed:int,tokens_in:int,tokens_out:int,cost_micro:int}>
     */
    public static function byTask(App $app, int $days = 30): array
    {
        $from = self::day($app, $app->clock()->now() - ($days - 1) * 86400);
        $rows = $app->db()->select(
            'SELECT task, COUNT(*) AS calls, SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout, SUM(cost_micro) AS cost '
            . 'FROM {ai_usage} WHERE day >= ? GROUP BY task ORDER BY cost DESC, task',
            [$from]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'task' => (string) $row['task'],
                'calls' => (int) $row['calls'],
                'failed' => (int) $row['failed'],
                'tokens_in' => (int) $row['tin'],
                'tokens_out' => (int) $row['tout'],
                'cost_micro' => (int) $row['cost'],
            ];
        }

        return $out;
    }

    /**
     * Gráfica de barras (SVG de servidor) del gasto por día. Los colores salen de las variables del panel.
     *
     * @param array<int,array{day:string,calls:int,failed:int,tokens_in:int,tokens_out:int,cost_micro:int}> $days
     */
    public static function chart(App $app, array $days): string
    {
        $max = 1;
        foreach ($days as $day) {
            $max = max($max, (int) $day['cost_micro']);
        }
        $width = 600;
        $base = 130;
        $slot = $width / max(1, count($days));
        $bars = '';
        foreach ($days as $i => $day) {
            $cost = (int) $day['cost_micro'];
            $height = $cost > 0 ? max(2, intdiv($cost * 120, $max)) : 1;
            $label = $app->i18n()->date(Clock::parse($day['day'] . ' 12:00:00'), 'short', 'UTC')
                . ': ' . self::amount($app, $cost) . ' · ' . tn('{n} call', '{n} calls', (int) $day['calls']);
            $bars .= '<rect x="' . round($i * $slot + 3, 2) . '" y="' . ($base - $height) . '" width="' . round($slot - 6, 2) . '" height="' . $height . '" rx="2" '
                . 'style="fill:var(' . ($cost > 0 ? '--accent' : '--line-2') . ')"><title>' . e($label) . '</title></rect>';
        }
        $first = $days !== [] ? $app->i18n()->date(Clock::parse($days[0]['day'] . ' 12:00:00'), 'short', 'UTC') : '';
        $last = $days !== [] ? $app->i18n()->date(Clock::parse($days[count($days) - 1]['day'] . ' 12:00:00'), 'short', 'UTC') : '';

        return '<svg viewBox="0 0 ' . $width . ' 160" style="width:100%;height:auto;display:block" role="img" aria-label="' . e(t('Estimated AI spending per day in the last 30 days')) . '">'
            . '<line x1="0" y1="' . ($base + 0.5) . '" x2="' . $width . '" y2="' . ($base + 0.5) . '" style="stroke:var(--muted)" stroke-width="1"/>'
            . $bars
            . '<text x="0" y="150" style="fill:var(--muted);font-size:var(--f1)">' . e($first) . '</text>'
            . '<text x="' . $width . '" y="150" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e($last) . '</text>'
            . '<text x="' . $width . '" y="10" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e(self::amount($app, $max > 1 ? $max : 0)) . '</text></svg>';
    }
}
