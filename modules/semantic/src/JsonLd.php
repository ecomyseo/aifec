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

use Aifec\Kernel\Clock;
use Aifec\Util\Str;

/**
 * Los objetos de datos estructurados (schema.org). Cada método parte de los mismos datos que pinta la página: ProductData del
 * catálogo, página y entrada del módulo de contenidos, migas de la página. Ninguna propiedad sale vacía: lo que no hay se omite.
 * Las direcciones salen siempre completas y las cadenas sin HTML.
 */
final class JsonLd
{
    private const CONTEXT = 'https://schema.org';

    /** Propiedades de variante que schema.org conoce con su propio nombre. */
    private const VARIANT_PROPERTIES = ['color' => 'color', 'size' => 'size', 'material' => 'material', 'pattern' => 'pattern'];

    /**
     * @param array<string,mixed> $data
     *
     * @return array<int,array<string,mixed>>
     */
    public static function build(Semantic $s, string $type, array $data): array
    {
        switch ($type) {
            case 'home':
                return [self::organization($s), self::website($s)];
            case 'organization':
                return [self::organization($s)];
            case 'website':
                return [self::website($s)];
            case 'product':
                return self::product($s, $data);
            case 'breadcrumbs':
                return self::breadcrumbs($s, $data);
            case 'list':
                return self::itemList($s, $data);
            case 'post':
                return [self::post($s, $data)];
            case 'page':
                return [self::webPage($s, $data)];
        }

        return [];
    }

    /**
     * Quita lo vacío (null, cadenas vacías, listas vacías) en todos los niveles; el cero y «false» se conservan.
     *
     * @param array<int|string,mixed> $values
     *
     * @return array<int|string,mixed>
     */
    public static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::clean($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return array_is_list($values) ? array_values($out) : $out;
    }

    // ------------------------------------------------------------------ organización y sitio

