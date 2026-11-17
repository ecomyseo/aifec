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

/**
 * Traducción de los textos de la interfaz y formato de números, dinero y fechas.
 *
 * En el código, los textos van en inglés: t('Add to cart'). Cada módulo, plugin y tema trae su
 * catálogo por idioma en lang/<idioma>.php, que devuelve ['Add to cart' => 'Añadir al carrito'].
 * Si falta una traducción se enseña el texto fuente y, en modo de depuración, queda apuntada.
 *
 * Variables: t('Hello {name}', ['name' => $n]). Plurales: tn('{n} item', '{n} items', $n), con la
 * clave «{n} item|{n} items» y el valor «{n} artículo|{n} artículos» en el catálogo.
 */
final class I18n
{
    /** @var string */
    private $locale;

    /** @var string[] carpetas lang/ registradas, por orden (la última gana) */
    private $dirs = [];

    /** @var array<string,array<string,string>> idioma => catálogo ya cargado */
    private $catalogs = [];

    /** @var array<string,bool> textos sin traducción vistos en esta petición */
    private $missing = [];

    /** @var string carpeta donde se guardan los catálogos fundidos (lang-<idioma>.php); vacío = sin fundir */
    private $compiledDir = '';

    /** @var string firma de la lista de carpetas para la que valen los catálogos fundidos */
    private $compiledFor = '';

    /** @var bool si se pueden escribir los catálogos fundidos (no en una vista previa) */
    private $compiledWritable = true;

    /** @var string carpeta de los catálogos propios del sitio (<datos>/<sitio>/lang); vacío = ninguna */
    private $siteDir = '';

