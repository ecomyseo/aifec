<?php
/**
 * AIFEC — ayudas de las pruebas de la tienda del pedido (carrito, caja y página del pedido). No es una prueba (no acaba
 * en «Test.php»): se carga con require_once.
 *
 *   frontShop(array $opciones = []): App
 *       Como aifecOrderApp() pero con un transportista «std» de tarifas distintas por zona (Península 4,95 €, Baleares
 *       9,95 €, Canarias 15,95 €, Ceuta y Melilla 12,95 €) para que cambiar el código postal cambie el envío, y la forma
 *       de pago «gw» (aifecOrderPayment) lista para retocar. Acepta las opciones de aifecOrderApp().
 *
 *   frontProduct(App $app, array $datos = []): int
 *       Un producto físico de 121,00 € con IVA del 21 % y 2 kg, con las claves de aifecProduct() que se quieran cambiar.
 *
 *   frontLogin(TestClient $web, string $email = 'ana@cliente.test'): ?int
 *       Entra un cliente con cuenta (la que da aifecCartCustomer()) en el navegador de mentira.
 *
 *   frontAdd(TestClient $web, int $productId, int $qty = 1): void
 *       Añade al carrito como el formulario de la ficha (POST cart.add).
 *
 *   frontPairs(string $html): array
 *       Lo que mandaría un navegador al enviar el formulario de la caja: lista [nombre, valor] de sus campos (los
 *       desactivados no van; casillas y radios, solo los marcados; listas, la opción elegida o la primera).
 *
 *   frontForm(TestClient $web, array $cambios = [], ?string $html = null): array
 *       Pide la caja (o usa $html), recoge sus campos como un navegador y aplica $cambios [nombre => valor]; un valor null
 *       quita el campo (casilla sin marcar). Devuelve el POST ya anidado, listo para $web->post().
 *
 *   frontFill(array $extra = []): array
 *       Los campos de una entrega correcta en Madrid, en los nombres del formulario (shipping[campo]).
 *
 *   frontTotal(string $html): int
 *       Los céntimos del total del resumen (la primera fila de total con data-total).
 *
 *   frontButton(string $html): int
 *       Los céntimos del total que lleva el botón de pagar.
 *
 *   frontOrderPath(Response $r): array
 *       De una redirección a la página del pedido, [número, ficha]; falla si no lo es.
 *
 *   frontNode(string $html, string $xpath): ?DOMNode  /  frontNodes(string $html, string $xpath): array
 *       Consultas XPath sobre el HTML que sirve la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

if (!function_exists('frontShop')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function frontShop(array $opciones = []): App
    {
        $app = aifecOrderApp($opciones + ['carrier' => false]);
        $carrier = shippingAddCarrier($app, 'std', ['name' => 'Estándar', 'delay' => '24-48 h']);
        foreach (['Peninsula' => 495, 'Balearic Islands' => 995, 'Canary Islands' => 1595, 'Ceuta and Melilla' => 1295] as $zone => $price) {
            shippingAddRate($app, $carrier, shippingZoneId($app, $zone), 'weight', 0, 0, $price);
        }

        return $app;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function frontProduct(App $app, array $datos = []): int
    {
        return aifecProduct($app, $datos + ['name' => 'Mesa de roble', 'price' => 12100, 'tax_class_id' => 'standard', 'weight' => 2000, 'stock' => 10]);
    }

    /** Entra el cliente con cuenta en el navegador de mentira (dispara customer.login) y devuelve su id, o null si no entró. */
    function frontLogin(TestClient $web, string $email = 'ana@cliente.test'): ?int
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($email): ?int {
            return $app->service('customers')->login($email, 'clave-larga-1234');
        });
    }

    function frontAdd(TestClient $web, int $productId, int $qty = 1): void
    {
        $r = $web->post('/carrito/add', ['product_id' => (string) $productId, 'qty' => (string) $qty]);
        check($r->status === 302, 'el producto se añade al carrito (' . $r->status . ')');
    }

    function frontDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($before);

        return $dom;
    }

    /**
     * @return array<int,DOMNode>
     */
    function frontNodes(string $html, string $xpath): array
    {
        $found = [];
        foreach ((new DOMXPath(frontDom($html)))->query($xpath) as $node) {
            $found[] = $node;
        }

        return $found;
    }

    function frontNode(string $html, string $xpath): ?DOMNode
    {
        $nodes = frontNodes($html, $xpath);

        return $nodes !== [] ? $nodes[0] : null;
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    function frontPairs(string $html): array
    {
        $x = new DOMXPath(frontDom($html));
        $pairs = [];
        $form = '//form[@data-af-checkout-form]';
        foreach ($x->query($form . '//input | ' . $form . '//select | ' . $form . '//textarea') as $el) {
            /** @var DOMElement $el */
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $tag = $el->nodeName;
            if ($tag === 'select') {
                $value = null;
                $first = null;
                foreach ($x->query('.//option', $el) as $option) {
                    /** @var DOMElement $option */
                    $first = $first === null ? $option->getAttribute('value') : $first;
                    if ($option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
                $pairs[] = [$name, $value !== null ? $value : (string) $first];
            } elseif ($tag === 'textarea') {
                $pairs[] = [$name, $el->textContent];
            } else {
                $type = strtolower($el->getAttribute('type'));
                if ($type === 'checkbox' || $type === 'radio') {
                    if ($el->hasAttribute('checked')) {
                        $pairs[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];
                    }
                } elseif ($type !== 'submit' && $type !== 'button') {
                    $pairs[] = [$name, $el->getAttribute('value')];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array<string,string|null> $cambios
     *
     * @return array<string,mixed>
     */
    function frontForm(TestClient $web, array $cambios = [], ?string $html = null): array
    {
        if ($html === null) {
            $r = $web->get('/finalizar-compra');
            check($r->status === 200, 'la caja se abre (' . $r->status . ')');
            $html = $r->body;
        }
        $pairs = frontPairs($html);
        foreach ($cambios as $name => $value) {
            $name = (string) $name;
            $pairs = array_values(array_filter($pairs, static function (array $pair) use ($name): bool {
                return $pair[0] !== $name;
            }));
            if ($value !== null) {
                $pairs[] = [$name, (string) $value];
            }
        }
        $query = [];
        foreach ($pairs as $pair) {
            $query[] = rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]);
        }
        $post = [];
        parse_str(implode('&', $query), $post);

        return $post;
    }

    /**
     * @param array<string,string> $extra
     *
     * @return array<string,string>
     */
    function frontFill(array $extra = []): array
    {
        return $extra + [
            'email' => 'ana@cliente.test', 'shipping[first_name]' => 'Ana', 'shipping[last_name]' => 'Ruiz', 'shipping[phone]' => '999345678',
            'shipping[line1]' => 'Calle Mayor 1', 'shipping[postcode]' => '28001', 'shipping[city]' => 'Madrid', 'shipping[country]' => 'ES',
            'accept_terms' => '1',
        ];
    }

    function frontTotal(string $html): int
    {
        check(preg_match('/af-totals-total"><dt>[^<]*<\/dt><dd data-total="(-?\d+)"/', $html, $m) === 1, 'el resumen trae su total');

        return (int) $m[1];
    }

    function frontButton(string $html): int
    {
        check(preg_match('/data-af-checkout-submit data-total="(\d+)"/', $html, $m) === 1, 'la caja trae el botón de pagar con su total');

        return (int) $m[1];
    }

    /**
     * @return array{0:string,1:string}
     */
    function frontOrderPath(Response $r): array
    {
        $location = isset($r->headers['Location']) ? $r->headers['Location'] : '';
        check(preg_match('#/pedido/([^/?]+)/([^/?]+)#', $location, $m) === 1, 'lleva a la página del pedido: «' . $location . '» (' . $r->status . ')');

        return [$m[1], $m[2]];
    }
}
