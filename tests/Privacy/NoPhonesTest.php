<?php
/**
 * AIFEC — en el proyecto no hay ningún teléfono que pueda ser de alguien (orden del usuario del 16/11/2026: «quita todos los
 * teléfonos»). Un número «de relleno» con forma de móvil es el teléfono de una persona de verdad: el 15/11/2026 una prueba
 * mandó un código a uno.
 *
 * Lo único que se admite, porque no puede ser de nadie:
 *  - números españoles del rango 999, que el Plan Nacional de Numeración Telefónica no tiene atribuido a ningún servicio
 *    («Listado de atribuciones y adjudicaciones vigentes», actualizado el 18/02/2021: no hay ningún indicativo 99X; y la
 *    «Descripción del Plan», noviembre de 2016: «N = 9: servicio telefónico fijo (X ≠ 0, 9), tarifas especiales y acceso a
 *    Internet (X = 0), y pendiente de atribución»);
 *  - los que Ofcom reserva para la ficción (+44 7700 900000 a 900999).
 *
 * Una prueba que necesite un número que empiece por 6, 7 u 8 lo monta al ejecutarse; no lo escribe entero.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Los teléfonos escritos en un texto que no son de un rango de mentira.
 *
 * @return string[]
 */
function privacyPhonesIn(string $text): array
{
    $found = [];
    $spanish = '~(?<![\w.,/#-])(?:(?:\+|00)?34[\s.-]?)?([6789]\d{2}(?:[\s.-]?\d{3}){2}|[6789]\d{2}(?:[\s.-]\d{2}){3})(?![\w-]|[.,]\d)~';
    if (preg_match_all($spanish, $text, $all) > 0) {
        foreach ($all[1] as $number) {
            $digits = (string) preg_replace('/\D/', '', $number);
            if (strpos($digits, '999') !== 0) {
                $found[] = $number;
            }
        }
    }
    if (preg_match_all('~(?<![\w.])\+(\d[\d\s().-]{6,20}\d)(?!\w)~', $text, $all) > 0) {
        foreach ($all[1] as $number) {
            $digits = (string) preg_replace('/\D/', '', $number);
            // Un español con su prefijo ya lo ha contado (o lo ha dado por bueno) el patrón de arriba.
            $spanish = preg_match('/^34[6789]\d{8}$/D', $digits) === 1;
            $drama = preg_match('/^447700900\d{3}$/D', $digits) === 1;
            if (strlen($digits) >= 8 && strlen($digits) <= 15 && !$spanish && !$drama) {
                $found[] = '+' . $number;
            }
        }
    }

    return $found;
}

test('el buscador de teléfonos distingue los que pueden ser de alguien de los de mentira', function (): void {
    // Cifras al azar en cada pasada: ni siquiera aquí se escribe un número fijo con forma de móvil (el «de ejemplo» es de alguien).
    $pairs = sprintf('%02d %02d %02d %02d', random_int(10, 99), random_int(10, 99), random_int(10, 99), random_int(10, 99));
    $mobile = '6' . sprintf('%02d %03d %03d', random_int(10, 99), random_int(100, 999), random_int(100, 999));
    $landline = '9' . sprintf('%d %03d %02d %02d', random_int(1, 8), random_int(100, 999), random_int(10, 99), random_int(10, 99));
    same([$mobile], privacyPhonesIn('Llámanos al ' . $mobile . ' por la tarde'), 'un móvil con espacios');
    same(1, count(privacyPhonesIn('"phone": "' . str_replace(' ', '', $mobile) . '"')), 'un móvil seguido');
    same(1, count(privacyPhonesIn('tel. +34 ' . $mobile)), 'con prefijo de España se cuenta una vez');
    same(1, count(privacyPhonesIn('fijo ' . str_replace(' ', '', $landline))), 'un fijo');
    same(1, count(privacyPhonesIn('móvil en pares: 6' . substr($pairs, 0, 2) . ' ' . substr($pairs, 3))), 'un móvil en pares');
    same(1, count(privacyPhonesIn('whatsapp +33 6 ' . $pairs)), 'uno de otro país');
    same([], privacyPhonesIn('999 000 001, 999345678 y +34 999 123 456'), 'el rango 999 no es de nadie');
    same([], privacyPhonesIn('+44 7700 900123'), 'los que Ofcom reserva para la ficción');
    // Los textos van en variables: en la misma línea que el nombre de la función, el vigilante de antes de publicar los leería
    // como teléfonos.
    $codes = 'EAN 8412345678905, 1700000000 segundos, 12.345.678 unidades y 28013 Madrid';
    $order = 'un pedido 2026-000123 y un precio de 612,50 €';
    same([], privacyPhonesIn($codes), 'números que no son teléfonos');
    same([], privacyPhonesIn($order), 'ni un pedido ni un precio');
});

test('en el proyecto no hay ningún teléfono que pueda ser de alguien', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $extensions = ['php', 'md', 'json', 'js', 'css', 'txt', 'html', 'xml', 'yml', 'yaml', 'sh', 'py', 'sql', 'csv', 'ini'];
    $skip = ['.git', 'node_modules', 'media', 'sites', 'storage', 'cache', '.cache', '__pycache__'];
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skip): bool {
            return !in_array($file->getFilename(), $skip, true);
        }
    );
    $checked = 0;
    $bad = [];
    foreach (new RecursiveIteratorIterator($filter) as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true) || filesize($path) > 3000000) {
            continue;
        }
        ++$checked;
        $text = (string) file_get_contents($path);
        foreach (privacyPhonesIn($text) as $number) {
            // Se enseña el fichero y el final del número: el número entero no se repite en la salida de las pruebas.
            $bad[] = substr($path, strlen($root) + 1) . ' · …' . substr((string) preg_replace('/\D/', '', $number), -3);
        }
    }
    check($checked > 1500, 'se han mirado los ficheros del proyecto (' . $checked . ')');
    same([], array_slice($bad, 0, 40), 'teléfonos que pueden ser de alguien (se admiten solo el rango 999 y +44 7700 900xxx)');
});
