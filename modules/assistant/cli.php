<?php
/** AIFEC — comprobar el asistente sobre datos de tienda, con reglas y una base desechable. */
declare(strict_types=1);
use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Assistant\CatalogueEvaluation;
return [
    'assistant:evaluate' => ['help' => 'Checks catalogue relevance, prices and delivery on an isolated SQLite copy', 'run' => static function (App $app, array $args, Console $io): int {
        $result = CatalogueEvaluation::measure($app);
        $io->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $result['passed'] ? 0 : 1;
    }],
];
