<?php
/**
 * AIFEC — órdenes de consola del catálogo.
 *
 *   php aifec catalog:replace-text "<texto>" "<por>" [--apply]   busca un texto en el nombre, el resumen y la descripción de los
 *                                                               productos (sin mirar mayúsculas) y dice en cuántos sale; con
 *                                                               --apply lo cambia, vacía la caché y vuelve a indexar el buscador
 *
 * Para qué (14/11/2026): fichas importadas de otra tienda que seguían nombrándola («En Droguería … sabemos…»). Un agente de
 * compra las repetía tal cual a sus clientes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;

return [
    'catalog:restock' => ['help' => 'Tops up the stock of what is running out: catalog:restock [--below=3] [--to=20] [--apply] (every change goes to the stock moves)', 'run' => static function (App $app, array $args, Console $io): int {
        // Para una tienda de muestra que se queda sin existencias con los pedidos de prueba (14/11/2026: el agente no encontró
        // ninguna zapatilla de pádel que se pudiera comprar). Cada cambio, con su movimiento: nunca un UPDATE directo.
        $below = isset($args['below']) && is_numeric($args['below']) ? max(1, (int) $args['below']) : 3;
        $to = isset($args['to']) && is_numeric($args['to']) ? max($below, (int) $args['to']) : 20;
        $db = $app->db();
        $rows = array_merge(
            $db->select("SELECT p.id AS product_id, 0 AS variant_id, p.stock FROM {products} p WHERE p.has_variants = 0 AND p.manage_stock = 1 AND p.status = 'published' AND p.stock < ?", [$below]),
            $db->select("SELECT v.product_id, v.id AS variant_id, v.stock FROM {variants} v JOIN {products} p ON p.id = v.product_id WHERE p.has_variants = 1 AND p.manage_stock = 1 AND p.status = 'published' AND v.active = 1 AND v.stock < ?", [$below])
        );
        if (empty($args['apply'])) {
            $io->line('Would top up ' . count($rows) . ' products or variants to ' . $to . ' (add --apply)');

            return 0;
        }
        $done = 0;
        foreach ($rows as $row) {
            if ($app->service('catalog')->adjustStock((int) $row['product_id'], (int) $row['variant_id'], $to - (int) $row['stock'], 'restock', 'catalog:restock')) {
                ++$done;
            }
        }
        $io->line('Topped up ' . $done . ' of ' . count($rows) . ' products or variants to ' . $to);

        return 0;
    }],
    'catalog:replace-text' => ['help' => 'Finds a text in the product names, summaries and descriptions and, with --apply, replaces it: catalog:replace-text "<text>" "<with>" [--apply]', 'run' => static function (App $app, array $args, Console $io): int {
        $from = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        $to = isset($args[1]) && is_string($args[1]) ? $args[1] : '';
        if (mb_strlen(trim($from), 'UTF-8') < 3) {
            $io->error('Give the text to find (3 characters or more) and the text to put instead');

            return 2;
        }
        $db = $app->db();
        $pattern = '/' . preg_quote($from, '/') . '/iu';
        $changed = 0;
        $places = 0;
        // Se compara en PHP y no con LIKE: LOWER() de SQLite no pasa a minúsculas las letras con tilde («DROGUERÍA»). Por tandas.
        $last = 0;
        $rows = [];
        while (true) {
            $batch = $db->select('SELECT product_id, lang, name, summary, description FROM {product_i18n} WHERE product_id > ? ORDER BY product_id LIMIT 500', [$last]);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $last = max($last, (int) $row['product_id']);
                if (preg_match($pattern, (string) $row['name'] . "\n" . (string) $row['summary'] . "\n" . (string) $row['description']) === 1) {
                    $rows[] = $row;
                }
            }
            if (count($batch) < 500) {
                break;
            }
        }
        foreach ($rows as $row) {
            $update = [];
            foreach (['name', 'summary', 'description'] as $field) {
                $value = (string) $row[$field];
                $count = 0;
                $new = (string) preg_replace($pattern, $to, $value, -1, $count);
                if ($count > 0) {
                    $places += $count;
                    $update[$field] = $new;
                }
            }
            if ($update !== []) {
                ++$changed;
                if (!empty($args['apply'])) {
                    $db->update('product_i18n', $update, ['product_id' => (int) $row['product_id'], 'lang' => (string) $row['lang']]);
                }
            }
        }
        if (!empty($args['apply']) && $changed > 0) {
            $app->invalidate(['catalog']);
            if ($app->has('search')) {
                $app->service('search')->reindex('product');
            }
            $io->line('Replaced ' . $places . ' times in ' . $changed . ' product texts; the search index is being rebuilt');
        } else {
            $io->line('Found ' . $places . ' times in ' . $changed . ' product texts' . ($changed > 0 ? ' (add --apply to replace them)' : ''));
        }

        return 0;
    }],
];
