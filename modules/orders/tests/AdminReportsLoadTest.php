<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Admin\ReportsAdmin;

/*
 * «Informes» con 50.000 pedidos repartidos en dos años: la pantalla de los últimos 30 días responde en menos de 300 ms.
 * Con AIFEC_REPORTS_MEASURE en el entorno se escriben las cifras medidas (milisegundos, consultas y bytes).
 */

if (!function_exists('reportsLoadSeed')) {
    /**
     * 50.000 pedidos cobrados (más uno de cada diez sin cobrar), uno cada ~21 minutos hacia atrás desde ahora, en tres países
     * y dos formas de pago, con una línea cada uno.
     */
    function reportsLoadSeed(App $app, int $count): void
    {
        $db = $app->db();
        $pdo = $db->pdo();
        $now = $app->clock()->now();
        $step = (int) floor(2 * 365 * 86400 / $count);
        $orders = $pdo->prepare('INSERT INTO ' . $db->table('orders') . ' (number, email, currency, status, payment_status, payment_method, payment_title, total, total_net, total_tax, paid_total, billing, test, placed_at, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)');
        $items = $pdo->prepare('INSERT INTO ' . $db->table('order_items') . " (order_id, kind, product_id, name, qty, unit_price, line_total, line_net, position) VALUES (?, 'product', ?, ?, ?, ?, ?, ?, 0)");
        $countries = ['ES', 'PT', 'FR'];
        $pdo->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $when = $app->clock()->sql($now - $i * $step - 60);
            $paid = $i % 10 !== 9;
            $total = 1000 + ($i % 50) * 100;
            $orders->execute([
                'CARGA-' . $i, 'cliente' . ($i % 7000) . '@cliente.test', 'EUR', $paid ? 'processing' : 'pending_payment', $paid ? 'paid' : 'pending',
                $i % 2 === 0 ? 'transfer' : 'redsys', $i % 2 === 0 ? 'Transferencia bancaria' : 'Pago con tarjeta',
                $total, $total - 100, 100, $paid ? $total : 0, '{"country":"' . $countries[$i % 3] . '"}', $when, $paid ? $when : null, $when, $when,
            ]);
            $id = (int) $pdo->lastInsertId();
            $items->execute([$id, 1 + ($i % 40), 'Producto ' . (1 + ($i % 40)), 1 + ($i % 3), $total, $total, $total - 100]);
        }
        $pdo->commit();
        $pdo->exec('ANALYZE');
    }
}

test('con 50.000 pedidos, «Informes» de los últimos 30 días responde en menos de 300 ms', function (): void {
    $app = testApp(['modules' => ['orders']]);
    $app->boot();
    reportsLoadSeed($app, 50000);
    same(50000, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    $web = adminClient($app);
    $web->get('/admin/reports');

    $measure = static function (string $uri) use ($app, $web): array {
        $best = PHP_INT_MAX;
        $queries = 0;
        $bytes = 0;
        for ($run = 0; $run < 3; $run++) {
            $before = $app->db()->queries;
            $t = microtime(true);
            $r = $web->get($uri);
            $ms = (int) round((microtime(true) - $t) * 1000);
            same(200, $r->status, $uri);
            $best = min($best, $ms);
            $queries = $app->db()->queries - $before;
            $bytes = strlen($r->body);
        }

        return [$best, $queries, $bytes];
    };
    list($ms, $queries, $bytes) = $measure('/admin/reports');
    list($msLong, $queriesLong, $bytesLong) = $measure('/admin/reports?period=custom&from=2024-11-13&to=2026-11-12');
    $p = ReportsAdmin::period($app, '30d');
    $inPeriod = (int) $app->db()->value('SELECT COUNT(*) FROM {orders} WHERE test = 0 AND paid_at IS NOT NULL AND paid_at >= ? AND paid_at <= ?', [$p['start'], $p['end']]);
    if (getenv('AIFEC_REPORTS_MEASURE') !== false) {
        fwrite(STDERR, 'INFORMES 50000 pedidos · 30 días (' . $inPeriod . ' cobrados): ' . $ms . ' ms, ' . $queries . ' consultas, ' . $bytes . " bytes\n"
            . 'INFORMES 50000 pedidos · dos años: ' . $msLong . ' ms, ' . $queriesLong . ' consultas, ' . $bytesLong . " bytes\n");
    }
    check($inPeriod > 1500, 'el periodo trae pedidos de verdad (' . $inPeriod . ')');
    check($ms < 300, 'los últimos 30 días en ' . $ms . ' ms (tope 300)');
    check($queries <= 25, $queries . ' consultas en toda la petición');
    check($msLong < 3000, 'dos años enteros en ' . $msLong . ' ms');
    check($bytes < 300 * 1024 && $bytesLong < 300 * 1024, 'dentro del presupuesto de peso');
});
