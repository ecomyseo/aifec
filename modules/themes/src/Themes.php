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

use Aifec\Front\ThemeVars;
use Aifec\Kernel\App;

/**
 * Servicio «themes»: lista los temas instalados, activa uno (solo si pasa el validador), guarda sus opciones, y reparte el
 * trabajo del validador y del generador.
 *
 * Los ajustes que toca (todos por sitio): «theme.active» (el tema activo), «theme.options» (lo que ha cambiado el
 * comerciante, por tema), «home.order» y «home.hidden» (la portada), «store.logo» y «store.icon» (la imagen de marca).
 */
final class Themes
{
    /** Un identificador de tema: letras minúsculas, cifras y guiones. */
    public const SLUG = '/^[a-z0-9][a-z0-9-]{0,38}$/';

    /** Opciones válidas de un tema como máximo en una cadena de herencia (como App::theme()). */
    private const MAX_DEPTH = 6;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function app(): App
    {
        return $this->app;
    }

    /** La carpeta themes/ de la instalación, o la de un tema. */
    public function dir(string $slug = ''): string
    {
        return $this->app->root() . '/themes' . ($slug !== '' ? '/' . $slug : '');
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match(self::SLUG, $slug) === 1;
    }

    /** ¿Hay una carpeta de tema con ese nombre y su theme.json (válido o no)? */
    public function exists(string $slug): bool
    {
        return self::validSlug($slug) && is_file($this->dir($slug) . '/theme.json');
    }

    /** El tema activo según los ajustes (la aplicación cae en «base» si no existe). */
    public function activeSlug(): string
    {
        $slug = $this->app->settings()->str('theme.active', 'base');

        return $this->exists($slug) ? $slug : 'base';
    }

    // ------------------------------------------------------------------ lectura de temas

    /**
     * El theme.json de un tema.
     *
     * @return array{ok:bool,data:array<string,mixed>,error:string}
     */
    public function read(string $slug): array
    {
        if (!$this->exists($slug)) {
            return ['ok' => false, 'data' => [], 'error' => 'The theme does not exist'];
        }
        $data = json_decode((string) file_get_contents($this->dir($slug) . '/theme.json'), true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => [], 'error' => 'theme.json is not valid JSON'];
        }

        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    /**
     * La cadena de herencia, del tema al más general, con las mismas reglas que App::theme(): si no declara padre, hereda de
     * «base» (salvo «base» mismo). Un padre que no existe o un ciclo cortan la cadena y se anotan en «error».
     *
     * @return array{chain:string[],error:string}
     */
    public function chain(string $slug): array
    {
        $chain = [];
        $error = '';
        $current = $slug;
        $guard = 0;
        while ($current !== '' && $guard++ < self::MAX_DEPTH) {
            if (!$this->exists($current)) {
                $error = $chain === [] ? 'The theme does not exist' : 'The parent theme "' . $current . '" does not exist';
                break;
            }
            if (in_array($current, $chain, true)) {
                $error = 'The theme inherits from itself';
                break;
            }
            $chain[] = $current;
            $read = $this->read($current);
            $data = $read['data'];
            $explicit = isset($data['parent']) && is_string($data['parent']) && $data['parent'] !== '';
            $current = $explicit ? (string) $data['parent'] : ($current === 'base' ? '' : 'base');
            if (!$explicit && $current === 'base' && !$this->exists('base')) {
                break; // sin padre declarado y sin «base»: la aplicación se queda con lo que hay
            }
        }
        if ($error === '' && $guard > self::MAX_DEPTH && $current !== '') {
            $error = 'The chain of parent themes is too long';
        }

        return ['chain' => $chain, 'error' => $error];
    }

    /**
     * El theme.json fundido con el de sus padres (lo del hijo manda), como lo ve la aplicación cuando está activo.
     *
     * @return array<string,mixed> slug, chain, settings, font_stacks, menus…
     */
    public function resolve(string $slug): array
    {
        $chain = $this->chain($slug)['chain'];
        $merged = [];
        foreach ($chain as $current) {
            $merged = array_replace_recursive($this->read($current)['data'], $merged);
        }
        $merged['slug'] = $chain !== [] ? $chain[0] : $slug;
        $merged['chain'] = $chain;

        return $merged;
    }

    /**
     * Los campos de opciones de un tema (con los heredados): clave => campo (+ 'group'); y las etiquetas de sus grupos.
     *
     * @return array{fields:array<string,array<string,mixed>>,groups:array<string,string>}
     */
    public function fields(string $slug): array
    {
        $theme = $this->resolve($slug);
        $fields = [];
        $groups = [];
        foreach (isset($theme['settings']) && is_array($theme['settings']) ? $theme['settings'] : [] as $groupKey => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[(string) $groupKey] = isset($group['label']) ? (string) $group['label'] : (string) $groupKey;
            foreach (isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [] as $key => $field) {
                if (is_array($field) && preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) === 1) {
                    $fields[(string) $key] = $field + ['type' => 'text', 'label' => (string) $key, 'default' => '', 'group' => (string) $groupKey];
                }
            }
        }

