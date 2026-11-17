<?php
/**
 * AIFEC — ayudas comunes de las pruebas del panel del catálogo (categorías, marcas, atributos, tipos y ajustes).
 * No contiene pruebas: lo cargan con require_once las demás del paquete. Termina en «Test.php» solo para quedar en la zona del paquete.
 *
 *   taxApp(array $ajustes = [], bool $dosIdiomas = false): App
 *       Aplicación de prueba con el catálogo y los medios, arrancada. Con $dosIdiomas, el sitio tiene «es» y «en».
 *   taxCategoryForm(array $cambios = []): array
 *       Campos del formulario de una categoría como los manda un navegador (los interruptores siempre viajan).
 *   taxSaveCategory(TestClient $web, array $form, ?int $id = null): Response
 *       Abre la pantalla de alta o de edición y envía el formulario con la ficha de esa página.
 *   taxMakeCategory(TestClient $web, string $nombre, ?int $madre = null, array $cambios = []): int
 *       Crea una categoría por el panel y devuelve su identificador.
 *   taxPost(TestClient $web, string $abrir, string $enviar, array $datos): Response
 *       Abre una pantalla (para tener su ficha) y envía un formulario a otra dirección.
 *   taxViewer(App $app): TestClient
 *       Persona que solo puede ver el catálogo (papel «viewer» con catalog.view).
 *   taxScalar(App $app, string $sql, array $params = []): int
 *       Primer valor de una consulta, como entero.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

if (!function_exists('taxApp')) {
    /**
     * @param array<string,mixed> $settings
     */
    function taxApp(array $settings = [], bool $twoLanguages = false): App
    {
        if ($twoLanguages) {
            $settings['i18n.languages'] = ['es', 'en'];
        }
        $options = ['modules' => ['catalog', 'media']];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function taxCategoryForm(array $over = []): array
    {
        return $over + [
            'name' => ['es' => 'Muebles'], 'slug' => ['es' => ''], 'parent_id' => '', 'active' => '1', 'in_menu' => '1', 'media_id' => '', 'position' => '0',
            'description' => ['es' => ''], 'meta_title' => ['es' => ''], 'meta_description' => ['es' => ''],
        ];
    }

    /**
     * @param array<string,mixed> $form
     */
    function taxSaveCategory(TestClient $web, array $form, ?int $id = null): Response
    {
        $web->get($id === null ? '/admin/r/categories/new' : '/admin/r/categories/' . $id);

        return $web->post('/admin/r/categories/' . ($id === null ? '' : $id . '/') . 'save', ['_token' => aifecToken($web), 'f' => $form]);
    }

    /**
     * @param array<string,mixed> $changes
     */
    function taxMakeCategory(TestClient $web, string $name, ?int $parent = null, array $changes = []): int
    {
        $form = taxCategoryForm($changes + ['name' => ['es' => $name], 'parent_id' => $parent !== null ? (string) $parent : '']);
        $response = taxSaveCategory($web, $form);
        same(302, $response->status, 'la categoría «' . $name . '» se crea');

        return (int) $web->app->db()->value('SELECT MAX(id) FROM {categories}');
    }

    /**
     * @param array<string,mixed> $data
     */
    function taxPost(TestClient $web, string $open, string $post, array $data): Response
    {
        $web->get($open);

        return $web->post($post, ['_token' => aifecToken($web)] + $data);
    }

    function taxViewer(App $app): TestClient
    {
        $app->db()->insert('roles', ['code' => 'viewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'catalog.view']), 'system' => 0]);

        return adminClient($app, 'viewer');
    }

    /**
     * @param array<int,mixed> $params
     */
    function taxScalar(App $app, string $sql, array $params = []): int
    {
        return (int) $app->db()->value($sql, $params);
    }
}
