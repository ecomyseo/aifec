<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;

/**
 * Lo que enseña la pantalla «Bots and assistants»: los datos ya sumados de bot_visits y, si hay pedidos, los que llegaron
 * desde un asistente (orders.attribution). Todo mira solo los últimos 30 días.
 */
final class BotsReport
{
    /** Días de la gráfica y de los totales largos. */
    public const DAYS = 30;

    /**
     * El nombre legible de cada clase de página.
     *
     * @return array<string,string>
     */
    public static function classLabels(): array
    {
        return [
            'product' => t('Product pages'),
            'category' => t('Category pages'),
            'text' => t('Text versions (.md)'),
            'llms' => t('llms.txt'),
            'feed' => t('Product feeds'),
            'api' => t('API and MCP'),
            'other' => t('Other pages'),
        ];
    }

    /**
     * El nombre legible de cada familia de robots.
     *
     * @return array<string,string>
     */
    public static function familyLabels(): array
    {
        return [
            'search' => t('Search engines'),
            'assistant' => t('AI assistants'),
            'training' => t('AI training'),
            'seo' => t('SEO tools'),
            'other' => t('Other robots'),
        ];
    }

    /**
     * Todo lo que necesita la pantalla.
     *
     * @return array{
     *     totals:array{bot7:int,bot30:int,ref7:int,ref30:int},
     *     days:array<int,array{day:string,bot:int,ref:int}>,
     *     bots:array<int,array{name:string,family:string,visits:int,last:string,reads:array<string,int>}>,
     *     sources:array<int,array{name:string,visits:int,orders:int,amount:int}>,
     *     pages:array<int,array{class:string,bots:int,refs:int}>,
     *     blocked:bool,
     *     currency:string
     * }
     */
    public static function build(App $app): array
    {
        $db = $app->db();
        $now = $app->clock()->now();
        $since30 = gmdate('Y-m-d', $now - (self::DAYS - 1) * 86400);
        $since7 = gmdate('Y-m-d', $now - 6 * 86400);

        // Visitas por día y tipo (rellenando con ceros los días sin nada).
        $days = [];
        for ($i = self::DAYS - 1; $i >= 0; --$i) {
            $days[gmdate('Y-m-d', $now - $i * 86400)] = ['day' => gmdate('Y-m-d', $now - $i * 86400), 'bot' => 0, 'ref' => 0];
        }
        $totals = ['bot7' => 0, 'bot30' => 0, 'ref7' => 0, 'ref30' => 0];
        foreach ($db->select('SELECT day, kind, SUM(visits) AS v FROM {bot_visits} WHERE day >= ? GROUP BY day, kind', [$since30]) as $row) {
            $day = (string) $row['day'];
            $kind = (string) $row['kind'] === 'ref' ? 'ref' : 'bot';
            $visits = (int) $row['v'];
            if (isset($days[$day])) {
                $days[$day][$kind] += $visits;
            }
            $totals[$kind . '30'] += $visits;
            if ($day >= $since7) {
                $totals[$kind . '7'] += $visits;
            }
        }

        // Robots: visitas en 30 días, última vez y qué leen.
        $bots = [];
        $botRows = $db->select(
            "SELECT name, family, page_class, SUM(visits) AS v, MAX(last_at) AS l FROM {bot_visits} WHERE kind = 'bot' AND day >= ? GROUP BY name, family, page_class",
            [$since30]
        );
        $pages = [];
        foreach ($botRows as $row) {
            $name = (string) $row['name'];
            if (!isset($bots[$name])) {
                $bots[$name] = ['name' => $name, 'family' => (string) $row['family'], 'visits' => 0, 'last' => '', 'reads' => []];
            }
            $bots[$name]['visits'] += (int) $row['v'];
            $bots[$name]['reads'][(string) $row['page_class']] = (int) $row['v'];
            if (strcmp((string) $row['l'], $bots[$name]['last']) > 0) {
                $bots[$name]['last'] = (string) $row['l'];
            }
            if ((string) $row['family'] === 'assistant') {
                $class = (string) $row['page_class'];
                $pages[$class] = isset($pages[$class]) ? $pages[$class] : ['class' => $class, 'bots' => 0, 'refs' => 0];
                $pages[$class]['bots'] += (int) $row['v'];
            }
        }
        $bots = array_values($bots);
        usort($bots, static function (array $a, array $b): int {
            return $a['visits'] === $b['visits'] ? strcmp($a['name'], $b['name']) : $b['visits'] <=> $a['visits'];
        });
        foreach ($bots as $index => $bot) {
            arsort($bot['reads']);
            $bots[$index]['reads'] = $bot['reads'];
            // Las visitas que recibieron la versión en texto, da igual cómo (la dirección «.md», «Accept: text/markdown» o el servicio
            // directo a su familia): todas se apuntan con la clase «text».
            $text = isset($bot['reads']['text']) ? (int) $bot['reads']['text'] : 0;
            $bots[$index]['md'] = $text;
            $bots[$index]['md_pct'] = $bot['visits'] > 0 ? round(100 * $text / $bot['visits'], 1) : 0.0;
            $bots[$index]['reads_md'] = $text > 0;
        }

        // Orígenes de IA: visitas llegadas desde cada asistente.
        $sources = [];
        foreach ($db->select("SELECT name, page_class, SUM(visits) AS v FROM {bot_visits} WHERE kind = 'ref' AND day >= ? GROUP BY name, page_class", [$since30]) as $row) {
            $name = (string) $row['name'];
            $sources[$name] = isset($sources[$name]) ? $sources[$name] : ['name' => $name, 'visits' => 0, 'orders' => 0, 'amount' => 0];
            $sources[$name]['visits'] += (int) $row['v'];
            $class = (string) $row['page_class'];
            $pages[$class] = isset($pages[$class]) ? $pages[$class] : ['class' => $class, 'bots' => 0, 'refs' => 0];
            $pages[$class]['refs'] += (int) $row['v'];
        }
        foreach (self::orders($app, $now) as $name => $sum) {
            $sources[$name] = isset($sources[$name]) ? $sources[$name] : ['name' => $name, 'visits' => 0, 'orders' => 0, 'amount' => 0];
            $sources[$name]['orders'] += $sum['orders'];
            $sources[$name]['amount'] += $sum['amount'];
        }
        $sources = array_values($sources);
        usort($sources, static function (array $a, array $b): int {
            return $a['visits'] === $b['visits'] ? strcmp($a['name'], $b['name']) : $b['visits'] <=> $a['visits'];
        });
        $pages = array_values($pages);
        usort($pages, static function (array $a, array $b): int {
            $x = $a['bots'] + $a['refs'];
            $y = $b['bots'] + $b['refs'];

            return $x === $y ? strcmp($a['class'], $b['class']) : $y <=> $x;
        });

        return [
            'totals' => $totals,
            'days' => array_values($days),
            'bots' => $bots,
            'sources' => $sources,
            'pages' => $pages,
            'blocked' => Audit::assistantsBlocked($app),
            'currency' => $app->has('semantic') ? $app->service('semantic')->currency() : 'EUR',
        ];
    }

