<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;
use Aifec\Kernel\Cache;

/**
 * El catálogo de textos propio del sitio: `<datos>/<sitio>/lang/<idioma>.php`, un array «texto inglés => traducción». Lo lee el
 * motor de idiomas del núcleo por encima de los catálogos del código (I18n::setSiteDir), así que sirve para un idioma que el código
 * no trae (francés, alemán…) y para cambiar una frase del castellano sin tocar el código.
 *
 * Se escribe de forma atómica (Cache::export: un temporal que se renombra) y bajo un cerrojo, para que una persona que guarda y un
 * trabajo de la IA que aprueba a la vez no se pisen. Al guardar se olvida el catálogo fundido de ese idioma y se vacía la caché del
 * sitio (las páginas y las fichas guardadas llevan sus textos dentro).
 */
final class SiteCatalog
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** La carpeta del sitio donde viven los catálogos. */
    public function dir(): string
    {
        return $this->app->site()->path('lang');
    }

    /**
     * @throws \InvalidArgumentException si el idioma no es un código de dos letras
     */
    public function file(string $lang): string
    {
        if (preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            throw new \InvalidArgumentException('Invalid language code');
        }

        return $this->dir() . '/' . $lang . '.php';
    }

    /**
     * Las traducciones del sitio en un idioma (solo las que tienen texto).
     *
     * @return array<string,string>
     */
    public function all(string $lang): array
    {
        if (preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            return [];
        }

        return self::clean(Cache::import($this->file($lang)));
    }

    /**
     * Cambia varias traducciones de una vez: un texto se guarda; una cadena vacía quita la traducción del sitio (y vuelve la de
     * serie). Devuelve cuántas han cambiado de verdad.
     *
     * @param array<string,string> $changes texto inglés => traducción
     *
     * @throws \RuntimeException si no se puede escribir el fichero
     */
    public function update(string $lang, array $changes): int
    {
        $file = $this->file($lang);
        if ($changes === []) {
            return 0;
        }
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the language folder');
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock the language folder');
        }
        $changed = 0;
        try {
            flock($lock, LOCK_EX);
            $current = self::clean(Cache::import($file));
            foreach ($changes as $key => $text) {
                $key = (string) $key;
                if ($key === '') {
                    continue;
                }
                $text = (string) $text;
                $before = isset($current[$key]) ? $current[$key] : '';
                if ($text === $before) {
                    continue;
                }
                if ($text === '') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $text;
                }
                ++$changed;
            }
            if ($changed > 0) {
                ksort($current, SORT_STRING);
                if (!Cache::export($file, $current)) {
                    throw new \RuntimeException('Cannot write the language file');
                }
                clearstatcache(true, $file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($changed > 0) {
            // Una frase de la interfaz va dentro de todo lo que se guarda ya pintado (páginas, fichas de producto con su
            // disponibilidad, bloques): se vacía la caché entera del sitio. Las sesiones y los límites viven en la base de datos.
            $this->app->i18n()->forget($lang);
            $this->app->cache()->flush();
        }

        return $changed;
    }

    /**
     * @param mixed $data
     *
     * @return array<string,string>
     */
    private static function clean($data): array
    {
        $out = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if ((is_string($key) || is_int($key)) && (string) $key !== '' && is_string($value) && $value !== '') {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
