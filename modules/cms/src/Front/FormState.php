<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Lo que el bloque «form» enseña cuando una persona sin JavaScript se equivoca: los errores y lo que había escrito. Se guarda
 * en la sesión de cliente SOLO en ese caso (un envío bueno no deja nada) y se lee una sola vez, así que la página con los
 * errores no sale de la caché ni se repite al recargar.
 *
 * Cms::page() pinta el contenido más de una vez en la misma petición cuando la ficha no estaba en caché (al construirla y al
 * entregarla, porque un formulario no se congela): por eso lo leído se recuerda mientras dure ESA petición, y solo esa. Es la
 * única memoria de esta clase y se renueva en cuanto llega otra petición.
 */
final class FormState
{
    /** @var Request|null la petición a la que pertenece lo recordado */
    private static $request;

    /** @var array<string,array<string,mixed>|null> lo leído de la sesión en esta petición, por código de formulario */
    private static $read = [];

    /** Clave de la sesión de un formulario. */
    public static function key(string $code): string
    {
        return 'cms_form_' . $code;
    }

    /**
     * El filtro «cms.form.state» del bloque: añade los errores guardados de este formulario, si los hay.
     *
     * @param mixed               $state ['sent' => bool, 'errors' => [], 'old' => []]
     * @param array<string,mixed> $form  lo que da Cms::form()
     *
     * @return array<string,mixed>
     */
    public static function apply($state, $form, $app): array
    {
        $state = is_array($state) ? $state : [];
        if (!($app instanceof App) || !is_array($form) || !isset($form['code'])) {
            return $state;
        }
        $request = $app->request();
        if (self::$request !== $request) {
            self::$request = $request;
            self::$read = [];
        }
        $code = (string) $form['code'];
        if (!array_key_exists($code, self::$read)) {
            $session = $app->session('customer');
            $saved = $session->get(self::key($code));
            if (is_array($saved)) {
                $session->forget(self::key($code));
            }
            self::$read[$code] = is_array($saved) ? $saved : null;
        }
        $saved = self::$read[$code];
        if ($saved === null) {
            return $state;
        }
        $state['sent'] = false;
        $state['errors'] = isset($saved['errors']) && is_array($saved['errors']) ? $saved['errors'] : [];
        $state['old'] = isset($saved['old']) && is_array($saved['old']) ? $saved['old'] : [];

        return $state;
    }
}
