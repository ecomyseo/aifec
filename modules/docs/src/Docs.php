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

use Aifec\Kernel\App;
use Aifec\Text\Markdown;

/**
 * El servicio «docs»: la carpeta de documentación del proyecto y lo que se saca de cada página (HTML ya saneado, índice
 * de encabezados, descripción). La carpeta es el ajuste «docs.dir» (por defecto `docs`, relativa a la raíz del proyecto).
 */
final class Docs
{
    /** Etiqueta de la caché de página: la orden `docs:refresh` la invalida. */
    public const TAG = 'docs';

    /** @var App */
    private $app;

    /** @var Library|null */
    private $library;

    /** @var array<string,bool> páginas cuyos enlaces rotos ya se han apuntado en el registro */
    private $reported = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function library(): Library
    {
        if ($this->library === null) {
            $this->library = new Library($this->directory());
        }

        return $this->library;
    }

    /** La documentación en inglés vive junto a la de siempre, con este final: «docs» → «docs-en» (17/11/2026). */
    public const ENGLISH_SUFFIX = '-en';

    /**
     * La carpeta de documentación, absoluta: la inglesa («docs-en») cuando se navega en inglés y existe; si no, la de siempre.
     */
    public function directory(): string
    {
        $base = $this->baseDirectory();
        $lang = strtolower((string) $this->app->lang());

        return strpos($lang, 'en') === 0 && is_dir($base . self::ENGLISH_SUFFIX) ? $base . self::ENGLISH_SUFFIX : $base;
    }

    /**
     * La biblioteca de siempre cuando se está leyendo la inglesa, o null: de ella salen las imágenes que la inglesa no tiene
     * (las capturas se comparten).
     */
    public function fallbackLibrary(): ?Library
    {
        $base = $this->baseDirectory();

        return $this->directory() !== $base ? new Library($base) : null;
    }

    /** La carpeta de documentación de siempre, absoluta. Una ruta relativa cuenta desde la raíz del proyecto. */
    public function baseDirectory(): string
    {
        $configured = trim($this->app->settings()->str('docs.dir', 'docs'));
        if ($configured === '') {
            $configured = 'docs';
        }
        $configured = str_replace('\\', '/', $configured);
        if (strpos($configured, '/') === 0 || preg_match('#^[A-Za-z]:/#', $configured) === 1) {
            return rtrim($configured, '/');
        }

        return rtrim(str_replace('\\', '/', $this->app->root()), '/') . '/' . trim($configured, '/');
    }

    /**
     * El HTML de una página: Markdown a HTML, que no deja pasar nada de HTML crudo ni direcciones peligrosas. El primer
     * encabezado `# Título` se quita (la plantilla ya pinta el título como h1) y cualquier otro h1 baja a h2: la página
     * lleva un solo h1.
     */
    public function html(string $markdown, ?array $entry = null, bool $panel = false): string
    {
        return $this->render($markdown, $entry, $panel)['html'];
    }

    /**
     * Lo mismo que {@see html()} y además dice qué enlaces relativos no apuntan a ninguna página conocida y qué imágenes faltan.
     * Con la página que contiene el texto, los enlaces a otras páginas pasan a su dirección publicada, las imágenes de `_media` a la
     * suya (con su tamaño, para que la página no salte al cargarlas) y lo que no existe no se pinta: un enlace roto se queda como
     * texto, sin `href`, y una imagen que falta deja una nota discreta solo a quien tiene abierto el panel.
     *
     * @param array<string,string>|null $entry la página (de Library) a la que pertenece el texto
     * @param bool                      $panel quien lo mira tiene abierto el panel: ve las notas de lo que falta
     *
     * @return array{html:string,broken:string[],missing:string[]}
     */
    public function render(string $markdown, ?array $entry = null, bool $panel = false): array
    {
        $text = (string) preg_replace('/\A(?:\s*\n)*\s*#[ \t]+[^\n]*(?:\n|\z)/', '', $markdown, 1);
        $html = Markdown::toHtml($text);
        $html = (string) preg_replace(['/<h1(\s|>)/', '/<\/h1>/'], ['<h2$1', '</h2>'], $html);
        // Las tablas anchas (la referencia las trae de cinco y seis columnas) se desplazan dentro de su caja, no ensanchan la página.
        $html = str_replace(['<table>', '</table>'], ['<div class="af-docs-table"><table>', '</table></div>'], $html);
        $broken = [];
        $missing = [];
        if ($entry !== null) {
            $html = $this->rewriteLinks($html, $entry, $broken);
            $html = $this->rewriteImages($html, $entry, $panel, $missing);
            $html = (string) preg_replace('#<p>\s*</p>\n?#', '', $html);
            $this->reportBroken($entry, $broken);
        }

        return ['html' => $html, 'broken' => $broken, 'missing' => $missing];
    }

