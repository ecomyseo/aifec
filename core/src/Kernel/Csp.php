<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

use Aifec\Text\HtmlSanitizer;

/**
 * La política de seguridad de contenido (cabecera Content-Security-Policy) de las páginas HTML.
 *
 * Por defecto es ESTRICTA: los guiones solo se cargan de la propia tienda; un guion escrito dentro de la página solo se ejecuta
 * si su huella está en la cabecera (la pone quien lo pinta, con inline()); nada de objetos incrustados, de <base> ajenos ni de
 * meter la tienda en un marco de otra web. Lo que inyecte un atacante en una página (un «<script>» en un comentario, en un
 * nombre, en una dirección) no se ejecuta.
 *
 * Los módulos y los plugins que necesitan algo de fuera lo declaran con el filtro «csp»:
 *
 *     $app->hooks()->filter('csp', static function (Csp $csp) { return $csp->add('frame-src', 'https://mapas.example'); });
 *
 * Y quien carga guiones de terceros que escriben los suyos (un gestor de etiquetas) pide la política RELAJADA con relax(): los
 * guiones dejan de estar limitados, y el resto de la política sigue en pie.
 *
 * Ajustes: «security.csp» (strict, relaxed u off) y «security.csp_sources» (orígenes de más, uno por línea).
 */
final class Csp
{
    /** El guion que pone el tema claro u oscuro del panel antes de pintar nada (va escrito en la página: no puede esperar). */
    public const ADMIN_THEME_JS = "(function(){try{var t=localStorage.getItem('af-theme');if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches){t='dark';}if(t){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();";

    /** Las directivas que cargan algo: un origen de más (ajustes) se añade a todas estas. */
    private const FETCH = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src'];

    /** @var array<string,string[]> */
    private $directives = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        // Los estilos escritos en la página (atributo style, <style> del tema) se permiten: no ejecutan nada.
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
        'media-src' => ["'self'", 'data:', 'blob:', 'https:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'frame-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];

    /** @var bool */
    private $relaxed = false;

    /** La política de esta respuesta, o '' si está apagada. Nunca lanza: si algo falla, queda la mínima. */
    public static function build(App $app, Request $request): string
    {
        try {
            $mode = $app->settings()->str('security.csp', 'strict');
            if ($mode === 'off') {
                return '';
            }
            $csp = new self();
            if ($mode === 'relaxed') {
                $csp->relax();
            }
            $csp->inline(self::ADMIN_THEME_JS);
            // Los vídeos incrustados en el contenido se ven en un marco de su servicio.
            foreach (HtmlSanitizer::EMBED_HOSTS as $host) {
                $csp->add('frame-src', 'https://' . $host);
            }
            foreach (self::origins($app->settings()->str('security.csp_sources', '')) as $origin) {
                $csp->allow($origin);
            }
            $filtered = $app->hooks()->apply('csp', $csp, $request, $app);
            if ($filtered instanceof self) {
                $csp = $filtered;
            }

            return $csp->header($request->secure);
        } catch (\Throwable $e) {
            return "object-src 'none'; base-uri 'self'; frame-ancestors 'self'";
        }
    }

    /** Añade fuentes a una directiva (la crea si no estaba). Lo que no tiene forma de fuente se descarta. */
    public function add(string $directive, string ...$sources): self
    {
        $directive = strtolower(trim($directive));
        if (preg_match('/^[a-z][a-z-]{2,40}$/', $directive) !== 1) {
            return $this;
        }
        if (!isset($this->directives[$directive])) {
            $this->directives[$directive] = [];
        }
        foreach ($sources as $source) {
            $source = trim($source);
            if ($source !== '' && self::isSource($source) && !in_array($source, $this->directives[$directive], true)) {
                $this->directives[$directive][] = $source;
            }
        }

        return $this;
    }

    /** Un origen de fuera («https://cdn.example») permitido para todo lo que se carga: guiones, estilos, imágenes, letras, peticiones y marcos. */
    public function allow(string $origin): self
    {
        foreach (self::FETCH as $directive) {
            $this->add($directive, $origin);
        }

        return $this;
    }

    /** Permite un guion escrito dentro de la página: tiene que ser, letra por letra, lo que va entre <script> y </script>. */
    public function inline(string $js): self
    {
        return $this->add('script-src', self::hash($js));
    }

    /** La huella de un guion escrito en la página, como va en la cabecera. */
    public static function hash(string $js): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $js, true)) . "'";
    }

    /**
     * Política relajada: los guiones dejan de estar limitados (de cualquier web segura y escritos en la página). La pide quien
     * carga un gestor de etiquetas o un píxel, que escriben sus propios guiones. Lo demás (objetos, <base>, marcos) no cambia.
     */
    public function relax(): self
    {
        $this->relaxed = true;

        return $this;
    }

    public function relaxed(): bool
    {
        return $this->relaxed;
    }

    /** @return string[] las fuentes de una directiva, como saldrán en la cabecera */
    public function sources(string $directive): array
    {
        $sources = isset($this->directives[$directive]) ? $this->directives[$directive] : [];
        if (!$this->relaxed) {
            return $sources;
        }
        if ($directive === 'script-src') {
            // Con una huella en la lista el navegador deja de hacer caso a 'unsafe-inline': fuera las huellas.
            $sources = array_values(array_filter($sources, static function (string $source): bool {
                return strpos($source, "'sha256-") !== 0;
            }));

            return array_values(array_unique(array_merge($sources, ["'unsafe-inline'", 'https:'])));
        }
        if (in_array($directive, ['connect-src', 'frame-src', 'style-src', 'font-src'], true)) {
            return array_values(array_unique(array_merge($sources, ['https:'])));
        }

        return $sources;
    }

    /** La cabecera entera. Con la tienda en https, además, lo que quede escrito con http se pide por https. */
    public function header(bool $secure = false): string
    {
        $parts = [];
        foreach (array_keys($this->directives) as $directive) {
            $sources = $this->sources($directive);
            if ($sources !== []) {
                $parts[] = $directive . ' ' . implode(' ', $sources);
            }
        }
        if ($secure) {
            $parts[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $parts);
    }

    /**
     * Los orígenes escritos en los ajustes: uno por línea (o separados por espacios o comas), con su https://. Lo que no lo sea
     * se descarta sin romper nada.
     *
     * @return string[]
     */
    public static function origins(string $text): array
    {
        $out = [];
        $parts = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($parts) ? $parts : [] as $part) {
            $part = rtrim($part, '/');
            if (preg_match('/^(https|wss):\/\/(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d{1,5})?$/i', $part) === 1 && !in_array(strtolower($part), $out, true)) {
                $out[] = strtolower($part);
            }
        }

        return $out;
    }

    /** ¿Tiene forma de fuente de una política? Una palabra entre comillas simples, un esquema o un origen; nunca un «;» ni un salto. */
    private static function isSource(string $source): bool
    {
        if (preg_match('/[\s;,]/', $source) === 1) {
            return false;
        }

        return preg_match('/^\'(self|none|unsafe-inline|unsafe-eval|strict-dynamic|wasm-unsafe-eval|sha(256|384|512)-[A-Za-z0-9+\/=]+|nonce-[A-Za-z0-9+\/=_-]+)\'$/', $source) === 1
            || preg_match('/^(https|wss|data|blob|mediastream):$/', $source) === 1
            || preg_match('/^(https|wss):\/\/(\*\.)?[A-Za-z0-9.-]+(:\d{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/', $source) === 1;
    }
}
