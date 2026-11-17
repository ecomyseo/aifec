<?php
/**
 * AIFEC — las direcciones de un pedido se enseñan con nombres, no con los códigos con que se guardan: «Alicante» y
 * «España», no «03» y «ES». En la ficha del pedido del panel, en la página del pedido de la tienda y en los correos.
 * Visto en las capturas del panel: la ficha enseñaba «46» y «ES».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Orders;

require_once __DIR__ . '/AdminKit.php';

test('la ficha del pedido, la página del pedido y el correo enseñan la provincia y el país con su nombre', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $billing = [
        'first_name' => 'Marta', 'last_name' => 'Ruiz Peña', 'vat_id' => '12345678Z', 'line1' => 'Calle de la Muestra 5',
        'postcode' => '03202', 'city' => 'Elche', 'region' => '03', 'country' => 'ES', 'phone' => '999000001',
    ];
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['billing' => $billing, 'email' => 'marta@ejemplo.test']);
    $number = adminOrdersNumber($app, $id);

    // El panel.
    $admin = adminClient($app);
    $html = $admin->get('/admin/orders/' . $id)->body;
    contains('03202 Elche', $html);
    contains('Alicante', $html, 'la provincia, con su nombre');
    contains('España', $html, 'el país, con su nombre');
    contains('12345678Z', $html, 'el NIF de la factura');
    check(preg_match('~<br\s*/?>\s*(ES|03)\s*(<|$)~m', $html) !== 1, 'ni «ES» ni «03» sueltos en una línea de la dirección');

    // La página que ve el cliente.
    $front = (new TestClient($app))->get('/pedido/' . $number . '/' . Orders::viewToken($app, $number))->body;
    contains('Alicante', $front);
    contains('España', $front);

    // El correo de confirmación.
    $mails = aifecOrderMails($app, 'order_confirmed');
    check(count($mails) >= 1, 'hay correo de confirmación');
    $mail = (string) $mails[0]['html'];
    contains('03202 Elche', $mail);
    contains('Alicante', $mail);
    contains('España', $mail);
    check(preg_match('~<br\s*/?>\s*ES\s*(<|$)~m', $mail) !== 1, 'el correo no enseña el código del país');
});

test('el historial del pedido no enseña marcas internas vacías: dice que el cliente vio la confirmación', function (): void {
    // Visto en las capturas: tras la compra salía una línea «Sistema» sin texto (la marca de «página de gracias vista»).
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $number = adminOrdersNumber($app, $id);
    same(200, (new TestClient($app))->get('/pedido/' . $number . '/' . Orders::viewToken($app, $number))->status);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {order_history} WHERE order_id = ? AND kind = 'system' AND to_value = 'thanks_shown'", [$id]), 'la tienda apunta que se vio');
    $app->db()->insert('order_history', ['order_id' => $id, 'kind' => 'system', 'to_value' => 'otra_marca_interna', 'is_public' => 0, 'actor' => 'system', 'created_at' => $app->clock()->sql()]);

    $html = adminClient($app)->get('/admin/orders/' . $id)->body;
    contains('El cliente vio la página de confirmación', $html);
    notContains('<strong>Sistema</strong>', $html, 'ninguna línea «Sistema» a secas');
    notContains('otra_marca_interna', $html);
    notContains('thanks_shown', $html);
});
