<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Front;

use Aifec\Kernel\Request;

/**
 * Lo que trae la dirección de un listado: orden, página, filtros por atributo («f[color][]=rojo»), precio mínimo y máximo
 * («min=19,99»), «solo con existencias» y «en oferta», y la palabra buscada. Lo lee de la petición, lo limpia y sabe volver a
 * escribirlo como dirección (paginación, «quitar filtros», dirección canónica).
 */
final class ListingQuery
{
    public const DEFAULT_SORT = 'relevance';

    /** Tope de grupos de filtros, de valores por grupo y de largo de cada texto: una dirección inventada no cuesta consultas de más. */
    private const MAX_GROUPS = 12;

    private const MAX_VALUES = 20;

    private const MAX_LENGTH = 60;

    private const MAX_PAGE = 10000;

    /** @var string */
    public $sort = self::DEFAULT_SORT;

    /** @var int */
    public $page = 1;

    /** @var array<string,string[]> código del atributo => valores elegidos */
    public $filters = [];

    /** @var int|null céntimos */
    public $min;

    /** @var int|null céntimos */
    public $max;

    /** @var bool */
    public $stock = false;

    /** @var bool */
    public $sale = false;

    /** @var string la palabra buscada (solo en la búsqueda) */
    public $q = '';

    /** Lee y limpia lo que trae la petición. */
    public static function fromRequest(Request $request): self
    {
        $query = new self();
        $sort = $request->str('sort');
        if ($sort !== '' && preg_match('/^[a-z0-9_]{1,30}$/', $sort) === 1) {
            $query->sort = $sort;
        }
        $query->page = max(1, min(self::MAX_PAGE, $request->int('page', 1)));
        $groups = 0;
        foreach ($request->arr('f') as $code => $values) {
            $code = (string) $code;
            if ($groups >= self::MAX_GROUPS || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code) !== 1) {
                continue;
            }
            $clean = [];
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value === '' || strlen($value) > self::MAX_LENGTH || count($clean) >= self::MAX_VALUES) {
                    continue;
                }
                $clean[$value] = $value;
            }
            if ($clean !== []) {
                $query->filters[$code] = array_values($clean);
                ++$groups;
            }
        }
        $query->min = Support::cents($request->str('min'));
        $query->max = Support::cents($request->str('max'));
        $query->stock = $request->str('stock') === '1';
        $query->sale = $request->str('sale') === '1';

        return $query;
    }

    /** ¿Hay algún filtro puesto (sin contar el orden)? */
    public function hasFilters(): bool
    {
        return $this->filters !== [] || $this->min !== null || $this->max !== null || $this->stock || $this->sale;
    }

    /** ¿Es una vista filtrada u ordenada de otra manera? Esas no se indexan. */
    public function isFiltered(): bool
    {
        return $this->hasFilters() || $this->sort !== self::DEFAULT_SORT;
    }

    /**
     * Lo que se le pide a catalog->listing(). La marca elegida en las facetas («f[brand][]=3») pasa a brand_id.
     *
     * @return array<string,mixed>
     */
    public function toCatalog(): array
    {
        $filters = $this->filters;
        $brand = 0;
        if (isset($filters['brand'])) {
            $brand = (int) $filters['brand'][0];
            unset($filters['brand']);
        }
        $query = ['sort' => $this->sort, 'page' => $this->page, 'filters' => $filters];
        if ($this->min !== null) {
            $query['price_min'] = $this->min;
        }
        if ($this->max !== null) {
            $query['price_max'] = $this->max;
        }
        if ($this->stock) {
            $query['in_stock'] = true;
        }
        if ($this->sale) {
            $query['on_sale'] = true;
        }
        if ($brand > 0) {
            $query['brand_id'] = $brand;
        }
        if ($this->q !== '') {
            $query['q'] = $this->q;
        }

        return $query;
    }

    /**
     * La dirección de este listado a partir de la dirección base (sin parámetros).
     *
     * @param int|null $page  la página; null = la actual
     * @param bool     $clean true = sin filtros ni orden ni página (solo la palabra buscada): la dirección canónica de lo filtrado
     */
    public function url(string $base, ?int $page = null, bool $clean = false): string
    {
        $page = $page === null ? $this->page : $page;
        $parts = [];
        if ($this->q !== '') {
            $parts[] = 'q=' . rawurlencode($this->q);
        }
        if (!$clean) {
            if ($this->sort !== self::DEFAULT_SORT) {
                $parts[] = 'sort=' . rawurlencode($this->sort);
            }
            foreach ($this->filters as $code => $values) {
                foreach ($values as $value) {
                    $parts[] = 'f[' . rawurlencode((string) $code) . '][]=' . rawurlencode($value);
                }
            }
            if ($this->min !== null) {
                $parts[] = 'min=' . rawurlencode(Support::formatCents($this->min));
            }
            if ($this->max !== null) {
                $parts[] = 'max=' . rawurlencode(Support::formatCents($this->max));
            }
            if ($this->stock) {
                $parts[] = 'stock=1';
            }
            if ($this->sale) {
                $parts[] = 'sale=1';
            }
            if ($page > 1) {
                $parts[] = 'page=' . $page;
            }
        }

        return $base . ($parts !== [] ? '?' . implode('&', $parts) : '');
    }

    /** ¿Está marcado este valor de este filtro? */
    public function selected(string $code, string $value): bool
    {
        return isset($this->filters[$code]) && in_array($value, $this->filters[$code], true);
    }
}
