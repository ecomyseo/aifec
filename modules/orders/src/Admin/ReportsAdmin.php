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

use Aifec\Kernel\App;

/**
 * Las cifras de «Ventas › Informes» (/admin/reports): solo LEE pedidos y siempre acotado a un periodo.
 *
 * Cuentan los pedidos COBRADOS (paid_at puesto) que no son de pruebas, por la fecha del cobro: lo mismo que la gráfica del
 * inicio. Un pedido pendiente o cancelado sin cobrar no cuenta. Los días son los de la zona horaria de la tienda: el periodo
 * va de las 00:00:00 del primer día a las 23:59:59 del último, pasado a UTC (que es como se guardan las fechas).
 *
 * Los importes nunca se suman entre monedas: cada tabla va por moneda, y la serie por días es la de la moneda de la tienda.
 * Cada consulta está escrita entera, con sus valores como parámetros, y lleva el periodo en el WHERE (índices: paid_at de
 * pedidos y created_at de devoluciones, migración 0002 del módulo). Un periodo de más de dos años se recorta a los dos
 * últimos.
 */
final class ReportsAdmin
{
    /** Días que puede abarcar un periodo como mucho: dos años. */
    public const MAX_DAYS = 731;

    /** A partir de cuántos días la serie va por meses (una barra y una fila por mes). */
    public const DAYS_BY_DAY = 62;

    /** Los periodos que se eligen en la pantalla. */
    public const PERIODS = ['today', '7d', '30d', 'month', 'last_month', 'custom'];

    /** Las tablas que se exportan en CSV. */
    public const TABLES = ['summary', 'series', 'products', 'methods', 'countries', 'customers', 'refunds'];

    /**
     * El periodo pedido, en días de la tienda y en UTC.
     *
     * @return array{key:string,from:string,to:string,start:string,end:string,days:int,clipped:bool,invalid:bool}
     */
    public static function period(App $app, string $key, string $from = '', string $to = ''): array
    {
        $tz = new \DateTimeZone($app->site()->timezone());
        $today = (new \DateTimeImmutable('@' . $app->clock()->now()))->setTimezone($tz)->setTime(0, 0, 0);
        $key = in_array($key, self::PERIODS, true) ? $key : '30d';
        $clipped = false;
        $invalid = false;
        switch ($key) {
            case 'today':
                $a = $today;
                $b = $today;
                break;
            case '7d':
                $a = $today->modify('-6 days');
                $b = $today;
                break;
            case 'month':
                $a = $today->modify('first day of this month');
                $b = $today;
                break;
            case 'last_month':
                $a = $today->modify('first day of last month');
                $b = $today->modify('last day of last month');
                break;
            case 'custom':
                $a = self::day($from, $tz);
                $b = self::day($to, $tz);
                if ($a === null || $b === null) {
                    $invalid = true;
                    $key = '30d';
                    $a = $today->modify('-29 days');
                    $b = $today;
                    break;
                }
                if ($a > $b) {
                    $swap = $a;
                    $a = $b;
                    $b = $swap;
                }
                if (self::span($a, $b) > self::MAX_DAYS) {
                    $a = $b->modify('-' . (self::MAX_DAYS - 1) . ' days');
                    $clipped = true;
                }
                break;
            default:
                $a = $today->modify('-29 days');
                $b = $today;
        }

        return [
            'key' => $key,
            'from' => $a->format('Y-m-d'),
            'to' => $b->format('Y-m-d'),
            'start' => $app->clock()->sql($a->getTimestamp()),
            'end' => $app->clock()->sql($b->setTime(23, 59, 59)->getTimestamp()),
            'days' => self::span($a, $b),
            'clipped' => $clipped,
            'invalid' => $invalid,
        ];
    }

