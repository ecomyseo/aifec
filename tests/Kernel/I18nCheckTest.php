<?php
/**
 * AIFEC — la comprobación de traducciones: encuentra cada texto traducible de una carpeta, dice cuál falta y cuál
 * sobra, y no se deja engañar por lo que no es un texto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\I18nCheck;

/**
 * Monta una instalación de mentira con un núcleo mínimo y un módulo «demo».
 *
 * @param array<string,string> $files ruta relativa => contenido
 */
function i18nCheckTree(array $files): string
{
    $root = str_replace('\\', '/', tmpDir('i18n'));
    $files += ['core/lang/es.php' => "<?php\nreturn ['Save' => 'Guardar'];\n"];
    foreach ($files as $path => $content) {
        $full = $root . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $content);
    }

    return $root;
}

test('encuentra las llamadas, las definiciones del panel y los manifiestos, y dice qué falta', function (): void {
    $root = i18nCheckTree([
        'modules/demo/module.json' => '{"name":"Demo shop","description":"Sells things","version":"1.0.0"}',
        'modules/demo/src/Thing.php' => "<?php\n\$a = t('Add to cart');\n\$b = tn('One item', '{n} items', 2);\n\$c = t('Save');\n\$d = t(\$variable);\n\$e = ['label' => 'No es del panel'];\n\$f = t('It\\'s here');\n",
        'modules/demo/admin.php' => "<?php\nreturn ['resources' => ['x' => ['title' => 'Things', 'form' => ['fields' => [\n"
            . "    'kind' => ['type' => 'select', 'label' => 'Kind', 'options' => ['a' => 'Alpha', 'b' => 'Beta']],\n"
            . "    'code' => ['type' => 'select', 'label' => 'Code', 'options' => ['x' => 'EUR', 'y' => 'USD'], 'translate' => false],\n"
            . "    'owner' => ['type' => 'select', 'label' => 'Owner', 'options_from' => ['table' => 'users', 'value' => 'id', 'label' => 'name']],\n"
            . "    'unit' => ['type' => 'number', 'label' => 'Weight', 'suffix' => 'kg', 'hint' => 'In kilos'],\n"
            . "]]]]];\n",
        'modules/demo/tests/ThingTest.php' => "<?php\n\$x = t('Only in a test');\n",
        'modules/demo/lang/es.php' => "<?php\nreturn ['Add to cart' => 'Añadir al carrito', 'Things' => 'Cosas', 'Kind' => 'Tipo', 'Alpha' => 'Alfa', 'Old text' => 'Texto viejo', 'Beta' => ''];\n",
    ]);

    $r = I18nCheck::run($root, 'modules/demo');
    same(false, $r['ok']);
    $missing = array_keys($r['missing']);
    sort($missing);
    same(['Beta', 'Code', 'Demo shop', 'In kilos', "It's here", 'One item|{n} items', 'Owner', 'Sells things', 'Weight'], $missing,
        'faltan: lo no traducido, lo traducido en vacío, el manifiesto y el plural; no lo de las pruebas, ni EUR/USD, ni la columna «name», ni «kg», ni un array cualquiera');
    same(['Old text'], $r['unused']);
    same(14, $r['strings']);
    same('modules/demo/admin.php', $r['missing']['Code']);
    same('modules/demo/module.json', $r['missing']['Demo shop']);
    contains("    'It\\'s here' => '',   // modules/demo/src/Thing.php", I18nCheck::snippet($r['missing']));
});

