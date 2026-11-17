<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Cms\Blocks\BlockRenderer;
use Aifec\Cms\Blocks\BlockText;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Util\Str;

/**
 * Servicio «cms»: páginas, entradas del blog, categorías y etiquetas, menús y formularios (contrato 05 § 2).
 *
 * Una sola verdad: lo que pinta la página, el JSON-LD, la versión en texto y los feeds salen de page() y post().
 * Cada ficha se guarda en caché con las etiquetas `page:<id>` / `post:<id>` y `cms`; lo que depende de la hora (un
 * contenido programado) NO se congela: la caché guarda los datos y la visibilidad se decide en cada llamada con el reloj.
 *
 * Estados: `draft`, `scheduled` (con `published_at`; se ve cuando llega su hora) y `published`.
 *
 * Los dos nombres de campo del formulario público que el pintor del bloque «form» reserva para las defensas contra
 * robots son FORM_TRAP (campo trampa, tiene que llegar vacío) y FORM_STAMP (marca de tiempo firmada que rellena el
 * JavaScript); los campos del formulario viajan como `f[clave]`.
 */
final class Cms
{
    public const FORM_TRAP = 'website';

    public const FORM_STAMP = '_mark';

    /** Versiones que se conservan de cada contenido. */
    public const KEEP_REVISIONS = 20;

    /** @var App */
    private $app;

    /** @var Forms|null */
    private $forms;

    /** @var Menus|null */
    private $menus;

    /** @var Painters|null */
    private $painters;

    /** @var BlockCache|null */
    private $blockCache;

    /** @var array<string,array<int,array<string,mixed>>> idioma => id => término */
    private $termMap = [];

    /** @var int versión de la etiqueta «cms» con la que se leyeron los términos */
    private $termVersion = -1;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ piezas

    public function forms(): Forms
    {
        if ($this->forms === null) {
            $this->forms = new Forms($this->app);
        }

        return $this->forms;
    }

    public function menus(): Menus
    {
        if ($this->menus === null) {
            $this->menus = new Menus($this->app, $this);
        }

        return $this->menus;
    }

    /** El idioma pedido si el sitio lo tiene; si no, el de la petición. */
    public function lang(?string $lang): string
    {
        if ($lang !== null && $lang !== '' && in_array($lang, $this->app->languages(), true)) {
            return $lang;
        }

        return $this->app->lang();
    }

    // ------------------------------------------------------------------ páginas y entradas

    /**
     * Una página: ['id','title','html','blocks','excerpt','template','url','url_abs','seo' => ['title','description','noindex'],
     * 'published_at','updated_at','text', y además 'status','parent_id','is_home','position','lang'].
     * Null si no existe o no se ve (borrador, o programada para más tarde), salvo con $any.
     *
     * @return array<string,mixed>|null
     */
    public function page(int $id, ?string $lang = null, bool $any = false): ?array
    {
        return $this->entity('page', $id, $this->lang($lang), $any);
    }

    /**
     * Una entrada: lo mismo que page() más 'author','author_id','image' (array de imagen o null),'media_id','categories','tags'
     * (listas de ['id','name','url']) y 'reading_minutes'.
     *
     * @return array<string,mixed>|null
     */
    public function post(int $id, ?string $lang = null, bool $any = false): ?array
    {
        return $this->entity('post', $id, $this->lang($lang), $any);
    }

    /**
     * Identificador de la página de portada si existe y se ve; null si no.
     */
    public function homePageId(): ?int
    {
        $id = $this->app->db()->value('SELECT id FROM {pages} WHERE is_home = 1 ORDER BY id LIMIT 1');
        if ($id === null) {
            return null;
        }

        return $this->page((int) $id) !== null ? (int) $id : null;
    }

    /**
     * ¿Se ve ahora? Un contenido publicado se ve siempre; uno programado, desde su hora.
     *
     * @param array<string,mixed> $row fila con status y published_at
     */
    public function isVisible(array $row): bool
    {
        $status = isset($row['status']) ? (string) $row['status'] : '';
        if ($status === 'published') {
            return true;
        }

        return $status === 'scheduled' && isset($row['published_at']) && Clock::parse((string) $row['published_at']) <= $this->app->clock()->now();
    }

