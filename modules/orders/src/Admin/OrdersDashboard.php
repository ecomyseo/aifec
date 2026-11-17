<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Lo que los pedidos ponen en el inicio del panel: ventas de hoy, de 7 y de 30 días con su comparación, lo que hay que
 * preparar y la gráfica de 30 días; además los avisos de estado y el buscador de órdenes (Ctrl + K).
 *
 * Las cifras son consultas agregadas con fecha acotada (60 días, agrupadas por hora para repartirlas después por días
 * de la zona horaria del sitio) y se guardan 5 minutos con la etiqueta «orders»: un cobro o una devolución la invalidan.
 * Solo cuentan los pedidos cobrados que no son de pruebas, por el importe cobrado.
 */
final class OrdersDashboard
{
    /** Segundos que viven las cifras en la caché. */
    private const TTL = 300;

    /**
     * Engancha los avisos, el buscador y la invalidación de la caché. Lo llama routes.php del módulo (corre en cada petición,
     * también en la tienda: un cobro tiene que vaciar las cifras aunque el panel no esté abierto).
     */
    public static function register(App $app): void
    {
        $hooks = $app->hooks();
        $hooks->filter('admin.status', [self::class, 'status']);
        $hooks->filter('admin.palette', [self::class, 'palette']);
        $invalidate = static function () use ($app): void {
            $app->invalidate(['orders']);
        };
        $hooks->on('order.paid', $invalidate);
        $hooks->on('order.refunded', $invalidate);
    }

    // ------------------------------------------------------------------ piezas del inicio

    public static function today(App $app): string
    {
        return self::tile($app, 'today', t('Sales today'), t('compared with {amount} yesterday'), t('Nothing was sold yesterday'));
    }

    public static function week(App $app): string
    {
        return self::tile($app, 'week', t('Sales in the last 7 days'), t('compared with {amount} in the 7 days before'), t('Nothing was sold in the 7 days before'));
    }

    public static function month(App $app): string
    {
        return self::tile($app, 'month', t('Sales in the last 30 days'), t('compared with {amount} in the 30 days before'), t('Nothing was sold in the 30 days before'));
    }

    /**
     * Una tarjeta de ventas: importe cobrado, número de pedidos y cómo va frente al periodo anterior.
     *
     * @param string $compare texto ya traducido con el marcador {amount} (lo cobrado en el periodo anterior)
     * @param string $none    texto ya traducido para cuando el periodo anterior no vendió nada
     */
    private static function tile(App $app, string $key, string $label, string $compare, string $none): string
    {
        $summary = self::summary($app);
        $s = $summary[$key];
        $note = e(tn('{n} order', '{n} orders', (int) $s['n']));
        $prevAmount = (int) $s['prev_amount'];
        $amount = (int) $s['amount'];
        if ($prevAmount > 0) {
            $percent = (int) round(($amount - $prevAmount) * 100 / $prevAmount);
            $kind = $percent > 0 ? 'ok' : ($percent < 0 ? 'bad' : '');
            $note .= ' · ' . Admin::badge(($percent > 0 ? '+' : '') . $percent . ' %', $kind) . ' ' . e(strtr($compare, ['{amount}' => money($prevAmount)]));
        } elseif ($amount > 0) {
            $note .= ' · ' . e($none);
        }
        if ($amount === 0 && (int) $s['n'] === 0 && !empty($s['test_n'])) {
            // Un cero con pedidos de prueba detrás parece un fallo: se dice que esos pedidos no cuentan.
            $note .= ' · ' . e(tn('{n} test order, which does not count', '{n} test orders, which do not count', (int) $s['test_n']));
        }

        return '<div class="af-card"><div class="af-stat"><div class="af-stat-label">' . e($label) . '</div>'
            . '<div class="af-stat-value">' . e(money($amount)) . '</div><div class="af-stat-note">' . $note . '</div></div></div>';
    }

    /**
     * Los 8 pedidos más antiguos pagados y sin enviar.
     */
    public static function prepare(App $app): string
    {
        $rows = $app->db()->select(
            'SELECT o.id, o.number, o.email, o.currency, o.total, o.paid_at, o.billing, c.first_name AS cu_first, c.last_name AS cu_last '
            . 'FROM {orders} o LEFT JOIN {customers} c ON c.id = o.customer_id WHERE ' . OrdersAdmin::TO_PREPARE . ' ORDER BY o.paid_at ASC, o.id ASC LIMIT 8'
        );
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('To prepare')) . '</h2></div>';
        if ($rows === []) {
            return $html . '<div class="af-empty"><p>' . e(t('Nothing to prepare: every paid order has been shipped.')) . '</p></div></div>';
        }
        $tz = $app->site()->timezone();
        $html .= '<div class="af-table-wrap"><table class="af-table"><tbody>';
        foreach ($rows as $row) {
            $name = OrdersAdmin::customerName($row);
            $paid = Clock::parse((string) $row['paid_at']);
            $html .= '<tr><td><a href="' . e($app->url()->plain('/admin/orders/' . (int) $row['id'])) . '"><strong>' . e((string) $row['number']) . '</strong></a>'
                . '<br><span class="af-small af-muted">' . e($name !== '' ? $name : (string) $row['email']) . '</span></td>'
                . '<td class="af-num af-nowrap">' . e(money((int) $row['total'], (string) $row['currency'])) . '</td>'
                . '<td class="af-small af-muted af-nowrap af-hide-sm">' . e($paid > 0 ? $app->i18n()->date($paid, 'short', $tz) : '') . '</td></tr>';
        }

