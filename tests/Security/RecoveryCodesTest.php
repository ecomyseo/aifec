<?php
/**
 * AIFEC — códigos de recuperación de la verificación en dos pasos: diez por persona, de un solo uso, y solo se guarda su resumen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\RecoveryCodes;

/** Los códigos de recuperación de una persona recién dada de alta, con el servicio ya montado. */
function recoveryKit(string $email = 'ana@tienda.test'): array
{
    $app = testApp();
    $id = aifecAdmin($app, 'admin', $email, 'Ana');
    $service = new RecoveryCodes($app->db(), $app->site()->secret());

    return [$app, $service, $id];
}

test('códigos de recuperación: salen diez, distintos y con el formato «abcd-2345»', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    same(10, count($codes));
    same(10, count(array_unique($codes)), 'ninguno repetido');
    foreach ($codes as $code) {
        same(1, preg_match('/^[a-hj-km-np-z2-9]{4}-[a-hj-km-np-z2-9]{4}$/', $code), 'legible, sin 0/o ni 1/l/i: ' . $code);
    }
    same(10, $service->remaining($id));
});

test('códigos de recuperación: en la base solo hay resúmenes, nunca el código', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    $rows = $app->db()->select('SELECT * FROM {recovery_codes}');
    same(10, count($rows));
    $dump = json_encode($rows);
    foreach ($codes as $code) {
        notContains($code, (string) $dump, 'ni el código con guion');
        notContains(str_replace('-', '', $code), (string) $dump, 'ni sin guion');
    }
    foreach ($rows as $row) {
        same(64, strlen((string) $row['hash']), 'un resumen de 64 caracteres');
        same(null, $row['used_at']);
    }
});

test('códigos de recuperación: cada uno vale una sola vez', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    check($service->consume($id, $codes[0], $app->clock()->sql()), 'la primera vez vale');
    check(!$service->consume($id, $codes[0], $app->clock()->sql()), 'la segunda no');
    same(9, $service->remaining($id));
    check($service->consume($id, $codes[1], $app->clock()->sql()), 'otro código distinto sí');
    same(8, $service->remaining($id));
});

test('códigos de recuperación: valen en mayúsculas, con espacios y sin el guion', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    check($service->consume($id, '  ' . strtoupper($codes[0]) . ' ', $app->clock()->sql()), 'mayúsculas y espacios');
    check($service->consume($id, str_replace('-', '', $codes[1]), $app->clock()->sql()), 'sin guion');
    check($service->consume($id, substr($codes[2], 0, 4) . ' ' . substr($codes[2], 5), $app->clock()->sql()), 'con espacio en vez de guion');
});

test('códigos de recuperación: lo mal escrito no vale ni gasta ninguno', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    foreach (['', '   ', 'abcd', $codes[0] . 'x', 'zzzz-zzzz', '0000-0000', 'o0o0-1l1l', "' OR 1=1 --", '123456'] as $bad) {
        check(!$service->consume($id, $bad, $app->clock()->sql()), 'rechazado: ' . $bad);
    }
    same(10, $service->remaining($id), 'ninguno se ha gastado');
    check($service->consume($id, $codes[0], $app->clock()->sql()), 'y el bueno sigue valiendo');
});

test('códigos de recuperación: el de una persona no vale para otra', function (): void {
    [$app, $service, $id] = recoveryKit();
    $other = aifecAdmin($app, 'editor', 'bea@tienda.test', 'Bea');
    $mine = $service->issue($id, $app->clock()->sql());
    $theirs = $service->issue($other, $app->clock()->sql());
    check(!$service->consume($other, $mine[0], $app->clock()->sql()), 'ajeno');
    check(!$service->consume($id, $theirs[0], $app->clock()->sql()), 'ajeno al revés');
    same(10, $service->remaining($id));
    same(10, $service->remaining($other));
    check($service->consume($other, $theirs[0], $app->clock()->sql()), 'el suyo sí');
});

test('códigos de recuperación: generar otros borra los anteriores y deja diez', function (): void {
    [$app, $service, $id] = recoveryKit();
    $old = $service->issue($id, $app->clock()->sql());
    $service->consume($id, $old[0], $app->clock()->sql());
    $new = $service->issue($id, $app->clock()->sql());
    same(10, $service->remaining($id));
    same(10, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$id]), 'sin restos de la tanda vieja');
    foreach ($old as $code) {
        check(!$service->consume($id, $code, $app->clock()->sql()), 'uno viejo ya no vale');
    }
    check($service->consume($id, $new[0], $app->clock()->sql()), 'uno nuevo sí');
});

test('códigos de recuperación: clear() los borra todos y dice cuántos había', function (): void {
    [$app, $service, $id] = recoveryKit();
    $other = aifecAdmin($app, 'editor', 'bea@tienda.test', 'Bea');
    $service->issue($id, $app->clock()->sql());
    $service->issue($other, $app->clock()->sql());
    same(10, $service->clear($id));
    same(0, $service->remaining($id));
    same(10, $service->remaining($other), 'los de otra persona no se tocan');
    same(0, $service->clear($id), 'sin nada que borrar');
});

test('códigos de recuperación: el resumen depende de la clave del sitio y de la persona', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    $stolen = new RecoveryCodes($app->db(), 'otra-clave-distinta');
    check(!$stolen->consume($id, $codes[0], $app->clock()->sql()), 'con otra clave del sitio, el mismo código no casa');
    $hash = (string) $app->db()->value('SELECT hash FROM {recovery_codes} WHERE user_id = ? ORDER BY id', [$id]);
    $plain = [];
    foreach ($codes as $code) {
        $plain[] = hash('sha256', str_replace('-', '', $code));
    }
    check(!in_array($hash, $plain, true), 'no es un SHA-256 sin clave: no se puede probar con un diccionario');
});

test('códigos de recuperación: normalize y format', function (): void {
    same('abcd2345', RecoveryCodes::normalize('ABCD-2345'));
    same('abcd2345', RecoveryCodes::normalize(' a b c d - 2 3 4 5 '));
    same('', RecoveryCodes::normalize('abcd-234'), 'corto');
    same('', RecoveryCodes::normalize('abcd-23456'), 'largo');
    same('', RecoveryCodes::normalize('abcd-23o5'), 'la o no está en el alfabeto');
    same('', RecoveryCodes::normalize(''));
    same('abcd-2345', RecoveryCodes::format('abcd2345'));
});

test('códigos de recuperación: dos envíos a la vez con el mismo código, solo uno entra', function (): void {
    [$app, $service, $id] = recoveryKit();
    $codes = $service->issue($id, $app->clock()->sql());
    // La actualización es condicional («used_at IS NULL»): lo que ve la segunda petición es la fila ya gastada.
    $first = $service->consume($id, $codes[3], $app->clock()->sql());
    $second = (new RecoveryCodes($app->db(), $app->site()->secret()))->consume($id, $codes[3], $app->clock()->sql());
    same([true, false], [$first, $second]);
});