    /**
     * Trozo de SQL y parámetros que dejan solo lo que se ve ahora.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function visibleSql(string $alias): array
    {
        $a = preg_match('/^[a-z]{1,3}$/', $alias) === 1 ? $alias : 'p';

        return ["(" . $a . ".status = 'published' OR (" . $a . ".status = 'scheduled' AND " . $a . ".published_at <= ?))", [$this->app->clock()->sql()]];
    }

    /**
     * Entradas del blog, las más recientes primero.
     *
     * @param array<string,mixed> $query term_id (categoría o etiqueta), tag (id o dirección de una etiqueta), q, page, per_page (10), lang
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function posts(array $query): array
    {
        $db = $this->app->db();
        $lang = $this->lang(isset($query['lang']) && is_string($query['lang']) ? $query['lang'] : null);
        $default = $this->app->defaultLang();
        $perPage = isset($query['per_page']) && (int) $query['per_page'] > 0 ? (int) $query['per_page'] : $this->app->settings()->int('cms.posts_per_page', 10);
        $perPage = max(1, min(100, $perPage));

        list($visible, $params) = $this->visibleSql('p');
        $where = [$visible];
        $termId = isset($query['term_id']) ? (int) $query['term_id'] : 0;
        if ($termId > 0) {
            $where[] = 'p.id IN (SELECT post_id FROM {post_terms} WHERE term_id = ?)';
            $params[] = $termId;
        }
        if (isset($query['tag']) && $query['tag'] !== '' && $query['tag'] !== null) {
            $tagId = $this->tagId($query['tag'], $lang);
            // Una etiqueta que no existe no deja ninguna entrada (no se ignora el filtro).
            $where[] = 'p.id IN (SELECT post_id FROM {post_terms} WHERE term_id = ?)';
            $params[] = $tagId;
        }
        $q = isset($query['q']) && is_string($query['q']) ? trim($query['q']) : '';
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "EXISTS (SELECT 1 FROM {post_i18n} x WHERE x.post_id = p.id AND x.lang IN (?, ?) AND (x.title LIKE ? ESCAPE '!' OR x.excerpt LIKE ? ESCAPE '!'))";
            array_push($params, $lang, $default, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $db->value('SELECT COUNT(*) FROM {posts} p WHERE ' . $whereSql, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, isset($query['page']) ? (int) $query['page'] : 1));
        $pageParams = $params;
        $pageParams[] = $perPage;
        $pageParams[] = ($page - 1) * $perPage;
        $ids = $db->column('SELECT p.id FROM {posts} p WHERE ' . $whereSql . ' ORDER BY p.published_at DESC, p.id DESC LIMIT ? OFFSET ?', $pageParams);

        $items = [];
        foreach ($ids as $id) {
            $post = $this->post((int) $id, $lang);
            if ($post !== null) {
                $items[] = $post;
            }
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /**
     * Las categorías o etiquetas del blog, en su orden: [['id','name','description','url','parent_id','count']]. `count` = entradas que se ven.
     *
     * @return array<int,array<string,mixed>>
     */
    public function terms(string $taxonomy = 'category', ?string $lang = null): array
    {
        $taxonomy = $taxonomy === 'tag' ? 'tag' : 'category';
        $lang = $this->lang($lang);
        $db = $this->app->db();
        $counts = [];
        list($visible, $params) = $this->visibleSql('p');
        foreach ($db->select('SELECT pt.term_id AS term_id, COUNT(*) AS n FROM {post_terms} pt JOIN {posts} p ON p.id = pt.post_id WHERE ' . $visible . ' GROUP BY pt.term_id', $params) as $row) {
            $counts[(int) $row['term_id']] = (int) $row['n'];
        }
        $out = [];
        foreach ($db->select('SELECT id, parent_id FROM {terms} WHERE taxonomy = ? ORDER BY position, id', [$taxonomy]) as $row) {
            $term = $this->term((int) $row['id'], $lang);
            if ($term === null) {
                continue;
            }
            $term['count'] = isset($counts[(int) $row['id']]) ? $counts[(int) $row['id']] : 0;
            $out[] = $term;
        }

        return $out;
    }

