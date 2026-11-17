<?php
/**
 * AIFEC — en el proyecto no se escribe ningún DNI, NIE ni NIF de entidad que pueda ser de alguien (orden del usuario del
 * 16/11/2026: «los DNI, siempre ejemplos»). Llegó a haber, en dos comentarios, el nombre y el DNI de una persona de verdad,
 * y viajaron en los ZIP publicados.
 *
 * Solo se admiten números DE EJEMPLO, que no se confunden con los de un documento real: todas las cifras iguales
 * (11111111), una escalera (12345678, 87654321, 34567890) o casi todo ceros o nueves (00000001, 99999999). Cuenta el
 * número, lleve la letra buena o no: un número de verdad con la letra cambiada sigue enseñando el número.
 *
 * Cuando una prueba necesita variedad, los genera al azar al ejecutarse (fakeDni(), fakeNie() y fakeCompanyTaxId() de
 * tests/support.php) y no quedan escritos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/** ¿Las cifras son de ejemplo? */
function privacyExampleNumber(string $digits): bool
{
    if (count(array_unique(str_split($digits))) === 1) {
        return true;
    }
    $steps = [];
    for ($i = 1, $n = strlen($digits); $i < $n; ++$i) {
        $steps[((int) $digits[$i] - (int) $digits[$i - 1] + 10) % 10] = true;
    }
    if (array_keys($steps) === [1] || array_keys($steps) === [9]) {
        return true;
    }
    $head = substr($digits, 0, -1);

    return $head === str_repeat('0', strlen($head)) || $head === str_repeat('9', strlen($head));
}

/**
 * Los documentos escritos en un texto cuyo número no es de ejemplo.
 *
 * @return string[]
 */
function privacyIdsIn(string $text): array
{
    $found = [];
    $taken = [];
    $mention = '/nif|cif|tax|vat|fiscal|dni|nie|documento/i';
    $near = static function (int $start, int $length) use ($text): string {
        return substr($text, max(0, $start - 60), $length + 80);
    };
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    if (preg_match_all('~(?<![A-Za-z0-9])([XYZxyz])[ -]?(\d{7})[ -]?([A-Za-z])(?![A-Za-z0-9])~', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($all as $m) {
            $taken[] = [$m[0][1], $m[0][1] + strlen($m[0][0])];
            if (!privacyExampleNumber($m[2][0])) {
                $found[] = $m[0][0];
            }
        }
    }
    $free = static function (int $start, int $end) use (&$taken): bool {
        foreach ($taken as $range) {
            if ($range[0] < $end && $start < $range[1]) {
                return false;
            }
        }

        return true;
    };
    if (preg_match_all('~(?<![A-Za-z0-9])([ABCDEFGHJNPQRSUVWabcdefghjnpqrsuvw])[ -]?(\d{7})[ -]?([0-9A-Ja-j])(?![A-Za-z0-9])~', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($all as $m) {
            $start = $m[0][1];
            $end = $start + strlen($m[0][0]);
            if (!$free($start, $end)) {
                continue;
            }
            // Una letra, siete cifras y otra cifra puede ser cualquier referencia: cuenta como NIF si el control cuadra o si
            // el texto de alrededor habla de un documento.
            $sum = 0;
            foreach (str_split($m[2][0]) as $position => $digit) {
                $value = (int) $digit * ($position % 2 === 0 ? 2 : 1);
                $sum += $value > 9 ? $value - 9 : $value;
            }
            $control = (10 - $sum % 10) % 10;
            $given = strtoupper($m[3][0]);
            $fits = $given === (string) $control || $given === 'JABCDEFGHI'[$control];
            if (!$fits && preg_match($mention, $near($start, $end - $start)) !== 1) {
                continue;
            }
            $taken[] = [$start, $end];
            if (!privacyExampleNumber($m[2][0])) {
                $found[] = $m[0][0];
            }
        }
    }
    if (preg_match_all('~(?<![A-Za-z0-9])(\d{2}\.?\d{3}\.?\d{3})[ -]?([A-Za-z])(?![A-Za-z0-9])~', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($all as $m) {
            $start = $m[0][1];
            $end = $start + strlen($m[0][0]);
            if (!$free($start, $end)) {
                continue;
            }
            $digits = str_replace('.', '', $m[1][0]);
            $fits = $letters[(int) $digits % 23] === strtoupper($m[2][0]);
            if (!$fits && preg_match($mention, $near($start, $end - $start)) !== 1) {
                continue; // ocho cifras y una letra que no cuadra, lejos de cualquier mención: una referencia
            }
            if (!privacyExampleNumber($digits)) {
                $found[] = $m[0][0];
            }
        }
    }

    return $found;
}

test('el buscador de documentos distingue los de ejemplo de los que pueden ser de alguien', function (): void {
    foreach (['11111111', '12345678', '87654321', '34567890', '00000000', '00000001', '99999999', '99999990', '1234567', '7654321'] as $example) {
        check(privacyExampleNumber($example), $example . ' es de ejemplo');
    }
    foreach (['12345679', '10000000', '11111112', '13579135'] as $real) {
        check(!privacyExampleNumber($real), $real . ' no es de ejemplo');
    }
    same([], privacyIdsIn("NIF 12345678Z, NIE X1234567L, empresa B12345674 y otro 00000000T; mal a propósito: 12345678A"), 'los de ejemplo');
    // Los que no son de ejemplo se montan aquí, para no dejarlos escritos.
    $dni = fakeDni(40000000 + 123456);
    $nie = fakeNie('Y', 4000000 + 12345);
    $company = fakeCompanyTaxId('A', 5881850);
    same([$dni], privacyIdsIn('Mi DNI es ' . $dni . ', gracias'), 'un DNI con su letra');
    same([substr($dni, 0, 2) . '.' . substr($dni, 2, 3) . '.' . substr($dni, 5, 3) . '-' . strtolower($dni[8])],
        privacyIdsIn('con puntos: ' . substr($dni, 0, 2) . '.' . substr($dni, 2, 3) . '.' . substr($dni, 5, 3) . '-' . strtolower($dni[8])), 'con puntos, guion y minúscula');
    same(1, count(privacyIdsIn('tax_id => ' . substr($dni, 0, 8) . 'A')), 'un número de verdad con la letra cambiada también cuenta, si se habla de un documento');
    same([$nie], privacyIdsIn('NIE ' . $nie), 'un NIE');
    same([$company], privacyIdsIn('la empresa ' . $company . ' factura'), 'un NIF de entidad');
    $order = '2026' . '0001A';
    same([], privacyIdsIn('pedido ' . $order . ', color #1234567a, 2026-000123 y la referencia R4000123X9'), 'ocho cifras y una letra que no cuadran, sin nada alrededor que hable de un documento');
});

test('en el proyecto no hay ningún DNI, NIE ni NIF de entidad que no sea de ejemplo', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $extensions = ['php', 'md', 'json', 'js', 'txt', 'html', 'xml', 'yml', 'yaml', 'py', 'sql', 'csv', 'ini'];
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
        foreach (privacyIdsIn((string) file_get_contents($path)) as $id) {
            // Se enseña el fichero y el final del documento: entero no se repite en la salida de las pruebas.
            $bad[] = substr($path, strlen($root) + 1) . ' · …' . substr($id, -3);
        }
    }
    check($checked > 1500, 'se han mirado los ficheros del proyecto (' . $checked . ')');
    same([], array_slice($bad, 0, 40), 'documentos que pueden ser de alguien (solo valen los de ejemplo: 12345678Z, X1234567L, B12345674…)');
});