test('con todo traducido pasa; el catálogo del núcleo también vale; lo ignorado no cuenta', function (): void {
    $root = i18nCheckTree([
        'themes/claro/theme.json' => '{"name":"Claro","settings":{"accent":{"label":"Accent colour","type":"color"},"header":{"label":"Header","type":"select","options":{"a":"Centred","b":"Left"}}},"menus":{"main":"Main menu"}}',
        'themes/claro/templates/layout.php' => "<?php\n?><button><?= e(t('Save')) ?></button><p><?= e(t('Free shipping')) ?></p><p><?= e(t('AIFEC')) ?></p>",
        'themes/claro/lang/es.php' => "<?php\nreturn ['Accent colour' => 'Color de acento', 'Header' => 'Cabecera', 'Centred' => 'Centrada', 'Left' => 'A la izquierda', 'Main menu' => 'Menú principal', 'Free shipping' => 'Envío gratis'];\n",
        'themes/claro/lang/i18n-ignore.php' => "<?php\nreturn ['AIFEC'];\n",
    ]);
    $r = I18nCheck::run($root, 'themes/claro');
    same([], $r['missing']);
    same(true, $r['ok']);
    same([], $r['unused']);
    same('', I18nCheck::snippet($r['missing']));

    // Sin el fichero de ignorados, la marca falta.
    unlink($root . '/themes/claro/lang/i18n-ignore.php');
    same(['AIFEC'], array_keys(I18nCheck::run($root, 'themes/claro')['missing']));
});

test('una carpeta que no existe, que se sale de la raíz o un idioma mal escrito se rechazan', function (): void {
    $root = i18nCheckTree(['modules/demo/src/Thing.php' => "<?php\n"]);
    foreach ([['modules/nada', 'es'], ['../fuera', 'es'], ['', 'es'], ['modules/demo', 'español']] as $case) {
        $thrown = false;
        try {
            I18nCheck::run($root, $case[0], $case[1]);
        } catch (InvalidArgumentException $e) {
            $thrown = true;
        }
        check($thrown, 'tiene que rechazar «' . $case[0] . '» en «' . $case[1] . '»');
    }
    same(0, I18nCheck::run($root, 'modules/demo')['strings']);
});

test('la orden de consola i18n:check da el recuento y sale con 1 si falta algo', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    check(isset($commands['i18n:check']), 'la orden existe');
    $io = new Aifec\Cli\Console(false);
    same(0, $commands['i18n:check']['run']($app, ['core'], $io));
    contains('I18N core [es]:', $io->output);
    contains(', 0 missing', $io->output);

    $io = new Aifec\Cli\Console(false);
    same(2, $commands['i18n:check']['run']($app, ['modules/no-existe'], $io));
});

test('una clave escrita dos veces en el catálogo se señala con sus líneas y hace fallar la comprobación', function (): void {
    $root = i18nCheckTree([
        'modules/demo/src/Thing.php' => "<?php\nreturn [t('Save'), t('It\\'s done'), t('Cancel')];\n",
        'modules/demo/lang/es.php' => "<?php\nreturn [\n    'Save' => 'Guardar',\n    'Cancel' => 'Cancelar',\n    'It\\'s done' => 'Hecho',\n    // la misma, más abajo\n    'Save' => 'Salvar',\n    'Nested' => ['Save' => 'no cuenta: no es del primer nivel'],\n    \"It's done\" => 'Ya está',\n];\n",
    ]);
    $result = I18nCheck::run($root, 'modules/demo');
    same([], $result['missing'], 'no falta ninguna');
    same(['Save' => [3, 7], "It's done" => [5, 9]], $result['duplicates'], 'las dos repetidas, con las líneas, se escriba la clave con unas comillas o con otras');
    same(false, $result['ok'], 'con claves repetidas la comprobación no pasa');

    $limpio = i18nCheckTree([
        'modules/demo/src/Thing.php' => "<?php\nreturn [t('Save')];\n",
        'modules/demo/lang/es.php' => "<?php\nreturn ['Save' => 'Guardar', 'Other' => ['Save' => 'x']];\n",
    ]);
    $result = I18nCheck::run($limpio, 'modules/demo');
    same([], $result['duplicates']);
    same(true, $result['ok']);
});

test('el catálogo del núcleo no tiene claves repetidas', function (): void {
    // Solo el del núcleo: los de los módulos los mira `i18n:check` de cada uno (una prueba del núcleo no debe depender
    // de un módulo que alguien tiene a medio escribir).
    same([], I18nCheck::duplicates(Aifec\Autoload::root() . '/core/lang/es.php'));
});
