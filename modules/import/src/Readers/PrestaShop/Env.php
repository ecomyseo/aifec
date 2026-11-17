<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Modules\Import\Neutral;

/**
 * Lo que hay que saber de la tienda para convertir sus datos: configuración, idiomas, monedas, países, provincias,
 * impuestos y estados de pedido. Los dos lectores (servicio web y copia de la base de datos) lo rellenan con el mismo
 * formato y el Mapper convierte desde aquí, así que un producto sale igual lo lea quien lo lea.
 */
final class Env
{
    /** @var array<string,string> nombre => valor de la configuración (la de la tienda elegida) */
    public $cfg = [];

    /** @var array<string,array<int,string>> nombre => [id de idioma => valor] (configuraciones por idioma) */
    public $cfgLang = [];

    /** @var array<int,string> id de idioma => código ISO en minúsculas (solo los activos) */
    public $langs = [];

    /** @var array<int,string> id de idioma => código ISO en minúsculas (todos) */
    public $allLangs = [];

    /** @var int */
    public $defaultLang = 0;

    /** @var array<int,string> id de moneda => código ISO */
    public $currencies = [];

    /** @var array<int,array{iso:string,zone:int,states:bool,active:bool}> */
    public $countries = [];

    /** @var array<int,array{iso:string,country:int,zone:int,active:bool}> */
    public $states = [];

    /** @var array<int,array{rate:string,name:array<int,string>}> */
    public $taxes = [];

    /** @var array<int,array{name:array<int,string>,paid:bool,shipped:bool,delivery:bool,logable:bool}> */
    public $orderStates = [];

    /** @var array<int,array{display_with_tax:bool,name:array<int,string>,reduction:string}> */
    public $groups = [];

    /** @var int */
    public $shopId = 1;

    /** @var string */
    public $timezone = 'UTC';

    /** @var string */
    public $weightUnit = 'kg';

    /** @var string */
    public $dimensionUnit = 'cm';

    /** @var string dirección base de la tienda, sin barra final */
    public $baseUrl = '';

    /** @var string versión del esquema («8.2.0») */
    public $version = '';

    /** @var string el siguiente número de factura real, si el lector lo ha podido saber */
    public $nextInvoice = '';

    /** @var string instante «ahora» (formato neutro), recibido de fuera */
    public $now = '1970-01-01T00:00:00Z';

    /** @var callable|null (ruta relativa «img/p/1/2/3/123.jpg») => parte «image» */
    public $image;

    /** @var array<string,callable> cargadores perezosos: categories, manufacturers, suppliers */
    public $loaders = [];

    /** @var array<string,mixed> */
    private $cache = [];

    public function cfg(string $name, string $default = ''): string
    {
        return isset($this->cfg[$name]) ? $this->cfg[$name] : $default;
    }

    public function cfgInt(string $name): int
    {
        return (int) $this->cfg($name, '0');
    }

    /** Un dato de configuración por idioma («PS_INVOICE_PREFIX»), en el idioma que se diga o en el de la tienda. */
    public function cfgLang(string $name, int $lang): string
    {
        $values = isset($this->cfgLang[$name]) ? $this->cfgLang[$name] : [];
        if (isset($values[$lang])) {
            return $values[$lang];
        }

        return isset($values[$this->defaultLang]) ? $values[$this->defaultLang] : '';
    }

    public function iso(int $lang): string
    {
        return isset($this->allLangs[$lang]) ? $this->allLangs[$lang] : '';
    }

    /** ¿Hay más de un idioma activo? (las direcciones llevan entonces su prefijo, «/es/…») */
    public function multiLang(): bool
    {
        return count($this->langs) > 1;
    }

    /**
     * Idiomas activos, con el de la tienda primero.
     *
     * @return array<int,string>
     */
    public function orderedLangs(): array
    {
        $out = [];
        if (isset($this->langs[$this->defaultLang])) {
            $out[$this->defaultLang] = $this->langs[$this->defaultLang];
        }
        foreach ($this->langs as $id => $iso) {
            $out[$id] = $iso;
        }

        return $out;
    }

    /**
     * Un texto por idioma de PrestaShop ([id => texto]) al formato neutro ([iso => texto]), con los idiomas activos. Si
     * ninguno de los activos tiene texto, se queda con el que haya (de un idioma que ya no se usa) para no perderlo.
     *
     * @param array<int|string,mixed> $byLang
     *
     * @return array<string,string>
     */
    public function text(array $byLang): array
    {
        $out = [];
        foreach ($this->orderedLangs() as $id => $iso) {
            if (isset($byLang[$id]) && is_string($byLang[$id])) {
                $out[$iso] = $byLang[$id];
            }
        }
        if ($out === []) {
            foreach ($byLang as $id => $value) {
                if (is_string($value) && trim($value) !== '' && isset($this->allLangs[(int) $id])) {
                    $out[$this->allLangs[(int) $id]] = $value;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * El texto de un idioma o, si falta, el de la tienda.
     *
     * @param array<int|string,mixed> $byLang
     */
    public function one(array $byLang, ?int $lang = null): string
    {
        $lang = $lang === null ? $this->defaultLang : $lang;
        if (isset($byLang[$lang]) && is_string($byLang[$lang]) && $byLang[$lang] !== '') {
            return $byLang[$lang];
        }
        if (isset($byLang[$this->defaultLang]) && is_string($byLang[$this->defaultLang]) && $byLang[$this->defaultLang] !== '') {
            return $byLang[$this->defaultLang];
        }
        foreach ($byLang as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function country(int $id): string
    {
        return isset($this->countries[$id]) ? $this->countries[$id]['iso'] : '';
    }

    /** El código de la provincia sin el prefijo del país («ES-B» y «B» dan «B»). */
    public function region(int $stateId): string
    {
        if (!isset($this->states[$stateId])) {
            return '';
        }

        return self::regionCode($this->states[$stateId]['iso']);
    }

    public static function regionCode(string $iso): string
    {
        $iso = trim($iso);
        $dash = strpos($iso, '-');

        return $dash === false ? $iso : substr($iso, $dash + 1);
    }

    /** Fecha y hora de la base de datos (en la zona de la tienda) a UTC. */
    public function utc($value): ?string
    {
        return Neutral::utc($value === null ? null : (string) $value, $this->timezone);
    }

    /** Una imagen de la tienda: la ruta relativa («img/p/1/2/3/123.jpg») convertida en parte «image». */
    public function image(string $relative): array
    {
        if ($this->image !== null) {
            $part = ($this->image)($relative);
            if (is_array($part)) {
                return $part;
            }
        }

        return ['url' => $this->baseUrl . '/' . $relative];
    }

    /** Ruta relativa de una imagen de producto: la 123 va en «img/p/1/2/3/123.jpg». */
    public static function productImagePath(int $imageId): string
    {
        return 'img/p/' . implode('/', str_split((string) $imageId)) . '/' . $imageId . '.jpg';
    }

    /** Un cargador perezoso (categorías, marcas, proveedores): se llama una vez y se recuerda. @return array<int|string,mixed> */
    public function lookup(string $name): array
    {
        if (!isset($this->cache[$name])) {
            $this->cache[$name] = isset($this->loaders[$name]) ? ($this->loaders[$name])() : [];
        }

        return is_array($this->cache[$name]) ? $this->cache[$name] : [];
    }
}
