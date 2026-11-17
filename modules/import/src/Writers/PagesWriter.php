<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Cms\Blocks\GutenbergParser;
use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Cms\Revisions;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Util\Str;

/**
 * Las páginas del origen. Se escriben por la capa de datos como lo deja el panel (fila, textos por idioma, bloques, dirección,
 * versión inicial y caché vaciada), con la fecha y el estado del origen y sin avisar a nadie.
 *
 * El contenido: «gutenberg» pasa por GutenbergParser y se convierte en bloques propios; «html» queda como UN bloque de HTML ya
 * saneado (nada de scripts ni manejadores de eventos). Las imágenes DENTRO del contenido que eran de la tienda antigua se traen a
 * la biblioteca y se reescribe su dirección; las que fallan se quedan apuntando al origen y se apuntan. Los códigos cortos de
 * WordPress que quedan (`[algo …]`) no tienen equivalente: se cuentan, uno por nombre.
 *
 * Qué es «la tienda antigua» lo dice la opción «source_host» (el dominio, o una lista) o «source_url» de la migración; además se
 * usa el dominio de la imagen destacada del propio registro. Sin ninguno, las imágenes del contenido se dejan como están.
 *
 * Esta clase sirve también de base de PostsWriter (entradas del blog): lo que cambia son los métodos protegidos de más abajo.
 */
class PagesWriter extends WriterBase
{
    public function type(): string
    {
        return 'pages';
    }

    /** El nombre de entidad de las direcciones amigables y de las versiones. */
    protected function entity(): string
    {
        return 'page';
    }

    protected function table(): string
    {
        return 'pages';
    }

    protected function textTable(): string
    {
        return 'page_i18n';
    }

    protected function textKey(): string
    {
        return 'page_id';
    }

    /** Lo que va delante de la dirección de cada contenido («blog» en las entradas, nada en las páginas). */
    protected function prefix(): string
    {
        return '';
    }

    /**
     * Una fila de aquí que ya es la misma cosa (por su dirección), o null. Las entradas no casan por lo natural.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    protected function natural(array $record, WriteContext $ctx, string $id): ?array
    {
        $lang = $this->app->defaultLang();
        $slug = $this->slugFor($record, $lang, $ctx, 0);
        if ($slug === '') {
            return null;
        }
        $found = $this->app->slugs()->resolve($lang, $slug);
        if ($found === null || $found['type'] !== $this->entity()) {
            return null;
        }
        $row = $this->db()->find($this->table(), ['id' => $found['entity_id']]);

        return $row !== null && !$this->claimed($this->type(), (int) $row['id'], $id) ? $row : null;
    }

    /**
     * Lo que se añade a la fila principal (la imagen destacada de una entrada).
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    protected function extraColumns(array $record, WriteContext $ctx, string $id): array
    {
        return [];
    }

    /**
     * Lo que se hace tras escribir la fila y sus textos (las categorías y etiquetas de una entrada).
     *
     * @param array<string,mixed> $record
     */
    protected function afterWrite(int $localId, array $record, WriteContext $ctx): void
    {
    }

