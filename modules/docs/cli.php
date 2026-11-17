<?php
/**
 * AIFEC — orden de consola de la documentación: docs:refresh, que invalida las copias guardadas de /docs.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Docs\Docs;

return [
    'docs:refresh' => ['help' => 'Forgets the saved copies of the documentation pages so the next visit reads the Markdown files again', 'run' => static function (App $app, array $args, Console $io): int {
        $app->invalidate([Docs::TAG]);
        /** @var Docs $docs */
        $docs = $app->service('docs');
        $io->line('Documentation refreshed: ' . count($docs->library()->pages()) . ' pages in ' . $docs->directory());

        return 0;
    }],
];
