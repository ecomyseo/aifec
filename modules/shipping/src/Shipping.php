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

use Aifec\Commerce\ShippingHandler;
use Aifec\Kernel\App;
use Aifec\Modules\Tax\Postcodes;

/**
 * Servicio «shipping»: cuánto cuesta y cuánto tarda llevar un paquete a un destino. Las firmas son las del contrato de comercio (§ 4).
 *
 * Un destino es ['country' => 'ES', 'region' => '35', 'postcode' => '35001', 'city' => '…']; lo que falte, vacío. Sin país se
 * usa el ajuste shipping.default_country. Un paquete es ['weight' => gramos, 'items_total' => céntimos, 'qty' => n, 'needs_shipping' => bool].
 *
 * Zona: de las zonas activas gana la que tiene la localización más concreta que contenga el destino —códigos postales (4),
 * región (3), país (2), «resto del mundo» (1)—; a igualdad, la de menor posición y, a igualdad, la de menor id. Una localización
 * con códigos postales no mira su región. Sin zona no hay opciones.
 *
 * Opciones: cada transportista activo cuyo manejador sirva el destino y el paquete, por orden de posición. Un transportista con
 * peso máximo no ofrece paquetes más pesados; con «gratis a partir de», un paquete cuyos artículos llegan a ese importe sale a
 * 0 con free = true. El resultado pasa por el filtro sellado «shipping.options» (array $options, array $package, array $destination).
 *
 * A los manejadores se les pasa el destino con una clave más, «zone_id» (la zona ya resuelta), que los plugins pueden ignorar.
 */
final class Shipping
{
    /** @var App */
    private $app;