    /**
     * Todas las cifras de la pantalla para un periodo.
     *
     * @param array<string,mixed> $period lo que da period()
     *
     * @return array<string,mixed>
     */
    public static function data(App $app, array $period): array
    {
        $currency = strtoupper($app->settings()->str('shop.currency', 'EUR'));
        $summary = self::summary($app, $period);
        $main = ['currency' => $currency, 'n' => 0, 'gross' => 0, 'net' => 0, 'units' => 0, 'average' => 0];
        foreach ($summary as $row) {
            if ($row['currency'] === $currency) {
                $main = $row;
            }
        }

        return [
            'currency' => $currency,
            'main' => $main,
            'summary' => $summary,
            'series' => self::series($app, $period, $currency),
            'products' => self::products($app, $period),
            'methods' => self::methods($app, $period),
            'countries' => self::countries($app, $period),
            'customers' => self::customers($app, $period),
            'refunds' => self::refunds($app, $period),
            'tests' => (int) $app->db()->value(
                'SELECT COUNT(*) FROM {orders} WHERE test = 1 AND created_at >= ? AND created_at <= ?',
                [$period['start'], $period['end']]
            ),
        ];
    }

    /**
     * Ventas con y sin impuestos, pedidos, ticket medio y unidades, por moneda (la de la tienda primero).
     *
     * @param array<string,mixed> $period
     *
     * @return array<int,array{currency:string,n:int,gross:int,net:int,units:int,average:int}>
     */
    public static function summary(App $app, array $period): array
    {
        $db = $app->db();
        $rows = $db->select(
            'SELECT o.currency AS currency, COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS gross, COALESCE(SUM(o.total_net), 0) AS net FROM {orders} o '
            . 'WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? GROUP BY o.currency',
            [$period['start'], $period['end']]
        );
        $units = [];
        foreach ($db->select(
            "SELECT o.currency AS currency, COALESCE(SUM(i.qty), 0) AS units FROM {orders} o JOIN {order_items} i ON i.order_id = o.id "
            . "WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? AND i.kind = 'product' GROUP BY o.currency",
            [$period['start'], $period['end']]
        ) as $row) {
            $units[(string) $row['currency']] = (int) $row['units'];
        }
        $out = [];
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $gross = (int) $row['gross'];
            $out[] = [
                'currency' => (string) $row['currency'],
                'n' => $n,
                'gross' => $gross,
                'net' => (int) $row['net'],
                'units' => isset($units[(string) $row['currency']]) ? $units[(string) $row['currency']] : 0,
                'average' => $n > 0 ? (int) round($gross / $n) : 0,
            ];
        }

