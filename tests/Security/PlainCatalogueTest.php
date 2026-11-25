<?php
/**
 * AIFEC — un catálogo de traducciones es solo una lista de textos: lo que trae código no se ejecuta al validar un plugin.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\I18nCheck;

test('un catálogo normal es una lista de textos y uno con código no lo es', function (): void {
    check(I18nCheck::isPlainCatalogue("<?php\n/** cabecera */\n\ndeclare(strict_types=1);\n\nreturn [\n    'Hello' => 'Hola', // nota\n    'Bye' => 'Adiós' . ' y hasta luego',\n];\n"));
    check(I18nCheck::isPlainCatalogue("<?php\ndeclare(strict_types=1);\nreturn [];\n"));
    foreach ([
        "<?php\nfile_put_contents('x', 'y');\nreturn [];\n",
        "<?php\nreturn ['a' => system('id')];\n",
        "<?php\nreturn ['a' => \$_GET['x']];\n",
        "<?php\ndeclare(ticks=1){system(\$_GET[0]);}\nreturn [];\n",
        "<?php\nreturn [];\nexit;\n",
        "<?php\nreturn ['a' => PHP_EOL];\n",
        "<?php\nreturn [];\n?>\n<?php system('id');\n",
        "<?php\n\$x = 1;\nreturn [];\n",
    ] as $bad) {
        check(!I18nCheck::isPlainCatalogue($bad), 'no es un catálogo plano: ' . str_replace("\n", ' ', substr($bad, 0, 60)));
    }
});

test('todos los catálogos lang/es.php y en.php del proyecto son listas de textos', function (): void {
    $root = \Aifec\Autoload::root();
    $count = 0;
    foreach (['core', 'modules', 'plugins', 'themes'] as $dir) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (preg_match('#/lang/[a-z-]+\.php$#', $path) !== 1 || strpos($path, 'i18n-ignore') !== false) {
                continue;
            }
            ++$count;
            check(I18nCheck::isPlainCatalogue((string) file_get_contents($path)), 'catálogo con algo más que textos: ' . $path);
        }
    }
    check($count > 20, 'se han mirado los catálogos: ' . $count);
});
