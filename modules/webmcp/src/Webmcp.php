<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Webmcp;

use Aifec\Kernel\App;

/**
 * WebMCP: lo que la tienda le cuenta al navegador para que un agente de IA la maneje.
 *
 * Dos caminos, uno por cada forma de la especificación:
 *
 *  - Declarativo: los formularios de la tienda llevan «toolname» y «tooldescription» y cada campo «toolparamdescription».
 *    Las plantillas los piden con los huecos «webmcp.form» y «webmcp.param»; este servicio los pinta (y no pinta nada con
 *    el ajuste apagado). Las descripciones salen del catálogo de idioma.
 *  - Por guion: webmcp.js registra las herramientas de la tienda con su esquema de entrada. La lista se saca de la misma
 *    tabla que usan el asistente, la API y el MCP (servicio «tools»), así que no puede diferir de lo que existe.
 *
 * El pedido no se hace por aquí: «checkout_place» no se publica nunca. El agente lleva a la persona hasta el botón de
 * la página y es ella quien lo confirma.
 */
final class Webmcp
{
    /** Herramientas que no se publican. */
    public const EXCLUDED = ['checkout_place'];

    /** Los formularios que la tienda sabe marcar. */
    public const FORMS = [
        'search_products', 'add_to_cart', 'update_cart_line', 'apply_coupon', 'calculate_shipping',
        'sign_in', 'subscribe_newsletter', 'send_contact_message',
    ];

    /** Las herramientas de la caja que solo leen. */
    private const CHECKOUT_READS = ['checkout_status', 'checkout_shipping_options', 'checkout_payment_options'];

    /** @var App */
    private $app;

    /** @var array<string,int> los nombres de herramienta ya escritos en esta petición (no pueden repetirse en una página) */
    private $used = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Empieza una petición nueva: los nombres de herramienta ya escritos son los de la página anterior, no los de esta. */
    public function reset(): void
    {
        $this->used = [];
    }

    /** ¿Está encendido? Con el ajuste apagado no hay guion, ni marcas, ni dirección. */
    public function enabled(): bool
    {
        return $this->app->settings()->bool('webmcp.enabled', true);
    }

    // ------------------------------------------------------------------ herramientas

    /**
     * Las herramientas que se publican: todas las de la tabla compartida menos las excluidas.
     *
     * @return array<string,array{description:string,schema:array<string,mixed>,writes:bool}>
     */
    public function tools(): array
    {
        if (!$this->app->has('tools')) {
            return [];
        }
        $service = $this->app->service('tools');
        $all = is_object($service) && method_exists($service, 'all') ? $service->all() : [];
        $out = [];
        foreach (is_array($all) ? $all : [] as $name => $tool) {
            if (!is_string($name) || in_array($name, self::EXCLUDED, true) || !is_array($tool)) {
                continue;
            }
            $out[$name] = [
                'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                'schema' => isset($tool['schema']) && is_array($tool['schema']) ? $tool['schema'] : ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                'writes' => !empty($tool['writes']),
            ];
        }

        return $out;
    }

    /**
     * ¿Solo lee? Las que cambian el carrito o lo que lleva guardado la caja no son de solo lectura.
     */
    public function readOnly(string $name, bool $writes): bool
    {
        if ($writes) {
            return false;
        }

        return strpos($name, 'checkout_') !== 0 || in_array($name, self::CHECKOUT_READS, true);
    }