        return $html . '</tbody></table></div><div class="af-card-body"><a class="af-btn af-btn-sm" href="'
            . e($app->url()->plain('/admin/orders', ['f' => ['prepare' => '1']])) . '">' . e(t('See all')) . '</a></div></div>';
    }

    /**
     * Gráfica de barras de 30 días pintada en el servidor: un SVG sin JavaScript con un título por barra.
     */
    public static function chart(App $app): string
    {
        $points = [];
        foreach (self::summary($app)['chart'] as $day) {
            $points[] = [
                'label' => $app->i18n()->date(Clock::parse((string) $day['day'] . ' 12:00:00'), 'short', 'UTC'),
                'n' => (int) $day['n'],
                'amount' => (int) $day['amount'],
            ];
        }
        // De aquí a los informes, donde están el resto de periodos y las tablas.
        $more = $app->router()->has('admin.reports') && $app->auth()->can('orders.view')
            ? '<a class="af-btn af-btn-sm af-btn-ghost" href="' . e($app->url()->plain('/admin/reports')) . '">' . e(t('See the reports')) . '</a>'
            : '';

        return '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Sales per day')) . '</h2>' . $more . '</div><div class="af-card-body">'
            . self::bars($points, t('Sales per day in the last 30 days')) . '</div></div>';
    }

    /**
     * Gráfica de barras pintada en el servidor (SVG sin JavaScript, un título por barra). La usan el inicio y los informes.
     *
     * @param array<int,array{label:string,n:int,amount:int}> $points una barra por punto, en orden
     */
    public static function bars(array $points, string $aria, ?string $currency = null): string
    {
        $max = 1;
        foreach ($points as $point) {
            $max = max($max, (int) $point['amount']);
        }
        $width = 600;
        $base = 130;
        $slot = $width / max(1, count($points));
        $bars = '';
        foreach ($points as $i => $point) {
            $amount = (int) $point['amount'];
            $height = $amount > 0 ? max(2, (int) round($amount / $max * 120)) : 1;
            $label = $point['label'] . ': ' . money($amount, $currency) . ' · ' . tn('{n} order', '{n} orders', (int) $point['n']);
            $bars .= '<rect x="' . round($i * $slot + 3, 2) . '" y="' . ($base - $height) . '" width="' . round(max(1, $slot - 6), 2) . '" height="' . $height . '" rx="2" '
                . 'style="fill:var(' . ($amount > 0 ? '--accent' : '--line-2') . ')"><title>' . e($label) . '</title></rect>';
        }
        $first = $points !== [] ? (string) $points[0]['label'] : '';
        $last = $points !== [] ? (string) $points[count($points) - 1]['label'] : '';

        return '<svg viewBox="0 0 ' . $width . ' 160" style="width:100%;height:auto;display:block" role="img" aria-label="' . e($aria) . '">'
            . '<line x1="0" y1="' . ($base + 0.5) . '" x2="' . $width . '" y2="' . ($base + 0.5) . '" style="stroke:var(--muted)" stroke-width="1"/>'
            . $bars
            . '<text x="0" y="150" style="fill:var(--muted);font-size:var(--f1)">' . e($first) . '</text>'
            . '<text x="' . $width . '" y="150" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e($last) . '</text>'
            . '<text x="' . $width . '" y="10" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e(money($max, $currency)) . '</text></svg>';
    }

    // ------------------------------------------------------------------ cifras

    /**
     * Las cifras de ventas, de la caché si siguen vigentes.
     *
     * @return array<string,mixed>
     */
    public static function summary(App $app): array
    {
        $value = $app->cache()->remember('orders.admin.sales', self::TTL, static function () use ($app): array {
            return self::compute($app);
        }, ['orders']);

        return is_array($value) ? $value : self::compute($app);
    }

    /**
     * today, week y month con n, amount, prev_n y prev_amount; chart con los últimos 30 días (day, n, amount).
     *
     * @return array<string,mixed>
     */
    private static function compute(App $app): array
    {
        $now = $app->clock()->now();
        $tz = new \DateTimeZone($app->site()->timezone());
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);
        $days = [];
        for ($i = 59; $i >= 0; --$i) {
            $days[] = $today->modify('-' . $i . ' days')->format('Y-m-d');
        }
        $perDay = [];
        foreach ($days as $day) {
            $perDay[$day] = ['n' => 0, 'amount' => 0];
        }
        $start = $today->modify('-59 days')->getTimestamp();
        $rows = $app->db()->select(
            'SELECT SUBSTR(o.paid_at, 1, 13) AS h, COUNT(*) AS n, SUM(o.paid_total) AS amount FROM {orders} o '
            . 'WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? GROUP BY SUBSTR(o.paid_at, 1, 13)',
            [$app->clock()->sql($start), $app->clock()->sql($now)]
        );
        foreach ($rows as $row) {
            $time = Clock::parse((string) $row['h'] . ':00:00');
            if ($time <= 0) {
                continue;
            }
            $key = (new \DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y-m-d');
            if (isset($perDay[$key])) {
                $perDay[$key]['n'] += (int) $row['n'];
                $perDay[$key]['amount'] += (int) $row['amount'];
            }
        }
        $sum = static function (array $keys) use ($perDay): array {
            $n = 0;
            $amount = 0;
            foreach ($keys as $key) {
                $n += $perDay[$key]['n'];
                $amount += $perDay[$key]['amount'];
            }

            return [$n, $amount];
        };
        $out = [];
        foreach (['today' => [1, 1], 'week' => [7, 7], 'month' => [30, 30]] as $name => $span) {
            list($n, $amount) = $sum(array_slice($days, 60 - $span[0], $span[0]));
            list($prevN, $prevAmount) = $sum(array_slice($days, 60 - 2 * $span[0], $span[0]));
            $from = $today->modify('-' . ($span[0] - 1) . ' days')->getTimestamp();
            $tests = (int) $app->db()->value(
                'SELECT COUNT(*) FROM {orders} WHERE test = 1 AND created_at >= ? AND created_at <= ?',
                [$app->clock()->sql($from), $app->clock()->sql($now)]
            );
            $out[$name] = ['n' => $n, 'amount' => $amount, 'prev_n' => $prevN, 'prev_amount' => $prevAmount, 'test_n' => $tests];
        }
        $chart = [];
        foreach (array_slice($days, 30, 30) as $day) {
            $chart[] = ['day' => $day, 'n' => $perDay[$day]['n'], 'amount' => $perDay[$day]['amount']];
        }
        $out['chart'] = $chart;

        return $out;
    }

    // ------------------------------------------------------------------ avisos y buscador

    /**
     * Filtro «admin.status»: pedidos en espera desde hace más de 3 días y pagados sin enviar desde hace más de 2.
     *
     * @param array<int,array<string,string>> $status
     *
     * @return array<int,array<string,string>>
     */
    public static function status(array $status, App $app): array
    {
        if (!$app->auth()->can('orders.view')) {
            return $status;
        }
        $now = $app->clock()->now();
        $held = (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE status = 'on_hold' AND updated_at < ?", [$app->clock()->sql($now - 3 * 86400)]);
        if ($held > 0) {
            $status[] = [
                'kind' => 'warn',
                'text' => tn('{n} order has been on hold for more than 3 days.', '{n} orders have been on hold for more than 3 days.', $held),
                'url' => $app->url()->plain('/admin/orders', ['f' => ['status' => 'on_hold']]),
                'label' => t('Review'),
            ];
        }
        $waiting = (int) $app->db()->value('SELECT COUNT(*) FROM {orders} o WHERE ' . OrdersAdmin::TO_PREPARE . ' AND o.paid_at < ?', [$app->clock()->sql($now - 2 * 86400)]);
        if ($waiting > 0) {
            $status[] = [
                'kind' => 'warn',
                'text' => tn('{n} paid order has been waiting to be shipped for more than 2 days.', '{n} paid orders have been waiting to be shipped for more than 2 days.', $waiting),
                'url' => $app->url()->plain('/admin/orders', ['f' => ['prepare' => '1']]),
                'label' => t('Review'),
            ];
        }

        return $status;
    }

    /**
     * Filtro «admin.palette»: pedidos por número o correo, para quien puede verlos.
     *
     * @param array<int,array<string,string>> $items
     *
     * @return array<int,array<string,string>>
     */
    public static function palette(array $items, string $q, App $app): array
    {
        $q = trim($q);
        if ($q === '' || !$app->auth()->can('orders.view')) {
            return $items;
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $sql = "SELECT id, number, email, total, currency FROM {orders} WHERE number LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT 8";
        foreach ($app->db()->select($sql, [$like, $like]) as $row) {
            $items[] = [
                'label' => (string) $row['number'] . ' · ' . (string) $row['email'] . ' · ' . money((int) $row['total'], (string) $row['currency']),
                'group' => t('Orders'),
                'url' => $app->url()->plain('/admin/orders/' . (int) $row['id']),
            ];
        }

        return $items;
    }
}
