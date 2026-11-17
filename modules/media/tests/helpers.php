<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Media\Library;

if (!function_exists('mediaApp')) {
    /**
     * Aplicación de prueba con el módulo de medios (y los que se pidan), ya arrancada.
     *
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     */
    function mediaApp(array $modules = ['media'], array $settings = []): App
    {
        $options = ['modules' => $modules];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    function mediaLib(App $app): Library
    {
        /** @var Library $library */
        $library = $app->service('media');

        return $library;
    }

    /** Imagen de prueba creada con GD en una carpeta temporal; la semilla cambia los colores (y por tanto el resumen). */
    function mediaImage(int $width, int $height, string $format = 'jpeg', int $seed = 1, string $name = 'foto'): string
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = (int) imagecolorallocate($img, ($seed * 53) % 256, ($seed * 97) % 256, ($seed * 191) % 256);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        $fg = (int) imagecolorallocate($img, 255 - (($seed * 53) % 256), 200, 40);
        imagefilledellipse($img, intdiv($width, 2), intdiv($height, 2), max(2, intdiv($width, 2)), max(2, intdiv($height, 2)), $fg);
        $path = tmpDir('media-img') . '/' . $name . '-' . $seed . ($format === 'png' ? '.png' : '.jpg');
        if ($format === 'png') {
            imagepng($img, $path);
        } else {
            imagejpeg($img, $path, 90);
        }
        imagedestroy($img);

        return $path;
    }

    /** Fichero de prueba con ese contenido. */
    function mediaFile(string $content, string $name): string
    {
        $path = tmpDir('media-file') . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Todos los ficheros bajo una carpeta, como rutas relativas ordenadas (vacío si la carpeta no existe).
     *
     * @return string[]
     */
    function mediaScan(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $out[] = ltrim(substr(str_replace('\\', '/', (string) $file), strlen(rtrim(str_replace('\\', '/', $dir), '/'))), '/');
        }
        sort($out);

        return $out;
    }

    /** Carpeta de medios del sitio de prueba. */
    function mediaRoot(App $app): string
    {
        return rtrim(str_replace('\\', '/', $app->site()->mediaDir($app->publicDir())), '/');
    }

    /**
     * Manda un POST con ficheros al panel como lo hace PHP (`$_FILES['files']` con listas por campo) y con la ficha
     * de la última página abierta.
     *
     * @param array<string,mixed>  $post
     * @param array<int,string>    $paths ruta de cada fichero
     * @param array<int,string>    $names nombre con el que se sube cada uno (por defecto, el de la ruta)
     * @param array<int,int>       $errors código de error de cada fichero (por defecto 0)
     */
    function mediaPost(TestClient $web, string $uri, array $post = [], array $paths = [], bool $json = true, array $names = [], array $errors = []): Response
    {
        $files = [];
        if ($paths !== []) {
            $files = ['files' => ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]];
            foreach (array_values($paths) as $i => $path) {
                $files['files']['name'][] = isset($names[$i]) ? $names[$i] : basename($path);
                $files['files']['type'][] = 'application/octet-stream';
                $files['files']['tmp_name'][] = $path;
                $files['files']['error'][] = isset($errors[$i]) ? $errors[$i] : 0;
                $files['files']['size'][] = is_file($path) ? (int) filesize($path) : 0;
            }
        }
        $headers = $web->headers + ($json ? ['accept' => 'application/json', 'x-requested-with' => 'fetch'] : []);
        $request = new Request('POST', $uri, [], $post + ['_token' => aifecToken($web)], $web->cookies, $headers, '', $files);
        $request->host = 'tienda.test';
        $request->secure = true;
        $response = $web->app->handle($request);
        foreach ($response->cookies as $name => $cookie) {
            if ($cookie['value'] === '' || ((int) $cookie['options']['expires'] !== 0 && (int) $cookie['options']['expires'] < $web->app->clock()->now())) {
                unset($web->cookies[$name]);
            } else {
                $web->cookies[$name] = $cookie['value'];
            }
        }
        $web->last = $response;

        return $response;
    }

    /** Papel con solo mirar la biblioteca (media.view), sin subir ni cambiar nada. */
    function mediaViewerRole(App $app): void
    {
        $app->db()->insert('roles', ['code' => 'mviewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'media.view']), 'system' => 0]);
    }
}