    /**
     * @return array<string,mixed>
     */
    private static function organization(Semantic $s): array
    {
        $settings = $s->app()->settings();
        $address = \Aifec\Front\StoreAddress::postal($s->app());

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'Organization',
            '@id' => $s->siteUrl() . '#organization',
            'name' => $s->shop(),
            'legalName' => $s->line($settings->str('store.legal_name')),
            'url' => $s->siteUrl(),
            'logo' => $s->logoUrl(),
            'email' => trim($settings->str('store.email')),
            'telephone' => $s->line($settings->str('store.phone')),
            'vatID' => $s->line($settings->str('store.vat_id')),
            'address' => $address !== [] ? ['@type' => 'PostalAddress'] + $address : [],
            'sameAs' => $s->socialProfiles(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function website(Semantic $s): array
    {
        $app = $s->app();
        $action = [];
        if ($app->router()->has('search')) {
            $action = [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $s->abs($app->url()->route('search')) . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'WebSite',
            '@id' => $s->siteUrl() . '#website',
            'url' => $app->url()->abs('/'),
            'name' => $s->shop(),
            'inLanguage' => $app->lang(),
            'publisher' => ['@id' => $s->siteUrl() . '#organization'],
            'potentialAction' => $action,
        ]);
    }

    // ------------------------------------------------------------------ producto

    /**
     * El producto (o el grupo de variantes) y, aparte, sus preguntas frecuentes.
     *
     * @param array<string,mixed> $p ProductData
     *
     * @return array<int,array<string,mixed>>
     */
    private static function product(Semantic $s, array $p): array
    {
        if (!isset($p['name'])) {
            return [];
        }
        $url = isset($p['url_abs']) ? (string) $p['url_abs'] : '';
        $description = $s->plain(isset($p['description']) ? (string) $p['description'] : '', 5000);
        if ($description === '') {
            $description = $s->plain(isset($p['summary']) ? (string) $p['summary'] : '', 5000);
        }
        // Sin SKU en el catálogo, el identificador del producto (el mismo que lleva products.json con sku_source = product_id).
        $sku = $s->sku($p)[0];
        $brandName = $s->brand($p);
        $brand = $brandName !== '' ? ['@type' => 'Brand', 'name' => $brandName] : [];
        $rating = [];
        if (isset($p['rating']['count']) && (int) $p['rating']['count'] > 0) {
            $rating = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $p['rating']['average'], 2),
                'reviewCount' => (int) $p['rating']['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }
        $common = [
            '@id' => $url . '#product',
            'name' => $s->line((string) $p['name']),
            'description' => $description,
            'url' => $url,
            'image' => $s->images($p),
            'brand' => $brand,
            'category' => $s->categoryPath($p),
            'additionalProperty' => self::properties($p),
            'aggregateRating' => $rating,
        ];

        $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [];
        if (!empty($p['has_variants']) && $variants !== []) {
            $group = $sku !== '' ? $sku : 'product-' . (int) $p['id'];
            $has = [];
            foreach ($variants as $variant) {
                if (is_array($variant)) {
                    $has[] = self::variant($s, $p, $variant, $group);
                }
            }
            $object = array_merge(['@context' => self::CONTEXT, '@type' => 'ProductGroup'], $common, [
                'productGroupID' => $group,
                'sku' => $sku,
                'variesBy' => self::variesBy($p),
                'hasVariant' => $has,
            ]);
        } else {
            $price = isset($p['price']) && is_array($p['price']) ? $p['price'] : [];
            $availability = isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : [];
            $object = array_merge(['@context' => self::CONTEXT, '@type' => 'Product'], $common, [
                'sku' => $sku,
                'gtin' => isset($p['gtin']) ? trim((string) $p['gtin']) : '',
                'mpn' => isset($p['mpn']) ? trim((string) $p['mpn']) : '',
                'offers' => self::offer($s, $p, $price, $availability, $url),
            ]);
        }
        $objects = [self::clean($object)];

        $questions = [];
        foreach (isset($p['faqs']) && is_array($p['faqs']) ? $p['faqs'] : [] as $faq) {
            $question = is_array($faq) && isset($faq['question']) ? $s->line((string) $faq['question']) : '';
            if ($question === '') {
                continue;
            }
            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $s->plain(isset($faq['answer']) ? (string) $faq['answer'] : '')],
            ];
        }
        if ($questions !== []) {
            $objects[] = self::clean(['@context' => self::CONTEXT, '@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => $questions]);
        }

        return $objects;
    }

    /**
     * Una variante como producto con su propia oferta.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $v
     *
     * @return array<string,mixed>
     */
    private static function variant(Semantic $s, array $p, array $v, string $group): array
    {
        $url = isset($p['url_abs']) ? (string) $p['url_abs'] : '';
        $label = isset($v['label']) && $v['label'] !== null ? trim((string) $v['label']) : '';
        $name = $s->line((string) $p['name']);
        $image = [];
        foreach (isset($p['images']) && is_array($p['images']) ? $p['images'] : [] as $img) {
            if (is_array($img) && isset($v['image_id']) && (int) $v['image_id'] > 0 && (int) $img['id'] === (int) $v['image_id'] && !empty($img['url'])) {
                $image = [$s->abs((string) $img['url'])];
            }
        }
        $object = [
            '@type' => 'Product',
            '@id' => $url . '#variant-' . (int) $v['id'],
            'name' => $label !== '' ? $name . ' — ' . $label : $name,
            'sku' => isset($v['sku']) && $v['sku'] !== null ? trim((string) $v['sku']) : '',
            'gtin' => isset($v['gtin']) && $v['gtin'] !== null ? trim((string) $v['gtin']) : '',
            'mpn' => isset($p['mpn']) ? trim((string) $p['mpn']) : '',
            'url' => $url,
            'image' => $image,
            'inProductGroupWithID' => $group,
            'offers' => self::offer(
                $s,
                $p,
                isset($v['price']) && is_array($v['price']) ? $v['price'] : [],
                isset($v['availability']) && is_array($v['availability']) ? $v['availability'] : [],
                $url
            ),
        ];

        $names = [];
        $labels = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option) || !isset($option['code'])) {
                continue;
            }
            $names[(string) $option['code']] = isset($option['name']) ? (string) $option['name'] : (string) $option['code'];
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['code'])) {
                    $labels[(string) $option['code']][(string) $value['code']] = isset($value['label']) ? (string) $value['label'] : (string) $value['code'];
                }
            }
        }
        $extra = [];
        foreach (isset($v['values']) && is_array($v['values']) ? $v['values'] : [] as $attribute => $valueCode) {
            $attribute = (string) $attribute;
            $text = isset($labels[$attribute][(string) $valueCode]) ? $labels[$attribute][(string) $valueCode] : (string) $valueCode;
            if (isset(self::VARIANT_PROPERTIES[$attribute])) {
                $object[self::VARIANT_PROPERTIES[$attribute]] = $text;
            } else {
                $extra[] = ['@type' => 'PropertyValue', 'name' => isset($names[$attribute]) ? $names[$attribute] : $attribute, 'value' => $text];
            }
        }
        $object['additionalProperty'] = $extra;

        return $object;
    }

    /**
     * Lo que varía entre las variantes: la propiedad de schema.org si existe y, si no, el nombre de la opción.
     *
     * @param array<string,mixed> $p
     *
     * @return string[]
     */
    private static function variesBy(array $p): array
    {
        $out = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option) || !isset($option['code'])) {
                continue;
            }
            $code = (string) $option['code'];
            $out[] = isset(self::VARIANT_PROPERTIES[$code]) ? self::CONTEXT . '/' . self::VARIANT_PROPERTIES[$code] : (isset($option['name']) ? (string) $option['name'] : $code);
        }

        return $out;
    }

    /**
     * Los datos clave y los atributos visibles como propiedades del producto.
     *
     * @param array<string,mixed> $p
     *
     * @return array<int,array<string,mixed>>
     */
    private static function properties(array $p): array
    {
        $pairs = [];
        foreach (isset($p['facts']) && is_array($p['facts']) ? $p['facts'] : [] as $fact) {
            if (is_array($fact) && isset($fact['label'], $fact['value'])) {
                $pairs[] = [trim((string) $fact['label']), trim((string) $fact['value'])];
            }
        }
        foreach (isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['name'], $attribute['value'])) {
                $pairs[] = [trim((string) $attribute['name']), trim((string) $attribute['value'])];
            }
        }
        // Un dato que está a la vez en las características y en los atributos (13/11/2026: «Marca: Munich» dos veces) va una vez.
        $out = [];
        $seen = [];
        foreach ($pairs as $pair) {
            $key = mb_strtolower($pair[0] . "\n" . $pair[1], 'UTF-8');
            if ($pair[0] === '' || $pair[1] === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['@type' => 'PropertyValue', 'name' => $pair[0], 'value' => $pair[1]];
        }

        return $out;
    }

    /**
     * La oferta: precio con impuestos, disponibilidad, fin de la oferta, vendedor, envío y devoluciones.
     *
     * @param array<string,mixed> $product
     * @param array<string,mixed> $price
     * @param array<string,mixed> $availability
     *
     * @return array<string,mixed>
     */
    private static function offer(Semantic $s, array $product, array $price, array $availability, string $url): array
    {
        $gross = $s->gross($price);
        $currency = isset($price['currency']) && $price['currency'] !== '' ? (string) $price['currency'] : $s->currency();
        // Un producto cuyo precio no se enseña (disponibilidad con show_price = false) sale sin precio ni moneda; la disponibilidad
        // es la que pida el plugin con «schema» (PreOrder…) y, si no pide ninguna, la de siempre.
        $hidden = !empty($price['hidden']);
        $schema = isset($availability['schema']) && is_string($availability['schema']) ? $availability['schema'] : '';
        $offer = [
            '@type' => 'Offer',
            'url' => $url,
            'priceCurrency' => $hidden ? '' : $currency,
            'price' => $hidden ? '' : $s->decimal($gross),
            'availability' => $schema !== '' ? self::CONTEXT . '/' . $schema : self::availability(isset($availability['status']) ? (string) $availability['status'] : 'in_stock'),
            'itemCondition' => self::CONTEXT . '/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => $s->shop()],
        ];
        if (!$hidden && !empty($price['on_sale']) && !empty($price['sale_until'])) {
            $until = Clock::parse((string) $price['sale_until']);
            if ($until > 0) {
                $offer['priceValidUntil'] = gmdate('Y-m-d', $until);
            }
        }

        $ship = $hidden ? null : $s->shippingFor($product, $gross);
        if ($ship !== null) {
            $details = [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => $s->decimal($ship['price']), 'currency' => $currency],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $s->country()],
            ];
            if ($ship['min_days'] !== null && $ship['max_days'] !== null) {
                $details['deliveryTime'] = [
                    '@type' => 'ShippingDeliveryTime',
                    'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => $ship['min_days'], 'maxValue' => $ship['max_days'], 'unitCode' => 'DAY'],
                ];
            }
            $offer['shippingDetails'] = $details;
        }

        $returns = $s->returns();
        if ($returns !== null) {
            $methods = ['mail' => 'ReturnByMail', 'store' => 'ReturnInStore', 'kiosk' => 'ReturnAtKiosk'];
            $method = $s->returnMethodFor($product, $returns);
            $policy = [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => $returns['country'],
                'returnPolicyCategory' => self::CONTEXT . '/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => $returns['days'],
            ];
            // Una descarga o un servicio no vuelven por correo ni se pagan los portes: sin método ni gastos de devolución.
            if ($method !== 'none') {
                $policy['returnMethod'] = self::CONTEXT . '/' . $methods[$method];
                $policy['returnFees'] = self::CONTEXT . '/' . ($returns['payer'] === 'merchant' ? 'FreeReturn' : 'ReturnShippingFees');
            }
            $link = $s->returnLink();
            if ($link !== '') {
                $policy['merchantReturnLink'] = $link;
            }
            $offer['hasMerchantReturnPolicy'] = $policy;
        }

        return $offer;
    }

    private static function availability(string $status): string
    {
        $map = ['out_of_stock' => 'OutOfStock', 'backorder' => 'BackOrder'];

        return self::CONTEXT . '/' . (isset($map[$status]) ? $map[$status] : 'InStock');
    }

    // ------------------------------------------------------------------ migas y listados

    /**
     * @param array<string,mixed> $data breadcrumbs (lista de ['name','url']), canonical
     *
     * @return array<int,array<string,mixed>>
     */
    private static function breadcrumbs(Semantic $s, array $data): array
    {
        $crumbs = isset($data['breadcrumbs']) && is_array($data['breadcrumbs']) ? $data['breadcrumbs'] : [];
        $canonical = isset($data['canonical']) ? (string) $data['canonical'] : '';
        $items = [];
        foreach ($crumbs as $crumb) {
            $name = is_array($crumb) && isset($crumb['name']) ? $s->line((string) $crumb['name']) : '';
            if ($name === '') {
                continue;
            }
            $url = isset($crumb['url']) && (string) $crumb['url'] !== '' ? $s->abs((string) $crumb['url']) : $canonical;
            $items[] = self::clean(['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $name, 'item' => $url]);
        }

        return $items === [] ? [] : [['@context' => self::CONTEXT, '@type' => 'BreadcrumbList', 'itemListElement' => $items]];
    }

    /**
     * @param array<string,mixed> $data items (lista de ['name','url']), offset (los que quedan en páginas anteriores), total
     *
     * @return array<int,array<string,mixed>>
     */
    private static function itemList(Semantic $s, array $data): array
    {
        $offset = isset($data['offset']) ? max(0, (int) $data['offset']) : 0;
        $elements = [];
        foreach (isset($data['items']) && is_array($data['items']) ? $data['items'] : [] as $item) {
            $url = is_array($item) && isset($item['url']) ? $s->abs((string) $item['url']) : '';
            if ($url === '') {
                continue;
            }
            $elements[] = self::clean(['@type' => 'ListItem', 'position' => $offset + count($elements) + 1, 'url' => $url, 'name' => isset($item['name']) ? $s->line((string) $item['name']) : '']);
        }
        if ($elements === []) {
            return [];
        }

        return [[
            '@context' => self::CONTEXT,
            '@type' => 'ItemList',
            'numberOfItems' => isset($data['total']) ? max(count($elements), (int) $data['total']) : count($elements),
            'itemListElement' => $elements,
        ]];
    }

    // ------------------------------------------------------------------ contenidos

    /**
     * @param array<string,mixed> $post cms->post()
     *
     * @return array<string,mixed>
     */
    private static function post(Semantic $s, array $post): array
    {
        $url = isset($post['url_abs']) ? (string) $post['url_abs'] : '';
        $image = isset($post['image']['url']) && $post['image']['url'] !== '' ? $s->abs((string) $post['image']['url']) : '';
        $author = isset($post['author']) ? trim((string) $post['author']) : '';
        $categories = isset($post['categories']) && is_array($post['categories']) ? $post['categories'] : [];
        $tags = [];
        foreach (isset($post['tags']) && is_array($post['tags']) ? $post['tags'] : [] as $tag) {
            $name = is_array($tag) ? (isset($tag['name']) ? (string) $tag['name'] : '') : (string) $tag;
            if (trim($name) !== '') {
                $tags[] = trim($name);
            }
        }
        $logo = $s->logoUrl();

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'BlogPosting',
            '@id' => $url . '#post',
            'headline' => Str::limit($s->line(isset($post['title']) ? (string) $post['title'] : ''), 110, ''),
            'description' => isset($post['seo']['description']) ? $s->line((string) $post['seo']['description']) : '',
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'datePublished' => $s->iso(isset($post['published_at']) ? (string) $post['published_at'] : null),
            'dateModified' => $s->iso(isset($post['updated_at']) ? (string) $post['updated_at'] : null),
            'author' => $author !== '' ? ['@type' => 'Person', 'name' => $author] : ['@type' => 'Organization', 'name' => $s->shop()],
            'publisher' => ['@type' => 'Organization', 'name' => $s->shop(), 'logo' => $logo !== '' ? ['@type' => 'ImageObject', 'url' => $logo] : []],
            'image' => $image !== '' ? [$image] : [],
            'inLanguage' => $s->app()->lang(),
            'articleSection' => isset($categories[0]['name']) ? (string) $categories[0]['name'] : '',
            'keywords' => implode(', ', $tags),
        ]);
    }

    /**
     * @param array<string,mixed> $page cms->page()
     *
     * @return array<string,mixed>
     */
    private static function webPage(Semantic $s, array $page): array
    {
        $url = isset($page['url_abs']) ? (string) $page['url_abs'] : '';

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'WebPage',
            '@id' => $url . '#webpage',
            'name' => $s->line(isset($page['title']) ? (string) $page['title'] : ''),
            'description' => isset($page['seo']['description']) ? $s->line((string) $page['seo']['description']) : '',
            'url' => $url,
            'inLanguage' => $s->app()->lang(),
            'isPartOf' => ['@id' => $s->siteUrl() . '#website'],
            'datePublished' => $s->iso(isset($page['published_at']) ? (string) $page['published_at'] : null),
            'dateModified' => $s->iso(isset($page['updated_at']) ? (string) $page['updated_at'] : null),
        ]);
    }
}