    /**
     * Pedidos de los últimos 30 días que llegaron desde un asistente: asistente => número e importe cobrado (céntimos, sin lo
     * devuelto). Solo cuentan los pagados: ni los de prueba, ni los cancelados, ni los que esperan el pago. Sin el módulo de
     * pedidos, nada.
     *
     * @return array<string,array{orders:int,amount:int}>
     */
    private static function orders(App $app, int $now): array
    {
        $db = $app->db();
        if (!$db->tableExists('orders')) {
            return [];
        }
        $out = [];
        $rows = $db->select(
            "SELECT attribution, total, refunded_total FROM {orders} WHERE placed_at >= ? AND test = 0 AND cancelled_at IS NULL AND payment_status IN ('paid', 'partially_refunded') AND attribution IS NOT NULL AND attribution LIKE ?",
            [$app->clock()->sql($now - self::DAYS * 86400), '%source%']
        );
        foreach ($rows as $row) {
            $attribution = json_decode((string) $row['attribution'], true);
            $name = is_array($attribution) && isset($attribution['source']) && is_string($attribution['source']) ? trim($attribution['source']) : '';
            if ($name === '') {
                continue;
            }
            $out[$name] = isset($out[$name]) ? $out[$name] : ['orders' => 0, 'amount' => 0];
            ++$out[$name]['orders'];
            $out[$name]['amount'] += max(0, (int) $row['total'] - (int) $row['refunded_total']);
        }

        return $out;
    }
}