        return ['fields' => $fields, 'groups' => $groups];
    }

    /**
     * Los temas instalados: una fila por carpeta de themes/ con theme.json. Un theme.json roto sale como no válido.
     *
     * @return array<int,array<string,mixed>> slug, name, version, description, parent, preview, active, valid, error, validation
     */
    public function all(): array
    {
        $rows = [];
        $dirs = glob($this->dir() . '/*', GLOB_ONLYDIR);
        $names = [];
        foreach (is_array($dirs) ? $dirs : [] as $dir) {
            $names[] = basename($dir);
        }
        sort($names);
        foreach ($names as $slug) {
            if ($this->exists($slug)) {
                $rows[] = $this->summary($slug);
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->exists($slug) ? $this->summary($slug) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(string $slug): array
    {
        $read = $this->read($slug);
        $data = $read['data'];
        $chain = $this->chain($slug);
        $parent = isset($chain['chain'][1]) ? $chain['chain'][1] : '';
        $row = [
            'slug' => $slug,
            'name' => isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : $slug,
            'version' => isset($data['version']) && is_string($data['version']) ? $data['version'] : '',
            'description' => isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            'parent' => $parent,
            'preview' => $this->previewFile($slug) !== null,
            'active' => $slug === $this->activeSlug(),
            'valid' => $read['ok'],
            'error' => $read['error'],
            'validation' => null,
        ];
        $report = $this->validate($slug, false);
        $row['validation'] = ['ok' => $report['ok'], 'score' => $report['score'], 'errors' => $report['errors'], 'warnings' => $report['warnings']];

        return $row;
    }

    /** El preview.png del tema (o del padre más cercano que lo tenga), o null. */
    public function previewFile(string $slug): ?string
    {
        foreach ($this->chain($slug)['chain'] as $current) {
            $file = $this->dir($current) . '/preview.png';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ validar y activar

    /**
     * Pasa el validador a un tema.
     *
     * @param bool $dynamic también las comprobaciones que piden páginas a la aplicación con ese tema puesto en memoria
     *
     * @return array<string,mixed> ok, score, errors, warnings, checks (id, level, ok, message, file)
     */
    public function validate(string $slug, bool $dynamic = true): array
    {
        return Validator::run($this, $slug, $dynamic);
    }

    /**
     * Activa un tema. Antes lo valida (también en vivo): con un fallo bloqueante no se activa.
     *
     * @throws ThemeException
     */
    public function activate(string $slug): void
    {
        if (!$this->exists($slug)) {
            throw new ThemeException('The theme does not exist');
        }
        $report = $this->validate($slug, true);
        if (!$report['ok']) {
            throw new ThemeException('The theme has blocking errors and cannot be activated', $report);
        }
        $this->app->settings()->set('theme.active', $slug);
        $this->app->invalidate(['pages', 'home', 'settings']);
    }

    // ------------------------------------------------------------------ opciones

    /**
     * Las opciones de un tema con su valor vigente: los de fábrica con lo que cambió el comerciante encima (ya validado).
     *
     * @return array{fields:array<string,array<string,mixed>>,groups:array<string,string>,values:array<string,mixed>,defaults:array<string,mixed>}
     */
    public function options(string $slug): array
    {
        $def = $this->fields($slug);
        $saved = $this->app->settings()->arr('theme.options');
        $own = isset($saved[$slug]) && is_array($saved[$slug]) ? $saved[$slug] : [];
        $values = [];
        $defaults = [];
        foreach ($def['fields'] as $key => $field) {
            $cleanDefault = ThemeVars::clean($field, $field['default']);
            $defaults[$key] = $cleanDefault !== null ? $cleanDefault : $field['default'];
            $clean = array_key_exists($key, $own) ? ThemeVars::clean($field, $own[$key]) : null;
            $values[$key] = $clean !== null ? $clean : $defaults[$key];
        }

        return ['fields' => $def['fields'], 'groups' => $def['groups'], 'values' => $values, 'defaults' => $defaults];
    }

    /**
     * Valida los valores recibidos contra los campos del tema. Devuelve lo válido y los errores por campo.
     *
     * @param array<string,mixed> $values clave => valor tal como llega del formulario
     *
     * @return array{clean:array<string,mixed>,errors:array<string,string>}
     */
    public function check(string $slug, array $values): array
    {
        $fields = $this->fields($slug)['fields'];
        $clean = [];
        $errors = [];
        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (!isset($fields[$key])) {
                continue;
            }
            $value = ThemeVars::clean($fields[$key], $raw);
            if ($value === null) {
                $errors[$key] = t('That value is not valid');
            } else {
                $clean[$key] = $value;
            }
        }

        return ['clean' => $clean, 'errors' => $errors];
    }

    /**
     * Guarda las opciones del tema. Cada valor se valida con ThemeVars::clean(): los que valen se guardan (y los que
     * coinciden con el de fábrica se quitan), los que no, se devuelven como error de su campo y no se guardan.
     *
     * @param array<string,mixed> $values clave => valor
     *
     * @return array<string,string> errores por campo (vacío si todo se guardó)
     */
    public function saveOptions(string $slug, array $values): array
    {
        if (!$this->exists($slug)) {
            throw new ThemeException('The theme does not exist');
        }
        $options = $this->options($slug);
        $result = $this->check($slug, $values);
        $before = $this->app->settings()->arr('theme.options');
        $all = $before;
        $own = isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
        foreach ($result['clean'] as $key => $value) {
            if ((string) $value === (string) $options['defaults'][$key]) {
                unset($own[$key]);
            } else {
                $own[$key] = $value;
            }
        }
        if ($own === []) {
            unset($all[$slug]);
        } else {
            $all[$slug] = $own;
        }
        if ($all !== $before) {
            $this->app->settings()->set('theme.options', $all);
            $this->app->invalidate(['pages', 'home', 'settings']);
        }

        return $result['errors'];
    }

    /** Vuelve a los valores de fábrica: borra lo que había cambiado el comerciante en ese tema. */
    public function resetOptions(string $slug): void
    {
        $all = $this->app->settings()->arr('theme.options');
        unset($all[$slug]);
        $this->app->settings()->set('theme.options', $all);
        $this->app->invalidate(['pages', 'home', 'settings']);
    }

    // ------------------------------------------------------------------ portada

    /**
     * Las secciones que entregan los módulos para la portada, en el orden que se verá, con si se ven.
     *
     * Cada una lleva su nombre para quien la ordena en el panel: el «title» que dé el módulo y, si no lo da, el primer
     * encabezado de lo que pinta; el identificador («catalog.featured») solo si no hay ni lo uno ni lo otro.
     *
     * @return array<int,array{id:string,visible:bool,title:string}>
     */
    public function homeSections(): array
    {
        $found = $this->app->hooks()->apply('home.sections', [], $this->app);
        $sections = [];
        foreach (is_array($found) ? $found : [] as $section) {
            if (is_array($section) && isset($section['id'], $section['html']) && trim((string) $section['html']) !== '') {
                $title = isset($section['title']) && is_string($section['title']) ? trim($section['title']) : '';
                if ($title === '' && preg_match('#<h[1-3][^>]*>(.*?)</h[1-3]>#is', (string) $section['html'], $m) === 1) {
                    $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                $sections[(string) $section['id']] = [
                    'id' => (string) $section['id'],
                    'order' => isset($section['order']) ? (int) $section['order'] : 100,
                    'title' => $title !== '' ? mb_substr($title, 0, 80, 'UTF-8') : (string) $section['id'],
                ];
            }
        }
        $order = $this->app->settings()->arr('home.order');
        $hidden = $this->app->settings()->arr('home.hidden');
        if ($order !== []) {
            $position = 0;
            foreach ($order as $id) {
                if (is_string($id) && isset($sections[$id])) {
                    $sections[$id]['order'] = $position++;
                }
            }
        }
        $list = array_values($sections);
        usort($list, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'];
        });
        $out = [];
        foreach ($list as $section) {
            $out[] = ['id' => $section['id'], 'visible' => !($order !== [] && in_array($section['id'], $hidden, true)), 'title' => $section['title']];
        }

        return $out;
    }

    /**
     * Guarda el orden de la portada y las secciones ocultas («home.order» y «home.hidden»).
     *
     * @param string[] $order   identificadores en el orden que se quiere
     * @param string[] $visible identificadores que se enseñan (los demás se ocultan)
     */
    public function saveHome(array $order, array $visible): void
    {
        $known = [];
        foreach ($this->homeSections() as $section) {
            $known[] = $section['id'];
        }
        $clean = [];
        foreach ($order as $id) {
            if (is_string($id) && in_array($id, $known, true) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        foreach ($known as $id) {
            if (!in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        $hidden = [];
        foreach ($clean as $id) {
            if (!in_array($id, $visible, true)) {
                $hidden[] = $id;
            }
        }
        $this->app->settings()->set('home.order', $clean);
        $this->app->settings()->set('home.hidden', $hidden);
        $this->app->invalidate(['pages', 'home', 'settings']);
    }

    // ------------------------------------------------------------------ generador

    /**
     * Crea un tema hijo en themes/<slug>/. Ver Generator.
     *
     * @param array<string,mixed> $spec slug, name, parent, accent, bg, font, header, cards, columns
     *
     * @return string el identificador del tema creado
     *
     * @throws ThemeException
     */
    public function generate(array $spec): string
    {
        return (new Generator($this))->create($spec);
    }

    /**
     * Pide a la IA una paleta y una tipografía para una tienda (si el servicio «ai» existe y está encendido). Lo que
     * propone NO se guarda: la persona lo revisa antes de crear el tema. Los colores se ajustan a contraste AA.
     *
     * @return array{available:bool,palette:array<string,string>,notes:string[],error:string}
     */
    public function suggestPalette(string $description, string $parent = 'base'): array
    {
        return (new Generator($this))->suggest($description, $parent);
    }
}
