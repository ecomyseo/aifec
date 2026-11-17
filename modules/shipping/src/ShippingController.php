<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Shipping;

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Geo\Countries;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Validation\Validator;

/**
 * «Envío» en una sola entrada del menú, con cuatro pestañas: Transportistas, Zonas y tarifas, Ajustes y Probar un envío.
 * Cada pestaña es una dirección propia (/admin/shipping/<pestaña>) que enseña solo su contenido. Las direcciones de antes
 * (el listado de transportistas, el de zonas y los ajustes) redirigen a su pestaña. Las altas y las fichas de un
 * transportista o de una zona siguen siendo las del recurso (/admin/r/carriers/…, /admin/r/shipping_zones/…).
 *
 * El precio de una tarifa se cambia en su fila de la pestaña «Zonas y tarifas»: con JavaScript va por fetch y repinta la tarjeta
 * de muestra; sin él, es un envío normal y vuelve a la misma pestaña.
 */
final class ShippingController
{
    /** pestaña => [texto, permiso para verla] */
    private const TABS = [
        'carriers' => ['Carriers', 'shipping.view'],
        'zones' => ['Zones and rates', 'shipping.view'],
        'settings' => ['Settings', 'shipping.edit'],
        'test' => ['Test a shipment', 'shipping.view'],
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ entradas y direcciones de antes

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        foreach (self::TABS as $key => $tab) {
            if ($this->app->auth()->can($tab[1])) {
                return Response::redirect($this->tabUrl($key));
            }
        }
        throw HttpError::forbidden();
    }

    /** @param array<string,string> $params */
    public function oldCarriers(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.view');

        return Response::redirect($this->tabUrl('carriers'));
    }

    /** @param array<string,string> $params */
    public function oldZones(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.view');

        return Response::redirect($this->tabUrl('zones'));
    }

    /** @param array<string,string> $params */
    public function oldSettings(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.edit');

        return Response::redirect($this->tabUrl('settings'));
    }

    // ------------------------------------------------------------------ pestañas

