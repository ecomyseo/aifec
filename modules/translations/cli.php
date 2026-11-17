<?php
/**
 * AIFEC — órdenes de consola de las traducciones: la tabla de cobertura, el CSV de ida y vuelta de la interfaz y encolar la IA.
 *
 *   php aifec translations:status [idioma]
 *   php aifec translations:export <idioma> [--out=fichero.csv]
 *   php aifec translations:import <idioma> <fichero.csv>
 *   php aifec translations:fill <idioma> [--only=interface,products,…]
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Translations\Csv;
use Aifec\Modules\Translations\Fill;
use Aifec\Modules\Translations\Scopes;
use Aifec\Modules\Translations\Translations;

/** El idioma de la orden: uno de la tienda o nada. */
$language = static function (App $app, array $args): string {
    $lang = isset($args[0]) && is_string($args[0]) ? strtolower(trim($args[0])) : '';

    return in_array($lang, $app->languages(), true) ? $lang : '';
};

/** Lo que admite --only: los códigos de las filas y unos alias en castellano. */
$aliases = [
    'interface' => ['shop', 'panel'], 'interfaz' => ['shop', 'panel'], 'tienda' => ['shop'], 'productos' => ['products'],
    'categorias' => ['categories'], 'atributos' => ['attributes'], 'paginas' => ['pages'], 'correos' => ['mail'],
];

return [
    'translations:status' => ['help' => 'Shows how much is translated in each language: translations:status [lang]', 'run' => static function (App $app, array $args, Console $io) use ($language): int {
        $service = new Translations($app);
        $langs = $service->languages();
        if (isset($args[0])) {
            $one = $language($app, $args);
            if ($one === '') {
                $io->error('Unknown language. The shop has: ' . implode(', ', $app->languages()));

                return 2;
            }
            $langs = [$one];
        }
        if ($langs === []) {
            $io->line('The shop has only one language.');

            return 0;
        }
        foreach ($langs as $lang) {
            $rows = [];
            foreach ($service->coverage($lang) as $row) {
                $rows[] = [$row['code'], $row['total'], $row['translated'], $row['proposed'], $row['missing'], $row['percent'] . ' %'];
            }
            $io->line('Language: ' . $lang);
            $io->table(['row', 'total', 'translated', 'proposed', 'missing', 'done'], $rows);
        }

        return 0;
    }],
    'translations:export' => ['help' => 'Writes the interface texts of a language to a CSV file: translations:export <lang> [--out=file.csv]', 'run' => static function (App $app, array $args, Console $io) use ($language): int {
        $lang = $language($app, $args);
        if ($lang === '') {
            $io->error('Usage: translations:export <lang> [--out=file.csv]');

            return 2;
        }
        $csv = Csv::export($app, $lang);
        $out = isset($args['out']) && is_string($args['out']) ? $args['out'] : '';
        if ($out === '') {
            $io->line($csv);

            return 0;
        }
        if (file_put_contents($out, $csv) === false) {
            $io->error('Cannot write ' . $out);

            return 1;
        }
        $io->line('Written: ' . $out);

        return 0;
    }],
    'translations:import' => ['help' => 'Reads a CSV file into the interface texts of a language: translations:import <lang> <file.csv>', 'run' => static function (App $app, array $args, Console $io) use ($language): int {
        $lang = $language($app, $args);
        $file = isset($args[1]) && is_string($args[1]) ? $args[1] : '';
        if ($lang === '' || $file === '' || !is_file($file)) {
            $io->error('Usage: translations:import <lang> <file.csv>');

            return 2;
        }
        $result = Csv::import($app, $lang, (string) file_get_contents($file));
        $io->line('saved ' . $result['saved'] . ', unchanged ' . $result['unchanged'] . ', empty ' . $result['skipped'] . ', rejected ' . count($result['rejected']));
        foreach ($result['rejected'] as $row) {
            $io->error('line ' . $row['line'] . ': ' . $row['reason']);
        }

        return $result['rejected'] === [] ? 0 : 1;
    }],
    'translations:fill' => ['help' => 'Queues the AI translation of what is missing: translations:fill <lang> [--only=interface,products,…]', 'run' => static function (App $app, array $args, Console $io) use ($language, $aliases): int {
        $lang = $language($app, $args);
        if ($lang === '') {
            $io->error('Usage: translations:fill <lang> [--only=interface,products,…]');

            return 2;
        }
        $codes = [];
        $only = isset($args['only']) && is_string($args['only']) ? $args['only'] : '';
        if ($only !== '') {
            foreach (explode(',', strtolower($only)) as $item) {
                $item = trim($item);
                foreach (isset($aliases[$item]) ? $aliases[$item] : [$item] as $code) {
                    $codes[] = $code;
                }
            }
        } else {
            $codes = array_keys(Scopes::all($app));
        }
        $code = 0;
        foreach (array_values(array_unique($codes)) as $row) {
            try {
                $id = (new Fill($app))->start($lang, $row);
                $io->line($row . ': queued as job ' . $id);
            } catch (\InvalidArgumentException $e) {
                if ($only !== '' || !in_array($e->getMessage(), ['blocked', 'nothing'], true)) {
                    $io->error($row . ': ' . $e->getMessage());
                    $code = 1;
                }
            }
        }

        return $code;
    }],
    'translations:approve' => ['help' => 'Publishes what the AI proposed in a language: translations:approve <lang> [--only=products,categories,…]', 'run' => static function (App $app, array $args, Console $io) use ($language, $aliases): int {
        $lang = $language($app, $args);
        if ($lang === '') {
            $io->error('Usage: translations:approve <lang> [--only=products,categories,…]');

            return 2;
        }
        $only = isset($args['only']) && is_string($args['only']) ? $args['only'] : '';
        $codes = [];
        foreach ($only !== '' ? explode(',', strtolower($only)) : array_keys(Scopes::all($app)) as $item) {
            $item = trim((string) $item);
            foreach (isset($aliases[$item]) ? $aliases[$item] : [$item] as $code) {
                $codes[] = $code;
            }
        }
        foreach (array_values(array_unique($codes)) as $code) {
            $scope = Scopes::get($app, $code);
            if ($scope === null) {
                $io->error($code . ': unknown row');

                return 1;
            }
            $io->line($code . ': ' . $scope->approveAll($lang, 100000) . ' approved');
        }

        return 0;
    }],
];
