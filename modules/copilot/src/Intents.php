<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * «¿Dónde cambio el IVA?»: encuentra la pantalla de ajustes que resuelve lo que la persona quiere, aunque no sepa cómo se llama.
 *
 * Indexa todos los campos de ajustes de los módulos activos (su etiqueta, su ayuda, su pestaña y su pantalla) y les añade un
 * fichero de SINÓNIMOS en castellano e inglés («IVA», «impuestos» → los de impuestos; «envío gratis», «portes» → el importe de
 * envío gratis; «logo»; «correo», «SMTP»…). Cada resultado lleva a su pantalla con el campo señalado: el enlace termina en
 * «#field=…&tab=…» y copilot.js lo enseña. Solo salen los ajustes que el permiso de quien busca deja ver.
 */
final class Intents
{
    /** Palabras que no dicen qué se busca. */
    private const STOP = [
        'donde', 'como', 'cual', 'cuales', 'cambio', 'cambiar', 'cambia', 'quiero', 'puedo', 'pongo', 'poner', 'pon', 'configuro', 'configurar', 'ajusto', 'ajustar',
        'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'al', 'que', 'para', 'en', 'mi', 'mis', 'tu', 'su', 'se', 'es', 'por', 'con', 'y', 'o', 'a',
        'where', 'how', 'do', 'does', 'did', 'the', 'to', 'set', 'can', 'my', 'change', 'what', 'is', 'are', 'of', 'in', 'on', 'for', 'and', 'or', 'i', 'it', 'find', 'busca', 'buscar',
    ];