    /**
     * La lista que lee webmcp.js (/webmcp/tools.json): nombre, descripción, esquema de entrada, anotaciones y dirección.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        $list = [];
        $url = $this->app->url();
        foreach ($this->tools() as $name => $tool) {
            $list[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => self::schema($tool['schema']),
                'annotations' => ['readOnlyHint' => $this->readOnly($name, $tool['writes'])],
                'endpoint' => $url->route('webmcp.call', ['name' => $name], ''),
            ];
        }

        return ['version' => 1, 'tools' => $list];
    }

    /**
     * Un esquema listo para JSON: una lista vacía de propiedades tiene que salir como «{}», no como «[]».
     *
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    public static function schema(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            if ($schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            } else {
                foreach ($schema['properties'] as $key => $property) {
                    if (is_array($property)) {
                        $schema['properties'][$key] = self::schema($property);
                    }
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::schema($schema['items']);
        }

        return $schema;
    }

    // ------------------------------------------------------------------ formularios marcados

    /**
     * Lo que dice un formulario de la tienda: descripción y descripción de cada campo, en el idioma de la página.
     *
     * @param array<string,mixed> $vars  n (la línea del carrito) y name (su producto), solo para update_cart_line
     *
     * @return array{description:string,params:array<string,string>}|null null si no es un formulario que se sepa marcar
     */
    public function describe(string $form, array $vars = []): ?array
    {
        switch ($form) {
            case 'search_products':
                return ['description' => t('Search the shop catalogue. Give the words to look for and the results page opens.'), 'params' => [
                    'q' => t('What to look for, in the customer\'s own words'),
                ]];
            case 'add_to_cart':
                // En un listado cada tarjeta es su propia herramienta: la descripción dice de qué producto.
                $product = isset($vars['name']) ? trim((string) $vars['name']) : '';

                return ['description' => $product !== ''
                    ? t('Add «{name}» to the cart. The price is always the shop\'s own.', ['name' => $product])
                    : t('Add this product to the cart. The price is always the shop\'s own.'), 'params' => [
                    'product_id' => t('The product number, already filled in'),
                    'variant_id' => t('The variant number, 0 when the product has no variants'),
                    'qty' => t('Units to add'),
                    'opt' => t('The value chosen for this option, for example the size or the colour'),
                ]];
            case 'update_cart_line':
                $n = isset($vars['n']) ? (string) (int) $vars['n'] : '1';
                $name = isset($vars['name']) ? (string) $vars['name'] : '';

                return ['description' => t('Change the quantity of cart line {n}: {name}.', ['n' => $n, 'name' => $name]), 'params' => [
                    'key' => t('The cart line, already filled in'),
                    'qty' => t('The new quantity'),
                ]];
            case 'apply_coupon':
                return ['description' => t('Apply a discount coupon to the cart.'), 'params' => [
                    'code' => t('The coupon code'),
                ]];
            case 'calculate_shipping':
                return ['description' => t('Estimate the delivery options and their price for a country and a postcode.'), 'params' => [
                    'region' => t('The region, leave it empty'),
                    'country' => t('Two-letter country code, for example ES'),
                    'postcode' => t('The postcode of the destination'),
                ]];
            case 'sign_in':
                return ['description' => t('Sign in to the customer account with the email and the password.'), 'params' => [
                    'email' => t('The email of the account'),
                    'password' => t('The password of the account'),
                    'next' => t('Where to go after signing in, already filled in'),
                ]];
            case 'subscribe_newsletter':
                return ['description' => t('Subscribe an email address to the shop newsletter.'), 'params' => [
                    'email' => t('The email to subscribe'),
                ]];
            case 'send_contact_message':
                // Los campos del formulario del módulo de contenido se llaman f[clave]; los dos últimos son la trampa contra robots y la marca
                // de tiempo, que no se rellenan nunca.
                return ['description' => t('Send a message to the shop through the contact form.'), 'params' => [
                    'f[name]' => t('The name of the person writing'),
                    'f[email]' => t('The email where the shop will answer'),
                    'f[message]' => t('What the person wants to ask or say'),
                    'f[consent]' => t('Send 1 only if the person has said they accept the privacy policy'),
                    'website' => t('Anti-spam trap: always leave it empty'),
                    '_mark' => t('Filled in by the page: leave it empty'),
                ]];
        }

        return null;
    }

    /**
     * Los atributos de un formulario: « toolname="…" tooldescription="…"», ya escapados. Vacío con WebMCP apagado o si
     * el formulario no se conoce. update_cart_line lleva el número de su línea en el nombre (update_cart_line_2): en una
     * página hay una por línea y los nombres no pueden repetirse.
     *
     * @param array<string,mixed> $vars
     */
    public function formAttributes(string $form, array $vars = []): string
    {
        if (!$this->enabled()) {
            return '';
        }
        $info = $this->describe($form, $vars);
        if ($info === null) {
            return '';
        }
        $name = $form . (isset($vars['n']) ? '_' . (int) $vars['n'] : '');
        // Un nombre no puede repetirse en una página (dos buscadores, el panel móvil y la cabecera): el segundo lleva «_2», el tercero «_3».
        if (isset($this->used[$name])) {
            $base = $name;
            $count = 2;
            while (isset($this->used[$base . '_' . $count])) {
                ++$count;
            }
            $name = $base . '_' . $count;
        }
        $this->used[$name] = 1;

        return ' toolname="' . e($name) . '" tooldescription="' . e($info['description']) . '"';
    }

    /**
     * El atributo de un campo: « toolparamdescription="…»", ya escapado. Vacío con WebMCP apagado o si el campo no se conoce.
     */
    public function paramAttribute(string $form, string $field): string
    {
        if (!$this->enabled()) {
            return '';
        }
        $info = $this->describe($form);
        if ($info === null || !isset($info['params'][$field])) {
            return '';
        }

        return ' toolparamdescription="' . e($info['params'][$field]) . '"';
    }

    /**
     * Dónde están marcados los formularios: nombre => plantillas que piden su marca. Lo mira el panel.
     *
     * @return array<string,string[]>
     */
    public function placement(): array
    {
        $root = rtrim(str_replace('\\', '/', $this->app->root()), '/');
        $found = array_fill_keys(self::FORMS, []);
        $files = [];
        // Plantillas de los temas, vistas de módulos y plugins, y el código de los módulos que pinta HTML (el formulario del bloque
        // «form» del módulo de contenido es PHP, no una plantilla).
        foreach (['themes' => ['templates'], 'modules' => ['views', 'src'], 'plugins' => ['views']] as $base => $subs) {
            $dirs = glob($root . '/' . $base . '/*', GLOB_ONLYDIR);
            foreach (is_array($dirs) ? $dirs : [] as $dir) {
                foreach ($subs as $sub) {
                    if (is_dir($dir . '/' . $sub)) {
                        $files = array_merge($files, self::phpFiles($dir . '/' . $sub));
                    }
                }
            }
        }
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            if (strpos($code, "'webmcp.form'") === false) {
                continue;
            }
            foreach (self::FORMS as $form) {
                if (strpos($code, "'webmcp.form', '" . $form . "'") !== false) {
                    $found[$form][] = substr($file, strlen($root) + 1);
                }
            }
        }

        return $found;
    }

    /**
     * @return string[]
     */
    private static function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (substr($path, -4) === '.php') {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }
}
