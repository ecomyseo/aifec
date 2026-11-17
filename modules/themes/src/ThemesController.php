<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las pantallas del panel «Diseño»: la galería de temas, el personalizador con su vista previa, la portada, el logotipo y
 * el icono, el asistente de creación y el informe del validador. Ver = appearance.view; todo lo demás = appearance.edit.
 */
final class ThemesController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function themes(): Themes
    {
        /** @var Themes $themes */
        $themes = $this->app->service('themes');

        return $themes;
    }

    private function panelUrl(string $path, array $query = []): string
    {
        return $this->app->url()->plain($path, $query);
    }

    private function slug(array $params): string
    {
        $slug = isset($params['slug']) ? (string) $params['slug'] : '';
        if (!$this->themes()->exists($slug)) {
            throw HttpError::notFound();
        }

        return $slug;
    }

    // ------------------------------------------------------------------ galería

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');
        $canEdit = $this->app->auth()->can('appearance.edit');
        $canCreate = $this->app->auth()->can('appearance.create');
        $actions = $canCreate ? '<a class="af-btn af-btn-primary" href="' . e($this->panelUrl('/admin/themes/new')) . '">' . Admin::icon('plus') . e(t('New theme')) . '</a>' : '';

        return Admin::page($this->app, 'themes::admin/index', [
            'rows' => $this->themes()->all(),
            'canEdit' => $canEdit,
        ], [
            'title' => t('Themes'),
            'active' => '/admin/themes',
            'actions' => $actions,
            'help' => Admin::helpText("A theme sets the look of the shop. A child theme inherits everything from another theme and only changes what you ask it to.\nA theme with blocking errors cannot be activated: open its report to see what has to be fixed.\nCustomise lets you change colours, fonts and layout and see the result before publishing it."),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function previewImage(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');
        $file = $this->themes()->previewFile($this->slug($params));
        if ($file === null) {
            throw HttpError::notFound();
        }

        return Response::file($file, 'preview.png', 'image/png', true);
    }

    /**
     * @param array<string,string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');
        $slug = $this->slug($params);
        $row = $this->themes()->find($slug);

        return Admin::page($this->app, 'themes::admin/report', [
            'row' => $row,
            'report' => $this->themes()->validate($slug, true),
            'canEdit' => $this->app->auth()->can('appearance.edit'),
        ], [
            'title' => t('Theme report'),
            'crumbs' => [[t('Themes'), $this->panelUrl('/admin/themes')]],
            'active' => '/admin/themes',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function activate(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.edit');
        $slug = $this->slug($params);
        try {
            $this->themes()->activate($slug);
        } catch (ThemeException $e) {
            Admin::flash($this->app, 'bad', t($e->getMessage()));

            return Response::redirect($this->panelUrl('/admin/themes/' . $slug . '/report'));
        }
        Admin::audit($this->app, 'theme.activate', 'theme', $slug, 'Theme activated');
        Admin::flash($this->app, 'ok', t('Theme activated'));

        return Response::redirect($this->panelUrl('/admin/themes'));
    }

    // ------------------------------------------------------------------ personalizador

    /**
     * @param array<string,string> $params
     */
    public function customise(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');
        $slug = $this->slug($params);
        $options = $this->themes()->options($slug);
        $values = $options['values'];
        $draft = Preview::draft($this->app, $request, $slug);
        if ($draft !== null) {
            $values = array_merge($values, $draft['values']);
        }

        return $this->customiseScreen($request, $slug, $values, [], $request->str('tab'));
    }

    /**
     * @param array<string,string> $params
     */
    public function customiseSave(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.edit');
        $slug = $this->slug($params);
        $themes = $this->themes();
        $action = $request->str('do');
        $tab = $request->str('_tab');
        $back = $this->panelUrl('/admin/themes/customise/' . $slug, $tab !== '' ? ['tab' => $tab] : []);

        if ($action === 'reset') {
            $themes->resetOptions($slug);
            Preview::forget($this->app);
            Admin::audit($this->app, 'theme.reset', 'theme', $slug, 'Theme options reset');
            Admin::flash($this->app, 'ok', t('The factory values are back'));

            return Response::redirect($back);
        }

        $fields = $themes->fields($slug)['fields'];
        $posted = $this->posted($request->arr('f'), $fields);

        if ($action === 'preview') {
            $checked = $themes->check($slug, $posted);
            Preview::start($this->app, $slug, $checked['clean']);
            Admin::flash($this->app, $checked['errors'] === [] ? 'ok' : 'warn', $checked['errors'] === [] ? t('Preview updated') : t('Some values are not valid and were left out of the preview'));

            return Response::redirect($back);
        }

        $before = $themes->options($slug)['values'];
        $errors = $themes->saveOptions($slug, $posted);
        Preview::forget($this->app);
        $after = $themes->options($slug)['values'];
        $changes = [];
        foreach ($after as $key => $value) {
            if ((string) $before[$key] !== (string) $value) {
                $changes[$key] = [$before[$key], $value];
            }
        }
        Admin::audit($this->app, 'theme.options', 'theme', $slug, 'Theme options published', $changes);
        if ($errors === []) {
            Admin::flash($this->app, 'ok', t('Published'));

            return Response::redirect($back);
        }

        return $this->customiseScreen($request, $slug, array_merge($after, $posted), $errors, $tab);
    }

    /**
     * Valores del formulario listos para validar: un campo de imagen llega como lista de identificadores separados por comas.
     *
     * @param array<mixed,mixed>                $posted
     * @param array<string,array<string,mixed>> $fields
     *
     * @return array<string,mixed>
     */
    private function posted(array $posted, array $fields): array
    {
        $out = [];
        foreach ($posted as $key => $value) {
            $key = (string) $key;
            if (!isset($fields[$key]) || is_array($value)) {
                continue;
            }
            if ($fields[$key]['type'] === 'image') {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $value))));
                $value = $ids !== [] ? $ids[0] : 0;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     */
    private function customiseScreen(Request $request, string $slug, array $values, array $errors, string $tab): Response
    {
        $themes = $this->themes();
        // Las etiquetas de las opciones las traduce el tema en su lang/. Si el que se personaliza no es el tema activo, su
        // catálogo no está cargado y saldrían en inglés: se carga el de toda su cadena (el suyo, el último: sus textos mandan).
        $themesDir = rtrim(str_replace('\\', '/', $this->app->root()), '/') . '/themes/';
        foreach (array_reverse((array) $themes->chain($slug)['chain']) as $one) {
            if (is_string($one) && is_dir($themesDir . $one . '/lang')) {
                $this->app->i18n()->addDir($themesDir . $one . '/lang');
            }
        }
        $options = $themes->options($slug);
        $tabs = [];
        $formFields = [];
        foreach ($options['fields'] as $key => $field) {
            $tabKey = 'th' . $field['group'];
            if (!isset($tabs[$tabKey])) {
                $tabs[$tabKey] = isset($options['groups'][$field['group']]) ? $options['groups'][$field['group']] : (string) $field['group'];
            }
            $formFields[$key] = $this->formField($field, $tabKey);
        }
        $form = new Form($this->app, ['tabs' => $tabs, 'fields' => $formFields]);
        $errorTabs = [];
        foreach (array_keys($errors) as $key) {
            if (isset($formFields[$key])) {
                $errorTabs[$formFields[$key]['tab']] = (isset($errorTabs[$formFields[$key]['tab']]) ? $errorTabs[$formFields[$key]['tab']] : 0) + 1;
            }
        }
        $active = isset($tabs[$tab]) ? $tab : ($errorTabs !== [] ? (string) array_keys($errorTabs)[0] : (string) (array_keys($tabs)[0] ?? ''));
        $row = $themes->find($slug);
        $isActive = $row !== null && $row['active'];

        $pages = [['label' => t('Home page'), 'url' => url('/')]];
        $router = $this->app->router();
        if ($router->has('cart')) {
            $pages[] = ['label' => t('Cart'), 'url' => route('cart')];
        }
        foreach (['category' => t('A category'), 'product' => t('A product')] as $type => $label) {
            try {
                $hit = $this->app->db()->row('SELECT entity_id FROM {slugs} WHERE type = ? AND lang = ? AND canonical = 1 ORDER BY entity_id', [$type, $this->app->defaultLang()]);
            } catch (\Throwable $e) {
                $hit = null;
            }
            if ($hit !== null) {
                $pages[] = ['label' => $label, 'url' => $this->app->url()->entity($type, (int) $hit['entity_id'])];
            }
        }

        $html = Admin::page($this->app, 'themes::admin/customise', [
            'row' => $row,
            'slug' => $slug,
            'fieldsHtml' => $form->renderFields($values, $errors, $active, $errorTabs),
            'active' => $active,
            'isActive' => $isActive,
            'canEdit' => $this->app->auth()->can('appearance.edit'),
            'pages' => $pages,
            'previewParam' => Preview::PARAM,
            'action' => $this->panelUrl('/admin/themes/customise/' . $slug),
            'hasDraft' => Preview::draft($this->app, $request, $slug) !== null,
        ], [
            'title' => t('Customise') . ' · ' . ($row !== null ? $row['name'] : $slug),
            'crumbs' => [[t('Themes'), $this->panelUrl('/admin/themes')]],
            'active' => '/admin/themes',
        ]);

        return $html;
    }

    /**
     * Un campo de theme.json como campo del formulario del panel.
     *
     * @param array<string,mixed> $field
     *
     * @return array<string,mixed>
     */
    private function formField(array $field, string $tab): array
    {
        $def = ['label' => (string) $field['label'], 'tab' => $tab, 'default' => $field['default']];
        switch ((string) $field['type']) {
            case 'color':
                $def['type'] = 'color';
                break;
            case 'number':
                $def['type'] = 'number';
                foreach (['min', 'max'] as $bound) {
                    if (isset($field[$bound]) && is_numeric($field[$bound])) {
                        $def[$bound] = $field[$bound];
                    }
                }
                if (isset($field['unit']) && is_string($field['unit'])) {
                    $def['suffix'] = $field['unit'];
                }
                break;
            case 'toggle':
                $def['type'] = 'toggle';
                break;
            case 'select':
            case 'font':
                $def['type'] = 'select';
                $def['options'] = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                break;
            case 'image':
                $def['type'] = $this->app->has('media') ? 'image' : 'number';
                break;
            default:
                $def['type'] = 'text';
        }

        return $def;
    }

    // ------------------------------------------------------------------ portada

    /**
     * @param array<string,string> $params
     */
    public function home(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');

        return Admin::page($this->app, 'themes::admin/home', [
            'sections' => $this->themes()->homeSections(),
            'canEdit' => $this->app->auth()->can('appearance.edit'),
            'action' => $this->panelUrl('/admin/themes/home'),
        ], [
            'title' => t('Home page'),
            'active' => '/admin/themes/home',
            'help' => Admin::helpText("The sections of the home page come from the modules. Here you choose their order and hide the ones you do not want.\nA hidden section is not deleted: tick it again to show it."),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function homeSave(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.edit');
        $order = array_values(array_filter(array_map('strval', array_filter($request->arr('order'), 'is_scalar'))));
        $visible = array_values(array_filter(array_map('strval', array_filter($request->arr('show'), 'is_scalar'))));
        $move = $request->str('move');
        if ($move !== '' && preg_match('/^(.+):(up|down)$/', $move, $m) === 1) {
            $at = array_search($m[1], $order, true);
            if ($at !== false) {
                $to = $m[2] === 'up' ? $at - 1 : $at + 1;
                if (isset($order[$to])) {
                    $swap = $order[$to];
                    $order[$to] = $order[$at];
                    $order[$at] = $swap;
                }
            }
        }
        $this->themes()->saveHome($order, $visible);
        Admin::audit($this->app, 'theme.home', 'home', '', 'Home sections changed');
        Admin::flash($this->app, 'ok', t('Saved'));

        return Response::redirect($this->panelUrl('/admin/themes/home'));
    }

    // ------------------------------------------------------------------ logotipo e icono

    /**
     * @param array<string,string> $params
     */
    public function brand(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.view');

        return $this->brandScreen();
    }

    private function brandScreen(): Response
    {
        $hasMedia = $this->app->has('media');
        $form = new Form($this->app, ['fields' => [
            'logo' => ['type' => 'image', 'label' => 'Logo', 'hint' => 'Shown in the header instead of the shop name. Leave it empty to show the name.'],
            'icon' => ['type' => 'image', 'label' => 'Site icon', 'hint' => 'The small icon of the browser tab. Square, 180 px or more.'],
        ]]);
        $values = ['logo' => $this->app->settings()->int('store.logo'), 'icon' => $this->app->settings()->int('store.icon')];

        return Admin::page($this->app, 'themes::admin/brand', [
            'fieldsHtml' => $hasMedia ? $form->renderFields($values) : '',
            'hasMedia' => $hasMedia,
            'canEdit' => $this->app->auth()->can('appearance.edit'),
            'action' => $this->panelUrl('/admin/themes/brand'),
        ], [
            'title' => t('Logo and icon'),
            'active' => '/admin/themes/brand',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function brandSave(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.edit');
        $posted = $request->arr('f');
        foreach (['logo' => 'store.logo', 'icon' => 'store.icon'] as $field => $setting) {
            $ids = array_values(array_filter(array_map('intval', explode(',', isset($posted[$field]) && is_scalar($posted[$field]) ? (string) $posted[$field] : ''))));
            $this->app->settings()->set($setting, $ids !== [] ? $ids[0] : 0);
        }
        $this->app->invalidate(['pages', 'home', 'settings']);
        Admin::audit($this->app, 'theme.brand', 'settings', 'store', 'Logo and icon changed');
        Admin::flash($this->app, 'ok', t('Saved'));

        return Response::redirect($this->panelUrl('/admin/themes/brand'));
    }

    // ------------------------------------------------------------------ asistente de creación

    /**
     * @param array<string,string> $params
     */
    public function newForm(Request $request, array $params): Response
    {
        // Crear un tema escribe ficheros en el servidor: es un permiso aparte de personalizar (una demostración pública no lo tiene).
        $this->app->auth()->requireCap('appearance.create');

        return $this->wizard(['parent' => 'base'], [], [], '');
    }

    /**
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('appearance.create');
        $values = [];
        foreach ($request->arr('f') as $key => $value) {
            if (is_scalar($value)) {
                $values[(string) $key] = trim((string) $value);
            }
        }
        $themes = $this->themes();

        if ($request->str('do') === 'suggest') {
            $parent = isset($values['parent']) && $themes->exists($values['parent']) ? $values['parent'] : 'base';
            $suggestion = $themes->suggestPalette(isset($values['describe']) ? $values['describe'] : '', $parent);
            foreach ($suggestion['palette'] as $key => $value) {
                $values[$key] = $value;
            }
            $notes = [];
            foreach ($suggestion['notes'] as $note) {
                $notes[] = t($note);
            }
            if ($suggestion['error'] !== '') {
                $notes[] = t($suggestion['error']);
            }

            return $this->wizard($values, [], $notes, $suggestion['palette'] !== [] ? t('Review the proposed colours and the font before creating the theme.') : '');
        }

        try {
            $slug = $themes->generate($values);
        } catch (ThemeException $e) {
            return $this->wizard($values, ['_form' => t($e->getMessage())], [], '');
        }
        Admin::audit($this->app, 'theme.create', 'theme', $slug, 'Theme created');
        Admin::flash($this->app, 'ok', t('Theme created'));

        return Response::redirect($this->panelUrl('/admin/themes/customise/' . $slug));
    }

    /**
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     * @param string[]             $notes
     */
    private function wizard(array $values, array $errors, array $notes, string $info): Response
    {
        $themes = $this->themes();
        $parents = [];
        foreach ($themes->all() as $row) {
            $parents[$row['slug']] = $row['name'];
        }
        $parent = isset($values['parent']) && $themes->exists((string) $values['parent']) ? (string) $values['parent'] : 'base';
        $theme = $themes->resolve($parent);
        $fonts = [];
        foreach (isset($theme['font_stacks']) && is_array($theme['font_stacks']) ? array_keys($theme['font_stacks']) : [] as $font) {
            $fonts[(string) $font] = (string) $font;
        }
        $aiAvailable = $themes->suggestPalette('', $parent)['available'];
        $fields = [
            'slug' => ['type' => 'text', 'label' => 'Identifier', 'hint' => 'Lowercase letters, digits and hyphens. It names the folder of the theme.', 'placeholder' => 'my-theme'],
            'name' => ['type' => 'text', 'label' => 'Name'],
            'parent' => ['type' => 'select', 'label' => 'Inherits from', 'options' => $parents],
            'header' => ['type' => 'select', 'label' => 'Header', 'options' => ['classic' => 'Classic', 'centered' => 'Centred'], 'empty' => 'As the parent theme'],
            'cards' => ['type' => 'select', 'label' => 'Product card image', 'options' => ['square' => 'Square', 'portrait' => 'Portrait'], 'empty' => 'As the parent theme'],
            'columns' => ['type' => 'select', 'label' => 'Product columns', 'options' => ['3' => '3 columns', '4' => '4 columns'], 'empty' => 'As the parent theme'],
            'bg' => ['type' => 'color', 'label' => 'Page background', 'default' => ''],
            'accent' => ['type' => 'color', 'label' => 'Accent colour', 'default' => ''],
            'font' => ['type' => 'select', 'label' => 'Font', 'options' => $fonts, 'empty' => 'As the parent theme'],
        ];
        if ($aiAvailable) {
            $fields['describe'] = ['type' => 'textarea', 'label' => 'Describe your shop', 'hint' => 'The AI proposes a palette and a font; you review them before creating the theme.'];
        }
        $form = new Form($this->app, ['fields' => $fields]);

        return Admin::page($this->app, 'themes::admin/new', [
            'fieldsHtml' => $form->renderFields($values, $errors),
            'error' => isset($errors['_form']) ? $errors['_form'] : '',
            'notes' => $notes,
            'info' => $info,
            'aiAvailable' => $aiAvailable,
            'action' => $this->panelUrl('/admin/themes/new'),
        ], [
            'title' => t('New theme'),
            'crumbs' => [[t('Themes'), $this->panelUrl('/admin/themes')]],
            'active' => '/admin/themes/new',
            'help' => Admin::helpText("The new theme is a child of the theme you choose: it inherits everything and only changes what you pick here.\nColours that do not reach AA contrast are adjusted; the ones that cannot be adjusted are rejected.\nThe same can be done from the console: php aifec theme:create <identifier>."),
        ]);
    }
}
