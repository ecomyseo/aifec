<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Commerce\Money;
use Aifec\Front\Page;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Text\HtmlSanitizer;
use Aifec\Util\Str;

/**
 * El servicio «semantic»: lo que una máquina lee de la tienda.
 *
 *   jsonld(tipo, datos)     lista de objetos de datos estructurados (schema.org) de una página o de una pieza;
 *   markdown(tipo, datos)   la versión en texto de una página, compuesta desde los datos y no desde el HTML.
 *
 * Todo sale de los mismos servicios que pintan la página (catalog->product(), cms->page()…): aquí no se lee ninguna tabla.
 * La clase no es «final» a propósito: el módulo de auditoría la amplía con la nota del sitio y de cada producto.
 */
class Semantic
{
    /** Imágenes de un producto que salen en los datos estructurados y en los feeds. */
    public const MAX_IMAGES = 10;

    /** @var App */
    protected $app;

    /** @var array<string,array<string,mixed>|null> envíos ya calculados en esta petición */
    private $shipping = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function app(): App
    {
        return $this->app;
    }

    // ------------------------------------------------------------------ lo que ofrece el servicio

    /**
     * Objetos de datos estructurados de un tipo de página o de pieza: home, product, breadcrumbs, list, post, page, organization,
     * website. Al final pasan por el filtro «jsonld» (array $objects, string $type, array $data).
     *
     * @param array<string,mixed> $data
     *
     * @return array<int,array<string,mixed>>
     */
    public function jsonld(string $type, array $data): array
    {
        $objects = JsonLd::build($this, $type, $data);
        $filtered = $this->app->hooks()->apply('jsonld', $objects, $type, $data);
        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $object) {
            if (is_array($object) && $object !== []) {
                $out[] = $object;
            }
        }