    /**
     * La versión en texto de una página: el Markdown tal cual, salvo que los enlaces a otras páginas pasan a la dirección `.md`
     * de esa página. Lo que no apunta a ninguna se deja como está; los bloques de código y el código en línea no se tocan.
     *
     * @param array<string,string> $entry
     */
    public function textVersion(string $markdown, array $entry): string
    {
        $out = [];
        $fence = '';
        foreach (explode("\n", $markdown) as $line) {
            if ($fence !== '') {
                if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line) === 1) {
                    $fence = '';
                }
                $out[] = $line;
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $m[1];
                $out[] = $line;
                continue;
            }
            $parts = preg_split('/(`+[^`]*`+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
            $parts = is_array($parts) ? $parts : [$line];
            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) {
                    $parts[$i] = (string) preg_replace_callback('/\]\(([^)\s]+)((?:\s+"[^"]*")?)\)/', function (array $m) use ($entry): string {
                        $page = $this->isRelative($m[1]) ? $this->library()->pageFor($entry, $m[1]) : null;
                        if ($page === null) {
                            return $m[0];
                        }

                        return '](' . $this->app->url()->to('/docs/' . $page['path'] . '.md') . $this->anchor($m[1]) . $m[2] . ')';
                    }, $part);
                }
            }
            $out[] = implode('', $parts);
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string,string> $entry
     * @param string[]             $broken
     */
    private function rewriteLinks(string $html, array $entry, array &$broken): string
    {
        return (string) preg_replace_callback('/<a href="([^"]*)"/', function (array $m) use ($entry, &$broken): string {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->isRelative($href)) {
                return $m[0];
            }
            $library = $this->library();
            $url = null;
            $page = $library->pageFor($entry, $href);
            if ($page !== null) {
                $url = $this->app->url()->to('/docs/' . $page['path']) . $this->anchor($href);
            } else {
                $name = $library->mediaName($entry, $href);
                if ($name !== null && $library->media($name) !== null) {
                    $url = $this->app->url()->to('/docs/_media/' . $name);
                }
            }
            if ($url === null) {
                $broken[] = $href;

                return '<a';
            }

            return '<a href="' . e($url) . '"';
        }, $html);
    }

    /**
     * @param array<string,string> $entry
     * @param string[]             $missing
     */
    private function rewriteImages(string $html, array $entry, bool $panel, array &$missing): string
    {
        return (string) preg_replace_callback('/<img src="([^"]*)" alt="([^"]*)"((?: title="[^"]*")?)>/', function (array $m) use ($entry, $panel, &$missing): string {
            $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->isRelative($src)) {
                return $m[0];
            }
            $name = $this->library()->mediaName($entry, $src);
            $info = $name !== null ? $this->library()->media($name) : null;
            if ($name === null || $info === null) {
                $shown = basename(rawurldecode((string) preg_replace('/[#?].*$/s', '', $src)));
                $missing[] = $src;

                return $panel ? '<span class="af-docs-pending">' . e(t('(screenshot pending: {file})', ['file' => $shown])) . '</span>' : '';
            }

            return '<img src="' . e($this->app->url()->to('/docs/_media/' . $name)) . '" alt="' . $m[2] . '"' . $m[3]
                . ' width="' . $info['width'] . '" height="' . $info['height'] . '" loading="lazy">';
        }, $html);
    }

    /** Una dirección relativa: sin protocolo, sin barra ni almohadilla ni signo de pregunta al principio. */
    private function isRelative(string $href): bool
    {
        return $href !== '' && strpos('/#?', $href[0]) === false && preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $href) !== 1;
    }

    /** El `#ancla` de una dirección, lista para ponerla detrás de otra (o vacío). */
    private function anchor(string $href): string
    {
        $pos = strpos($href, '#');
        $fragment = $pos === false ? '' : (string) substr($href, $pos + 1);

        return $fragment === '' ? '' : '#' . rawurlencode(rawurldecode($fragment));
    }

    /**
     * Apunta en el registro, una sola vez por página, los enlaces que no llevan a ninguna parte.
     *
     * @param array<string,string> $entry
     * @param string[]             $broken
     */
    private function reportBroken(array $entry, array $broken): void
    {
        if ($broken === [] || isset($this->reported[$entry['path']])) {
            return;
        }
        $this->reported[$entry['path']] = true;
        $this->app->log()->warning('docs: links that point to no page', [
            'page' => $entry['path'],
            'links' => array_slice(array_values(array_unique($broken)), 0, 20),
        ]);
    }

    /**
     * Los `h2` del HTML de una página, para el índice lateral.
     *
     * @return array<int,array{id:string,text:string}>
     */
    public function headings(string $html): array
    {
        $found = [];
        if (preg_match_all('/<h2 id="([^"]+)">(.*?)<\/h2>/s', $html, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $text = trim(html_entity_decode(strip_tags($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text !== '') {
                    $found[] = ['id' => html_entity_decode($hit[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'text' => $text];
                }
            }
        }

        return $found;
    }

    /** El primer párrafo como texto plano y de no más de 160 caracteres. */
    public function description(string $html): string
    {
        if (preg_match_all('/<p>(.*?)<\/p>/s', $html, $found) < 1) {
            return '';
        }
        // El primer párrafo que tenga texto: uno que solo lleva una imagen no describe nada.
        foreach ($found[1] as $paragraph) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) <= 160) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, 157), " ,;:.") . '…';
        }

        return '';
    }

    /**
     * El texto de una página sin marcas de Markdown: lo que se busca y de donde sale el fragmento.
     */
    public function plain(string $markdown): string
    {
        $text = (string) preg_replace('/^```.*$/m', ' ', $markdown);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/<\/?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?\/?>/', ' ', $text);
        $text = (string) preg_replace('/^[#>\-*+\s]+/m', '', $text);
        $text = (string) preg_replace('/[*_`|]+/', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
