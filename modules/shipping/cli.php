<?php
/**
 * AIFEC — órdenes de consola del envío.
 *
 *   php aifec shipping:rate <transportista> "<zona>" <precio en céntimos> [--min=0] [--max=40000] [--basis=weight]
 *       da al transportista (su código) una tarifa para una zona (su nombre): si ya hay una con el mismo tramo, la cambia.
 *
 * Para qué (14/11/2026): la tienda de muestra no enviaba a Baleares y un cliente de Palma no podía comprar; con el panel se hace
 * igual (Envío → Zonas y tarifas), esto es para dejarlo hecho desde el despliegue.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;

return [
    'shipping:rate' => ['help' => 'Gives a carrier a rate for a zone: shipping:rate <carrier code> "<zone name>" <price in cents> [--min=0] [--max=40000] [--basis=weight|price]', 'run' => static function (App $app, array $args, Console $io): int {
        $code = isset($args[0]) && is_string($args[0]) ? trim($args[0]) : '';
        $zoneName = isset($args[1]) && is_string($args[1]) ? trim($args[1]) : '';
        $price = isset($args[2]) && is_numeric($args[2]) ? (int) $args[2] : -1;
        $basis = isset($args['basis']) && in_array($args['basis'], ['weight', 'price'], true) ? (string) $args['basis'] : 'weight';
        $min = isset($args['min']) && is_numeric($args['min']) ? (int) $args['min'] : 0;
        $max = isset($args['max']) && is_numeric($args['max']) ? (int) $args['max'] : 40000;
        if ($code === '' || $zoneName === '' || $price < 0 || $max < $min) {
            $io->error('Use: shipping:rate <carrier code> "<zone name>" <price in cents> [--min=0] [--max=40000] [--basis=weight|price]');

            return 2;
        }
        $db = $app->db();
        $carrier = $db->value('SELECT id FROM {carriers} WHERE code = ?', [$code]);
        $zone = $db->value('SELECT id FROM {shipping_zones} WHERE name = ?', [$zoneName]);
        if ($carrier === null || $zone === null) {
            $io->error($carrier === null ? 'No carrier with the code ' . $code : 'No zone called ' . $zoneName);

            return 1;
        }
        $same = ['carrier_id' => (int) $carrier, 'zone_id' => (int) $zone, 'basis' => $basis, 'min' => $min, 'max' => $max];
        $existing = $db->value('SELECT id FROM {carrier_rates} WHERE carrier_id = ? AND zone_id = ? AND basis = ? AND min = ? AND max = ?', array_values($same));
        if ($existing !== null) {
            $db->update('carrier_rates', ['price' => $price], ['id' => (int) $existing]);
            $io->line('Rate changed: ' . $code . ' to ' . $zoneName . ' = ' . $price);
        } else {
            $db->insert('carrier_rates', $same + ['price' => $price]);
            $io->line('Rate added: ' . $code . ' to ' . $zoneName . ' = ' . $price);
        }
        $app->invalidate(['shipping']);

        return 0;
    }],
];
