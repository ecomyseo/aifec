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

use Aifec\Cli\I18nCheck;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Validador de temas: comprueba que un tema no puede romper lo que AIFEC garantiza (contrato de contratos/04-temas.md).
 *
 * Comprobaciones ESTÁTICAS (solo leen ficheros, sin navegador): theme.json y herencia, plantillas y piezas del contrato,
 * envoltorio (page_head, <main id="main">, barra móvil), peso, direcciones y recursos externos, @import, opciones con
 * variable usadas, datos impresos sin escapar, galería antes que compra, textos traducidos y contraste AA de los pares de
 * color de fábrica.
 *
 * Comprobaciones DINÁMICAS («live-…»): piden páginas a la propia aplicación con ese tema puesto en memoria (una segunda
 * instancia de la aplicación sobre el mismo sitio, sin guardar nada): la portada y la página de error responden, el
 * <head> lleva canónica y robots, y no hay enlaces con la dirección vacía.
 *
 * Cada comprobación: id, level («error» bloquea la activación; «warning» avisa), ok, message, file.
 */
final class Validator
{
    /** Plantillas y piezas que todo tema tiene que tener (propias o heredadas). */
    public const TEMPLATES = [
        'layout', 'home', 'error', 'partials/header', 'partials/footer', 'partials/mobile-bar', 'partials/product-card',
        'partials/price', 'partials/breadcrumbs', 'partials/pagination', 'partials/messages', 'partials/qty', 'partials/nav',
        'partials/drawers',
    ];

    /** Peso máximo (sin comprimir) de la hoja y del guion del tema. */
    public const CSS_MAX = 45000;
    public const JS_MAX = 25000;

    /** Cuántas cadenas sin traducir se detallan como mucho. */
    private const MAX_MISSING = 25;

    /** @var Themes */
    private $themes;

    /** @var string */
    private $slug;

    /** @var array<int,array<string,mixed>> */
    private $checks = [];

    /** @var string[] cadena de herencia (del tema al más general) */
    private $chain = [];

    private function __construct(Themes $themes, string $slug)
    {
        $this->themes = $themes;
        $this->slug = $slug;
    }

    /**
     * @return array<string,mixed> slug, ok, score, errors, warnings, checks
     */
    public static function run(Themes $themes, string $slug, bool $dynamic = true): array
    {
        $v = new self($themes, $slug);
        if ($v->manifest()) {
            $v->parent();
            $v->templates();
            $v->wrapper();
            $v->weight();
            $v->external();
            $v->options();
            $v->escaping();
            $v->gallery();
            $v->translations();
            $v->contrast();
            if ($dynamic && $v->count('error') === 0) {
                $v->live();
            }
        }

        return $v->report();
    }

    // ------------------------------------------------------------------ informe

    private function add(string $id, string $level, bool $ok, string $message, string $file = ''): void
    {
        $this->checks[] = ['id' => $id, 'level' => $level, 'ok' => $ok, 'message' => $message, 'file' => $file];
    }