    /**
     * El texto del extracto de un idioma (solo las entradas lo traen).
     *
     * @param array<string,mixed> $record
     */
    protected function excerpt(array $record, string $lang, WriteContext $ctx): string
    {
        return '';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $titles = $this->texts(isset($record['title']) && is_array($record['title']) ? $record['title'] : [], $ctx);
        if ($titles === []) {
            return $this->result(self::SKIPPED, null, 'The ' . $this->entity() . ' has no title');
        }
        $table = $this->table();
        $found = $this->resolve($this->type(), $record, $ctx, static function (int $localId) use ($db, $table): ?array {
            return $db->find($table, ['id' => $localId]);
        }, function () use ($record, $ctx, $id): ?array {
            return $this->natural($record, $ctx, $id);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $now = $this->now();
        $status = $this->status($record, $now, $ctx);
        $publishedAt = $this->sqlDate(isset($record['published_at']) ? $record['published_at'] : null);
        if ($status !== 'draft' && $publishedAt === null) {
            $publishedAt = $now;
        }
        if ($status === 'published' && $publishedAt > $now) {
            $status = 'scheduled';
        }
        $data = ['status' => $status, 'published_at' => $publishedAt, 'updated_at' => $now] + $this->extraColumns($record, $ctx, $id);
        if ($row === null) {
            $localId = $db->insert($table, $data + ['created_at' => $publishedAt !== null ? $publishedAt : $now]);
            $this->defaults($localId);
        } else {
            $localId = (int) $row['id'];
            $db->update($table, $data, ['id' => $localId]);
        }
        $this->writeTexts($localId, $record, $titles, $ctx, $id);
        $this->addresses($localId, $record, $titles, $ctx);
        $this->afterWrite($localId, $record, $ctx);
        if ($this->entity() === 'page') {
            $this->markLegal($record, $localId);
        }
        (new Revisions($this->app))->record($this->entity(), $localId);
        $this->app->invalidate(['cms', $this->entity() . ':' . $localId]);
        $ctx->remember($this->type(), $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * Las columnas que solo se ponen al crear.
     */
    protected function defaults(int $localId): void
    {
        $this->db()->update($this->table(), ['template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0], ['id' => $localId]);
    }

    /**
     * El estado de aquí: lo publicado, publicado; borrador y privado, borrador (aquí no hay privados).
     *
     * @param array<string,mixed> $record
     */
    private function status(array $record, string $now, WriteContext $ctx): string
    {
        $status = isset($record['status']) && is_string($record['status']) ? $record['status'] : 'draft';
        if ($status === 'private') {
            $this->noteOnce($ctx, 'private', 'Private content was brought as a draft: there are no private pages or posts here');
        }

        return $status === 'published' ? 'published' : 'draft';
    }

    // ------------------------------------------------------------------ textos, bloques y direcciones

    /**
     * Los textos de cada idioma con título: título, bloques, extracto y textos de buscadores.
     *
     * @param array<string,mixed> $record
     * @param array<string,string> $titles
     */
    private function writeTexts(int $localId, array $record, array $titles, WriteContext $ctx, string $id): void
    {
        $db = $this->db();
        $contents = $this->texts(isset($record['content']) && is_array($record['content']) ? $record['content'] : [], $ctx);
        $metaTitles = $this->texts(isset($record['meta_title']) && is_array($record['meta_title']) ? $record['meta_title'] : [], $ctx);
        $metaDescriptions = $this->texts(isset($record['meta_description']) && is_array($record['meta_description']) ? $record['meta_description'] : [], $ctx);
        $format = isset($record['content_format']) && $record['content_format'] === 'gutenberg' ? 'gutenberg' : 'html';
        $shortcodes = [];
        foreach ($titles as $lang => $title) {
            $html = isset($contents[$lang]) ? $this->contentImages($contents[$lang], $record, $ctx, $id) : '';
            foreach (GutenbergParser::shortcodes($html) as $name) {
                $shortcodes[$name] = true;
            }
            $db->upsert($this->textTable(), [
                $this->textKey() => $localId, 'lang' => $lang, 'title' => trim($title), 'blocks' => $this->blocks($html, $format),
                'excerpt' => $this->excerpt($record, $lang, $ctx), 'meta_title' => isset($metaTitles[$lang]) ? trim($metaTitles[$lang]) : '',
                'meta_description' => isset($metaDescriptions[$lang]) ? trim($metaDescriptions[$lang]) : '',
            ], [$this->textKey(), 'lang']);
        }
        foreach (array_keys($shortcodes) as $name) {
            $ctx->unmapped('shortcode', $name, 1, 'WordPress shortcodes have no equivalent here: the text «[' . $name . ']» was left in the content');
        }
    }

    /**
     * Los bloques, como texto JSON.
     */
    private function blocks(string $html, string $format): string
    {
        $html = trim($html);
        if ($html === '') {
            return '[]';
        }
        $blocks = $format === 'gutenberg' ? GutenbergParser::parse($html) : BlockSchema::validate([['type' => 'html', 'attrs' => ['html' => $this->html($html)]]]);

        return (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * El trozo de dirección de un idioma: el «slug» del origen o, si no hay, el título; solo letras, cifras y guiones.
     *
     * @param array<string,mixed> $record
     */
    private function slugFor(array $record, string $lang, WriteContext $ctx, int $localId): string
    {
        $slugs = $this->texts(isset($record['slug']) && is_array($record['slug']) ? $record['slug'] : [], $ctx);
        $titles = $this->texts(isset($record['title']) && is_array($record['title']) ? $record['title'] : [], $ctx);
        $text = isset($slugs[$lang]) ? $slugs[$lang] : (isset($titles[$lang]) ? $titles[$lang] : '');
        $slug = Str::slug($text, 90);
        if ($slug === '') {
            $slug = $localId > 0 ? $this->entity() . '-' . $localId : '';
        }

        return $slug !== '' && $this->prefix() !== '' ? $this->prefix() . '/' . $slug : $slug;
    }

    /**
     * La dirección amigable de cada idioma y las antiguas del origen.
     *
     * @param array<string,mixed>  $record
     * @param array<string,string> $titles
     */
    private function addresses(int $localId, array $record, array $titles, WriteContext $ctx): void
    {
        foreach (array_keys($titles) as $lang) {
            $path = $this->slugFor($record, $lang, $ctx, $localId);
            if ($path !== '') {
                $this->app->slugs()->set($this->entity(), $localId, $lang, $path);
            }
        }
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $ctx->oldPaths($this->entity(), $localId, $paths);
    }

    // ------------------------------------------------------------------ imágenes dentro del contenido

    /**
     * Trae a la biblioteca las imágenes del contenido que eran de la tienda antigua y reescribe su dirección.
     *
     * @param array<string,mixed> $record
     */
    private function contentImages(string $html, array $record, WriteContext $ctx, string $id): string
    {
        if ($ctx->dryRun() || stripos($html, '<img') === false || !(bool) $ctx->option('import_images', true) || !$this->app->has('media')) {
            return $html;
        }
        $hosts = $this->oldHosts($record, $ctx);
        if ($hosts === []) {
            return $html;
        }
        $media = $this->app->service('media');
        $out = preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/is', function (array $m) use ($hosts, $ctx, $id, $media): string {
            $url = html_entity_decode($m[3], ENT_QUOTES, 'UTF-8');
            $absolute = $this->oldAddress($url, $hosts);
            if ($absolute === null) {
                return $m[0];
            }
            $mediaId = $ctx->image(['url' => $absolute], $this->type(), $id);
            $new = $mediaId !== null ? $media->url($mediaId) : '';
            if ($new === '') {
                $ctx->note($this->type(), $id, 'note', 'An image inside the content could not be brought and still points to the old shop: ' . $this->cut($absolute, 150));

                // Con la dirección completa: una relativa apuntaría a esta tienda, que no la tiene.
                return $absolute !== $url ? $m[1] . $m[2] . htmlspecialchars($absolute, ENT_QUOTES, 'UTF-8') . $m[2] : $m[0];
            }

            return $m[1] . $m[2] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $m[2];
        }, $html);

        return is_string($out) ? $out : $html;
    }

    /**
     * Los dominios de la tienda antigua: las opciones source_host y source_url y el de la imagen destacada del registro.
     *
     * @param array<string,mixed> $record
     *
     * @return string[]
     */
    private function oldHosts(array $record, WriteContext $ctx): array
    {
        $candidates = [];
        $option = $ctx->option('source_host', null);
        foreach (is_array($option) ? $option : [$option] as $host) {
            if (is_string($host) && trim($host) !== '') {
                $candidates[] = trim($host);
            }
        }
        $url = $ctx->option('source_url', null);
        if (is_string($url) && $url !== '') {
            $candidates[] = $url;
        }
        if (isset($record['image']) && is_array($record['image']) && isset($record['image']['url']) && is_string($record['image']['url'])) {
            $candidates[] = $record['image']['url'];
        }
        $hosts = [];
        foreach ($candidates as $candidate) {
            $host = strpos($candidate, '//') !== false ? parse_url($candidate, PHP_URL_HOST) : $candidate;
            if (is_string($host) && $host !== '') {
                $hosts[$this->bareHost($host)] = $this->bareHost($host);
            }
        }

        return array_values($hosts);
    }

    private function bareHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r./"));

        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    /**
     * La dirección completa de una imagen si es de la tienda antigua; null si es de otro sitio.
     *
     * @param string[] $hosts
     */
    private function oldAddress(string $url, array $hosts): ?string
    {
        $url = trim($url);
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && in_array($this->bareHost($host), $hosts, true) ? $url : null;
        }
        if (strpos($url, '/') === 0) {
            return 'https://' . $hosts[0] . $url;
        }

        return null;
    }

    // ------------------------------------------------------------------ segunda pasada

    public function link(array $record, WriteContext $ctx): void
    {
        if ($ctx->dryRun() || $this->entity() !== 'page') {
            return;
        }
        $db = $this->db();
        $localId = $ctx->localId($this->type(), (string) $record['id']);
        if ($localId === null || $db->find('pages', ['id' => $localId]) === null) {
            return;
        }
        $parent = isset($record['parent_id']) && is_string($record['parent_id']) && $record['parent_id'] !== '' ? $ctx->localId('pages', $record['parent_id']) : null;
        if ($parent !== null && ($parent === $localId || $db->find('pages', ['id' => $parent]) === null || $this->inside($parent, $localId))) {
            $ctx->note('pages', (string) $record['id'], 'note', 'The parent page was not brought or would make a loop: the page was left at the top level');
            $parent = null;
        }
        $current = $db->value('SELECT parent_id FROM {pages} WHERE id = ?', [$localId]);
        if (($current !== null ? (int) $current : null) !== $parent) {
            $db->run('UPDATE {pages} SET parent_id = ? WHERE id = ?', [$parent, $localId]);
            $this->app->invalidate(['cms', 'page:' . $localId]);
        }
    }

    /**
     * ¿Está la página $id dentro de la rama de $start (sube por los padres)?
     */
    private function inside(int $start, int $id): bool
    {
        $db = $this->db();
        $seen = [];
        $current = $start;
        while ($current > 0 && !isset($seen[$current])) {
            if ($current === $id) {
                return true;
            }
            $seen[$current] = true;
            $parent = $db->value('SELECT parent_id FROM {pages} WHERE id = ?', [$current]);
            $current = $parent !== null ? (int) $parent : 0;
        }

        return false;
    }

    /**
     * Si la página es una legal del origen (aviso legal, privacidad…), se marca como la de aquí SOLO si ese hueco está vacío.
     *
     * @param array<string,mixed> $record
     */
    private function markLegal(array $record, int $localId): void
    {
        $legal = isset($record['legal']) && is_string($record['legal']) ? $record['legal'] : '';
        $codes = ['terms' => 'terms', 'privacy' => 'privacy', 'cookies' => 'cookies', 'legal' => 'legal-notice', 'returns' => 'shipping-returns', 'shipping' => 'shipping-returns'];
        if (!isset($codes[$legal])) {
            return;
        }
        $code = $codes[$legal];
        $map = $this->app->settings()->arr('cms.legal');
        $current = isset($map[$code]) ? (int) $map[$code] : 0;
        if ($current > 0 && $this->exists('pages', ['id' => $current])) {
            return;
        }
        $map[$code] = $localId;
        $this->app->settings()->set('cms.legal', $map);
        $this->app->invalidate(['cms']);
    }

    public function checksums(WriteContext $ctx): array
    {
        return [$this->entity() . 's_count' => $this->mappedCount($this->type(), $this->table())];
    }

    /**
     * El prefijo de las direcciones de las entradas según los ajustes del módulo de contenidos.
     */
    protected function cmsPrefix(string $kind): string
    {
        return (new Cms($this->app))->prefix($kind);
    }
}