    /** @var array<string,ShippingHandler> código => manejador */
    private $handlers = [];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->registerHandler(new RatesHandler($app));
        $this->registerHandler(new PickupHandler());
    }

    /** Un plugin de transporte registra su forma de calcular el precio; el código es el que se pone en carriers.handler. */
    public function registerHandler(ShippingHandler $handler): void
    {
        $code = trim($handler->code());
        if ($code === '') {
            throw new \InvalidArgumentException('A shipping handler needs a code');
        }
        $this->handlers[$code] = $handler;
    }

    /**
     * Los códigos de los manejadores registrados, los de serie primero.
     *
     * @return string[]
     */
    public function handlerCodes(): array
    {
        return array_keys($this->handlers);
    }

    public function handler(string $code): ?ShippingHandler
    {
        return isset($this->handlers[$code]) ? $this->handlers[$code] : null;
    }

    /** País de partida cuando el destino no lo dice: ajuste shipping.default_country («ES»). */
    public function defaultCountry(): string
    {
        $country = strtoupper(trim($this->app->settings()->str('shipping.default_country', 'ES')));

        return preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : 'ES';
    }

    /** ¿El carrito deja calcular el envío con país y código postal? Ajuste shipping.estimate_in_cart (sí). */
    public function estimateInCart(): bool
    {
        return $this->app->settings()->bool('shipping.estimate_in_cart', true);
    }

    /** Un transportista con su ajuste ya descodificado y los números como números, o null si no existe. */
    public function carrier(int $id): ?array
    {
        $row = $this->app->db()->row('SELECT * FROM {carriers} WHERE id = ?', [$id]);

        return $row !== null ? self::carrierRow($row) : null;
    }

    /**
     * La zona de envío de un destino, o null si ninguna lo contiene.
     *
     * @param array<string,mixed> $destination
     */
    public function zoneFor(array $destination): ?int
    {
        return $this->resolveZone($this->resolve($destination));
    }

    /**
     * Lo que se puede ofrecer para este paquete en este destino.
     *
     * @param array<string,mixed> $package
     * @param array<string,mixed> $destination
     *
     * @return array<int,array<string,mixed>> carrier_id, code, name, delay, price, free, tax_class_id, min_days, max_days, needs_address
     */
    public function options(array $package, array $destination): array
    {
        $pkg = array_merge($package, [
            'weight' => isset($package['weight']) && is_numeric($package['weight']) ? max(0, (int) $package['weight']) : 0,
            'items_total' => isset($package['items_total']) && is_numeric($package['items_total']) ? max(0, (int) $package['items_total']) : 0,
            'qty' => isset($package['qty']) && is_numeric($package['qty']) ? max(0, (int) $package['qty']) : 0,
            'needs_shipping' => !array_key_exists('needs_shipping', $package) || !empty($package['needs_shipping']),
        ]);
        if (!$pkg['needs_shipping']) {
            return [];
        }
        $dest = $this->resolve($destination);
        $zone = $this->resolveZone($dest);
        if ($zone === null) {
            return [];
        }
        $handlerDest = $dest + ['zone_id' => $zone];

        $options = [];
        $rows = $this->app->db()->select('SELECT * FROM {carriers} WHERE active = 1 ORDER BY position, id');
        foreach ($rows as $row) {
            $carrier = self::carrierRow($row);
            $handler = $this->handler($carrier['handler']);
            if ($handler === null) {
                continue;
            }
            if ($carrier['max_weight'] > 0 && $pkg['weight'] > $carrier['max_weight']) {
                continue;
            }
            $quote = $handler->quote($carrier, $pkg, $handlerDest);
            if (!is_array($quote) || !isset($quote['price']) || !is_numeric($quote['price'])) {
                continue;
            }
            $price = max(0, (int) $quote['price']);
            $free = false;
            if ($carrier['free_from'] !== null && $pkg['items_total'] >= $carrier['free_from']) {
                $price = 0;
                $free = true;
            }
            $options[] = [
                'carrier_id' => $carrier['id'],
                'code' => $carrier['code'],
                'name' => $carrier['name'],
                'delay' => $carrier['delay'],
                'price' => $price,
                'free' => $free,
                'tax_class_id' => $carrier['tax_class_id'],
                'min_days' => isset($quote['min_days']) && is_numeric($quote['min_days']) ? (int) $quote['min_days'] : $carrier['min_days'],
                'max_days' => isset($quote['max_days']) && is_numeric($quote['max_days']) ? (int) $quote['max_days'] : $carrier['max_days'],
                'needs_address' => $handler->needsAddress(),
            ];
        }

        $filtered = $this->app->hooks()->apply('shipping.options', $options, $pkg, $destination);
        if (!is_array($filtered)) {
            return $options;
        }

        return array_values(array_filter($filtered, 'is_array'));
    }

    /**
     * Una fila de carriers con los tipos que dice el contrato: números como números, «settings» como array.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function carrierRow(array $row): array
    {
        $int = static function ($value): ?int {
            return $value !== null && $value !== '' && is_numeric($value) ? (int) $value : null;
        };
        $settings = isset($row['settings']) && is_string($row['settings']) ? json_decode($row['settings'], true) : null;
        $text = static function (string $key) use ($row): string {
            return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
        };

        return [
            'id' => (int) $row['id'],
            'code' => $text('code'),
            'name' => $text('name'),
            'delay' => $text('delay'),
            'handler' => $text('handler') !== '' ? $text('handler') : 'rates',
            'settings' => is_array($settings) ? $settings : [],
            'tax_class_id' => $int(isset($row['tax_class_id']) ? $row['tax_class_id'] : null),
            'free_from' => $int(isset($row['free_from']) ? $row['free_from'] : null),
            'max_weight' => (int) $int(isset($row['max_weight']) ? $row['max_weight'] : 0),
            'min_days' => $int(isset($row['min_days']) ? $row['min_days'] : null),
            'max_days' => $int(isset($row['max_days']) ? $row['max_days'] : null),
            'tracking_url' => $text('tracking_url'),
            'media_id' => $int(isset($row['media_id']) ? $row['media_id'] : null),
            'active' => !empty($row['active']) ? 1 : 0,
            'position' => (int) $int(isset($row['position']) ? $row['position'] : 0),
        ];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El destino completo: país en mayúsculas (el de por defecto si falta o no vale) y el resto como texto.
     *
     * @param array<string,mixed> $destination
     *
     * @return array{country:string,region:string,postcode:string,city:string}
     */
    private function resolve(array $destination): array
    {
        $text = static function (string $key) use ($destination): string {
            return isset($destination[$key]) && is_scalar($destination[$key]) ? trim((string) $destination[$key]) : '';
        };
        $country = strtoupper($text('country'));

        return [
            'country' => preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : $this->defaultCountry(),
            'region' => $text('region'),
            'postcode' => $text('postcode'),
            'city' => $text('city'),
        ];
    }

    /**
     * @param array{country:string,region:string,postcode:string,city:string} $dest
     */
    private function resolveZone(array $dest): ?int
    {
        $db = $this->app->db();
        $zones = $db->select('SELECT id FROM {shipping_zones} WHERE active = 1 ORDER BY position, id');
        if ($zones === []) {
            return null;
        }
        $byZone = [];
        foreach ($db->select('SELECT zone_id, country, region, postcodes FROM {zone_locations} ORDER BY id') as $location) {
            $byZone[(int) $location['zone_id']][] = $location;
        }

        $best = null;
        $bestScore = 0;
        foreach ($zones as $zone) {
            $id = (int) $zone['id'];
            $score = 0;
            foreach (isset($byZone[$id]) ? $byZone[$id] : [] as $location) {
                $score = max($score, self::specificity($location, $dest));
            }
            // Las zonas llegan por posición y id: a igualdad de concreción se queda la primera.
            if ($score > $bestScore) {
                $best = $id;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Cuánto de concreta es una localización para el destino: 4 casan los códigos postales, 3 la región, 2 solo el país,
     * 1 «resto del mundo», 0 no contiene el destino.
     *
     * @param array<string,mixed>                                              $location
     * @param array{country:string,region:string,postcode:string,city:string} $dest
     */
    private static function specificity(array $location, array $dest): int
    {
        $country = strtoupper(trim((string) $location['country']));
        if ($country === '*') {
            return 1;
        }
        if ($country !== $dest['country']) {
            return 0;
        }
        $postcodes = trim((string) $location['postcodes']);
        if ($postcodes !== '') {
            return Postcodes::matches($postcodes, $dest['postcode']) ? 4 : 0;
        }
        $region = trim((string) $location['region']);
        if ($region !== '') {
            return strcasecmp($region, $dest['region']) === 0 ? 3 : 0;
        }

        return 2;
    }
}
