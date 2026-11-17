<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Docs;

/**
 * La carpeta de documentación: qué páginas hay, en qué sección y en qué orden.
 *
 * Cada subcarpeta es una sección y cada fichero `.md` una página. El orden y los títulos salen de `indice.json` si existe
 * (`{"sections": [{"slug": "guia", "title": "Guía", "pages": [{"slug": "instalar", "title": "Instalar"}]}]}`; una sección
 * con `slug` vacío agrupa los ficheros de la raíz) y, si no, del nombre del fichero con su prefijo numérico (`02-instalar.md`
 * es la página «instalar», en segundo lugar). Lo que el índice no nombra va detrás, por nombre de fichero.
 *
 * Una dirección nunca se une a la ruta de la carpeta: se busca entre las páginas que el recorrido ha encontrado. Por eso
 * un `..`, un `.php` o un enlace simbólico que salga de la carpeta no se pueden pedir.
 */
final class Library
{
    /** Tamaño máximo de una página, en bytes: más grande no se lee. */
    private const MAX_BYTES = 1048576;

    /** Tamaño máximo de una imagen publicada, en bytes. */
    private const MAX_IMAGE = 10485760;

    /** @var string */
    private $dir;

    /** @var array<int,array<string,mixed>>|null */
    private $tree;

    /** @var array<string,array<string,string>>|null páginas por su ruta relativa en minúsculas (`02-tienda/05-pedidos.md`) */
    private $byRel;

    public function __construct(string $dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** ¿Hay alguna página? Una carpeta que no existe o sin ningún `.md` cuenta como vacía. */
    public function isEmpty(): bool
    {
        return $this->pages() === [];
    }

    /**
     * Las secciones con sus páginas, en su orden. Cada página: section, slug, path (`guia/instalar`), title, file.
     *
     * @return array<int,array{slug:string,title:string,pages:array<int,array<string,string>>}>
     */
    public function tree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }
        $this->tree = [];
        $real = realpath($this->dir);
        if ($real === false || !is_dir($real)) {
            return $this->tree;
        }
        $base = rtrim(str_replace('\\', '/', $real), '/');
        $index = $this->indexFile($base);
        $sections = [];
        $root = $this->pagesIn($base, '', $base);
        if ($root !== []) {
            $sections[''] = ['slug' => '', 'title' => '', 'pages' => $root, 'dir' => ''];
        }
        foreach ($this->sorted($base) as $name) {
            $path = $base . '/' . $name;
            if (!is_dir($path) || is_link($path) || strpos($name, '.') === 0 || strtolower($name) === '_media') {
                continue;
            }
            $slug = self::slug($name);
            if ($slug === '' || isset($sections[$slug])) {
                continue;
            }
            $pages = array_merge($this->pagesIn($path, $slug, $base), $this->subPages($path, $slug, $base));
            if ($pages !== []) {
                $sections[$slug] = ['slug' => $slug, 'title' => self::humanize($slug), 'pages' => $pages, 'dir' => $name];
            }
        }
        $this->tree = $this->applyIndex($sections, $index);

