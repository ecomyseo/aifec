<?php
/**
 * AIFEC — las provincias de la caja van por orden alfabético sin mirar tildes (14/11/2026: «Araba/Álava» y «Ávila» salían fuera de
 * su sitio para quien navega oyendo la lista).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Front\Destinations;

test('Ávila va entre Asturias y Badajoz, y la lista entera sale ordenada', function (): void {
    $names = array_values(Destinations::provinces());
    $at = array_flip($names);
    $find = static function (string $start) use ($names): string {
        foreach ($names as $name) {
            if (strpos($name, $start) === 0) {
                return $name;
            }
        }

        return '';
    };
    $avila = $find('Ávila');
    check($avila !== '' && $at[$avila] < $at[$find('Badajoz')] && $at[$avila] > $at[$find('Asturias')], 'Ávila en su sitio: ' . implode(', ', array_slice($names, 0, 8)));
    $folded = array_map(static function (string $n): string {
        return \Aifec\Util\Str::slug($n);
    }, $names);
    $sorted = $folded;
    sort($sorted);
    same($sorted, $folded, 'todas en orden');
});