    /** @param array<string,string> $params */
    public function carriers(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.view');
        $app = $this->app;
        $rows = [];
        foreach ($app->db()->select('SELECT * FROM {carriers} ORDER BY position, id') as $row) {
            $rows[] = $row + ['handler_label' => ShippingAdmin::handlerLabel($app, (string) $row['handler'])];
        }
        $canEdit = $app->auth()->can('shipping.edit');

        return $this->page('carriers', 'shipping::admin/carriers', ['carriers' => $rows, 'canEdit' => $canEdit], [
            'actions' => $canEdit ? '<a class="af-btn af-btn-primary" href="' . e(Admin::resourceUrl($app, 'carriers', 'new')) . '">' . Admin::icon('plus') . e(t('Add a carrier')) . '</a>' : '',
            'help' => Admin::helpText("A carrier is a way to send the order. With a rate table, you set a price for each zone and each weight or order amount range.\nWith pickup in store there are no rates: you set the price and the address where the customer collects the order.\nUse Test a shipment to check that a destination gets the prices you expect."),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function zones(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.view');

        return $this->zonesPage(null);
    }

    /** @param array<string,string> $params */
    public function settings(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('shipping.edit');
        $app = $this->app;
        $definition = $app->modules()->definition('shipping', 'settings');
        $fields = [];
        foreach (isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [] as $tab) {
            foreach (is_array($tab) && isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [] as $name => $field) {
                if (is_array($field)) {
                    $fields[(string) $name] = $field;
                }
            }
        }
        $form = new Form($app, ['fields' => $fields]);
        $settings = $app->settings();
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $settings->get($key, array_key_exists('default', $field) ? $field['default'] : null);
        }
        // El envío va a la misma dirección de guardado que siempre: vuelve a «/admin/settings/shipping», que redirige aquí.
        $html = $form->render($values, [], ['action' => $app->url()->plain('/admin/settings/shipping'), 'cancel' => $this->tabUrl('carriers')]);

        return $this->page('settings', 'shipping::admin/settings', ['form' => $html], [
            'help' => Admin::helpText(isset($definition['help']) ? (string) $definition['help'] : ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function test(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('shipping.view');
        $service = $app->service('shipping');
        if (!$service instanceof Shipping) {
            throw new \LogicException('The shipping service is not available');
        }

        $country = strtoupper($request->str('country', $service->defaultCountry()));
        $country = preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : $service->defaultCountry();
        $postcode = trim($request->str('postcode'));
        $weightText = trim($request->str('weight'));
        $amountText = trim($request->str('amount'));
        $ran = $request->str('run') !== '';

        $errors = [];
        $weight = 0;
        if ($weightText !== '') {
            if (preg_match('/^\d{1,9}$/D', $weightText) === 1) {
                $weight = (int) $weightText;
            } else {
                $errors['weight'] = t('Enter the weight in whole grams, for example 1500.');
            }
        }
        $amount = 0;
        if ($amountText !== '') {
            $cents = Validator::toCents($amountText);
            if ($cents === null || $cents < 0) {
                $errors['amount'] = t('Enter an amount, for example 12,50');
            } else {
                $amount = $cents;
            }
        }

        $result = null;
        if ($ran && $errors === []) {
            $region = '';
            $province = '';
            if ($country === 'ES') {
                $found = Spain::provinceByPostcode($postcode);
                if ($found !== null) {
                    $region = $found['prefix'];
                    $province = $found['name'];
                }
            }
            $destination = ['country' => $country, 'region' => $region, 'postcode' => $postcode, 'city' => ''];
            $zoneId = $service->zoneFor($destination);
            $zoneName = '';
            if ($zoneId !== null) {
                $zoneName = (string) $app->db()->value('SELECT name FROM {shipping_zones} WHERE id = ?', [$zoneId]);
            }
            $result = [
                'zone_id' => $zoneId,
                'zone_name' => $zoneName,
                'province' => $province,
                'options' => $service->options(['weight' => $weight, 'items_total' => $amount, 'qty' => 1, 'needs_shipping' => true], $destination),
            ];
        }

        return $this->page('test', 'shipping::admin/test', [
            'action' => $app->url()->plain('/admin/shipping/test'),
            'countries' => Countries::all($app->i18n()->locale()),
            'country' => $country,
            'postcode' => $postcode,
            'weight' => $weightText,
            'amount' => $amountText,
            'errors' => $errors,
            'result' => $result,
        ]);
    }

    // ------------------------------------------------------------------ cambiar el precio de una tarifa

    /**
     * «Guardar» cambia el precio y «Ver el efecto» solo enseña lo que pagaría el pedido de muestra con ese precio.
     *
     * @param array<string,string> $params
     */
    public function saveRate(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('shipping.edit');
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($app->db()->find('carrier_rates', ['id' => $id]) === null) {
            throw HttpError::notFound();
        }
        $text = $request->str('price');
        $price = RateEditor::parsePrice($text);
        $check = $request->str('op') === 'check';

        if ($price === null) {
            $message = t('Enter an amount, for example 4,95');
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $message], 422);
            }
            Admin::flash($app, 'bad', $message);

            return Response::redirect($this->tabUrl('zones'));
        }
        if ($check) {
            $options = RateEditor::sample($app, $id, $price);
            if ($request->wantsJson()) {
                return Response::json(['ok' => true, 'message' => RateEditor::sentence($options), 'html' => RateEditor::previewCard($options, true)]);
            }

            return $this->zonesPage(['rate' => $id, 'price' => $text, 'options' => $options]);
        }

        RateEditor::save($app, $id, $price);
        $options = RateEditor::sample($app);
        $message = RateEditor::sentence($options);
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => $message, 'html' => RateEditor::previewCard($options, false)]);
        }
        Admin::flash($app, 'ok', $message);

        return Response::redirect($this->tabUrl('zones') . '#rate-' . $id);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * La pestaña «Zonas y tarifas». $try = ['rate' => id, 'price' => texto escrito, 'options' => lo que saldría] cuando se ha
     * pedido ver el efecto de un precio sin guardarlo.
     *
     * @param array<string,mixed>|null $try
     */
    private function zonesPage(?array $try): Response
    {
        $app = $this->app;
        $zones = [];
        foreach ($app->db()->select('SELECT z.*, (SELECT COUNT(*) FROM {carrier_rates} r WHERE r.zone_id = z.id) AS rates_count FROM {shipping_zones} z ORDER BY z.position, z.id') as $row) {
            $zones[] = $row + ['where_html' => ShippingAdmin::locationsCell($row, $app)];
        }
        $canEdit = $app->auth()->can('shipping.edit');
        $rates = RateEditor::rows($app);
        foreach ($rates as $i => $rate) {
            $rates[$i]['range'] = RateEditor::rangeText($app, $rate);
            $rates[$i]['field'] = $try !== null && (int) $try['rate'] === $rate['id'] ? (string) $try['price'] : RateEditor::cents($rate['price']);
        }
        $preview = $try !== null ? RateEditor::previewCard($try['options'], true) : RateEditor::previewCard(RateEditor::sample($app), false);

        return $this->page('zones', 'shipping::admin/zones', [
            'zones' => $zones,
            'rates' => $rates,
            'canEdit' => $canEdit,
            'preview' => $preview,
            'newZoneUrl' => Admin::resourceUrl($app, 'shipping_zones', 'new'),
            'currency' => (string) setting('shop.currency_symbol', '€'),
        ], [
            'actions' => $canEdit ? '<a class="af-btn af-btn-primary" href="' . e(Admin::resourceUrl($app, 'shipping_zones', 'new')) . '">' . Admin::icon('plus') . e(t('Add a zone')) . '</a>' : '',
            'help' => Admin::helpText("A zone is a set of places that share the same shipping prices, for example the peninsula or the islands.\nThe most specific place wins: a postcode before a region, a region before a country, a country before the rest of the world.\nChange the price of a rate in its own row: you see what a 1 kg order to Madrid would pay before you save.\nA zone with carrier rates cannot be deleted."),
        ]);
    }

    /**
     * Una pantalla de pestaña dentro del marco del panel.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     */
    private function page(string $tab, string $template, array $data, array $meta = [], int $status = 200): Response
    {
        $tabs = [];
        foreach (self::TABS as $key => $def) {
            if ($this->app->auth()->can($def[1])) {
                $tabs[] = ['key' => $key, 'label' => t($def[0]), 'url' => $this->tabUrl($key), 'active' => $key === $tab];
            }
        }

        return Admin::page($this->app, $template, $data + ['tabs' => $tabs], $meta + ['title' => t('Shipping'), 'active' => '/admin/shipping'], $status);
    }

    private function tabUrl(string $tab): string
    {
        return $this->app->url()->plain('/admin/shipping/' . $tab);
    }
}