    /**
     * Los sinónimos: expresiones (sin tildes, separadas por «|») → a qué apuntan. «module» sube todos los ajustes de ese módulo,
     * «keys» sube ajustes concretos y «links» son pantallas que no son ajustes (cada una con su permiso y su módulo).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function concepts(): array
    {
        return [
            ['terms' => 'iva|igic|impuesto|impuestos|tax|taxes|vat|tasas fiscales|tipo impositivo|tipos impositivos', 'module' => 'tax', 'links' => [
                ['label' => 'Tax classes', 'url' => '/admin/r/tax_classes', 'cap' => 'tax.view', 'module' => 'tax'],
                ['label' => 'Tax rates', 'url' => '/admin/r/tax_rates', 'cap' => 'tax.view', 'module' => 'tax'],
            ]],
            ['terms' => 'envio gratis|envios gratis|envio gratuito|portes gratis|portes gratuitos|portes|gastos de envio gratis|free shipping|free delivery|shipping threshold', 'module' => 'shipping', 'links' => [
                ['label' => 'Free shipping amount', 'url' => '/admin/r/carriers', 'cap' => 'shipping.view', 'module' => 'shipping', 'score' => 30],
            ]],
            ['terms' => 'envio|envios|transporte|transportista|transportistas|mensajeria|gastos de envio|tarifa de envio|tarifas de envio|shipping|carrier|carriers|delivery|shipping rates', 'module' => 'shipping', 'links' => [
                ['label' => 'Carriers', 'url' => '/admin/r/carriers', 'cap' => 'shipping.view', 'module' => 'shipping'],
                ['label' => 'Shipping zones', 'url' => '/admin/r/shipping_zones', 'cap' => 'shipping.view', 'module' => 'shipping'],
            ]],
            ['terms' => 'logo|logotipo|icono|favicon|imagen de la tienda|shop logo|site icon', 'keys' => ['store.logo', 'store.icon']],
            ['terms' => 'nombre de la tienda|nombre del negocio|razon social|nif|cif|shop name|legal name|tax id', 'keys' => ['store.name', 'store.legal_name', 'store.vat_id']],
            ['terms' => 'correo|correos|email|emails|mail|smtp|contacto|contact|telefono|phone|direccion de la tienda', 'keys' => ['store.email', 'store.phone', 'store.address'], 'module' => 'mail'],
            ['terms' => 'moneda|divisa|euro|euros|currency|simbolo de la moneda', 'keys' => ['shop.currency', 'shop.currency_symbol']],
            ['terms' => 'idioma|idiomas|traduccion|traducciones|language|languages|translation', 'keys' => ['i18n.languages']],
            ['terms' => 'cache|rendimiento|velocidad|lentitud|performance|speed', 'keys' => ['cache.pages']],
            ['terms' => 'seguridad|contrasena|api|limite de peticiones|security|rate limit', 'keys' => ['api.rate_per_minute']],
            ['terms' => 'stock|existencias|inventario|agotado|agotados|stock bajo|low stock|inventory', 'keys' => ['catalog.low_stock', 'catalog.show_out_of_stock'], 'links' => [
                ['label' => 'Stock and prices', 'url' => '/admin/catalog/stock', 'cap' => 'catalog.view', 'module' => 'catalog'],
            ]],
            ['terms' => 'ia|inteligencia artificial|ai|gemini|openai|modelo|asistente|assistant', 'module' => 'ai'],
            ['terms' => 'pago|pagos|cobro|cobros|tarjeta|redsys|paypal|transferencia|contrareembolso|bizum|payment|payments|card', 'prefix' => 'payments-'],
            ['terms' => 'factura|facturas|facturacion|invoice|invoices|invoicing', 'module' => 'invoices'],
            ['terms' => 'seo|posicionamiento|sitemap|robots|meta|etiquetas meta|metadatos', 'module' => 'seo'],
            ['terms' => 'pedido|pedidos|numeracion de pedidos|numero de pedido|order|orders|order number', 'module' => 'orders'],
            ['terms' => 'cliente|clientes|registro|cuenta|cuentas|customer|customers|account|register', 'module' => 'customers'],
            ['terms' => 'resenas|valoraciones|opiniones|reviews|ratings', 'module' => 'reviews'],
        ];
    }

    /**
     * Todos los campos de ajustes de los módulos activos que la persona puede ver.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function settings(App $app): array
    {
        $entries = [];
        foreach ($app->modules()->definitions('settings') as $module => $definition) {
            $cap = isset($definition['cap']) ? (string) $definition['cap'] : 'settings.edit';
            if (!isset($definition['tabs']) || !is_array($definition['tabs']) || !$app->auth()->can($cap)) {
                continue;
            }
            $title = t(isset($definition['title']) ? (string) $definition['title'] : ucfirst((string) $module));
            foreach ($definition['tabs'] as $tabKey => $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $tabLabel = t(isset($tab['label']) ? (string) $tab['label'] : (string) $tabKey);
                foreach (isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [] as $key => $field) {
                    if (!is_array($field) || !isset($field['label']) || in_array(isset($field['type']) ? (string) $field['type'] : '', ['html', 'heading'], true)) {
                        continue;
                    }
                    $entries[] = [
                        'key' => (string) $key, 'module' => (string) $module, 'label' => t((string) $field['label']),
                        'hint' => isset($field['hint']) ? t((string) $field['hint']) : '', 'tab' => (string) $tabKey, 'tab_label' => $tabLabel, 'title' => $title,
                        'url' => $app->url()->plain('/admin/settings/' . $module) . '#field=f-' . trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $key), '-') . '&tab=' . rawurlencode((string) $tabKey),
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Las pantallas de ajustes que encajan con lo que se escribió, las mejores primero.
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function search(App $app, string $query, int $limit = 5): array
    {
        $norm = Reader::norm($query);
        $tokens = [];
        foreach (explode(' ', $norm) as $word) {
            if (strlen($word) >= 2 && !in_array($word, self::STOP, true) && !in_array($word, $tokens, true)) {
                $tokens[] = $word;
            }
        }
        if ($tokens === []) {
            return [];
        }
        $entries = self::settings($app);
        $scored = [];
        $concepts = [];
        foreach (self::concepts() as $concept) {
            if (Reader::has($norm, '(?:' . implode('|', array_map(static function (string $term): string {
                return preg_quote($term, '/');
            }, explode('|', (string) $concept['terms']))) . ')')) {
                $concepts[] = $concept;
            }
        }

        foreach ($entries as $entry) {
            $score = 0;
            $label = Reader::norm($entry['label']);
            $hint = Reader::norm($entry['hint']);
            $tab = Reader::norm($entry['tab_label']);
            $title = Reader::norm($entry['title']);
            $key = Reader::norm(str_replace(['.', '_'], ' ', $entry['key']));
            foreach ($tokens as $token) {
                $quoted = preg_quote($token, '/');
                $score += Reader::has($label, $quoted) ? 3 : 0;
                $score += Reader::has($key, $quoted) ? 2 : 0;
                $score += Reader::has($tab, $quoted) ? 2 : 0;
                $score += Reader::has($hint, $quoted) ? 1 : 0;
                $score += Reader::has($title, $quoted) ? 1 : 0;
            }
            foreach ($concepts as $concept) {
                if (isset($concept['module']) && $concept['module'] === $entry['module']) {
                    $score += 10;
                }
                if (isset($concept['keys']) && in_array($entry['key'], $concept['keys'], true)) {
                    $score += 12;
                }
                if (isset($concept['prefix']) && strpos($entry['module'], (string) $concept['prefix']) === 0) {
                    $score += 10;
                }
            }
            if ($score >= 3) {
                $scored[] = ['score' => $score, 'label' => $entry['title'] . ' › ' . $entry['tab_label'] . ' › ' . $entry['label'], 'url' => $entry['url']];
            }
        }
        foreach ($concepts as $concept) {
            foreach (isset($concept['links']) ? $concept['links'] : [] as $link) {
                if ($app->modules()->isLoaded((string) $link['module']) && $app->auth()->can((string) $link['cap'])) {
                    $scored[] = ['score' => isset($link['score']) ? (int) $link['score'] : 20, 'label' => t((string) $link['label']), 'url' => $app->url()->plain((string) $link['url'])];
                }
            }
        }
        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']);
        });
        $out = [];
        $seen = [];
        foreach ($scored as $item) {
            if (isset($seen[$item['url']])) {
                continue;
            }
            $seen[$item['url']] = true;
            $out[] = ['label' => $item['label'], 'group' => t('Settings'), 'url' => $item['url']];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
