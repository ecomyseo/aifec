<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Kernel\App;

/**
 * Los mapas del sitio: un índice (/sitemap.xml) y un mapa por tipo y por tramo de 1.000 contenidos
 * (/sitemap-products-1.xml). Se montan al vuelo con consultas paginadas, nunca cargando una tabla entera.
 *
 * Solo sale lo publicado y lo indexable: nada de borradores, de productos ocultos ni de contenidos marcados «no indexar».
 * Cada contenido sale en cada idioma en que tiene dirección, con las alternativas de idioma (xhtml:link).
 */
final class Sitemap
{
    /** Contenidos por fichero (cada uno puede salir en varios idiomas). */
    public const PER_FILE = 1000;

    /** Tipos de mapa, en el orden en que salen en el índice. */
    public const TYPES = ['pages', 'posts', 'products', 'categories', 'brands'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** El índice: una entrada por cada fichero que hay. */
    public function index(): string
    {
        $url = $this->app->url();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::TYPES as $type) {
            $parts = $this->parts($type);
            for ($n = 1; $n <= $parts; ++$n) {
                $xml .= '  <sitemap><loc>' . self::x($url->abs('/sitemap-' . $type . '-' . $n . '.xml', [], '')) . "</loc></sitemap>\n";
            }
        }

        return $xml . "</sitemapindex>\n";
    }

    /** Un fichero del mapa, o null si ese tipo no existe, está vacío o el tramo se pasa de largo. */
    public function part(string $type, int $n): ?string
    {
        if (!in_array($type, self::TYPES, true) || $n < 1 || $n > $this->parts($type)) {
            return null;
        }
        $offset = ($n - 1) * self::PER_FILE;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        if ($type === 'pages') {
            // La portada es la primera dirección de «pages»: va antes que las páginas de contenido.
            $limit = self::PER_FILE;
            $skip = $offset > 0 ? $offset - 1 : 0;
            if ($offset === 0) {
                $xml .= $this->entry($this->homeVersions(), $this->homeLastmod());
                $limit = self::PER_FILE - 1;
            }
            foreach ($this->rows('pages', $limit, $skip) as $row) {
                $xml .= $this->entry($this->versions('page', (int) $row['id']), $this->lastmod((string) $row['updated_at']));
            }
        } else {
            $entity = ['posts' => 'post', 'products' => 'product', 'categories' => 'category', 'brands' => 'brand'][$type];
            $rows = $this->rows($type, self::PER_FILE, $offset);
            $this->preload($entity, $rows);
            foreach ($rows as $row) {
                $xml .= $this->entry($this->versions($entity, (int) $row['id']), $this->lastmod((string) $row['updated_at']));
            }
        }

        return $xml . "</urlset>\n";
    }

    // ------------------------------------------------------------------ cuántos hay

    /** Número de ficheros de un tipo. */
    public function parts(string $type): int
    {
        return (int) ceil($this->total($type) / self::PER_FILE);
    }

    /** Número de contenidos indexables de un tipo (en «pages», la portada cuenta como uno). */
    public function total(string $type): int
    {
        $db = $this->app->db();
        switch ($type) {
            case 'pages':
                $count = 1;
                if ($this->app->has('cms')) {
                    list($visible, $params) = $this->app->service('cms')->visibleSql('p');
                    $count += (int) $db->value('SELECT COUNT(*) FROM {pages} p WHERE ' . $visible . ' AND p.noindex = 0 AND p.is_home = 0', $params);
                }

                return $count;
            case 'posts':
                if (!$this->app->has('cms')) {
                    return 0;
                }
                list($visible, $params) = $this->app->service('cms')->visibleSql('p');

                return (int) $db->value('SELECT COUNT(*) FROM {posts} p WHERE ' . $visible . ' AND p.noindex = 0', $params);
            case 'products':
                return $this->app->has('catalog') ? (int) $db->value("SELECT COUNT(*) FROM {products} WHERE status = 'published' AND visibility <> 'hidden'") : 0;
            case 'categories':
                return $this->app->has('catalog') ? (int) $db->value('SELECT COUNT(*) FROM {categories} WHERE active = 1') : 0;
            case 'brands':
                return $this->app->has('catalog') ? (int) $db->value('SELECT COUNT(*) FROM {brands} WHERE active = 1') : 0;
        }

        return 0;
    }

    // ------------------------------------------------------------------ consultas

