<?php
/**
 * AIFEC — ayudas de prueba del módulo de documentación. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   aifecDocsFolder(): string     una carpeta docs/ de mentira, dentro de una raíz temporal que además lleva un secreto.md FUERA de docs/
 *   aifecDocsApp(string $dir, array $modules = ['docs']): App   un sitio con la documentación apuntando a esa carpeta
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('aifecDocsFolder')) {
    /**
     * Escribe un fichero creando las carpetas que falten.
     */
    function aifecDocsWrite(string $file, string $text): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, $text);
    }

    /**
     * La carpeta de prueba: dos secciones con prefijo numérico, una página en la raíz, una con HTML peligroso, un `.php` y un
     * `secreto.md` colocado FUERA de la carpeta docs/.
     */
    function aifecDocsFolder(): string
    {
        $root = tmpDir('aifec-docs');
        $docs = $root . '/docs';
        aifecDocsWrite($root . '/secreto.md', "# Secreto\n\nesto no se puede leer desde la web\n");
        aifecDocsWrite($docs . '/02-api/01-rutas.md', "# Rutas\n\nLas rutas del sistema.\n\n## Públicas\n\nTexto.\n");
        aifecDocsWrite($docs . '/10-extra/01-otra.md', "# Otra cosa\n\nPágina de la sección diez.\n");
        aifecDocsWrite($docs . '/01-guia/02-configurar.md', "# Configurar\n\nLos ajustes se guardan con la palabra rara zanahoria y también con Ñandú & Cía.\n\n## Ajustes\n\nMás texto sobre ajustes.\n");
        aifecDocsWrite($docs . '/01-guia/01-empezar.md', "# Empezar\n\nPrimer párrafo con **negrita** y un [enlace](https://ejemplo.test/).\n\n## Instalar\n\nSe instala con una orden.\n\n```php\necho 1;\n```\n\n## Usar\n\nSe usa tal cual.\n");
        aifecDocsWrite($docs . '/acerca.md', "# Acerca de\n\nUna página suelta en la raíz.\n");
        aifecDocsWrite($docs . '/peligro.md', "# Peligro\n\nTexto <script>alert(1)</script> y <img src=x onerror=alert(2)> y [clic](javascript:alert(3)).\n");
        aifecDocsWrite($docs . '/script.php', "<?php echo 'no';\n");
        aifecDocsWrite($docs . '/01-guia/notas.txt', "no es markdown\n");

        return $docs;
    }

    /**
     * @param string[] $modules
     */
    function aifecDocsApp(string $dir, array $modules = ['docs']): App
    {
        // El módulo no viene encendido de serie (default: false): se enciende como lo haría el panel, con «modules.active».
        $app = testApp(['modules' => $modules, 'settings' => ['docs.dir' => $dir, 'modules.active' => $modules]]);
        $app->boot();

        return $app;
    }
}

if (!function_exists('aifecDocsPng')) {
    /**
     * Escribe un PNG de verdad (de $w por $h píxeles) y devuelve sus bytes.
     */
    function aifecDocsPng(string $file, int $w = 4, int $h = 3): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        aifecDocsWrite($file, $bytes);

        return $bytes;
    }

    /**
     * Una carpeta docs/ con enlaces de todos los estilos, capturas (las hay y faltan), una subcarpeta `_generado` con una tabla
     * ancha, y en `_media` ficheros que NO se pueden publicar: un `.json` y un `.php` (con bytes de PNG o sin ellos) y un texto
     * con nombre de imagen.
     */
    function aifecDocsMediaFolder(): string
    {
        $root = tmpDir('aifec-docs-media');
        $docs = $root . '/docs';
        aifecDocsWrite($root . '/secreto.png', 'fuera de docs');
        aifecDocsWrite($docs . '/00-empezar/01-inicio.md', "# Inicio\n\nBienvenida a la tienda.\n");
        aifecDocsWrite($docs . '/01-tienda/07-envios.md', "# Envíos\n\nTexto de envíos. Vuelve a [Pedidos](05-pedidos.md#devolver).\n\n## Plazos\n\nTexto.\n");
        aifecDocsWrite($docs . '/01-tienda/05-pedidos.md', "# Pedidos\n\n"
            . "Estilos: [a](07-envios.md), [b](./07-envios.md), [c](../01-tienda/07-envios.md), [d](../00-empezar/01-inicio.md), [e](07-envios.md#plazos), [f](../07-referencia/_generado/rutas.md).\n\n"
            . "Malos: [roto](no-existe.md), [fuera](../../../etc/passwd.md), [js](javascript:alert(1)), [absoluto](https://ejemplo.test/x.md), [ancla](#devolver), [otra](/docs/otra).\n\n"
            . "![Listado de \"pedidos\" & más](../_media/pedidos-listado.png)\n\n"
            . "![Falta](../_media/falta.png)\n\n"
            . "## Devolver\n\nTexto.\n\n```\n[en código](no-existe.md)\n```\n\nY `[inline](no-existe.md)` tampoco.\n");
        aifecDocsWrite($docs . '/07-referencia/01-rutas.md', "# Rutas\n\nLas generadas están en [rutas](_generado/rutas.md).\n");
        aifecDocsWrite($docs . '/07-referencia/_generado/indice.md', "# Índice generado\n\nLista de lo generado.\n");
        aifecDocsWrite($docs . '/07-referencia/_generado/rutas.md', "# Rutas generadas\n\nCuadro con palabra rarisima mandarina.\n\n"
            . "| Método | Ruta | Nombre | Permiso | Módulo | Notas |\n|---|---|---|---|---|---|\n| GET | /docs | docs | - | docs | índice |\n| GET | /docs/buscar | docs.search | - | docs | buscador |\n");
        aifecDocsWrite($docs . '/07-referencia/_generado/niveles/hondo.md', "# Hondo\n\nDos niveles: no entra.\n");
        aifecDocsWrite($docs . '/_media/notas.md', "# Notas\n\nNo se publica.\n");
        aifecDocsPng($docs . '/_media/pedidos-listado.png', 7, 5);
        $png = aifecDocsPng($docs . '/_media/otra.png', 2, 2);
        aifecDocsWrite($docs . '/_media/datos.json', $png);
        aifecDocsWrite($docs . '/_media/script.php', $png);
        aifecDocsWrite($docs . '/_media/falso.png', "esto no es una imagen\n");
        aifecDocsWrite($docs . '/_media/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        aifecDocsWrite($docs . '/indice.json.bak', 'no');

        return $docs;
    }

    /**
     * Un sitio con la documentación montado en una subcarpeta («/demos/x»): sus direcciones llevan ese prefijo.
     */
    function aifecDocsAppAt(string $dir, string $basePath): App
    {
        $app = testApp(['modules' => ['docs'], 'base_path' => $basePath, 'settings' => ['docs.dir' => $dir, 'modules.active' => ['docs']]]);
        $app->boot();

        return $app;
    }

    /** El HTML del contenido de una página (lo que va dentro de `af-docs-prose`, sin el resto de la plantilla). */
    function aifecDocsProse(string $body): string
    {
        $from = (int) strpos($body, 'af-docs-prose');
        $prose = (string) substr($body, $from);

        return (string) substr($prose, 0, (int) strpos($prose, '</article>'));
    }
}
