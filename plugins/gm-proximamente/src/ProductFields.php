<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;

/**
 * La pestaña «Coming soon» de la ficha de producto del panel.
 *
 * Se engancha al filtro «admin.definitions» envolviendo la función `form` del recurso de productos (que es una función, no un
 * array: ProductForm::definition). Los campos no se guardan en la tabla de productos (`store` => false): los guarda este plugin en
 * la suya cuando el panel avisa de que se ha guardado el producto (`admin.resource.saved`).
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
            $form = is_array($form) ? $form : [];

            return self::addFields($app, $form, $id);
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
        $state = $id !== null && $id > 0 ? (new Soon($app))->state($id) : ['soon' => false, 'release_at' => ''];
        $tabs = isset($form['tabs']) && is_array($form['tabs']) ? $form['tabs'] : [];
        $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [];
        $tabs['gpssoon'] = 'Coming soon';
        $fields['gm_soon'] = [
            'type' => 'toggle', 'label' => 'Coming soon', 'hint' => 'Visible in the shop but it cannot be bought. Visitors can ask to be told when it is available.',
            'tab' => 'gpssoon', 'store' => false, 'default' => $state['soon'] ? 1 : 0,
        ];
        $fields['gm_soon_release'] = [
            'type' => 'datetime', 'label' => 'Available from', 'hint' => 'Optional. On that date it stops being «coming soon» by itself and the people who asked are told.',
            'tab' => 'gpssoon', 'store' => false, 'default' => $state['release_at'], 'show_if' => ['gm_soon' => '1'],
        ];

        return ['tabs' => $tabs, 'fields' => $fields] + $form;
    }

    /**
     * El panel acaba de guardar algo («admin.resource.saved»): si es un producto con estos campos, se apunta y, si deja de estar
     * «próximamente», se avisa a quien lo pidió.
     *
     * @param mixed $resource
     * @param mixed $id
     * @param mixed $values lo validado del formulario
     */
    public static function saved($resource, $id, $values, App $app): void
    {
        if ($resource !== 'products' || !is_numeric($id) || !is_array($values) || !array_key_exists('gm_soon', $values)) {
            return;
        }
        $release = isset($values['gm_soon_release']) && is_string($values['gm_soon_release']) ? trim($values['gm_soon_release']) : '';
        (new Soon($app))->set((int) $id, !empty($values['gm_soon']), $release);
    }
}
