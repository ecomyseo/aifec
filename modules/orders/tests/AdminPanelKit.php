<?php
/**
 * AIFEC — ayudas de prueba del panel ordenado (menú con pestañas, «Formas de pago» e «Informes»). No es una prueba (no acaba en
 * «Test.php»): se carga con require_once.
 *
 *   panelKitForm(string $html, string $accion, ?array $boton = null, array $cambios = []): array
 *       Lo que manda un navegador al enviar el formulario cuyo «action» es esa ruta: campos ocultos y de texto, casillas y
 *       radios marcados (una casilla sin «value» manda «on»; una sin marcar no viaja), la opción elegida de cada lista, los
 *       textos largos y, si se da, el botón pulsado ([nombre, valor]). Los botones que no se pulsan no viajan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

if (!function_exists('panelKitForm')) {
    /**
     * @param array{0:string,1:string}|null $boton
     * @param array<string,mixed>           $cambios se funden encima de lo que manda el navegador
     *
     * @return array<string,mixed>
     */
    function panelKitForm(string $html, string $accion, ?array $boton = null, array $cambios = []): array
    {
        $doc = new \DOMDocument();
        $antes = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($antes);
        $form = null;
        foreach ($doc->getElementsByTagName('form') as $candidato) {
            $ruta = (string) parse_url(html_entity_decode($candidato->getAttribute('action'), ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
            if ($ruta === $accion) {
                $form = $candidato;
                break;
            }
        }
        check($form !== null, 'hay un formulario que envía a ' . $accion);
        $xpath = new \DOMXPath($doc);
        $pares = [];
        foreach ($xpath->query('.//input|.//select|.//textarea', $form) ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $nombre = $el->getAttribute('name');
            if ($nombre === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $tag = strtolower($el->nodeName);
            if ($tag === 'textarea') {
                $pares[] = [$nombre, (string) preg_replace('/^\r?\n/', '', $el->textContent)];
                continue;
            }
            if ($tag === 'select') {
                $elegidas = [];
                $primera = null;
                foreach ($xpath->query('.//option', $el) ?: [] as $opcion) {
                    if (!$opcion instanceof \DOMElement) {
                        continue;
                    }
                    $valor = $opcion->hasAttribute('value') ? $opcion->getAttribute('value') : trim($opcion->textContent);
                    if ($primera === null) {
                        $primera = $valor;
                    }
                    if ($opcion->hasAttribute('selected')) {
                        $elegidas[] = $valor;
                    }
                }
                if ($elegidas === [] && $primera !== null && !$el->hasAttribute('multiple')) {
                    $elegidas[] = $primera;
                }
                foreach ($elegidas as $valor) {
                    $pares[] = [$nombre, $valor];
                }
                continue;
            }
            $tipo = strtolower($el->getAttribute('type') !== '' ? $el->getAttribute('type') : 'text');
            if (in_array($tipo, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                continue;
            }
            if (in_array($tipo, ['checkbox', 'radio'], true)) {
                if ($el->hasAttribute('checked')) {
                    $pares[] = [$nombre, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];
                }
                continue;
            }
            $pares[] = [$nombre, $el->getAttribute('value')];
        }
        if ($boton !== null) {
            $pares[] = $boton;
        }
        $consulta = implode('&', array_map(static function (array $par): string {
            return rawurlencode((string) $par[0]) . '=' . rawurlencode((string) $par[1]);
        }, $pares));
        parse_str($consulta, $datos);

        return array_replace_recursive($datos, $cambios);
    }
}
