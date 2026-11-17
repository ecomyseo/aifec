<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Publicado como el servicio «consent»: las categorías, la versión de la política, la decisión de quien visita (leída de su cookie),
 * el bloqueo de guiones de terceros y todo lo que se pinta en la tienda.
 *
 * Nada de lo que se pinta depende de la decisión: el HTML del aviso y de los guiones declarados es el mismo para todo el mundo,
 * así que las páginas siguen siendo cacheables. Quien activa lo concedido es el guion consent.js, en el navegador.
 *
 * Las cookies de terceros las declaran los otros módulos con el filtro «consent.cookies» (una lista de filas name, provider,
 * purpose, duration, category).
 */
final class ConsentService
{
    /** Las categorías en el orden en que se enseñan. */
    public const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];

    /** Nombre de cada categoría en otros idiomas o con otras palabras, ya sin tildes ni mayúsculas. */
    private const ALIASES = [
        'necessary' => 'necessary', 'necesarias' => 'necessary', 'necesaria' => 'necessary', 'essential' => 'necessary',
        'preferences' => 'preferences', 'preferencias' => 'preferences', 'functional' => 'preferences', 'funcionales' => 'preferences',
        'statistics' => 'statistics', 'estadisticas' => 'statistics', 'estadistica' => 'statistics', 'analytics' => 'statistics', 'analitica' => 'statistics',
        'marketing' => 'marketing', 'publicidad' => 'marketing', 'advertising' => 'marketing',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ ajustes

    public function enabled(): bool
    {
        return $this->app->settings()->bool('consent.enabled', true);
    }

    /** Versión de la política: al cambiarla, todo el mundo vuelve a decidir. */
    public function version(): int
    {
        return max(1, $this->app->settings()->int('consent.version', 1));
    }

    public function cookieName(): string
    {
        return $this->app->cookieName('consent');
    }

    /** Dirección de la dirección del registro (POST). */
    public function recordUrl(): string
    {
        return $this->app->url()->route('consent.record');
    }

    // ------------------------------------------------------------------ categorías

    /**
     * El nombre canónico de una categoría escrita de cualquier manera («estadísticas», «Analytics»…). Lo que no se reconoce cuenta
     * como marketing: el bloqueo más estricto, nunca el más laxo.
     */
    public static function normalize(string $category): string
    {
        $key = strtolower(trim(strtr($category, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n'])));

        return isset(self::ALIASES[$key]) ? self::ALIASES[$key] : 'marketing';
    }

    /**
     * Las categorías con su texto.
     *
     * @return array<int,array{code:string,letter:string,label:string,description:string,locked:bool}>
     */
    public function categories(): array
    {
        $texts = [
            'necessary' => [t('Necessary'), t('They keep the shop working: the cart, your session and your cookie choices. They cannot be turned off.')],
            'preferences' => [t('Preferences'), t('They remember choices you make, such as your language or the chat with the assistant.')],
            'statistics' => [t('Statistics'), t('They tell us how the shop is used, without knowing who you are, so we can improve it.')],
            'marketing' => [t('Marketing'), t('They show you adverts that match your interests and measure how well they work.')],
        ];
        $out = [];
        foreach (self::CATEGORIES as $code) {
            $out[] = [
                'code' => $code, 'letter' => $code === 'necessary' ? '' : ConsentCookie::LETTERS[$code],
                'label' => $texts[$code][0], 'description' => $texts[$code][1], 'locked' => $code === 'necessary',
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ la decisión de quien visita

    /**
     * La decisión de esta petición, leída de la cookie (null = no ha decidido, o decidió con otra versión de la política).
     *
     * @return array{version:int,letters:string,categories:string[],time:int,id:string}|null
     */
    public function decision(?Request $request = null): ?array
    {
        $request = $request !== null ? $request : $this->app->request();

        return ConsentCookie::parse($request->cookie($this->cookieName()), $this->version());
    }

    /**
     * ¿Puede el servidor tratar como concedida esta categoría para esta petición? Sin decisión, solo la necesaria. La señal Global
     * Privacy Control (cabecera «Sec-GPC: 1») deniega el marketing aunque la cookie lo diga.
     */
    public function allows(string $category, ?Request $request = null): bool
    {
        $request = $request !== null ? $request : $this->app->request();
        $category = self::normalize($category);
        if ($category === 'marketing' && trim((string) $request->header('sec-gpc', '')) === '1') {
            return false;
        }

        return ConsentCookie::allows($this->decision($request), $category);
    }

    // ------------------------------------------------------------------ inventario de cookies

    /**
     * Todas las cookies declaradas: las de la tabla (con el sufijo del sitio en las acabadas en «*») y las que añaden otros módulos.
     *
     * @return array<int,array{name:string,provider:string,purpose:string,duration:string,category:string}>
     */
    public function cookies(): array
    {
        $suffix = $this->app->site()->cookieSuffix();
        $rows = $this->app->db()->select('SELECT name, provider, purpose, duration, category, builtin FROM {consent_cookies} ORDER BY position, id', []);
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'name' => substr((string) $row['name'], -1) === '*' ? substr((string) $row['name'], 0, -1) . $suffix : (string) $row['name'],
                'provider' => !empty($row['builtin']) ? t((string) $row['provider']) : (string) $row['provider'],
                'purpose' => !empty($row['builtin']) ? t((string) $row['purpose']) : (string) $row['purpose'],
                'duration' => !empty($row['builtin']) ? t((string) $row['duration']) : (string) $row['duration'],
                'category' => (string) $row['category'],
            ];
        }
        $extra = $this->app->hooks()->apply('consent.cookies', []);
        foreach (is_array($extra) ? $extra : [] as $cookie) {
            if (is_array($cookie) && isset($cookie['name'])) {
                $list[] = [
                    'name' => (string) $cookie['name'], 'provider' => isset($cookie['provider']) ? (string) $cookie['provider'] : '',
                    'purpose' => isset($cookie['purpose']) ? (string) $cookie['purpose'] : '', 'duration' => isset($cookie['duration']) ? (string) $cookie['duration'] : '',
                    'category' => self::normalize(isset($cookie['category']) ? (string) $cookie['category'] : 'marketing'),
                ];
            }
        }

        return $list;
    }

    /**
     * Las cookies de una categoría.
     *
     * @return array<int,array{name:string,provider:string,purpose:string,duration:string,category:string}>
     */
    public function cookiesOf(string $category): array
    {
        $out = [];
        foreach ($this->cookies() as $cookie) {
            if ($cookie['category'] === $category) {
                $out[] = $cookie;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ bloqueo de guiones de terceros

    /**
     * Un guion de terceros que solo se activa con su categoría concedida. Recibe una dirección (http, https, «//» o de la propia
     * web) o código. Sale como «type="text/plain"»: el navegador no lo ejecuta ni descarga nada; consent.js lo activa si toca.
     * Una categoría sin reconocer cuenta como marketing. Con «necessary» el guion sale normal. Los atributos extra se limpian.
     *
     * @param array<string,string> $attrs atributos adicionales (async, defer, id, data-…)
     */
    public function script(string $category, string $srcOrCode, array $attrs = []): string
    {
        $category = self::normalize($category);
        $value = trim($srcOrCode);
        $extra = self::attributes($attrs, ['type', 'src', 'data-consent', 'data-src']);
        $isSrc = self::isSrc($value);
        if (!$isSrc && strpos($value, '<!--') !== false) {
            // «<!--» seguido de «<script» cambia cómo el navegador cierra la etiqueta y se tragaría el resto de la página: no sale.
            return '';
        }
        if ($category === 'necessary') {
            return $isSrc ? '<script src="' . e($value) . '"' . $extra . '></script>' : '<script' . $extra . '>' . self::safeCode($value) . '</script>';
        }
        $base = '<script type="text/plain" data-consent="' . $category . '"';
        if ($isSrc) {
            return $base . ' data-src="' . e($value) . '"' . $extra . '></script>';
        }

        return $base . $extra . '>' . self::safeCode($value) . '</script>';
    }

    /**
     * Un marco (vídeo, mapa) que no se carga hasta que la categoría esté concedida. Solo direcciones http o https. Sin dirección
     * válida, no sale nada. consent.js pone en su lugar un aviso «Acepta para ver».
     *
     * @param array<string,string> $attrs atributos adicionales (title, width, height, loading…)
     */
    public function iframe(string $category, string $src, array $attrs = []): string
    {
        $src = trim($src);
        if (preg_match('#^https?://[^\s"\'<>]+$#i', $src) !== 1) {
            return '';
        }
        $category = self::normalize($category);
        $extra = self::attributes($attrs, ['src', 'data-consent', 'data-src', 'srcdoc']);
        if ($category === 'necessary') {
            return '<iframe src="' . e($src) . '"' . $extra . '></iframe>';
        }

        return '<iframe data-consent="' . $category . '" data-src="' . e($src) . '"' . $extra . '></iframe>';
    }

    /** ¿Es una dirección (y no código)? */
    private static function isSrc(string $value): bool
    {
        return preg_match('#^(https?:)?//[^\s"\'<>]+$#i', $value) === 1 || preg_match('#^/[^\s/"\'<>][^\s"\'<>]*$#', $value) === 1;
    }

    /** El código de un guion sin lo que cerraría la etiqueta. */
    private static function safeCode(string $code): string
    {
        return str_ireplace('</script', '<\/script', $code);
    }

    /**
     * Atributos limpios: solo nombres de letras, cifras, guiones y guiones bajos, sin los que no se pueden tocar ni los «on…».
     *
     * @param array<string,mixed> $attrs
     * @param string[]            $reserved
     */
    private static function attributes(array $attrs, array $reserved): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            $name = strtolower((string) $name);
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1 || in_array($name, $reserved, true) || strpos($name, 'on') === 0) {
                continue;
            }
            $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . e((string) $value) . '"';
        }

        return $out;
    }

    // ------------------------------------------------------------------ lo que se pinta

    /** Lo que va en la cabecera: el valor por defecto de Consent Mode v2 (antes de cualquier etiqueta) y el guion. */
    public function head(): string
    {
        if (!$this->enabled()) {
            return '';
        }

        return '<script>' . $this->headJs() . '</script>'
            . '<script src="' . e($this->app->url()->asset('m', 'consent', 'consent.js')) . '" defer></script>';
    }

    /** El guion que va escrito en la cabecera, letra por letra: de aquí sale también su huella para la política de contenido. */
    public function headJs(): string
    {
        return 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}'
            . '(function(w,n,v){var m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([^;]*)")),p=m?decodeURIComponent(m[1]).split("."):[],'
            . 'ok=p[0]==v&&p.length>3,c=ok?p[1]:"",g=navigator.globalPrivacyControl===true;if(g){c=c.replace("m","")}'
            . 'var f=function(l){return c.indexOf(l)>-1?"granted":"denied"};'
            . 'gtag("consent","default",{ad_storage:f("m"),ad_user_data:f("m"),ad_personalization:f("m"),analytics_storage:f("s"),'
            . 'functionality_storage:f("p"),personalization_storage:f("p"),security_storage:"granted",wait_for_update:ok?0:500});'
            . 'w.AifecConsent={name:n,version:v,gpc:g,letters:ok?c:null}})(window,' . json_encode($this->cookieName()) . ',' . $this->version() . ');';
    }

    /** El aviso y su segunda capa. El mismo HTML para todo el mundo. */
    public function notice(): string
    {
        if (!$this->enabled()) {
            return '';
        }
        $settings = $this->app->settings();
        $groups = [];
        foreach ($this->categories() as $category) {
            $category['cookies'] = $this->cookiesOf($category['code']);
            $groups[] = $category;
        }

        return $this->app->view()->render('consent::front/notice', [
            'title' => trim($settings->str('consent.title', '')) !== '' ? $settings->str('consent.title', '') : t('We use cookies'),
            'message' => trim($settings->str('consent.message', '')) !== '' ? $settings->str('consent.message', '')
                : t('We use our own cookies to make the shop work and, only if you agree, cookies to measure visits and show adverts. You can change your mind at any time.'),
            'policyUrl' => $this->policyUrl(),
            'groups' => $groups,
            'cookie' => $this->cookieName(),
            'version' => $this->version(),
            'path' => $this->app->cookiePath(),
            'record' => $this->app->router()->has('consent.record') && $settings->bool('consent.log', true) ? $this->recordUrl() : '',
            'css' => $this->app->url()->asset('m', 'consent', 'consent.css'),
        ]);
    }

    /** Enlace a la política de cookies: una dirección completa o de la propia web; si no es válida, no sale. */
    public function policyUrl(): string
    {
        $url = trim($this->app->settings()->str('consent.policy_url', ''));
        if ($url === '') {
            // Sin dirección propia, la página /cookies de la propia web (la política publicada o la tabla de las cookies de verdad).
            return $this->app->url()->plain('/cookies');
        }

        return preg_match('#^(https?://|/)[^\s"\'<>]*$#i', $url) === 1 && strpos($url, '//') !== 0 ? $url : '';
    }

    /** El botón «Cambiar mis preferencias» para la política de cookies. */
    public function preferencesButton(): string
    {
        return '<button type="button" class="af-btn" data-af-consent-open>' . e(t('Change my cookie preferences')) . '</button>';
    }

    /** La tabla de cookies, agrupada por categoría, para la política. */
    public function cookieTable(): string
    {
        return $this->app->view()->render('consent::front/table', ['groups' => $this->groupedCookies()]);
    }

    /**
     * @return array<int,array{label:string,cookies:array<int,array<string,string>>}>
     */
    private function groupedCookies(): array
    {
        $groups = [];
        foreach ($this->categories() as $category) {
            $groups[] = ['label' => $category['label'], 'cookies' => $this->cookiesOf($category['code'])];
        }

        return $groups;
    }
}
