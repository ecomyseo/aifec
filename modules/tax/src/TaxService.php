<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tax;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;

/**
 * Servicio «tax»: qué impuesto lleva cada cosa según adónde va. Las firmas son las del contrato de comercio (§ 4).
 *
 * Un destino es ['country' => 'ES', 'region' => '35', 'postcode' => '35001', 'city' => '…']; lo que falte, vacío.
 * Un destino vacío o sin país es el de la tienda (baseDestination()).
 *
 * Los tipos van en centésimas de punto (2100 = 21 %). De todos los tipos activos de la clase y del país del destino
 * gana el más concreto: uno cuyos códigos postales casan (3) > uno de la misma región (2) > uno solo de país (1).
 * Un tipo con códigos postales no mira su región. A igualdad de concreción, la mayor prioridad; a igualdad, el menor id.
 *
 * La clase que no existe (o viene nula) se trata como la de por defecto.
 *
 * Las clases y los tipos se leen de la base de datos una vez por petición; flush() los suelta (lo hace solo el panel
 * al guardar o borrar). Los ajustes se leen cada vez.
 */
final class TaxService
{
    /** @var App */
    private $app;

    /** @var array<int,array{code:string,name:string,is_default:int}>|null id => clase, en el orden de la tienda */
    private $classes;

    /** @var array<string,array<int,array<string,mixed>>>|null «clase|país» => tipos activos */
    private $rates;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Suelta lo leído: la próxima consulta vuelve a la base de datos. */
    public function flush(): void
    {
        $this->classes = null;
        $this->rates = null;
    }

    /** Los precios de producto, envío, recargos y cupones se escriben con el impuesto dentro. Ajuste tax.prices_include_tax (sí). */
    public function pricesIncludeTax(): bool
    {
        return $this->app->settings()->bool('tax.prices_include_tax', true);
    }

    /** Cómo se ENSEÑAN los precios en la tienda: con impuestos. Ajuste tax.display_with_tax (sí). */
    public function displayWithTax(): bool
    {
        return $this->app->settings()->bool('tax.display_with_tax', true);
    }

    /**
     * Dónde está la tienda: tax.base_country («ES» si falta o no es válido) y tax.base_postcode.
     *
     * @return array{country:string,region:string,postcode:string,city:string}
     */
    public function baseDestination(): array
    {
        $settings = $this->app->settings();
        $country = self::country($settings->str('tax.base_country', 'ES'));

        return ['country' => $country !== '' ? $country : 'ES', 'region' => '', 'postcode' => trim($settings->str('tax.base_postcode')), 'city' => ''];
    }

    /** La clase marcada por defecto; si ninguna lo está, la primera; 0 si no hay clases. */
    public function defaultClassId(): int
    {
        $first = 0;
        foreach ($this->loadClasses() as $id => $class) {
            if ($class['is_default'] === 1) {
                return $id;
            }
            if ($first === 0) {
                $first = $id;
            }
        }

        return $first;
    }

    /**
     * El tipo de una clase para un destino: ['rate' => 2100, 'name' => 'IVA 21 %'], o ['rate' => 0, 'name' => ''] si no
     * hay ninguno que case. Pasa por el filtro sellado tax.rate (array $rate, ?int $classId, array $destination), al que
     * llega la clase ya resuelta (la de por defecto si venía nula) y el destino ya resuelto.
     *
     * @param array<string,mixed> $destination
     *
     * @return array{rate:int,name:string}
     */
    public function rate(?int $classId, array $destination): array
    {
        $classes = $this->loadClasses();
        $dest = $this->resolve($destination);
        $effective = $classId !== null && isset($classes[$classId]) ? $classId : $this->defaultClassId();

        $best = $this->bestRate($effective, $dest);
        $rate = $best !== null ? ['rate' => (int) $best['rate'], 'name' => (string) $best['name']] : ['rate' => 0, 'name' => ''];
        $filtered = $this->app->hooks()->apply('tax.rate', $rate, $effective > 0 ? $effective : null, $dest);

        return self::clean($filtered, $rate);
    }

    /**
     * El tipo del envío. Ajuste tax.shipping_class: el código de una clase (se resuelve con rate()) o «highest», el mayor
     * de los tipos de las líneas ($lineRates son los ['rate', 'name'] de cada línea; sin líneas, la clase por defecto).
     * Un código que ya no existe se trata como «highest».
     *
     * @param array<string,mixed>                $destination
     * @param array<int,array<string,mixed>>     $lineRates
     *
     * @return array{rate:int,name:string}
     */
    public function shippingRate(array $destination, array $lineRates = []): array
    {
        $mode = trim($this->app->settings()->str('tax.shipping_class', 'highest'));
        if ($mode !== '' && $mode !== 'highest') {
            foreach ($this->loadClasses() as $id => $class) {
                if ($class['code'] === $mode) {
                    return $this->rate($id, $destination);
                }
            }
        }

        $best = null;
        foreach ($lineRates as $line) {
            if (!is_array($line)) {
                continue;
            }
            $value = isset($line['rate']) && is_numeric($line['rate']) ? (int) $line['rate'] : 0;
            if ($best === null || $value > $best['rate']) {
                $best = ['rate' => $value, 'name' => isset($line['name']) && is_scalar($line['name']) ? (string) $line['name'] : ''];
            }
        }
        if ($best !== null) {
            return $best;
        }

        return $this->rate($this->defaultClassId(), $destination);
    }