        return $this->tree;
    }

    /**
     * Todas las páginas en el orden de lectura (la que viene detrás de otra es su «siguiente»).
     *
     * @return array<int,array<string,string>>
     */
    public function pages(): array
    {
        $all = [];
        foreach ($this->tree() as $section) {
            foreach ($section['pages'] as $page) {
                $all[] = $page;
            }
        }

        return $all;
    }

    /**
     * La página de una dirección (`guia/instalar` o `instalar`) o null si no existe.
     *
     * @return array<string,string>|null
     */
    public function find(string $path): ?array
    {
        $path = trim($path, '/');
        foreach ($this->pages() as $page) {
            if ($page['path'] === $path) {
                return $page;
            }
        }

        return null;
    }

    /**
     * La página anterior y la siguiente a una dada.
     *
     * @param array<string,string> $page
     *
     * @return array{prev:array<string,string>|null,next:array<string,string>|null}
     */
    public function around(array $page): array
    {
        $all = $this->pages();
        foreach ($all as $i => $candidate) {
            if ($candidate['path'] === $page['path']) {
                return ['prev' => $i > 0 ? $all[$i - 1] : null, 'next' => isset($all[$i + 1]) ? $all[$i + 1] : null];
            }
        }

        return ['prev' => null, 'next' => null];
    }

    /**
     * El Markdown de una página, tal cual está en el fichero (sin marca BOM y con saltos de línea LF).
     *
     * @param array<string,string> $page
     */
    public function read(array $page): string
    {
        $file = isset($page['file']) ? $page['file'] : '';
        if ($file === '' || !is_file($file) || (int) filesize($file) > self::MAX_BYTES) {
            return '';
        }
        $text = (string) file_get_contents($file);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = (string) substr($text, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * La página a la que apunta un enlace relativo escrito dentro de otra página, o null si no apunta a ninguna que la
     * biblioteca conozca (o si se sale de la carpeta). Se resuelve contra el fichero que lo contiene: `../02-tienda/05-pedidos.md`,
     * `07-envios.md`, `./y.md` y `_generado/rutas.md` valen desde donde estén.
     *
     * @param array<string,string> $from la página que contiene el enlace
     *
     * @return array<string,string>|null
     */
    public function pageFor(array $from, string $href): ?array
    {
        $rel = $this->relative($from, $href);
        if ($rel === null || strtolower((string) substr($rel, -3)) !== '.md') {
            return null;
        }
        if ($this->byRel === null) {
            $this->byRel = [];
            foreach ($this->pages() as $page) {
                $this->byRel[strtolower($page['rel'])] = $page;
            }
        }
        $key = strtolower($rel);

        return isset($this->byRel[$key]) ? $this->byRel[$key] : null;
    }

    /**
     * El nombre de la imagen de `_media` a la que apunta una ruta relativa (`../_media/pedidos.png`), o null si no apunta a
     * la carpeta de imágenes de la documentación. No comprueba que exista: para eso está {@see media()}.
     *
     * @param array<string,string> $from
     */
    public function mediaName(array $from, string $href): ?string
    {
        $rel = $this->relative($from, $href);
        if ($rel !== null && preg_match('#^_media/([^/]+)$#i', $rel, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Una imagen publicable de `_media`: solo `png`, `jpg`, `jpeg`, `webp` y `gif`, con un nombre de las letras, cifras, puntos
     * y guiones de siempre, que sea un fichero de verdad dentro de la carpeta (ni enlace simbólico ni fuera) y cuyo contenido sea
     * de verdad esa imagen. Devuelve null en cualquier otro caso.
     *
     * @return array{file:string,mime:string,width:int,height:int,size:int,time:int}|null
     */
    public function media(string $name): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) !== 1 || preg_match('/\.(png|jpe?g|webp|gif)$/', $name) !== 1) {
            return null;
        }
        $real = realpath($this->dir);
        if ($real === false) {
            return null;
        }
        $folder = rtrim(str_replace('\\', '/', $real), '/') . '/_media';
        $file = $folder . '/' . $name;
        if (!is_dir($folder) || is_link($folder) || !is_file($file) || is_link($file)) {
            return null;
        }
        $realFolder = realpath($folder);
        $realFile = realpath($file);
        if ($realFolder === false || $realFile === false) {
            return null;
        }
        $realFolder = rtrim(str_replace('\\', '/', $realFolder), '/');
        $realFile = str_replace('\\', '/', $realFile);
        if (strcasecmp($realFolder, $folder) !== 0 || strncasecmp($realFile, $realFolder . '/', strlen($realFolder) + 1) !== 0) {
            return null;
        }
        $size = (int) filesize($realFile);
        if ($size <= 0 || $size > self::MAX_IMAGE) {
            return null;
        }
        $info = @getimagesize($realFile);
        if (!is_array($info) || !isset($info['mime']) || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return null;
        }

        return [
            'file' => $realFile,
            'mime' => (string) $info['mime'],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'size' => $size,
            'time' => (int) filemtime($realFile),
        ];
    }

    /**
     * La ruta de un enlace relativo respecto a la raíz de la carpeta de documentación, resuelta contra el fichero que lo
     * contiene, sin ancla ni consulta. Null si está vacía, trae algo raro o sube por encima de la carpeta.
     *
     * @param array<string,string> $from
     */
    private function relative(array $from, string $href): ?string
    {
        $href = (string) preg_replace('/[#?].*$/s', '', $href);
        $href = rawurldecode($href);
        if ($href === '' || strpos($href, "\0") !== false || strpos($href, '\\') !== false || $href[0] === '/') {
            return null;
        }
        $here = isset($from['rel']) ? dirname((string) $from['rel']) : '';
        $parts = $here === '.' || $here === '' ? [] : explode('/', $here);
        foreach (explode('/', $href) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /** `02-instalar` o `instalar.md` → `instalar`: sin prefijo numérico, en minúsculas y con solo letras, cifras, puntos y guiones. */
    public static function slug(string $name): string
    {
        $name = (string) preg_replace('/\.md$/i', '', $name);
        $name = (string) preg_replace('/^\d+[-_.]\s*/', '', $name);
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-._'));

        return preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) === 1 ? $name : '';
    }

    /**
     * El rótulo de una subcarpeta de sección: la generada se llama por lo que es, el resto se humaniza.
     */
    public static function groupTitle(string $group): string
    {
        return $group === 'generado' ? t('Reference for developers') : self::humanize($group);
    }

    /** `guia-rapida` → `Guia rapida`: un título de respaldo cuando el fichero no trae ninguno. */
    public static function humanize(string $slug): string
    {
        $text = trim(str_replace(['-', '_'], ' ', $slug));

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * @return array<string,mixed>
     */
    private function indexFile(string $base): array
    {
        $file = $base . '/indice.json';
        if (!is_file($file) || (int) filesize($file) > 262144) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Los ficheros `.md` de una carpeta (sin entrar en subcarpetas, sin enlaces simbólicos y solo dentro de la carpeta base).
     *
     * @return array<int,array<string,string>>
     */
    private function pagesIn(string $dir, string $section, string $base, string $group = ''): array
    {
        $pages = [];
        $seen = [];
        foreach ($this->sorted($dir) as $name) {
            $file = $dir . '/' . $name;
            if (strpos($name, '.') === 0 || !is_file($file) || is_link($file) || strtolower((string) substr($name, -3)) !== '.md') {
                continue;
            }
            $real = realpath($file);
            if ($real === false || strpos(str_replace('\\', '/', $real), $base . '/') !== 0) {
                continue;
            }
            $slug = self::slug($name);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $full = $group !== '' ? $group . '/' . $slug : $slug;
            $unix = str_replace('\\', '/', $real);
            $pages[] = [
                'section' => $section,
                'slug' => $full,
                'path' => $section !== '' ? $section . '/' . $full : $full,
                'title' => $this->titleOf($real, $slug),
                'file' => $unix,
                'rel' => (string) substr($unix, strlen($base) + 1),
                'group' => $group,
                'group_title' => $group !== '' ? self::groupTitle($group) : '',
            ];
        }

        return $pages;
    }

    /**
     * Las páginas de las subcarpetas de una sección (un solo nivel; `_media` nunca): `07-referencia/_generado/rutas.md` es la
     * página «generado/rutas» de la sección «referencia». Van detrás de las páginas propias de la sección.
     *
     * @return array<int,array<string,string>>
     */
    private function subPages(string $dir, string $section, string $base): array
    {
        $pages = [];
        $groups = [];
        foreach ($this->sorted($dir) as $name) {
            $path = $dir . '/' . $name;
            if (!is_dir($path) || is_link($path) || strpos($name, '.') === 0 || strtolower($name) === '_media') {
                continue;
            }
            $group = self::slug($name);
            if ($group === '' || isset($groups[$group])) {
                continue;
            }
            $groups[$group] = true;
            $pages = array_merge($pages, $this->pagesIn($path, $section, $base, $group));
        }

        return $pages;
    }

    /**
     * Nombres de una carpeta ordenados como personas (`2-a` antes que `10-a`).
     *
     * @return string[]
     */
    private function sorted(string $dir): array
    {
        $names = @scandir($dir);
        $list = [];
        foreach (is_array($names) ? $names : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $list[] = (string) $name;
            }
        }
        natcasesort($list);

        return array_values($list);
    }

    /** El primer encabezado `# Título` del fichero; si no hay, el nombre. Solo se lee el principio. */
    private function titleOf(string $file, string $slug): string
    {
        $head = (string) file_get_contents($file, false, null, 0, 4096);
        if (preg_match('/^\s*#\s+(.+?)\s*#*\s*$/m', str_replace("\r", '', $head), $m) === 1) {
            $title = trim((string) preg_replace('/[*_`]/', '', $m[1]));
            if ($title !== '') {
                return mb_substr($title, 0, 160);
            }
        }

        return self::humanize($slug);
    }

    /**
     * Pone el orden y los títulos de `indice.json`: lo que nombra va primero y en su orden; lo demás, detrás.
     *
     * @param array<string,array<string,mixed>> $sections
     * @param array<string,mixed>               $index
     *
     * @return array<int,array<string,mixed>>
     */
    private function applyIndex(array $sections, array $index): array
    {
        $listed = isset($index['sections']) && is_array($index['sections']) ? array_values($index['sections']) : [];
        $out = [];
        foreach ($listed as $entry) {
            if (!is_array($entry) || !isset($entry['slug']) || !is_string($entry['slug']) || !isset($sections[$entry['slug']])) {
                continue;
            }
            $slug = $entry['slug'];
            $section = $sections[$slug];
            unset($sections[$slug]);
            if (isset($entry['title']) && is_string($entry['title']) && trim($entry['title']) !== '') {
                $section['title'] = mb_substr(trim($entry['title']), 0, 160);
            }
            $section['pages'] = $this->orderPages($section['pages'], isset($entry['pages']) && is_array($entry['pages']) ? array_values($entry['pages']) : []);
            $out[] = $section;
        }
        foreach ($sections as $section) {
            $out[] = $section;
        }
        $clean = [];
        foreach ($out as $section) {
            unset($section['dir']);
            $clean[] = $section;
        }

        return $clean;
    }

    /**
     * @param array<int,array<string,string>> $pages
     * @param array<int,mixed>                $listed
     *
     * @return array<int,array<string,string>>
     */
    private function orderPages(array $pages, array $listed): array
    {
        $bySlug = [];
        foreach ($pages as $page) {
            $bySlug[$page['slug']] = $page;
        }
        $out = [];
        foreach ($listed as $entry) {
            if (!is_array($entry) || !isset($entry['slug']) || !is_string($entry['slug']) || !isset($bySlug[$entry['slug']])) {
                continue;
            }
            $page = $bySlug[$entry['slug']];
            unset($bySlug[$entry['slug']]);
            if (isset($entry['title']) && is_string($entry['title']) && trim($entry['title']) !== '') {
                $page['title'] = mb_substr(trim($entry['title']), 0, 160);
            }
            $out[] = $page;
        }
        foreach ($bySlug as $page) {
            $out[] = $page;
        }

        return $out;
    }
}
