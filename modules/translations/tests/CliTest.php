<?php
/**
 * AIFEC — pruebas de las órdenes de consola: translations:status y translations:fill (export e import están en CsvTest).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Modules\Translations\SiteCatalog;

require_once __DIR__ . '/TranslationsKit.php';

test('translations:status enseña la tabla de cobertura de cada idioma o de uno', function (): void {
    $app = trxApp([], ['translations'], ['es', 'fr', 'de']);
    (new SiteCatalog($app))->update('fr', ['Checkout' => 'Commander']);
    $io = new Console(false);
    $commands = $io->commands($app);
    same(0, $commands['translations:status']['run']($app, [], $io));
    contains('Language: fr', $io->output);
    contains('Language: de', $io->output);
    check(preg_match('/shop\s+4\s+1\s+0\s+3\s+25 %/', $io->output) === 1, 'la fila de la tienda en francés: ' . $io->output);
    $one = new Console(false);
    same(0, $commands['translations:status']['run']($app, ['de'], $one));
    notContains('Language: fr', $one->output);
    $bad = new Console(false);
    same(2, $commands['translations:status']['run']($app, ['xx'], $bad));
    contains('Unknown language', $bad->output);
});

test('translations:status con un solo idioma lo dice', function (): void {
    $app = trxApp([], ['translations'], ['es']);
    $io = new Console(false);
    same(0, $io->commands($app)['translations:status']['run']($app, [], $io));
    contains('only one language', $io->output);
});

test('translations:fill encola la IA de las filas pedidas (con alias en castellano) y el trabajo traduce', function (): void {
    [$app, $ai] = trxAiApp();
    $io = new Console(false);
    same(0, $io->commands($app)['translations:fill']['run']($app, ['fr', 'only' => 'interfaz'], $io));
    contains('shop: queued as job', $io->output);
    contains('panel: queued as job', $io->output);
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {translation_jobs} WHERE status = 'running'"));
    trxWork($app);
    same(2, count($ai->requests));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {translation_jobs} WHERE status <> 'done'"));
});

test('translations:fill sin --only lanza todo lo que se puede y calla lo que no se rellena con IA', function (): void {
    [$app] = trxAiApp();
    $io = new Console(false);
    same(0, $io->commands($app)['translations:fill']['run']($app, ['fr'], $io));
    contains('shop: queued', $io->output);
    notContains('categories', $io->output);
});

test('translations:fill falla con un idioma desconocido, con una fila que no existe o con la IA apagada', function (): void {
    [$app] = trxAiApp();
    $io = new Console(false);
    $commands = $io->commands($app);
    same(2, $commands['translations:fill']['run']($app, ['xx'], $io));
    $two = new Console(false);
    same(1, $commands['translations:fill']['run']($app, ['fr', 'only' => 'nada'], $two));
    contains('nada: unknown', $two->output);
    [$off] = trxAiApp(['ai.enabled' => false]);
    $three = new Console(false);
    same(1, $three->commands($off)['translations:fill']['run']($off, ['fr', 'only' => 'shop'], $three));
    contains('no_ai', $three->output);
});