    /**
     * ¿Este cliente no paga impuestos en este destino?
     *
     * El cliente llega como array. Se lee `group_tax_exempt` (lo que trae la unión con su grupo) y `tax_exempt`; si
     * cualquiera de los dos vale, está exento. Además, solo con el ajuste tax.eu_b2b_exempt, lo está el que tiene `vat_id`
     * y recibe en otro país de la UE distinto del de la tienda.
     *
     * @param array<string,mixed>|null $customer
     * @param array<string,mixed>      $destination
     */
    public function exempt(?array $customer, array $destination): bool
    {
        if ($customer === null) {
            return false;
        }
        foreach (['group_tax_exempt', 'tax_exempt'] as $key) {
            if (isset($customer[$key]) && in_array($customer[$key], [true, 1, '1', 'true', 'on', 'yes'], true)) {
                return true;
            }
        }
        if (!$this->app->settings()->bool('tax.eu_b2b_exempt', false)) {
            return false;
        }
        $vat = isset($customer['vat_id']) && is_scalar($customer['vat_id']) ? trim((string) $customer['vat_id']) : '';
        if ($vat === '') {
            return false;
        }
        $country = $this->resolve($destination)['country'];

        return Countries::isEu($country) && $country !== $this->baseDestination()['country'];
    }

    /**
     * Las clases en el orden de la tienda: [id => ['code' => 'standard', 'name' => 'Standard rate']].
     *
     * @return array<int,array{code:string,name:string}>
     */
    public function classes(): array
    {
        $out = [];
        foreach ($this->loadClasses() as $id => $class) {
            $out[$id] = ['code' => $class['code'], 'name' => $class['name']];
        }

        return $out;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @return array<int,array{code:string,name:string,is_default:int}>
     */
    private function loadClasses(): array
    {
        if ($this->classes === null) {
            $this->classes = [];
            foreach ($this->app->db()->select('SELECT id, code, name, is_default FROM {tax_classes} ORDER BY position, id') as $row) {
                $this->classes[(int) $row['id']] = ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'is_default' => (int) $row['is_default'] === 1 ? 1 : 0];
            }
        }

        return $this->classes;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function loadRates(): array
    {
        if ($this->rates === null) {
            $this->rates = [];
            foreach ($this->app->db()->select('SELECT id, tax_class_id, country, region, postcodes, rate, name, priority FROM {tax_rates} WHERE active = 1 ORDER BY id') as $row) {
                $this->rates[(int) $row['tax_class_id'] . '|' . strtoupper((string) $row['country'])][] = $row;
            }
        }

        return $this->rates;
    }

    /**
     * El destino completo: el que se da, o el de la tienda si viene vacío o sin país.
     *
     * @param array<string,mixed> $destination
     *
     * @return array{country:string,region:string,postcode:string,city:string}
     */
    private function resolve(array $destination): array
    {
        $country = self::country(isset($destination['country']) && is_scalar($destination['country']) ? (string) $destination['country'] : '');
        if ($country === '') {
            return $this->baseDestination();
        }
        $text = static function (string $key) use ($destination): string {
            return isset($destination[$key]) && is_scalar($destination[$key]) ? trim((string) $destination[$key]) : '';
        };

        return ['country' => $country, 'region' => $text('region'), 'postcode' => $text('postcode'), 'city' => $text('city')];
    }

    /**
     * El tipo activo más concreto de la clase para el destino, o null.
     *
     * @param array{country:string,region:string,postcode:string,city:string} $dest
     *
     * @return array<string,mixed>|null
     */
    private function bestRate(int $classId, array $dest): ?array
    {
        $rates = $this->loadRates();
        $candidates = isset($rates[$classId . '|' . $dest['country']]) ? $rates[$classId . '|' . $dest['country']] : [];
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $row) {
            $score = $this->specificity($row, $dest);
            if ($score === 0) {
                continue;
            }
            if ($best === null || $score > $bestScore
                || ($score === $bestScore && ((int) $row['priority'] > (int) $best['priority']
                    || ((int) $row['priority'] === (int) $best['priority'] && (int) $row['id'] < (int) $best['id'])))) {
                $best = $row;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Cuánto de concreto es un tipo para el destino: 3 casan los códigos postales, 2 la región, 1 solo el país, 0 no aplica.
     *
     * @param array<string,mixed>                                              $row
     * @param array{country:string,region:string,postcode:string,city:string} $dest
     */
    private function specificity(array $row, array $dest): int
    {
        $postcodes = trim((string) $row['postcodes']);
        if ($postcodes !== '') {
            return Postcodes::matches($postcodes, $dest['postcode']) ? 3 : 0;
        }
        $region = trim((string) $row['region']);
        if ($region !== '') {
            return strcasecmp($region, $dest['region']) === 0 ? 2 : 0;
        }

        return 1;
    }

    /**
     * Lo que devolvió el filtro, o el tipo de antes si no tiene la forma.
     *
     * @param mixed                          $value
     * @param array{rate:int,name:string}    $fallback
     *
     * @return array{rate:int,name:string}
     */
    private static function clean($value, array $fallback): array
    {
        if (!is_array($value) || !isset($value['rate']) || !is_numeric($value['rate'])) {
            return $fallback;
        }

        return ['rate' => max(0, (int) $value['rate']), 'name' => isset($value['name']) && is_scalar($value['name']) ? (string) $value['name'] : ''];
    }

    /** El país en mayúsculas si son dos letras; '' en otro caso. */
    private static function country(string $iso): string
    {
        $iso = strtoupper(trim($iso));

        return preg_match('/^[A-Z]{2}$/D', $iso) === 1 ? $iso : '';
    }
}
