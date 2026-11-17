<?php
/**
 * AIFEC — catalog:replace-text: cuenta sin tocar nada y, con --apply, cambia el texto sin mirar mayúsculas solo donde está.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('catalog:restock dice cuántos repondría y, con --apply, los sube con su movimiento; lo que tiene de sobra no se toca', function (): void {
    $app = testApp(['modules' => ['catalog']]);
    $low = aifecProduct($app, ['name' => 'Zapatilla', 'stock' => 0]);
    $ok = aifecProduct($app, ['name' => 'Mochila', 'stock' => 15]);
    $run = (require dirname(__DIR__) . '/cli.php')['catalog:restock']['run'];
    $io = new \Aifec\Cli\Console(false);
    same(0, $run($app, ['below' => '3', 'to' => '20'], $io));
    contains('Would top up 1', $io->output);
    same(0, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$low]), 'sin --apply no se toca');
    same(0, $run($app, ['below' => '3', 'to' => '20', 'apply' => true], $io));
    same(20, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$low]));
    same(15, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$ok]));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ? AND reason = 'restock'", [$low]), 'con su movimiento');
});

test('catalog:replace-text cuenta, y con --apply cambia solo lo que coincide (con el «_» como letra, no como comodín)', function (): void {
    $app = testApp(['modules' => ['catalog']]);
    $a = aifecProduct($app, ['name' => 'Detergente', 'description' => '<p>En Droguería El Barco sabemos cuidar tu ropa.</p>']);
    $b = aifecProduct($app, ['name' => 'Jabón', 'description' => '<p>En DROGUERÍA EL BARCO lo usamos a diario.</p>']);
    $c = aifecProduct($app, ['name' => 'Cubo', 'description' => '<p>Droguería Elxbarco no es la misma.</p>']);
    $commands = require dirname(__DIR__) . '/cli.php';
    $run = $commands['catalog:replace-text']['run'];
    $io = new \Aifec\Cli\Console(false);

    same(0, $run($app, ['Droguería El Barco', 'nuestra tienda'], $io));
    contains('Found 2 times in 2 product texts', $io->output);
    contains('El Barco', (string) $app->db()->value('SELECT description FROM {product_i18n} WHERE product_id = ?', [$a]), 'sin --apply no se toca');

    same(0, $run($app, ['Droguería El Barco', 'nuestra tienda', 'apply' => true], $io));
    contains('Replaced 2 times', $io->output);
    same('<p>En nuestra tienda sabemos cuidar tu ropa.</p>', (string) $app->db()->value('SELECT description FROM {product_i18n} WHERE product_id = ?', [$a]));
    same('<p>En nuestra tienda lo usamos a diario.</p>', (string) $app->db()->value('SELECT description FROM {product_i18n} WHERE product_id = ?', [$b]));
    contains('Elxbarco', (string) $app->db()->value('SELECT description FROM {product_i18n} WHERE product_id = ?', [$c]), 'la otra no se toca');

    $io2 = new \Aifec\Cli\Console(false);
    same(0, $run($app, ['El_Barco', 'x'], $io2));
    contains('Found 0 times', $io2->output, 'el guion bajo no casa con cualquier letra');
    same(2, $run($app, ['ab', 'x'], $io2), 'un texto demasiado corto se rechaza');
});