    /**
     * Un término (categoría o etiqueta) con su nombre en el idioma, o en el principal si no está traducido.
     *
     * @return array<string,mixed>|null
     */
    public function term(int $id, ?string $lang = null): ?array
    {
        $lang = $this->lang($lang);
        $map = $this->termsOf($lang);

        return isset($map[$id]) ? $map[$id] : null;
    }

    // ------------------------------------------------------------------ menús, formularios y bloques

    /**
     * Los elementos de un menú del tema en el idioma pedido: [['label','url','current','children' => […]]].
     * Lo que apunta a algo que ya no existe o no se ve (página despublicada, producto borrado) no sale.
     *
     * @return array<int,array<string,mixed>>
     */
    public function menu(string $location, ?string $lang = null): array
    {
        return $this->menus()->items($location, $this->lang($lang));
    }

    /**
     * Bloques → HTML con los pintores dinámicos (imágenes de la biblioteca, productos, entradas, formularios).
     * Nunca lanza: un pintor que falla no pinta nada.
     *
     * @param array<int|string,mixed> $blocks
     */
    public function render(array $blocks): string
    {
        // Lo ya pintado se reutiliza: el contenido puro de la página y cada bloque de productos, por separado (BlockCache).
        return $this->blockCache()->render($blocks);
    }

    /** Pintado de bloques con caché (esqueleto de la página y un hueco por cada bloque de otro módulo). */
    public function blockCache(): BlockCache
    {
        if ($this->blockCache === null) {
            if ($this->painters === null) {
                $this->painters = new Painters($this->app, $this);
            }
            $this->blockCache = new BlockCache($this->app, $this->painters->all());
        }

        return $this->blockCache;
    }

    /**
     * Bloques → HTML pintándolo todo de nuevo, sin ninguna caché (la referencia con la que se compara BlockCache).
     *
     * @param array<int|string,mixed> $blocks
     */
    public function renderFresh(array $blocks): string
    {
        if ($this->painters === null) {
            $this->painters = new Painters($this->app, $this);
        }

        return (new BlockRenderer($this->painters->all()))->render($blocks);
    }

    /**
     * Un formulario: ['id','code','name','fields' => [['key','type','label','required','options']],'settings' => ['notify_to','thanks']].
     *
     * @return array<string,mixed>|null
     */
    public function form(int $id): ?array
    {
        return $this->forms()->get($id);
    }

    /**
     * Lo mismo que form() con el código («contact»).
     *
     * @return array<string,mixed>|null
     */
    public function formByCode(string $code): ?array
    {
        return $this->forms()->get($code);
    }

    /**
     * Valida lo que envía una persona (el array `f` del envío) contra la definición del formulario.
     *
     * @param array<string,mixed> $form  lo que da form()
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,string>,1:array<string,string>} [valores limpios por clave, errores por clave]
     */
    public function formValidate(array $form, array $input): array
    {
        return $this->forms()->validate($form, $input);
    }

    /** Guarda una entrada recibida y devuelve su identificador. */
    public function formStore(int $formId, array $data, string $ip = ''): int
    {
        return $this->forms()->store($formId, $data, $ip);
    }

    // ------------------------------------------------------------------ direcciones

    /**
     * Prefijo de la dirección de las entradas y de sus términos en un idioma: «blog», «blog/categoria», «blog/etiqueta».
     */
    public function prefix(string $kind): string
    {
        $post = trim($this->app->settings()->str('urls.post', 'blog'), '/');
        if ($kind === 'category') {
            return $post . '/' . trim($this->app->settings()->str('urls.term_category', 'categoria'), '/');
        }
        if ($kind === 'tag') {
            return $post . '/' . trim($this->app->settings()->str('urls.term_tag', 'etiqueta'), '/');
        }

        return $post;
    }