    private function count(string $level): int
    {
        $n = 0;
        foreach ($this->checks as $check) {
            if (!$check['ok'] && $check['level'] === $level) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        $total = 0;
        $passed = 0;
        foreach ($this->checks as $check) {
            $weight = $check['level'] === 'error' ? 3 : 1;
            $total += $weight;
            $passed += $check['ok'] ? $weight : 0;
        }
        $errors = $this->count('error');

        return [
            'slug' => $this->slug,
            'ok' => $errors === 0,
            'score' => $total > 0 ? (int) round(100 * $passed / $total) : 0,
            'errors' => $errors,
            'warnings' => $this->count('warning'),
            'checks' => $this->checks,
        ];
    }

    // ------------------------------------------------------------------ ficheros del tema

    private function dir(string $slug = ''): string
    {
        return $this->themes->dir($slug !== '' ? $slug : $this->slug);
    }

    /** El primer fichero de templates/ con ese nombre, mirando el tema y sus padres; null si no está en ninguno. */
    private function template(string $name): ?string
    {
        foreach ($this->chain as $slug) {
            $file = $this->dir($slug) . '/templates/' . $name . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Ficheros propios del tema (rutas relativas) cuyo nombre empieza por esa carpeta y acaba en una de esas extensiones.
     *
     * @param string[] $extensions
     *
     * @return string[]
     */
    private function own(string $folder, array $extensions): array
    {
        $found = [];
        foreach (Files::files($this->dir()) as $relative) {
            if (strpos($relative, $folder . '/') === 0 && in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $extensions, true)) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    private function read(string $relative): string
    {
        $file = $this->dir() . '/' . $relative;
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? $text : '';
    }

    private function line(string $text, int $offset): int
    {
        return substr_count(substr($text, 0, $offset), "\n") + 1;
    }

    // ------------------------------------------------------------------ comprobaciones estáticas

    /** theme.json válido y con nombre. Devuelve false si no se puede seguir. */
    private function manifest(): bool
    {
        $read = $this->themes->read($this->slug);
        if (!$read['ok']) {
            $this->add('manifest', 'error', false, $read['error'] === 'The theme does not exist' ? t('The theme does not exist') : t('theme.json is not valid JSON'), 'theme.json');

            return false;
        }
        $name = isset($read['data']['name']) ? $read['data']['name'] : null;
        if (!is_string($name) || trim($name) === '') {
            $this->add('manifest', 'error', false, t('theme.json needs a name'), 'theme.json');

            return false;
        }
        $this->add('manifest', 'error', true, t('theme.json is valid'), 'theme.json');
        $this->chain = $this->themes->chain($this->slug)['chain'];

        return true;
    }

    /** El padre existe y no hay ciclos. */
    private function parent(): void
    {
        $result = $this->themes->chain($this->slug);
        if ($result['error'] === '') {
            $this->add('parent', 'error', true, t('The parent theme exists and there are no cycles'), 'theme.json');

            return;
        }
        if ($result['error'] === 'The theme inherits from itself') {
            $message = t('The theme inherits from itself');
        } elseif ($result['error'] === 'The chain of parent themes is too long') {
            $message = t('The chain of parent themes is too long');
        } else {
            $slug = preg_match('/"([^"]+)"/', $result['error'], $m) === 1 ? $m[1] : '';
            $message = t('The parent theme "{slug}" does not exist', ['slug' => $slug]);
        }
        $this->add('parent', 'error', false, $message, 'theme.json');
    }

    /** Todas las plantillas y piezas del contrato, en el tema o en un padre. */
    private function templates(): void
    {
        foreach (self::TEMPLATES as $name) {
            $file = 'templates/' . $name . '.php';
            if ($this->template($name) !== null) {
                $this->add('template:' . $name, 'error', true, t('Template {name} found', ['name' => $name]), $file);
            } else {
                $this->add('template:' . $name, 'error', false, t('Template {name} is missing, neither in the theme nor in its parents', ['name' => $name]), $file);
            }
        }
    }

    /** El envoltorio: page_head(), <main id="main"> y la barra del móvil. */
    private function wrapper(): void
    {
        $layout = $this->template('layout');
        if ($layout === null) {
            return;
        }
        $text = (string) file_get_contents($layout);
        $relative = 'templates/layout.php';
        $this->add('wrapper-head', 'error', strpos($text, 'page_head(') !== false, strpos($text, 'page_head(') !== false
            ? t('The wrapper calls page_head()') : t('The wrapper must call page_head() in the head'), $relative);
        $hasMain = preg_match('/<main\b[^>]*\bid=["\']main["\']/', $text) === 1;
        $this->add('wrapper-main', 'error', $hasMain, $hasMain ? t('The wrapper has <main id="main">') : t('The wrapper must have <main id="main">'), $relative);
        $hasBar = strpos($text, 'partials/mobile-bar') !== false;
        $this->add('wrapper-mobile-bar', 'error', $hasBar, $hasBar ? t('The wrapper includes the mobile bar') : t('The wrapper must include partials/mobile-bar'), $relative);
    }

    /** theme.css ≤ 45 KB y theme.js ≤ 25 KB (los propios del tema). */
    private function weight(): void
    {
        foreach (['assets/css/theme.css' => ['css-size', self::CSS_MAX], 'assets/js/theme.js' => ['js-size', self::JS_MAX]] as $relative => $rule) {
            $file = $this->dir() . '/' . $relative;
            if (!is_file($file)) {
                continue;
            }
            $size = (int) filesize($file);
            $vars = ['file' => $relative, 'size' => number_format($size / 1000, 1, '.', ''), 'max' => number_format($rule[1] / 1000, 1, '.', '')];
            $this->add($rule[0], 'error', $size <= $rule[1], $size <= $rule[1]
                ? t('{file} weighs {size} KB (the limit is {max} KB)', $vars) : t('{file} weighs {size} KB and the limit is {max} KB', $vars), $relative);
        }
    }

    /** Ninguna dirección ajena, ningún @import, ningún script ni hoja de otro dominio. */
    private function external(): void
    {
        $texts = array_merge($this->own('templates', ['php', 'html']), $this->own('assets', ['css', 'js', 'svg', 'html', 'php']));
        $bad = ['external-urls' => [], 'imports' => [], 'external-tags' => []];
        foreach ($texts as $relative) {
            $text = $this->read($relative);
            if (preg_match('#https?://(?!www\.w3\.org/)#i', $text) === 1) {
                $bad['external-urls'][] = $relative;
            }
            if (preg_match('/@import\b/i', $text) === 1) {
                $bad['imports'][] = $relative;
            }
            if (preg_match('#<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["\']?(?:https?:)?//#i', $text) === 1) {
                $bad['external-tags'][] = $relative;
            }
        }
        if ($bad['external-urls'] === []) {
            $this->add('external-urls', 'error', true, t('No address on another domain'));
        }
        foreach ($bad['external-urls'] as $relative) {
            $this->add('external-urls', 'error', false, t('{file} points to an address on another domain', ['file' => $relative]), $relative);
        }
        if ($bad['imports'] === []) {
            $this->add('imports', 'error', true, t('No @import'));
        }
        foreach ($bad['imports'] as $relative) {
            $this->add('imports', 'error', false, t('{file} uses @import', ['file' => $relative]), $relative);
        }
        if ($bad['external-tags'] === []) {
            $this->add('external-tags', 'error', true, t('No script or stylesheet from another domain'));
        }
        foreach ($bad['external-tags'] as $relative) {
            $this->add('external-tags', 'error', false, t('{file} loads a script or a stylesheet from another domain', ['file' => $relative]), $relative);
        }
    }

    /** Cada opción propia con «var» tiene que usarse en alguna hoja de la cadena. */
    private function options(): void
    {
        $own = $this->themes->read($this->slug)['data'];
        $css = '';
        foreach ($this->chain as $slug) {
            $file = $this->dir($slug) . '/assets/css/theme.css';
            if (is_file($file)) {
                $css .= (string) file_get_contents($file) . "\n";
            }
        }
        $unused = [];
        foreach (isset($own['settings']) && is_array($own['settings']) ? $own['settings'] : [] as $group) {
            foreach (is_array($group) && isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [] as $key => $field) {
                if (!is_array($field) || !isset($field['var']) || !is_string($field['var']) || preg_match('/^--[a-z][a-z0-9-]*$/', $field['var']) !== 1) {
                    continue;
                }
                if (preg_match('/var\(\s*' . preg_quote($field['var'], '/') . '\s*[,)]/', $css) !== 1) {
                    $unused[(string) $key] = $field['var'];
                }
            }
        }
        if ($unused === []) {
            $this->add('option-vars', 'warning', true, t('Every option with a CSS variable is used'), 'theme.json');
        }
        foreach ($unused as $key => $var) {
            $this->add('option-vars', 'warning', false, t('The option "{key}" declares {var} but no stylesheet uses it', ['key' => $key, 'var' => $var]), 'theme.json');
        }
    }

    /** Datos impresos sin pasar por e(), t(), url()…: aviso, no error. */
    private function escaping(): void
    {
        $found = 0;
        foreach ($this->own('templates', ['php']) as $relative) {
            $text = $this->read($relative);
            if (preg_match('/<\?=\s*\$(?!this\b)/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                ++$found;
                $this->add('escaping', 'warning', false, t('{file} prints a variable without escaping it (line {line})', ['file' => $relative, 'line' => (string) $this->line($text, (int) $m[0][1])]), $relative);
            }
        }
        if ($found === 0) {
            $this->add('escaping', 'warning', true, t('Every printed variable goes through an escaping function'));
        }
    }

    /** Si el tema sustituye la ficha de producto, la galería va antes que la compra. */
    private function gallery(): void
    {
        $files = [];
        foreach ($this->own('templates', ['php']) as $relative) {
            if (preg_match('#^templates/(?:modules/[a-z0-9-]+/front/)?product[a-z0-9_-]*\.php$#', $relative) === 1) {
                $files[] = $relative;
            }
        }
        foreach ($files as $relative) {
            $text = $this->read($relative);
            $g = strpos($text, 'af-product-gallery');
            $b = strpos($text, 'af-product-buy');
            $ok = $g !== false && $b !== false && $g < $b;
            $this->add('gallery', 'error', $ok, $ok ? t('The product template puts the gallery before the purchase block')
                : t('The product template must put the gallery (af-product-gallery) before the purchase block (af-product-buy)'), $relative);
        }
    }

    /** Todas las cadenas del tema están en su lang/es.php (la misma comprobación que «php aifec i18n:check»). */
    private function translations(): void
    {
        try {
            $result = I18nCheck::run($this->themes->app()->root(), 'themes/' . $this->slug, 'es');
        } catch (\Throwable $e) {
            return;
        }
        if ($result['missing'] === []) {
            $this->add('i18n', 'error', true, t('Every text of the theme is translated'), 'lang/es.php');

            return;
        }
        $shown = 0;
        foreach ($result['missing'] as $string => $where) {
            if (++$shown > self::MAX_MISSING) {
                break;
            }
            $this->add('i18n', 'error', false, t('Missing translation in lang/es.php: "{text}"', ['text' => (string) $string]), (string) preg_replace('#^themes/' . preg_quote($this->slug, '#') . '/#', '', (string) $where));
        }
    }

    /** Contraste AA de los pares de color de fábrica: texto, acento, texto sobre el acento y precio anterior. */
    private function contrast(): void
    {
        $defaults = $this->themes->options($this->slug)['defaults'];
        $pairs = [
            ['text', 'ink', 'bg', t('Text on the page background')],
            ['accent', 'accent', 'bg', t('Accent colour on the page background')],
            ['accent-text', 'accent_ink', 'accent', t('Text on the accent colour')],
            ['old-price', 'muted', 'bg', t('Old price on the page background')],
        ];
        foreach ($pairs as $pair) {
            if (!isset($defaults[$pair[1]], $defaults[$pair[2]]) || !is_string($defaults[$pair[1]]) || !is_string($defaults[$pair[2]])
                || !Color::isHex($defaults[$pair[1]]) || !Color::isHex($defaults[$pair[2]])) {
                continue;
            }
            $ratio = Color::ratio($defaults[$pair[1]], $defaults[$pair[2]]);
            $ok = $ratio >= Color::AA;
            $vars = ['pair' => $pair[3], 'ratio' => number_format($ratio, 2, '.', '')];
            $this->add('contrast:' . $pair[0], 'warning', $ok, $ok ? t('{pair}: contrast {ratio}:1', $vars) : t('{pair}: contrast {ratio}:1, and AA asks for 4.5:1', $vars), 'theme.json');
        }
    }

    // ------------------------------------------------------------------ comprobaciones dinámicas

    /** ¿Algún functions.php de la cadena declara funciones sin guardia? Cargarlo por segunda vez sería un error fatal. */
    private function reloadable(): bool
    {
        foreach ($this->chain as $slug) {
            $file = $this->dir($slug) . '/functions.php';
            if (is_file($file) && preg_match('/^function\s+[A-Za-z_]/m', (string) file_get_contents($file)) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Pide páginas a una segunda instancia de la aplicación con este tema puesto solo en memoria. */
    private function live(): void
    {
        if (!$this->reloadable()) {
            $this->add('live-skipped', 'warning', false, t('The live checks were skipped: functions.php declares functions that cannot be loaded twice'), 'functions.php');

            return;
        }
        $app = $this->themes->app();
        $previous = App::hasCurrent() ? App::current() : null;
        $pages = [];
        try {
            $options = ['clock' => $app->clock(), 'http' => $app->http(), 'public_dir' => $app->publicDir(), 'log_files' => false, 'modules' => array_keys($app->modules()->all())];
            $probe = new App($app->site(), $app->root(), $options);
            Memory::apply($probe, ['theme.active' => $this->slug, 'theme.options' => [], 'cache.pages' => false]);
            $probe->makeCurrent();
            $origin = $app->request();
            foreach (['home' => '/', 'error' => '/aifec-theme-check-not-found'] as $name => $path) {
                $request = Request::create('GET', $path, [], [], ['sec-fetch-site' => 'same-origin', 'user-agent' => 'AifecThemeCheck/1.0']);
                $request->host = $origin->host;
                $request->secure = $origin->secure;
                $response = $probe->handle($request);
                $pages[$name] = ['status' => $response->status, 'body' => $response->body];
            }
        } catch (\Throwable $e) {
            $pages = [];
        } finally {
            if ($previous !== null) {
                $previous->makeCurrent();
            } else {
                App::clearCurrent();
            }
        }

        $home = isset($pages['home']) ? $pages['home'] : ['status' => 0, 'body' => ''];
        $this->add('live-home', 'error', $home['status'] === 200 && $home['body'] !== '', $home['status'] === 200 && $home['body'] !== ''
            ? t('The home page answers correctly with this theme') : t('The home page does not answer 200 with this theme (answer {status})', ['status' => (string) $home['status']]));
        $error = isset($pages['error']) ? $pages['error'] : ['status' => 0, 'body' => ''];
        $errorOk = $error['status'] === 404 && preg_match('/<main\b[^>]*\bid=["\']main["\']/', $error['body']) === 1;
        $this->add('live-error', 'error', $errorOk, $errorOk ? t('The error page answers 404 inside the theme') : t('The error page does not answer 404 inside the theme (answer {status})', ['status' => (string) $error['status']]));
        $headOk = preg_match('/<link\b[^>]*\brel="canonical"/', $home['body']) === 1 && preg_match('/<meta\b[^>]*\bname="robots"/', $home['body']) === 1;
        $this->add('live-head', 'error', $headOk, $headOk ? t('The head has the canonical link and the robots tag') : t('The head of the home page lacks the canonical link or the robots tag'));
        $emptyLink = preg_match('/<a\b[^>]*\bhref=(?:""|\'\')/i', $home['body']) === 1;
        $this->add('live-links', 'error', !$emptyLink, !$emptyLink ? t('No link with an empty address') : t('The home page has a link with an empty address'));
    }
}
