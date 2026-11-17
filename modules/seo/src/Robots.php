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
use Aifec\Kernel\VisitLog;

/**
 * El contenido de /robots.txt. Siempre cierra el panel, el carrito, la caja, la cuenta, el buscador y los parámetros de filtro;
 * y deja que el comerciante decida, por familia de robots, quién entra:
 *
 *   search     buscadores (Googlebot, Bingbot…)
 *   assistant  robots que leen una página para contestar a una persona (ChatGPT-User, Claude-User, PerplexityBot…)
 *   training   robots que recogen páginas para entrenar modelos (GPTBot, ClaudeBot, CCBot…)
 *
 * Los nombres de agente salen de VisitLog::BOTS, la misma lista con la que el sitio cuenta las visitas de robots.
 */
final class Robots
{
    /** Familias de robots, en el orden en que salen en el fichero. */
    public const FAMILIES = ['search', 'assistant', 'training'];

    /** Clase de VisitLog::BOTS => familia. Las clases «seo» y «other» no tienen familia: les vale la regla general. */
    private const FAMILY_OF = ['search' => 'search', 'ai_search' => 'assistant', 'ai_user' => 'assistant', 'ai_training' => 'training'];

    /** Parámetros de ordenación y de filtro del catálogo y del buscador. */
    private const FILTER_PARAMS = ['sort', 'min', 'max', 'stock', 'sale', 'q'];

    /** Líneas que se aceptan en el texto libre. */
    private const EXTRA_MAX_LINES = 100;

    /**
     * Los agentes de cada familia: familia => lista de nombres tal como van en «User-agent».
     *
     * @return array<string,string[]>
     */
    public static function agents(): array
    {
        $agents = ['search' => [], 'assistant' => [], 'training' => []];
        foreach (VisitLog::BOTS as $token => $info) {
            if (isset(self::FAMILY_OF[$info[2]])) {
                $agents[self::FAMILY_OF[$info[2]]][] = (string) $token;
            }
        }

        return $agents;
    }

    /**
     * Los lectores que abren una página porque una persona se lo acaba de pedir a su asistente (clase «ai_user» de
     * VisitLog::BOTS). No recorren el sitio ni lo indexan: por eso entran también en una tienda cerrada a los buscadores.
     *
     * @return string[]
     */
    public static function personReaders(): array
    {
        $agents = [];
        foreach (VisitLog::BOTS as $token => $info) {
            if ($info[2] === 'ai_user') {
                $agents[] = (string) $token;
            }
        }

        return $agents;
    }

    /**
     * ¿Qué familias están bloqueadas? Una familia se bloquea si «seo.bots» (familia => allow|disallow) o su propio ajuste
     * «seo.bots.<familia>» dicen «disallow». Por defecto no se bloquea ninguna.
     *
     * @return array<string,bool> familia => bloqueada
     */
    public static function blocked(App $app): array
    {
        $settings = $app->settings();
        $map = $settings->arr('seo.bots');
        $blocked = [];
        foreach (self::FAMILIES as $family) {
            $byMap = isset($map[$family]) && is_string($map[$family]) && strtolower(trim($map[$family])) === 'disallow';
            $byField = strtolower(trim($settings->str('seo.bots.' . $family, 'allow'))) === 'disallow';
            $blocked[$family] = $byMap || $byField;
        }

        return $blocked;
    }

    /**
     * Lo que no se debe rastrear: panel, carrito, caja, cuenta, búsqueda (en todos los idiomas) y los parámetros de filtro.
     *
     * @return string[]
     */
    public static function disallowed(App $app): array
    {
        $settings = $app->settings();
        $url = $app->url();
        $words = [];
        foreach (['urls.cart' => 'carrito', 'urls.checkout' => 'finalizar-compra', 'urls.account' => 'mi-cuenta', 'urls.search' => 'buscar'] as $key => $default) {
            $word = trim($settings->str($key, $default), '/');
            $words[] = $word !== '' ? $word : $default;
        }
        $paths = [$url->plain('/admin')];
        foreach ($words as $word) {
            foreach ($app->languages() as $lang) {
                $paths[] = $url->to('/' . $word, [], $lang);
            }
        }
        foreach (self::FILTER_PARAMS as $param) {
            $paths[] = '/*?' . $param . '=';
            $paths[] = '/*&' . $param . '=';
        }
        foreach (['?', '&'] as $join) {
            $paths[] = '/*' . $join . 'f[';
            $paths[] = '/*' . $join . 'f%5B';
        }

        return array_values(array_unique($paths));
    }

