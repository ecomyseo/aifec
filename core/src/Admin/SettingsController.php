<?php
/**
 * AIFEC — pantallas de ajustes. Cada módulo declara los suyos en settings.php y aquí se pintan como UN formulario
 * con pestañas y un solo botón de guardar; al guardar se vuelve a la misma pestaña.
 *
 * settings.php:
 *   return ['title' => 'Coupons', 'description' => 'Discount codes', 'icon' => 'tag', 'cap' => 'marketing.settings',
 *           'tabs' => ['cpgeneral' => ['label' => 'General', 'fields' => ['coupons.enabled' => ['type' => 'toggle', …]]]]];
 * Los campos «secret» (claves de API, contraseñas) se guardan cifrados y nunca vuelven a la pantalla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class SettingsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Índice: una tarjeta por cada grupo de ajustes que la persona puede ver. */
    public function index(Request $request, array $p): Response
    {
        $groups = [];
        foreach ($this->app->modules()->definitions('settings') as $module => $definition) {
            if (!isset($definition['tabs']) || !$this->app->auth()->can($this->cap($definition))) {
                continue;
            }
            $groups[] = [
                'module' => $module,
                'title' => t(isset($definition['title']) ? (string) $definition['title'] : ucfirst((string) $module)),
                'description' => isset($definition['description']) ? t((string) $definition['description']) : '',
                'icon' => isset($definition['icon']) ? (string) $definition['icon'] : 'settings',
                'order' => isset($definition['order']) ? (int) $definition['order'] : ($module === 'core' ? 0 : 100),
                'url' => $this->app->url()->plain('/admin/settings/' . $module),
            ];
        }
        // Lo que una dueña busca en «Ajustes» aunque viva en su propia pantalla (14/11/2026: «no aparece aspecto, tema ni diseño», «no sé
        // dónde se dan de alta las formas de pago de verdad»): un acceso más, con su nombre de la calle.
        $more = [
            ['appearance.view', '/admin/themes', 'Look of the shop', 'Theme, colours, logo and the home page.', 'palette'],
            ['content.view', '/admin/cms/menus', 'Menus', 'The links of the header and the footer.', 'menu'],
            ['orders.edit', '/admin/payments', 'Payment methods', 'Card, PayPal, transfer, cash on delivery: turn on the ones you use.', 'card'],
        ];
        foreach ($more as $item) {
            try {
                $exists = $this->app->router()->match('GET', $item[1]) !== null;
            } catch (\Throwable $e) {
                $exists = false;
            }
            if ($exists && $this->app->auth()->can($item[0])) {
                $groups[] = ['module' => '', 'title' => t($item[2]), 'description' => t($item[3]), 'icon' => $item[4], 'order' => 90, 'url' => $this->app->url()->plain($item[1])];
            }
        }
        usort($groups, static function (array $a, array $b): int {
            return $a['order'] === $b['order'] ? strcmp($a['title'], $b['title']) : $a['order'] - $b['order'];
        });

        return Admin::page($this->app, 'core::admin/settings', ['groups' => $groups], ['title' => t('Settings')]);
    }

    /** @param array<string,string> $p */
    public function edit(Request $request, array $p): Response
    {
        list($definition, $form) = $this->load((string) $p['module']);

        return $this->screen((string) $p['module'], $definition, $form, $this->values($form), [], $request);
    }

    /** @param array<string,string> $p */
    public function save(Request $request, array $p): Response
    {
        $module = (string) $p['module'];
        list($definition, $form) = $this->load($module);
        list($values, $errors) = $form->validate($request);
        if ($errors !== []) {
            return $this->screen($module, $definition, $form, $values + $this->values($form), $errors, $request, 422);
        }
        $settings = $this->app->settings();
        $changed = [];
        $plain = [];
        if ($this->app->site()->isDemo() && $module === 'ai') {
            // Cambiar el destino de un proveedor nunca arrastra una clave heredada hacia otro servicio.
            foreach (['economy', 'standard', 'advanced'] as $tier) {
                $prefix = 'ai.' . $tier . '.';
                foreach (['provider', 'base_url'] as $part) {
                    $key = $prefix . $part;
                    if (array_key_exists($key, $values) && $settings->get($key) !== $values[$key] && !isset($values[$prefix . 'key'])) {
                        $values[$prefix . 'key'] = '';
                    }
                }
            }
        }
        foreach ($form->fields() as $key => $field) {
            if (!array_key_exists($key, $values) || (isset($field['store']) && $field['store'] === false)) {
                continue;
            }
            if ($field['type'] === 'secret') {
                $this->app->setSecret($key, (string) $values[$key]);
                $changed[$key] = ['***', '***'];
                continue;
            }
            $before = $settings->get($key);
            if ($before !== $values[$key]) {
                $changed[$key] = [is_scalar($before) ? (string) $before : '[…]', is_scalar($values[$key]) ? (string) $values[$key] : '[…]'];
                $plain[$key] = $values[$key];
            }
        }
        if ($plain !== []) {
            $settings->setMany($plain);
        }
        if ($changed !== []) {
            Admin::audit($this->app, 'settings', 'settings', $module, implode(', ', array_slice(array_keys($changed), 0, 8)), $changed);
            $this->app->invalidate(['settings', 'pages']);
            $this->app->hooks()->fire('settings.saved', $module, array_keys($changed), $this->app);
        }
        Admin::flash($this->app, 'ok', t('Settings saved'));
        $tab = preg_replace('/[^a-zA-Z0-9_-]/', '', $request->str('_tab'));

        return Response::redirect($this->app->url()->plain('/admin/settings/' . $module) . ($tab !== '' ? '#tab=' . $tab : ''));
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @return array{0:array<string,mixed>,1:Form}
     */
    private function load(string $module): array
    {
        $definition = $module === 'core' || $this->app->modules()->isLoaded($module) ? $this->app->modules()->definition($module, 'settings') : [];
        if (!isset($definition['tabs']) || !is_array($definition['tabs'])) {
            throw HttpError::notFound();
        }
        $this->app->auth()->requireCap($this->cap($definition));
        $tabs = [];
        $fields = [];
        foreach ($definition['tabs'] as $key => $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $tabs[(string) $key] = isset($tab['label']) ? (string) $tab['label'] : (string) $key;
            foreach (isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [] as $name => $field) {
                if (is_array($field)) {
                    $fields[(string) $name] = $field + ['tab' => (string) $key];
                    $fields[(string) $name]['tab'] = (string) $key;
                }
            }
        }

        return [$definition, new Form($this->app, ['tabs' => $tabs, 'fields' => $fields])];
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function cap(array $definition): string
    {
        return isset($definition['cap']) ? (string) $definition['cap'] : 'settings.edit';
    }

    /**
     * @return array<string,mixed>
     */
    private function values(Form $form): array
    {
        $settings = $this->app->settings();
        $values = [];
        foreach ($form->fields() as $key => $field) {
            if (in_array($field['type'], ['html', 'heading'], true)) {
                continue;
            }
            // De un secreto solo se dice si está puesto.
            $values[$key] = $field['type'] === 'secret' ? ($settings->str($key) !== '' ? '1' : '') : $settings->get($key, array_key_exists('default', $field) ? $field['default'] : null);
        }

        return $values;
    }

    /**
     * @param array<string,mixed>  $definition
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     */
    private function screen(string $module, array $definition, Form $form, array $values, array $errors, Request $request, int $status = 200): Response
    {
        $html = $form->render($values, $errors, [
            'action' => $this->app->url()->plain('/admin/settings/' . $module),
            'cancel' => $this->app->url()->plain('/admin/settings'),
            'tab' => $request->str('_tab'),
        ]);

        return Admin::html($this->app, $html, [
            'title' => t(isset($definition['title']) ? (string) $definition['title'] : ucfirst($module)),
            'crumbs' => [[t('Settings'), $this->app->url()->plain('/admin/settings')]],
            'help' => isset($definition['help']) ? Admin::helpText((string) $definition['help']) : '',
            // La entrada de este módulo si el menú la tiene (o su pestaña); si no, «Todos los ajustes», que es su prefijo.
            'active' => '/admin/settings/' . $module,
        ], $status);
    }
}
