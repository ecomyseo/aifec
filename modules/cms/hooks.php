<?php
/**
 * AIFEC — contenidos: publica el servicio «cms», entrega los menús al tema, atiende las redirecciones y las direcciones que no
 * existen, aplica las propuestas de la IA sobre páginas y entradas, avisa en el panel de los mensajes sin leer y pinta en la tienda
 * las páginas, las entradas, las categorías y etiquetas y la portada (con los errores de un formulario sin JavaScript).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\AiApply;
use Aifec\Modules\Cms\BlocksEditor;
use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Cms\Front\FormState;
use Aifec\Modules\Cms\Front\HomeSection;
use Aifec\Modules\Cms\Front\PageController;
use Aifec\Modules\Cms\Redirects;

return static function (App $app): void {
    $app->bind('cms', static function (App $app): Cms {
        return new Cms($app);
    });

    // Los menús que compone el comerciante llegan al tema por el filtro «menu.items» (la función menu() de las plantillas).
    $app->hooks()->filter('menu.items', static function ($items, $location, $application): array {
        $items = is_array($items) ? $items : [];
        if (!($application instanceof App) || !is_string($location) || $location === '') {
            return $items;
        }
        /** @var Cms $cms */
        $cms = $application->service('cms');

        return array_merge($items, $cms->menu($location));
    });

    // Lo que ve el público: quién pinta cada entidad con dirección amigable, la portada y los errores de un formulario.
    $app->entity('page', [PageController::class, 'page']);
    $app->entity('post', [PageController::class, 'post']);
    $app->entity('term', [PageController::class, 'term']);
    $app->hooks()->filter('home.sections', [HomeSection::class, 'add']);
    $app->hooks()->filter('cms.form.state', [FormState::class, 'apply']);

    // La política de contenido de la tienda: el guion de los formularios va escrito en la página, así que lleva su huella.
    $app->hooks()->filter('csp', static function ($csp, $request = null) use ($app) {
        if (!($csp instanceof \Aifec\Kernel\Csp)) {
            return $csp;
        }
        if (!($request instanceof \Aifec\Kernel\Request) || strpos($request->path, '/admin') !== 0) {
            $js = \Aifec\Modules\Cms\Front\Support::formScriptJs($app);
            if ($js !== '') {
                $csp->inline($js);
            }
        }

        return $csp;
    });

    // El editor de bloques del panel (hoja de estilos, textos y guion) solo se carga donde puede haber un campo de bloques.
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        return BlocksEditor::assets($app);
    });

    // Redirecciones manuales y registro de lo que no existe (última oportunidad antes del 404).
    $app->hooks()->filter('request.not_found', [Redirects::class, 'handle']);

    // La IA propone y una persona aprueba: aquí se aplica lo aprobado sobre los campos de texto de páginas y entradas.
    $app->hooks()->filter('ai.apply', [AiApply::class, 'apply']);

    // Si cambian las palabras de las direcciones del blog, se rehacen las que ya existen (las antiguas siguen redirigiendo).
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module !== 'cms') {
            return;
        }
        foreach (['urls.post', 'urls.term_category', 'urls.term_tag'] as $key) {
            if (in_array($key, $keys, true)) {
                /** @var Cms $cms */
                $cms = $application->service('cms');
                $cms->rewriteAddresses();
                $application->invalidate(['cms']);

                return;
            }
        }
    });

    // Aviso del inicio del panel: mensajes de formularios sin leer.
    $app->hooks()->filter('admin.status', static function ($items): array {
        $items = is_array($items) ? $items : [];
        $application = App::current();
        if (!$application->auth()->can('content.view')) {
            return $items;
        }
        $new = (int) $application->db()->value("SELECT COUNT(*) FROM {form_entries} WHERE status = 'new'");
        if ($new > 0) {
            $items[] = [
                'kind' => 'info',
                'text' => tn('{n} new message in the forms', '{n} new messages in the forms', $new),
                'url' => $application->url()->plain('/admin/r/form_entries', ['f' => ['status' => 'new']]),
                'label' => t('Open'),
            ];
        }

        return $items;
    });

    // Ctrl + K del panel: páginas y entradas por título.
    $app->hooks()->filter('admin.palette', static function ($items, $q = ''): array {
        $items = is_array($items) ? $items : [];
        $q = is_string($q) ? trim($q) : '';
        $application = App::current();
        if ($q === '' || !$application->auth()->can('content.view')) {
            return $items;
        }
        $db = $application->db();
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $default = $application->defaultLang();
        foreach ($db->select("SELECT page_id AS id, title FROM {page_i18n} WHERE lang = ? AND title LIKE ? ESCAPE '!' ORDER BY title LIMIT 5", [$default, $like]) as $row) {
            $items[] = ['label' => (string) $row['title'], 'group' => t('Pages'), 'url' => $application->url()->plain('/admin/r/pages/' . (int) $row['id'])];
        }
        foreach ($db->select("SELECT post_id AS id, title FROM {post_i18n} WHERE lang = ? AND title LIKE ? ESCAPE '!' ORDER BY title LIMIT 5", [$default, $like]) as $row) {
            $items[] = ['label' => (string) $row['title'], 'group' => t('Posts'), 'url' => $application->url()->plain('/admin/r/posts/' . (int) $row['id'])];
        }

        return $items;
    });

    // La biblioteca de medios pregunta dónde se usa una imagen: aquí, como imagen destacada de una entrada.
    $app->hooks()->filter('media.usage', static function ($uses, $id = 0): array {
        $uses = is_array($uses) ? $uses : [];
        $application = App::current();
        foreach ($application->db()->select('SELECT x.title AS title FROM {posts} p JOIN {post_i18n} x ON x.post_id = p.id AND x.lang = ? WHERE p.media_id = ? LIMIT 10', [$application->defaultLang(), (int) $id]) as $row) {
            $uses[] = t('Post') . ': ' . (string) $row['title'];
        }

        return $uses;
    });
};