    /**
     * Rehace las direcciones de las entradas y de los términos cuando cambian las palabras de los ajustes: cada una conserva su
     * última parte y cambia el prefijo; las antiguas quedan redirigiendo. Devuelve cuántas direcciones se han movido.
     */
    public function rewriteAddresses(): int
    {
        $db = $this->app->db();
        $slugs = $this->app->slugs();
        $moved = 0;
        $groups = [
            ['post', $this->prefix('post'), 'SELECT id FROM {posts}'],
            ['term', $this->prefix('category'), "SELECT id FROM {terms} WHERE taxonomy = 'category'"],
            ['term', $this->prefix('tag'), "SELECT id FROM {terms} WHERE taxonomy = 'tag'"],
        ];
        foreach ($groups as $group) {
            foreach ($db->column($group[2]) as $id) {
                foreach ($this->app->languages() as $lang) {
                    $path = $slugs->path($group[0], (int) $id, $lang);
                    if ($path === null) {
                        continue;
                    }
                    $last = (string) substr($path, (int) strrpos('/' . $path, '/'));
                    $new = $group[1] . '/' . $last;
                    if ($new !== $path) {
                        $slugs->set($group[0], (int) $id, $lang, $new);
                        ++$moved;
                    }
                }
            }
        }

        return $moved;
    }

    // ------------------------------------------------------------------ interno

    /**
     * @return array<string,mixed>|null
     */
    private function entity(string $type, int $id, string $lang, bool $any): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $cache = $this->app->cache();
        $key = 'cms:' . $type . ':' . $id . ':' . $lang;
        $data = $cache->get($key);
        $fresh = false;
        if (!is_array($data)) {
            $data = $this->build($type, $id, $lang);
            if ($data === null) {
                return null;
            }
            $cache->set($key, $data['data'], 3600, $data['tags']);
            $data = $data['data'];
            $fresh = true;
        }
        if (!$any && !$this->isVisible($data)) {
            return null;
        }
        if (!empty($data['dynamic']) && !$fresh) {
            // Un formulario (que enseña el «gracias» o sus errores) o una lista de entradas (que cambia con el reloj) no se congelan:
            // lo que sale de la caché se vuelve a pintar. Lo recién construido en esta petición ya está pintado con el estado de ahora.
            $data['html'] = $this->render($data['blocks']);
        }