    public function __construct(string $locale = 'es')
    {
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** Idioma sin región: «es» de «es_ES». */
    public function lang(): string
    {
        return strtolower(substr($this->locale, 0, 2));
    }

    public function addDir(string $dir): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!in_array($dir, $this->dirs, true)) {
            $this->dirs[] = $dir;
            $this->catalogs = [];
        }
    }

    /**
     * Catálogos propios del sitio: `<carpeta>/<idioma>.php`, un array «texto inglés => traducción» que el comercio escribe desde
     * el panel. Mandan sobre los de núcleo, módulos, plugins y temas, se registren estos antes o después. El catálogo fundido los
     * incluye y se rehace solo cuando cambia ese fichero (o con forget()).
     */
    public function setSiteDir(string $dir): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir !== $this->siteDir) {
            $this->siteDir = $dir;
            $this->catalogs = [];
        }
    }

    public function siteDir(): string
    {
        return $this->siteDir;
    }

    /**
     * Olvida lo ya cargado de un idioma (o de todos, con null) y borra su catálogo fundido: tras guardar una traducción del sitio,
     * esta petición y las siguientes la leen ya.
     */
    public function forget(?string $lang = null): void
    {
        $this->catalogs = [];
        if ($this->compiledDir === '') {
            return;
        }
        if ($lang !== null) {
            $files = preg_match('/^[a-z]{2}$/', $lang) === 1 ? [$this->compiledDir . '/lang-' . $lang . '.php'] : [];
        } else {
            $found = glob($this->compiledDir . '/lang-*.php');
            $files = is_array($found) ? $found : [];
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
            }
        }
    }

    /**
     * Catálogos fundidos: a partir de aquí, el catálogo de cada idioma sale de UN fichero (`<dir>/lang-<idioma>.php`) que
     * reúne todos los de las carpetas registradas hasta ahora, en vez de abrir un fichero por módulo. El fundido vale
     * solo para esta lista exacta de carpetas y esta construcción ($build, que cambia cuando cambia algún catálogo): si
     * alguien añade otra carpeta después, se vuelve a leer carpeta a carpeta como siempre.
     *
     * @param string $dir      carpeta de la caché del sitio
     * @param string $build    identificador de la construcción de la caché de arranque
     * @param bool   $writable false en una vista previa: se usa lo fundido, pero no se escribe
     */
    public function useCompiled(string $dir, string $build, bool $writable = true): void
    {
        $this->compiledDir = rtrim(str_replace('\\', '/', $dir), '/');
        $this->compiledFor = $this->dirsSignature($build);
        $this->compiledWritable = $writable;
        $this->catalogs = [];
    }

    /**
     * Carpetas registradas, por orden.
     *
     * @return string[]
     */
    public function dirs(): array
    {
        return $this->dirs;
    }

    /**
     * @param array<string,string|int|float> $vars
     */
    public function t(string $text, array $vars = [], ?string $locale = null): string
    {
        $lang = $locale !== null ? strtolower(substr($locale, 0, 2)) : $this->lang();
        $catalog = $this->catalog($lang);
        if (isset($catalog[$text]) && $catalog[$text] !== '') {
            $out = $catalog[$text];
        } else {
            $out = $text;
            if ($lang !== 'en') {
                $this->missing[$text] = true;
            }
        }
        if ($vars !== []) {
            $replace = [];
            foreach ($vars as $key => $value) {
                $replace['{' . $key . '}'] = (string) $value;
            }
            $out = strtr($out, $replace);
        }

        return $out;
    }

    /**
     * @param array<string,string|int|float> $vars
     */
    public function tn(string $singular, string $plural, int $n, array $vars = [], ?string $locale = null): string
    {
        $lang = $locale !== null ? strtolower(substr($locale, 0, 2)) : $this->lang();
        $catalog = $this->catalog($lang);
        $key = $singular . '|' . $plural;
        $forms = [$singular, $plural];
        if (isset($catalog[$key]) && strpos($catalog[$key], '|') !== false) {
            $forms = explode('|', $catalog[$key], 2);
        } elseif ($lang !== 'en') {
            $this->missing[$key] = true;
        }
        $out = $n === 1 ? $forms[0] : $forms[1];
        $replace = ['{n}' => (string) $n];
        foreach ($vars as $k => $value) {
            $replace['{' . $k . '}'] = (string) $value;
        }

        return strtr($out, $replace);
    }

    /**
     * @return string[] textos pedidos en esta petición que no tenían traducción
     */
    public function missing(): array
    {
        return array_keys($this->missing);
    }

    /**
     * Importe en céntimos con su moneda: money(4999, 'EUR') → «49,99 €».
     */
    public function money(int $cents, string $currency = 'EUR', ?string $locale = null): string
    {
        $locale = $locale !== null ? $locale : $this->locale;
        $decimals = in_array($currency, ['JPY', 'HUF', 'CLP', 'KRW'], true) ? 0 : 2;
        $amount = $decimals === 0 ? (float) $cents : $cents / 100;
        if (class_exists('NumberFormatter')) {
            $fmt = new \NumberFormatter($this->icuLocale($locale), \NumberFormatter::CURRENCY);
            $out = $fmt->formatCurrency($amount, $currency);
            if (is_string($out)) {
                return str_replace("\u{202f}", "\u{a0}", $out);
            }
        }
        $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
        $symbol = isset($symbols[$currency]) ? $symbols[$currency] : $currency;
        if (strpos($locale, 'en') === 0) {
            return $symbol . number_format($amount, $decimals, '.', ',');
        }

        // Como hace ICU en castellano: las cifras de cuatro dígitos van sin punto de millares («6099,00 €»).
        return number_format($amount, $decimals, ',', abs($amount) < 10000 ? '' : '.') . "\u{a0}" . $symbol;
    }

    public function number(float $value, int $decimals = 0, ?string $locale = null): string
    {
        $locale = $locale !== null ? $locale : $this->locale;

        return strpos($locale, 'en') === 0
            ? number_format($value, $decimals, '.', ',')
            : number_format($value, $decimals, ',', '.');
    }

    /**
     * Fecha a partir de una marca de tiempo, en la zona horaria del sitio.
     *
     * @param string $style short («12/11/2026»), medium («12 nov 2026»), long («12 de noviembre de 2026») o datetime
     */
    public function date(int $timestamp, string $style = 'medium', string $timezone = 'Europe/Madrid', ?string $locale = null): string
    {
        $locale = $locale !== null ? $locale : $this->locale;
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        if (class_exists('IntlDateFormatter')) {
            $styles = [
                'short' => [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE],
                'medium' => [\IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE],
                'long' => [\IntlDateFormatter::LONG, \IntlDateFormatter::NONE],
                'datetime' => [\IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT],
            ];
            $s = isset($styles[$style]) ? $styles[$style] : $styles['medium'];
            $fmt = new \IntlDateFormatter($this->icuLocale($locale), $s[0], $s[1], $tz);
            $out = $fmt->format($date);
            if (is_string($out)) {
                return $out;
            }
        }
        if ($style === 'datetime') {
            return $date->format(strpos($locale, 'en') === 0 ? 'Y-m-d H:i' : 'd/m/Y H:i');
        }

        return $date->format(strpos($locale, 'en') === 0 ? 'Y-m-d' : 'd/m/Y');
    }

    /**
     * @return array<string,string>
     */
    private function catalog(string $lang): array
    {
        if (isset($this->catalogs[$lang])) {
            return $this->catalogs[$lang];
        }
        $compiled = '';
        $site = $this->siteStamp($lang);
        if ($this->compiledDir !== '' && preg_match('/^[a-z]{2}$/', $lang) === 1) {
            $signature = $this->compiledFor;
            if ($signature !== '' && substr($signature, 0, 32) === md5(implode('|', $this->dirs))) {
                $compiled = $this->compiledDir . '/lang-' . $lang . '.php';
                $copy = Cache::import($compiled);
                if (is_array($copy) && isset($copy['sig'], $copy['catalog']) && $copy['sig'] === $signature && is_array($copy['catalog'])
                    && (isset($copy['site']) ? (string) $copy['site'] : '') === $site) {
                    $this->catalogs[$lang] = $copy['catalog'];

                    return $copy['catalog'];
                }
            }
        }
        $catalog = [];
        foreach ($this->dirs as $dir) {
            $file = $dir . '/' . $lang . '.php';
            if (is_file($file)) {
                $data = require $file;
                if (is_array($data)) {
                    $catalog = $data + $catalog;
                }
            }
        }
        if ($site !== '') {
            $catalog = $this->siteCatalog($lang) + $catalog;
        }
        $this->catalogs[$lang] = $catalog;
        if ($compiled !== '' && $this->compiledWritable) {
            Cache::export($compiled, ['sig' => $this->compiledFor, 'site' => $site, 'catalog' => $catalog]);
        }

        return $catalog;
    }

    /** Huella del catálogo propio del sitio en un idioma («fecha:tamaño»); vacío si no hay carpeta o no hay fichero. */
    private function siteStamp(string $lang): string
    {
        if ($this->siteDir === '' || preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            return '';
        }
        $file = $this->siteDir . '/' . $lang . '.php';
        if (!is_file($file)) {
            return '';
        }
        $time = filemtime($file);
        $size = filesize($file);

        return ($time !== false ? $time : 0) . ':' . ($size !== false ? $size : 0);
    }

    /**
     * El catálogo propio del sitio en un idioma: solo las parejas con texto (un valor vacío o que no es texto no cuenta).
     *
     * @return array<string,string>
     */
    private function siteCatalog(string $lang): array
    {
        $data = Cache::import($this->siteDir . '/' . $lang . '.php');
        $out = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if ((is_string($key) || is_int($key)) && (string) $key !== '' && is_string($value) && $value !== '') {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /** Firma de la lista de carpetas actual y de la construcción: md5(carpetas) seguido de md5(construcción). */
    private function dirsSignature(string $build): string
    {
        return md5(implode('|', $this->dirs)) . md5($build);
    }

    private function icuLocale(string $locale): string
    {
        if (strlen($locale) === 2) {
            $regions = ['es' => 'es_ES', 'en' => 'en_GB', 'fr' => 'fr_FR', 'de' => 'de_DE', 'it' => 'it_IT', 'pt' => 'pt_PT', 'ca' => 'ca_ES'];

            return isset($regions[$locale]) ? $regions[$locale] : $locale;
        }

        return $locale;
    }
}