    /**
     * Un tramo de contenidos, por orden de identificador.
     *
     * @return array<int,array<string,mixed>> id, updated_at
     */
    private function rows(string $type, int $limit, int $offset): array
    {
        if ($limit < 1) {
            return [];
        }
        $db = $this->app->db();
        $tail = ' ORDER BY id LIMIT ? OFFSET ?';
        switch ($type) {
            case 'pages':
                if (!$this->app->has('cms')) {
                    return [];
                }
                list($visible, $params) = $this->app->service('cms')->visibleSql('p');
                $params[] = $limit;
                $params[] = $offset;

                // La página que hace de portada ya va como «/»: listarla también por su dirección sería la misma página dos veces.
                return $db->select('SELECT p.id AS id, p.updated_at AS updated_at FROM {pages} p WHERE ' . $visible . ' AND p.noindex = 0 AND p.is_home = 0 ORDER BY p.id LIMIT ? OFFSET ?', $params);
            case 'posts':
                if (!$this->app->has('cms')) {
                    return [];
                }
                list($visible, $params) = $this->app->service('cms')->visibleSql('p');
                $params[] = $limit;
                $params[] = $offset;

                return $db->select('SELECT p.id AS id, p.updated_at AS updated_at FROM {posts} p WHERE ' . $visible . ' AND p.noindex = 0 ORDER BY p.id LIMIT ? OFFSET ?', $params);
            case 'products':
                return $this->app->has('catalog')
                    ? $db->select("SELECT id, updated_at FROM {products} WHERE status = 'published' AND visibility <> 'hidden'" . $tail, [$limit, $offset])
                    : [];
            case 'categories':
                return $this->app->has('catalog') ? $db->select('SELECT id, updated_at FROM {categories} WHERE active = 1' . $tail, [$limit, $offset]) : [];
            case 'brands':
                return $this->app->has('catalog') ? $db->select('SELECT id, updated_at FROM {brands} WHERE active = 1' . $tail, [$limit, $offset]) : [];
        }

        return [];
    }

    /**
     * Lee de una vez las direcciones de todo el tramo (una consulta por idioma, no una por contenido).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function preload(string $entity, array $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        // De 400 en 400: un IN con mil parámetros pasa del límite de variables de algunas versiones de SQLite.
        foreach (array_chunk($ids, 400) as $chunk) {
            foreach ($this->app->languages() as $lang) {
                $this->app->slugs()->preload($entity, $chunk, $lang);
            }
        }
    }

    // ------------------------------------------------------------------ piezas del XML

    /**
     * Las direcciones de una entidad: idioma => dirección completa, solo de los idiomas en que tiene dirección.
     *
     * @return array<string,string>
     */
    private function versions(string $entity, int $id): array
    {
        $versions = [];
        foreach ($this->app->languages() as $lang) {
            if ($this->app->slugs()->path($entity, $id, $lang) !== null) {
                $versions[$lang] = $this->app->url()->entityAbs($entity, $id, $lang);
            }
        }

        return $versions;
    }

    /**
     * La portada en todos los idiomas.
     *
     * @return array<string,string>
     */
    /**
     * Cuándo cambió la portada por última vez: su página (si la hay) o el último producto publicado que cambió, lo que sea más
     * reciente. Sin ninguno de los dos, sin fecha.
     */
    private function homeLastmod(): string
    {
        $db = $this->app->db();
        $dates = [];
        if ($this->app->has('cms')) {
            $dates[] = (string) $db->value('SELECT MAX(updated_at) FROM {pages} WHERE is_home = 1');
        }
        if ($this->app->has('catalog')) {
            $dates[] = (string) $db->value("SELECT MAX(updated_at) FROM {products} WHERE status = 'published' AND visibility <> 'hidden'");
        }
        $dates = array_filter($dates, static function (string $d): bool {
            return $d !== '';
        });

        return $dates === [] ? '' : $this->lastmod((string) max($dates));
    }

    private function homeVersions(): array
    {
        $versions = [];
        foreach ($this->app->languages() as $lang) {
            $versions[$lang] = $this->app->url()->abs('/', [], $lang);
        }

        return $versions;
    }

    /**
     * Un <url> por cada idioma, cada uno con todas las alternativas (y «x-default» al idioma principal) si hay más de una.
     *
     * @param array<string,string> $versions idioma => dirección completa
     */
    private function entry(array $versions, string $lastmod): string
    {
        if ($versions === []) {
            return '';
        }
        $links = '';
        if (count($versions) > 1) {
            foreach ($versions as $lang => $href) {
                $links .= '    <xhtml:link rel="alternate" hreflang="' . self::x((string) $lang) . '" href="' . self::x($href) . "\"/>\n";
            }
            $default = $this->app->defaultLang();
            $first = array_values($versions);
            $links .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . self::x(isset($versions[$default]) ? $versions[$default] : $first[0]) . "\"/>\n";
        }
        $xml = '';
        foreach ($versions as $href) {
            $xml .= "  <url>\n    <loc>" . self::x($href) . "</loc>\n"
                . ($lastmod !== '' ? '    <lastmod>' . $lastmod . "</lastmod>\n" : '')
                . $links . "  </url>\n";
        }

        return $xml;
    }

    /** «2026-11-12 12:00:00» (UTC) → «2026-11-12T12:00:00+00:00». Lo que no tiene esa forma no sale. */
    private function lastmod(string $sql): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $sql, $m) === 1 ? $m[1] . 'T' . $m[2] . '+00:00' : '';
    }

    private static function x(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