        return $data;
    }

    /**
     * @return array{data:array<string,mixed>,tags:string[]}|null
     */
    private function build(string $type, int $id, string $lang): ?array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        if ($type === 'page') {
            $row = $db->row('SELECT * FROM {pages} WHERE id = ?', [$id]);
            $texts = $row !== null ? $db->select('SELECT * FROM {page_i18n} WHERE page_id = ?', [$id]) : [];
        } else {
            $row = $db->row('SELECT * FROM {posts} WHERE id = ?', [$id]);
            $texts = $row !== null ? $db->select('SELECT * FROM {post_i18n} WHERE post_id = ?', [$id]) : [];
        }
        if ($row === null || $texts === []) {
            return null;
        }
        $byLang = [];
        foreach ($texts as $text) {
            $byLang[(string) $text['lang']] = $text;
        }
        $main = isset($byLang[$default]) ? $byLang[$default] : reset($byLang);
        $mine = isset($byLang[$lang]) && trim((string) $byLang[$lang]['title']) !== '' ? $byLang[$lang] : $main;
        // Cada campo cae en el idioma principal si en el idioma pedido está vacío.
        $field = static function (string $name) use ($mine, $main): string {
            $value = isset($mine[$name]) && $mine[$name] !== null ? (string) $mine[$name] : '';

            return trim($value) !== '' ? $value : (isset($main[$name]) && $main[$name] !== null ? (string) $main[$name] : '');
        };

        $decoded = json_decode($field('blocks'), true);
        $blocks = is_array($decoded) ? array_values($decoded) : [];
        $text = BlockText::plain($blocks);
        $title = (string) $mine['title'];
        $excerpt = trim($field('excerpt'));
        if ($excerpt === '') {
            $excerpt = Str::words($text, 35);
        }
        $metaDescription = trim($field('meta_description'));
        $url = $this->app->url();

        $data = [
            'id' => $id,
            'lang' => $lang,
            'status' => (string) $row['status'],
            'title' => $title,
            'html' => $this->render($blocks),
            'dynamic' => self::hasDynamic($blocks),
            'blocks' => $blocks,
            'excerpt' => $excerpt,
            'text' => $text,
            'url' => $url->entity($type, $id, $lang),
            'url_abs' => $url->entityAbs($type, $id, $lang),
            'seo' => [
                'title' => trim($field('meta_title')) !== '' ? trim($field('meta_title')) : $title,
                'description' => $metaDescription !== '' ? $metaDescription : Str::limit($excerpt, 155),
                'noindex' => !empty($row['noindex']),
            ],
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        ];
        $tags = [$type . ':' . $id, 'cms'];

        if ($type === 'page') {
            $data['template'] = (string) $row['template'] !== '' ? (string) $row['template'] : 'default';
            $data['parent_id'] = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            $data['is_home'] = !empty($row['is_home']);
            $data['position'] = (int) $row['position'];
        } else {
            $author = isset($row['author_id']) && $row['author_id'] !== null ? $db->value('SELECT name FROM {users} WHERE id = ?', [(int) $row['author_id']]) : null;
            $data['author'] = $author !== null ? (string) $author : '';
            $data['author_id'] = isset($row['author_id']) && $row['author_id'] !== null ? (int) $row['author_id'] : null;
            $data['media_id'] = isset($row['media_id']) && $row['media_id'] !== null ? (int) $row['media_id'] : null;
            $data['image'] = $this->postImage($data['media_id'], $blocks, $title);
            $data['categories'] = [];
            $data['tags'] = [];
            foreach ($db->column('SELECT pt.term_id FROM {post_terms} pt JOIN {terms} t ON t.id = pt.term_id WHERE pt.post_id = ? ORDER BY t.position, t.id', [$id]) as $termId) {
                $term = $this->term((int) $termId, $lang);
                if ($term === null) {
                    continue;
                }
                $data[$term['taxonomy'] === 'tag' ? 'tags' : 'categories'][] = ['id' => $term['id'], 'name' => $term['name'], 'url' => $term['url']];
            }
            $data['reading_minutes'] = max(1, (int) ceil(BlockText::words($blocks) / 200));
        }
        // Lo que el contenido pinta de otros módulos se vacía cuando cambian ellos.
        foreach (self::dependencies($blocks) as $tag) {
            $tags[] = $tag;
        }

        return ['data' => $data, 'tags' => array_values(array_unique($tags))];
    }

    /**
     * ¿Lleva algún bloque cuyo HTML no se puede guardar (formulario o lista de entradas)?
     *
     * @param array<int|string,mixed> $blocks
     */
    private static function hasDynamic(array $blocks, int $depth = 0): bool
    {
        if ($depth > 20) {
            return false;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['type']) ? (string) $block['type'] : '';
            if ($type === 'form' || $type === 'posts' || (isset($block['children']) && is_array($block['children']) && self::hasDynamic($block['children'], $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Etiquetas de caché de lo que dependen unos bloques: el catálogo si llevan productos, y cada producto fijo.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return string[]
     */
    private static function dependencies(array $blocks, int $depth = 0): array
    {
        $tags = [];
        if ($depth > 20) {
            return $tags;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['type']) ? (string) $block['type'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            if ($type === 'products' || $type === 'product') {
                $tags[] = 'catalog';
                foreach ($type === 'product' ? [isset($attrs['id']) ? $attrs['id'] : 0] : (isset($attrs['ids']) && is_array($attrs['ids']) ? $attrs['ids'] : []) as $productId) {
                    $tags[] = 'product:' . (int) $productId;
                }
            }
            if ($type === 'company') {
                // Los datos de la empresa salen de Ajustes: al cambiarlos, la página guardada deja de valer.
                $tags[] = 'settings';
            }
            if (isset($block['children']) && is_array($block['children'])) {
                $tags = array_merge($tags, self::dependencies($block['children'], $depth + 1));
            }
        }

        return $tags;
    }

    /**
     * Imagen destacada de una entrada: la elegida; si no hay, la primera de su contenido.
     *
     * @param array<int,mixed> $blocks
     *
     * @return array<string,mixed>|null
     */
    private function postImage(?int $mediaId, array $blocks, string $title): ?array
    {
        $first = null;
        if ($mediaId === null || $mediaId <= 0) {
            $first = BlockText::firstImage($blocks);
            $mediaId = $first !== null && isset($first['media_id']) ? (int) $first['media_id'] : 0;
        }
        if ($mediaId > 0 && $this->app->has('media')) {
            $image = $this->app->service('media')->image($mediaId, $title);
            if ($image !== null) {
                return $image;
            }
        }
        if ($first !== null && isset($first['src']) && is_string($first['src']) && $first['src'] !== '') {
            return [
                'id' => 0, 'url' => $first['src'], 'alt' => isset($first['alt']) && is_string($first['alt']) && $first['alt'] !== '' ? $first['alt'] : $title,
                'width' => isset($first['width']) ? (int) $first['width'] : null, 'height' => isset($first['height']) ? (int) $first['height'] : null,
                'thumb' => $first['src'], 'card' => $first['src'], 'large' => $first['src'], 'srcset' => '', 'dominant' => null,
            ];
        }

        return null;
    }

    /**
     * Todos los términos de un idioma, por identificador.
     *
     * @return array<int,array<string,mixed>>
     */
    private function termsOf(string $lang): array
    {
        // Los términos leídos valen mientras nadie vacíe la caché de contenidos.
        $version = $this->app->cache()->tagVersion('cms');
        if ($version !== $this->termVersion) {
            $this->termMap = [];
            $this->termVersion = $version;
        }
        if (isset($this->termMap[$lang])) {
            return $this->termMap[$lang];
        }
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $names = [];
        foreach ($db->select('SELECT term_id, lang, name, description FROM {term_i18n}') as $row) {
            $names[(int) $row['term_id']][(string) $row['lang']] = $row;
        }
        $map = [];
        foreach ($db->select('SELECT id, taxonomy, parent_id, position FROM {terms} ORDER BY position, id') as $row) {
            $id = (int) $row['id'];
            $texts = isset($names[$id]) ? $names[$id] : [];
            $mine = isset($texts[$lang]) && trim((string) $texts[$lang]['name']) !== '' ? $texts[$lang] : (isset($texts[$default]) ? $texts[$default] : (reset($texts) ?: null));
            if ($mine === null) {
                continue;
            }
            $map[$id] = [
                'id' => $id,
                'taxonomy' => (string) $row['taxonomy'],
                'name' => (string) $mine['name'],
                'description' => isset($mine['description']) && $mine['description'] !== null ? (string) $mine['description'] : '',
                'url' => $this->app->url()->entity('term', $id, $lang),
                'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'position' => (int) $row['position'],
            ];
        }
        $this->termMap[$lang] = $map;

        return $map;
    }

    /**
     * @param mixed $tag identificador, o la última parte de la dirección de la etiqueta
     */
    private function tagId($tag, string $lang): int
    {
        if (is_int($tag) || (is_string($tag) && ctype_digit($tag))) {
            return (int) $tag;
        }
        $slug = Str::slug(is_string($tag) ? $tag : '', 90);
        if ($slug === '') {
            return 0;
        }
        foreach ([$lang, $this->app->defaultLang()] as $try) {
            $hit = $this->app->slugs()->resolve($try, $this->prefix('tag') . '/' . $slug);
            if ($hit !== null && $hit['type'] === 'term') {
                return $hit['entity_id'];
            }
        }

        return 0;
    }
}