        return self::mainFirst($app, $out);
    }

    /**
     * La serie de ventas de la moneda de la tienda: un punto por día (o por mes, si el periodo es largo), también los vacíos.
     *
     * @param array<string,mixed> $period
     *
     * @return array{by:string,points:array<int,array{key:string,n:int,amount:int}>}
     */
    public static function series(App $app, array $period, string $currency): array
    {
        $tz = new \DateTimeZone($app->site()->timezone());
        $byMonth = (int) $period['days'] > self::DAYS_BY_DAY;
        $format = $byMonth ? 'Y-m' : 'Y-m-d';
        $points = [];
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $period['from'], $tz);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $period['to'], $tz);
        while ($day !== false && $last !== false && $day <= $last) {
            $points[$day->format($format)] = ['key' => $day->format($format), 'n' => 0, 'amount' => 0];
            $day = $day->modify('+1 day');
        }
        // Agrupado por hora UTC y repartido después por días de la tienda (como el inicio): una consulta, filas acotadas.
        $rows = $app->db()->select(
            'SELECT SUBSTR(o.paid_at, 1, 13) AS h, COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount FROM {orders} o '
            . 'WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? AND o.currency = ? GROUP BY SUBSTR(o.paid_at, 1, 13)',
            [$period['start'], $period['end'], $currency]
        );
        foreach ($rows as $row) {
            $time = \Aifec\Kernel\Clock::parse((string) $row['h'] . ':00:00');
            if ($time <= 0) {
                continue;
            }
            $key = (new \DateTimeImmutable('@' . $time))->setTimezone($tz)->format($format);
            if (isset($points[$key])) {
                $points[$key]['n'] += (int) $row['n'];
                $points[$key]['amount'] += (int) $row['amount'];
            }
        }

        return ['by' => $byMonth ? 'month' : 'day', 'points' => array_values($points)];
    }

    /**
     * Los 10 productos más vendidos: unidades e importe (por moneda).
     *
     * @param array<string,mixed> $period
     *
     * @return array<int,array{product_id:int,name:string,currency:string,units:int,amount:int}>
     */
    public static function products(App $app, array $period): array
    {
        $rows = $app->db()->select(
            "SELECT i.product_id AS product_id, MAX(i.name) AS name, o.currency AS currency, COALESCE(SUM(i.qty), 0) AS units, COALESCE(SUM(i.line_total), 0) AS amount "
            . "FROM {orders} o JOIN {order_items} i ON i.order_id = o.id "
            . "WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? AND i.kind = 'product' "
            . 'GROUP BY i.product_id, o.currency ORDER BY units DESC, amount DESC, product_id ASC LIMIT 10',
            [$period['start'], $period['end']]
        );

        return array_map(static function (array $row): array {
            return ['product_id' => (int) $row['product_id'], 'name' => (string) $row['name'], 'currency' => (string) $row['currency'], 'units' => (int) $row['units'], 'amount' => (int) $row['amount']];
        }, $rows);
    }

    /**
     * Ventas por forma de pago (por moneda), de más a menos.
     *
     * @param array<string,mixed> $period
     *
     * @return array<int,array{method:string,title:string,currency:string,n:int,amount:int}>
     */
    public static function methods(App $app, array $period): array
    {
        $rows = $app->db()->select(
            "SELECT COALESCE(o.payment_method, '') AS method, MAX(o.payment_title) AS title, o.currency AS currency, COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount FROM {orders} o "
            . 'WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? GROUP BY o.payment_method, o.currency ORDER BY amount DESC, method ASC',
            [$period['start'], $period['end']]
        );

        return array_map(static function (array $row): array {
            $title = trim((string) $row['title']);

            return ['method' => (string) $row['method'], 'title' => $title !== '' ? $title : (string) $row['method'], 'currency' => (string) $row['currency'], 'n' => (int) $row['n'], 'amount' => (int) $row['amount']];
        }, $rows);
    }

    /**
     * Ventas por país de facturación (por moneda), de más a menos. El país se lee del JSON de la dirección con funciones de
     * texto que valen igual en SQLite y en MySQL.
     *
     * @param array<string,mixed> $period
     *
     * @return array<int,array{country:string,currency:string,n:int,amount:int}>
     */
    public static function countries(App $app, array $period): array
    {
        $country = "CASE WHEN INSTR(COALESCE(o.billing, ''), '\"country\":\"') > 0 THEN SUBSTR(o.billing, INSTR(o.billing, '\"country\":\"') + 11, 2) ELSE '' END";
        $rows = $app->db()->select(
            'SELECT ' . $country . ' AS country, o.currency AS currency, COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount FROM {orders} o '
            . 'WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? GROUP BY ' . $country . ', o.currency ORDER BY amount DESC, country ASC',
            [$period['start'], $period['end']]
        );

        return array_map(static function (array $row): array {
            $code = strtoupper((string) $row['country']);

            return ['country' => preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '', 'currency' => (string) $row['currency'], 'n' => (int) $row['n'], 'amount' => (int) $row['amount']];
        }, $rows);
    }

    /**
     * Clientes (por correo) que compraron en el periodo: nuevos (su primer cobro es de este periodo) y que repiten (ya
     * habían pagado un pedido antes). La comprobación de «antes» va por el índice del correo, un cliente cada vez.
     *
     * @param array<string,mixed> $period
     *
     * @return array{buyers:int,new:int,returning:int}
     */
    public static function customers(App $app, array $period): array
    {
        $db = $app->db();
        $buyers = (int) $db->value(
            'SELECT COUNT(DISTINCT o.email) FROM {orders} o WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ?',
            [$period['start'], $period['end']]
        );
        $returning = (int) $db->value(
            'SELECT COUNT(DISTINCT o.email) FROM {orders} o WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ? '
            . 'AND EXISTS (SELECT 1 FROM {orders} p WHERE p.email = o.email AND p.test = 0 AND p.paid_at IS NOT NULL AND p.paid_at < ?)',
            [$period['start'], $period['end'], $period['start']]
        );

        return ['buyers' => $buyers, 'new' => $buyers - $returning, 'returning' => $returning];
    }

    /**
     * Dinero devuelto en el periodo (por la fecha de la devolución), por moneda.
     *
     * @param array<string,mixed> $period
     *
     * @return array<int,array{currency:string,n:int,amount:int}>
     */
    public static function refunds(App $app, array $period): array
    {
        $rows = $app->db()->select(
            'SELECT o.currency AS currency, COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS amount FROM {order_refunds} r JOIN {orders} o ON o.id = r.order_id '
            . 'WHERE o.test = 0 AND r.created_at >= ? AND r.created_at <= ? GROUP BY o.currency',
            [$period['start'], $period['end']]
        );

        return self::mainFirst($app, array_map(static function (array $row): array {
            return ['currency' => (string) $row['currency'], 'n' => (int) $row['n'], 'amount' => (int) $row['amount']];
        }, $rows));
    }

    /**
     * Una tabla para exportar: cabeceras (ya traducidas) y filas. null si la tabla no existe.
     *
     * @param array<string,mixed> $period
     *
     * @return array{0:string[],1:array<int,array<int,string>>}|null
     */
    public static function csv(App $app, string $table, array $period): ?array
    {
        $amount = static function (int $cents): string {
            return number_format($cents / 100, 2, ',', '');
        };
        switch ($table) {
            case 'summary':
                $rows = [];
                foreach (self::summary($app, $period) as $row) {
                    $rows[] = [$row['currency'], $amount($row['gross']), $amount($row['net']), (string) $row['n'], $amount($row['average']), (string) $row['units']];
                }

                return [[t('Currency'), t('Sales with tax'), t('Sales without tax'), t('Orders'), t('Average order'), t('Units')], $rows];
            case 'series':
                $currency = strtoupper($app->settings()->str('shop.currency', 'EUR'));
                $series = self::series($app, $period, $currency);
                $rows = [];
                foreach ($series['points'] as $point) {
                    $rows[] = [$point['key'], (string) $point['n'], $amount($point['amount']), $currency];
                }

                return [[$series['by'] === 'month' ? t('Month') : t('Day'), t('Orders'), t('Sales with tax'), t('Currency')], $rows];
            case 'products':
                $rows = [];
                foreach (self::products($app, $period) as $row) {
                    $rows[] = [(string) $row['product_id'], $row['name'], (string) $row['units'], $amount($row['amount']), $row['currency']];
                }

                return [[t('Product ID'), t('Product'), t('Units'), t('Amount'), t('Currency')], $rows];
            case 'methods':
                $rows = [];
                foreach (self::methods($app, $period) as $row) {
                    $rows[] = [$row['method'], $row['title'], (string) $row['n'], $amount($row['amount']), $row['currency']];
                }

                return [[t('Code'), t('Payment method'), t('Orders'), t('Amount'), t('Currency')], $rows];
            case 'countries':
                $rows = [];
                foreach (self::countries($app, $period) as $row) {
                    $rows[] = [$row['country'], (string) $row['n'], $amount($row['amount']), $row['currency']];
                }

                return [[t('Country'), t('Orders'), t('Amount'), t('Currency')], $rows];
            case 'customers':
                $c = self::customers($app, $period);

                return [[t('Customers who bought'), t('New customers'), t('Returning customers')], [[(string) $c['buyers'], (string) $c['new'], (string) $c['returning']]]];
            case 'refunds':
                $rows = [];
                foreach (self::refunds($app, $period) as $row) {
                    $rows[] = [$row['currency'], (string) $row['n'], $amount($row['amount'])];
                }

                return [[t('Currency'), t('Refunds in the period'), t('Amount')], $rows];
        }

        return null;
    }

    // ------------------------------------------------------------------ piezas

    /** Un día «AAAA-MM-DD» de verdad en la zona de la tienda, o null. */
    private static function day(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /** Días entre dos fechas, las dos incluidas. */
    private static function span(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->days + 1;
    }

    /**
     * La moneda de la tienda primero; el resto, por su código.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private static function mainFirst(App $app, array $rows): array
    {
        $main = strtoupper($app->settings()->str('shop.currency', 'EUR'));
        usort($rows, static function (array $a, array $b) use ($main): int {
            $ma = $a['currency'] === $main ? 0 : 1;
            $mb = $b['currency'] === $main ? 0 : 1;

            return $ma === $mb ? strcmp((string) $a['currency'], (string) $b['currency']) : $ma - $mb;
        });

        return $rows;
    }
}
