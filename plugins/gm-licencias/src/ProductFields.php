<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;

/**
 * La pestaña «Licences» de la ficha de producto del panel: tipo de licencia, dominios permitidos y meses de actualizaciones.
 *
 * Se engancha al filtro «admin.definitions» envolviendo la función `form` del recurso de productos. Los campos no se guardan en
 * la tabla de productos (`store` => false): los guarda este plugin en la suya cuando el panel avisa de que se ha guardado el
 * producto (`admin.resource.saved`).
 */
final class ProductFields
{
    /**
     * @param mixed $definitions
     *
     * @return mixed
     */
    public static function definitions($definitions)
    {
        if (!is_array($definitions) || !isset($definitions['catalog']['resources']['products']) || !is_array($definitions['catalog']['resources']['products'])) {
            return $definitions;
        }
        $previous = isset($definitions['catalog']['resources']['products']['form']) ? $definitions['catalog']['resources']['products']['form'] : null;
        $definitions['catalog']['resources']['products']['form'] = static function (App $app, ?int $id) use ($previous): array {
            $form = is_callable($previous) ? call_user_func($previous, $app, $id) : (is_array($previous) ? $previous : []);

            return self::addFields($app, is_array($form) ? $form : [], $id);
        };

        return $definitions;
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array<string,mixed>
     */
    public static function addFields(App $app, array $form, ?int $id): array
    {
        $config = $id !== null && $id > 0 ? Products::get($app, $id) : null;
        $tabs = isset($form['tabs']) && is_array($form['tabs']) ? $form['tabs'] : [];
        $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [];
        $tabs['gllicence'] = 'Licences';
        $fields['gm_lic_kind'] = [
            'type' => 'select', 'label' => 'Licence type', 'options' => Products::KINDS, 'tab' => 'gllicence', 'store' => false,
            'hint' => 'Each unit bought gets its own key when the order is paid.', 'default' => $config !== null ? $config['kind'] : 'none',
        ];
        $fields['gm_lic_domains'] = [
            'type' => 'number', 'label' => 'Domains allowed', 'hint' => 'How many shops can use one key. 0: no limit.', 'tab' => 'gllicence', 'store' => false,
            'default' => $config !== null ? $config['max_domains'] : 1, 'rules' => 'int|min:0|max:1000', 'width' => 'sm', 'show_if' => ['gm_lic_kind' => 'domain'],
        ];
        $fields['gm_lic_months'] = [
            'type' => 'number', 'label' => 'Months of updates', 'hint' => 'From the day it is bought. 0: updates with no end date.', 'tab' => 'gllicence', 'store' => false,
            'default' => $config !== null ? $config['update_months'] : 12, 'rules' => 'int|min:0|max:240', 'width' => 'sm', 'show_if' => ['gm_lic_kind' => ['domain', 'signed']],
        ];

        return ['tabs' => $tabs, 'fields' => $fields] + $form;
    }

    /**
     * El panel acaba de guardar algo («admin.resource.saved»): si es un producto con estos campos, se apuntan.
     *
     * @param mixed $resource
     * @param mixed $id
     * @param mixed $values
     */
    public static function saved($resource, $id, $values, App $app): void
    {
        if ($resource !== 'products' || !is_numeric($id) || !is_array($values) || !array_key_exists('gm_lic_kind', $values)) {
            return;
        }
        Products::set(
            $app,
            (int) $id,
            is_string($values['gm_lic_kind']) ? $values['gm_lic_kind'] : 'none',
            isset($values['gm_lic_domains']) && is_numeric($values['gm_lic_domains']) ? (int) $values['gm_lic_domains'] : 1,
            isset($values['gm_lic_months']) && is_numeric($values['gm_lic_months']) ? (int) $values['gm_lic_months'] : 12
        );
    }
}