    /**
     * Los robots que pueden leer la tienda oculta, del ajuste «seo.noindex_allow» (separados por comas).
     *
     * @return string[]
     */
    public static function hiddenAllowed(App $app): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $app->settings()->str('seo.noindex_allow', '')) ?: [] as $agent) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,59}$/D', $agent) === 1) {
                $out[] = $agent;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * El enlace que abre en la caja el carrito que preparó un asistente (/carrito/retomar/…) sí lo puede abrir el lector de un
     * asistente: lo comprueba antes de dárselo a la persona, y con «Disallow: /carrito» ChatGPT concluía que la tienda no le
     * dejaba entrar y abandonaba la compra (16/11/2026). Esa dirección, abierta por un lector, solo contesta que es para la persona.
     */
    private static function resumeAllow(App $app): string
    {
        if (!$app->modules()->isLoaded('api') || !$app->settings()->bool('api.enabled', true)) {
            return '';
        }
        $word = trim($app->settings()->str('urls.cart', 'carrito'), '/');

        // Y lo que la guía de compra con enlaces les manda abrir: el buscador de la API y la compra misma, que llevan «?q=» o
        // «?text=» y caían en los «Disallow: /*?q=» del grupo (16/11/2026: Claude decía que /api/v1/search estaba bloqueado).
        return 'Allow: ' . $app->url()->plain('/' . ($word !== '' ? $word : 'carrito') . '/retomar/') . "\n"
            . 'Allow: ' . $app->url()->plain('/api/v1/search') . "\n"
            . 'Allow: ' . $app->url()->plain('/buy-with-links') . "\n";
    }

    /** El fichero entero. Con el sitio cerrado a los buscadores solo dice que no entre nadie. */
    public static function build(App $app): string
    {
        $settings = $app->settings();
        if ($app->site()->isDemo() || $settings->bool('seo.noindex_site', false)) {
            // Cerrado a los buscadores, pero no a un asistente que compra para una persona: su lector de páginas obedece este
            // fichero, y sin esta línea no puede abrir la compra con enlaces. Esa página no se indexa (X-Robots-Tag: noindex).
            $links = $app->modules()->isLoaded('api') && $settings->bool('api.enabled', true) && $settings->bool('api.links', true);
            // Con «seo.index_products» (nunca en una demo), las fichas de producto, lo que las pinta y su mapa sí se
            // pueden rastrear: el resto sigue cerrado (17/11/2026, para que Gemini encuentre los productos).
            $products = !$app->site()->isDemo() && $settings->bool('seo.index_products', false) && $app->modules()->isLoaded('catalog');
            $open = '';
            if ($products) {
                $prefix = trim($settings->str('urls.product', 'producto'), '/');
                foreach ([$prefix !== '' ? $prefix : 'producto', 'assets'] as $dir) {
                    $open .= 'Allow: ' . $app->url()->plain('/' . $dir . '/') . "\n";
                }
                // Y la ficha en los otros idiomas («/en/producto/…»).
                $open .= 'Allow: /*/' . ($prefix !== '' ? $prefix : 'producto') . "/\n";
                $open .= 'Allow: ' . $app->url()->plain('/sitemap-products-') . "\n";
            }
            // Y el servidor MCP con su ficha: los clientes y catálogos de MCP los buscan aunque la tienda esté cerrada a los
            // buscadores (17/11/2026). Son JSON y la página de /mcp lleva «noindex».
            $mcp = $app->modules()->isLoaded('mcp') && $settings->bool('mcp.enabled', true) ? "Allow: /mcp\nAllow: /.well-known/\n" : '';
            // Y la clave de IndexNow, que los buscadores comprueban antes de aceptar un aviso.
            $mcp .= IndexNow::key($app) !== '' ? "Allow: /indexnow.txt\n" : '';
            $out = "User-agent: *\n" . ($links ? "Allow: /buy-with-links\nAllow: /*buy-with-links\n" : '') . $mcp . $open . "Disallow: /\n";
            if ($products) {
                $parts = max(1, (new Sitemap($app))->parts('products'));
                for ($n = 1; $n <= $parts; $n++) {
                    $out .= 'Sitemap: ' . $app->url()->abs('/sitemap-products-' . $n . '.xml', [], '') . "\n";
                }
            }
            // Y tampoco está cerrado a quien lee UNA página para una persona en ese momento (ChatGPT-User, Claude-User…): no es
            // un buscador, no indexa nada, y las páginas van con «noindex». Sin este grupo el lector de Claude, que obedece este
            // fichero, no podía leer ni la portada ni una ficha de una tienda en pruebas y contestaba «la tienda me bloquea»
            // (16/11/2026). Se cierra, como en una tienda abierta, bloqueando a los asistentes en los ajustes.
            // Y los robots que la tienda deja pasar aunque esté oculta (seo.noindex_allow): el buscador de ChatGPT, sin el que su
            // herramienta web no abría la tienda (16/11/2026: abría gmartos.es y de aifec.gmartos.es no pedía ni una página).
            $readers = array_values(array_unique(array_merge(self::personReaders(), self::hiddenAllowed($app))));
            if ($readers !== [] && !self::blocked($app)['assistant']) {
                $out .= "\n# Assistants reading one page for a person, right now: allowed (nothing is indexed)\n";
                foreach ($readers as $agent) {
                    $out .= 'User-agent: ' . $agent . "\n";
                }
                $out .= "Allow: /\n" . self::resumeAllow($app);
                foreach (self::disallowed($app) as $path) {
                    $out .= 'Disallow: ' . $path . "\n";
                }
            }

            return $out;
        }
        $lines = [];
        $rules = [];
        foreach (self::disallowed($app) as $path) {
            $rules[] = 'Disallow: ' . $path;
        }

        $lines[] = 'User-agent: *';
        foreach ($rules as $rule) {
            $lines[] = $rule;
        }

        $blocked = self::blocked($app);
        $agents = self::agents();
        $names = ['search' => 'Search engines', 'assistant' => 'AI assistants', 'training' => 'AI training'];
        foreach (self::FAMILIES as $family) {
            if ($agents[$family] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '# ' . $names[$family] . ': ' . ($blocked[$family] ? 'blocked' : 'allowed');
            foreach ($agents[$family] as $agent) {
                $lines[] = 'User-agent: ' . $agent;
            }
            if ($blocked[$family]) {
                $lines[] = 'Disallow: /';
            } else {
                $lines[] = 'Allow: /';
                if ($family === 'assistant' && self::resumeAllow($app) !== '') {
                    $lines[] = trim(self::resumeAllow($app));
                }
                foreach ($rules as $rule) {
                    $lines[] = $rule;
                }
            }
        }

        $extra = self::sanitizeExtra($settings->str('seo.robots_extra'));
        if ($extra !== '') {
            $lines[] = '';
            $lines[] = '# Extra rules';
            $lines[] = $extra;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $app->url()->abs('/sitemap.xml', [], '');

        return implode("\n", $lines) . "\n";
    }

    /**
     * El texto libre del comerciante, solo con lo que un robots.txt admite: User-agent, Allow, Disallow, Crawl-delay,
     * Sitemap y comentarios. Lo demás se descarta, línea a línea. Si las reglas no abren con un User-agent, se les pone «*».
     */
    public static function sanitizeExtra(string $text): string
    {
        $kept = [];
        $needsAgent = true;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $raw) {
            if (count($kept) >= self::EXTRA_MAX_LINES) {
                break;
            }
            $line = trim($raw);
            if ($line === '' || strlen($line) > 300 || preg_match('/[^\x20-\x7E\x{00A0}-\x{10FFFF}]/u', $line) === 1) {
                continue;
            }
            if ($line[0] === '#') {
                $kept[] = str_replace(['<', '>'], '', $line);
                continue;
            }
            if (preg_match('/^(user-agent|allow|disallow|crawl-delay|sitemap)\s*:\s*(.*)$/i', $line, $m) !== 1) {
                continue;
            }
            $directive = strtolower($m[1]);
            $value = trim($m[2]);
            if (strpbrk($value, '<>"\'`\\') !== false) {
                continue;
            }
            if ($directive === 'user-agent') {
                if (preg_match('/^(\*|[A-Za-z0-9._\-]{1,80})$/', $value) !== 1) {
                    continue;
                }
                $needsAgent = false;
                $kept[] = 'User-agent: ' . $value;
            } elseif ($directive === 'crawl-delay') {
                if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) !== 1) {
                    continue;
                }
                $kept[] = ($needsAgent ? "User-agent: *\n" : '') . 'Crawl-delay: ' . $value;
                $needsAgent = false;
            } elseif ($directive === 'sitemap') {
                if (preg_match('#^https?://[^\s]{4,250}$#i', $value) !== 1) {
                    continue;
                }
                $kept[] = 'Sitemap: ' . $value;
            } else {
                // Allow y Disallow: vacío, o una ruta que empieza por «/» o «*».
                if ($value !== '' && preg_match('#^[/*][^\s]{0,250}$#', $value) !== 1) {
                    continue;
                }
                $kept[] = ($needsAgent ? "User-agent: *\n" : '') . ($directive === 'allow' ? 'Allow: ' : 'Disallow: ') . $value;
                $needsAgent = false;
            }
        }

        return implode("\n", $kept);
    }
}