        return $out;
    }

    /**
     * La versión en texto de una página: product, category, brand, page, post, home, blog o term.
     *
     * @param array<string,mixed> $data
     */
    public function markdown(string $type, array $data): string
    {
        return TextPages::compose($this, $type, $data);
    }

    // ------------------------------------------------------------------ servicios de los que se lee

    /**
     * @return object|null el servicio «catalog»
     */
    public function catalog()
    {
        return $this->app->has('catalog') ? $this->app->service('catalog') : null;
    }

    /**
     * @return object|null el servicio «cms»
     */
    public function cms()
    {
        return $this->app->has('cms') ? $this->app->service('cms') : null;
    }

    // ------------------------------------------------------------------ datos de la tienda

    public function shop(): string
    {
        $name = trim($this->app->settings()->str('store.name', 'AIFEC'));

        return $name !== '' ? $name : 'AIFEC';
    }

    /** País de la tienda (dos letras): el de los impuestos, y ES si no hay o no es válido. */
    public function country(): string
    {
        $country = strtoupper(trim($this->app->settings()->str('tax.base_country', 'ES')));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'ES';
    }

    public function currency(): string
    {
        $currency = strtoupper(trim($this->app->settings()->str('shop.currency', 'EUR')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'EUR';
    }

    /** La raíz del sitio, sin idioma: la dirección que identifica a la organización. */
    public function siteUrl(): string
    {
        return $this->app->url()->abs('/', [], '');
    }

    /** El logotipo para los buscadores: el del módulo si lo hay y, si no, el de la tienda. Dirección completa o vacío. */
    public function logoUrl(): string
    {
        $id = $this->app->settings()->int('semantic.logo');
        if ($id > 0 && $this->app->has('media')) {
            try {
                $image = $this->app->service('media')->image($id);
            } catch (\Throwable $e) {
                $image = null;
            }
            if (is_array($image) && !empty($image['url'])) {
                return $this->abs((string) $image['url']);
            }
        }
        $logo = Page::logo($this->app);

        return $logo !== null && !empty($logo['url']) ? $this->abs((string) $logo['url']) : '';
    }

    /**
     * Los perfiles sociales: una dirección por línea, solo http o https.
     *
     * @return string[]
     */
    public function socialProfiles(): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $this->app->settings()->str('semantic.social')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || filter_var($line, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $line) !== 1) {
                continue;
            }
            $out[$line] = $line;
            if (count($out) >= 20) {
                break;
            }
        }

        return array_values($out);
    }

    /** El resumen de la tienda: el escrito en los ajustes o, si no, uno hecho con los datos de la tienda. */
    public function summary(): string
    {
        $text = $this->line($this->app->settings()->str('semantic.summary'));
        if ($text !== '') {
            return $text;
        }
        $settings = $this->app->settings();
        $parts = [t('{shop} is an online shop.', ['shop' => $this->shop()])];
        $legal = $this->line($settings->str('store.legal_name'));
        if ($legal !== '') {
            $parts[] = t('Legal name: {name}.', ['name' => $legal]);
        }
        $contact = [];
        foreach (['store.email', 'store.phone', 'store.address'] as $key) {
            $value = $this->line($settings->str($key));
            if ($value !== '') {
                $contact[] = $value;
            }
        }
        if ($contact !== []) {
            $parts[] = t('Contact: {contact}.', ['contact' => implode(', ', $contact)]);
        }

        return implode(' ', $parts);
    }

    /**
     * La política de devoluciones de los ajustes, o null si no se ha definido (0 días).
     *
     * @return array{days:int,payer:string,method:string,country:string}|null
     */
    public function returns(): ?array
    {
        $days = $this->app->settings()->int('semantic.returns.days');
        if ($days <= 0 && $this->app->has('returns')) {
            // Una sola verdad: si aquí no se ha escrito el plazo y la tienda gestiona devoluciones con su módulo, el plazo es
            // el de ese módulo (sin esto la auditoría decía «falta la política de devoluciones» en una tienda que las acepta).
            $days = $this->app->settings()->int('returns.days');
        }
        if ($days <= 0) {
            return null;
        }
        $payer = $this->app->settings()->str('semantic.returns.payer', 'customer');
        $method = $this->app->settings()->str('semantic.returns.method', 'mail');

        return [
            'days' => min(365, $days),
            'payer' => $payer === 'merchant' ? 'merchant' : 'customer',
            'method' => in_array($method, ['mail', 'store', 'kiosk'], true) ? $method : 'mail',
            'country' => $this->country(),
        ];
    }

    /**
     * Qué puertas tiene la tienda abiertas y dónde: llms, feeds (los activos y ya generados), api, mcp y sitemap.
     *
     * @return array<string,mixed>
     */
    public function gates(): array
    {
        $url = $this->app->url();
        $gates = [
            'llms' => $url->abs('/llms.txt', [], ''),
            'llms_full' => $url->abs('/llms-full.txt', [], ''),
        ];
        $feeds = [];
        foreach (Feeds::FILES as $key => $file) {
            if (Feeds::enabled($this->app, $key) && is_file(Feeds::path($this->app, $key))) {
                $feeds[$key] = $url->abs('/feeds/' . $file, [], '');
            }
        }
        if ($feeds !== []) {
            $gates['feeds'] = $feeds;
        }
        if ($this->app->modules()->isLoaded('api')) {
            $gates['api'] = $url->abs('/api/openapi.json', [], '');
        }
        if ($this->app->modules()->isLoaded('mcp')) {
            $gates['mcp'] = $url->abs('/mcp', [], '');
        }
        if ($this->app->router()->has('seo.sitemap')) {
            $gates['sitemap'] = $url->abs($this->app->router()->path('seo.sitemap'), [], '');
        }

        return $gates;
    }

    // ------------------------------------------------------------------ textos, direcciones y dinero

    /** Una dirección de la propia tienda como dirección completa (las imágenes y los enlaces vienen relativos a la raíz del dominio). */
    public function abs(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        return $this->app->url()->origin() . '/' . ltrim($url, '/');
    }

    /** La dirección de la versión en texto de una página, a partir de la de su versión en HTML (completa). */
    public function mdUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        if (substr($url, -3) === '.md') {
            return $url;
        }
        if ($url === rtrim($this->app->url()->abs('/'), '/')) {
            return $url . '/index.md';
        }

        return $url . '.md';
    }

    /** Texto plano de un HTML, en una sola línea y con un largo máximo (0 = sin límite). */
    public function plain(string $html, int $max = 0): string
    {
        $text = $this->line(HtmlSanitizer::text($html));

        return $max > 0 ? Str::limit($text, $max) : $text;
    }

    /** Un texto en una sola línea: espacios y saltos colapsados. */
    public function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** «2026-11-12 12:00:00» (UTC) como fecha ISO 8601, o vacío si no hay. */
    public function iso(?string $sql): string
    {
        $ts = Clock::parse($sql);

        return $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    /** Céntimos como «19.99». */
    public function decimal(int $cents): string
    {
        return Money::decimal(max(0, $cents));
    }

    public function money(int $cents, ?string $currency = null): string
    {
        return $this->app->i18n()->money($cents, $currency !== null && $currency !== '' ? $currency : $this->currency());
    }

    /**
     * El importe con impuestos de un bloque de precio.
     *
     * @param array<string,mixed> $price
     */
    public function gross(array $price): int
    {
        return isset($price['gross']) ? (int) $price['gross'] : (isset($price['amount']) ? (int) $price['amount'] : 0);
    }

    /**
     * El precio de antes, con impuestos, o null si no hay oferta.
     *
     * @param array<string,mixed> $price
     */
    public function grossCompare(array $price): ?int
    {
        if (empty($price['compare']) || (int) $price['compare'] <= 0) {
            return null;
        }
        $compare = (int) $price['compare'];
        if (empty($price['with_tax'])) {
            $compare = Money::add($compare, isset($price['tax_rate']) ? (int) $price['tax_rate'] : 0)[1];
        }

        return $compare > $this->gross($price) ? $compare : null;
    }

    // ------------------------------------------------------------------ piezas de un producto

    /**
     * Direcciones completas de las imágenes de un producto.
     *
     * @param array<string,mixed> $product
     *
     * @return string[]
     */
    public function images(array $product, int $max = self::MAX_IMAGES): array
    {
        $out = [];
        foreach (isset($product['images']) && is_array($product['images']) ? $product['images'] : [] as $image) {
            if (is_array($image) && !empty($image['url'])) {
                $out[$this->abs((string) $image['url'])] = true;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * La ruta de categorías de un producto: «Muebles > Sillas».
     *
     * @param array<string,mixed> $product
     */
    public function categoryPath(array $product): string
    {
        if (!isset($product['main_category']['id'])) {
            return '';
        }
        $catalog = $this->catalog();
        $category = $catalog !== null ? $catalog->category((int) $product['main_category']['id']) : null;
        $names = [];
        foreach (is_array($category) && isset($category['crumbs']) && is_array($category['crumbs']) ? $category['crumbs'] : [] as $crumb) {
            if (isset($crumb['name']) && trim((string) $crumb['name']) !== '') {
                $names[] = trim((string) $crumb['name']);
            }
        }

        return $names !== [] ? implode(' > ', $names) : (isset($product['main_category']['name']) ? (string) $product['main_category']['name'] : '');
    }

    /**
     * La opción de envío más barata al país de la tienda para un producto, o null si no hay transportista, no hace falta envío o no hay
     * módulo de envíos. El precio sale con impuestos aunque la tienda escriba los precios sin ellos.
     *
     * @param array<string,mixed> $product
     *
     * @return array{price:int,min_days:?int,max_days:?int,name:string,free:bool}|null
     */
    public function shippingFor(array $product, int $gross): ?array
    {
        if (!$this->app->has('shipping') || (isset($product['kind']) && (string) $product['kind'] !== 'physical')) {
            return null;
        }
        $weight = isset($product['weight']) ? max(0, (int) $product['weight']) : 0;
        $key = $weight . '|' . $gross . '|' . $this->country();
        if (array_key_exists($key, $this->shipping)) {
            return $this->shipping[$key];
        }
        $destination = ['country' => $this->country()];
        try {
            $options = $this->app->service('shipping')->options(['weight' => $weight, 'items_total' => $gross, 'qty' => 1, 'needs_shipping' => true], $destination);
        } catch (\Throwable $e) {
            $options = [];
        }
        $best = null;
        foreach (is_array($options) ? $options : [] as $option) {
            if (!is_array($option) || !isset($option['price']) || ($best !== null && (int) $option['price'] >= (int) $best['price'])) {
                continue;
            }
            $best = $option;
        }
        $result = null;
        if ($best !== null) {
            $price = max(0, (int) $best['price']);
            if ($price > 0 && $this->app->has('tax') && !$this->app->service('tax')->pricesIncludeTax()) {
                $rate = $this->app->service('tax')->rate(isset($best['tax_class_id']) && $best['tax_class_id'] !== null ? (int) $best['tax_class_id'] : null, $destination);
                $price = Money::add($price, isset($rate['rate']) ? (int) $rate['rate'] : 0)[1];
            }
            $min = isset($best['min_days']) && $best['min_days'] !== null ? max(0, (int) $best['min_days']) : null;
            $max = isset($best['max_days']) && $best['max_days'] !== null ? max(0, (int) $best['max_days']) : null;
            $result = [
                'price' => $price,
                'min_days' => $min !== null ? $min : $max,
                'max_days' => $max !== null ? $max : $min,
                'name' => isset($best['name']) ? (string) $best['name'] : '',
                'free' => $price === 0,
            ];
        }
        $this->shipping[$key] = $result;

        return $result;
    }
}
