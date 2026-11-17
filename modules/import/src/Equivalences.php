<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

/**
 * Equivalencias entre lo del origen y lo de aquí: estados de pedido, clases de impuesto, grupos de clientes y formas
 * de pago. Antes de empezar se lee del origen lo necesario y se propone la tabla por el significado; la persona la
 * corrige y se guarda en import_runs.equivalences como [clase => [valor del origen => valor de aquí]].
 *
 * El estado de pedido del origen se propone por su «state_meaning»; los demás, por su código o su nombre.
 */
final class Equivalences
{
    public const KINDS = ['order_state', 'tax_class', 'customer_group', 'payment_method'];

    /** Estado de aquí que corresponde a cada significado neutro (si ese estado existe). */
    private const STATE_FOR_MEANING = [
        'pending' => 'pending_payment', 'on_hold' => 'on_hold', 'paid' => 'processing', 'processing' => 'processing',
        'shipped' => 'shipped', 'delivered' => 'completed', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'failed',
    ];

    /** Nombres con los que los orígenes llaman a las formas de pago de aquí. */
    private const PAYMENT_ALIASES = [
        'transfer' => ['bacs', 'bank_transfer', 'banktransfer', 'ps_wirepayment', 'wire', 'transferencia'],
        'cod' => ['cod', 'cash_on_delivery', 'ps_cashondelivery', 'contrareembolso'],
    ];

    /** Tandas que se leen como mucho de un tipo al buscar equivalencias (50 registros cada una). */
    private const MAX_BATCHES = 40;

    /** Pedidos que se miran como mucho para sacar los estados cuando el lector no sabe darlos. */
    private const ORDER_SAMPLE_BATCHES = 4;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Lo que hay en el origen para cada clase de equivalencia.
     *
     * @return array<string,array<int,array{id:string,name:string,meaning:string}>>
     *
     * @throws SourceError
     */
    public function sourceValues(SourceReader $reader): array
    {
        $types = $reader->types();
        $out = ['order_state' => [], 'tax_class' => [], 'customer_group' => [], 'payment_method' => []];

        if (method_exists($reader, 'orderStates')) {
            $states = $reader->orderStates();
            foreach (is_array($states) ? $states : [] as $state) {
                if (is_array($state) && isset($state['id'])) {
                    $out['order_state'][] = [
                        'id' => (string) $state['id'],
                        'name' => isset($state['name']) ? $this->text($state['name'], (string) $state['id']) : (string) $state['id'],
                        'meaning' => isset($state['meaning']) ? (string) $state['meaning'] : '',
                    ];
                }
            }
        } elseif (in_array('orders', $types, true)) {
            $seen = [];
            foreach ($this->readSome($reader, 'orders', self::ORDER_SAMPLE_BATCHES) as $order) {
                $state = isset($order['state']) ? (string) $order['state'] : '';
                if ($state !== '' && !isset($seen[$state])) {
                    $seen[$state] = true;
                    $out['order_state'][] = ['id' => $state, 'name' => $state, 'meaning' => isset($order['state_meaning']) ? (string) $order['state_meaning'] : ''];
                }
            }
        }
        $map = ['tax_class' => ['tax_classes', 'name'], 'customer_group' => ['customer_groups', 'name'], 'payment_method' => ['payment_methods', 'title']];
        foreach ($map as $kind => $spec) {
            if (!in_array($spec[0], $types, true)) {
                continue;
            }
            foreach ($this->readSome($reader, $spec[0], self::MAX_BATCHES) as $record) {
                $id = (string) $record['id'];
                $fallback = isset($record['code']) && is_string($record['code']) && $record['code'] !== '' ? $record['code'] : $id;
                $out[$kind][] = [
                    'id' => $id,
                    'name' => isset($record[$spec[1]]) ? $this->text($record[$spec[1]], $fallback) : $fallback,
                    'meaning' => isset($record['code']) && is_string($record['code']) ? $record['code'] : '',
                ];
            }
        }

        return $out;
    }

    /**
     * Las opciones de aquí para cada clase: [clase => [valor => etiqueta]]. Las etiquetas de lo sembrado van en inglés
     * (se enseñan con t()); lo que escribió el comerciante, tal cual.
     *
     * @return array<string,array<string,string>>
     */
    public function localOptions(): array
    {
        $db = $this->app->db();
        $out = ['order_state' => [], 'tax_class' => [], 'customer_group' => [], 'payment_method' => []];
        if ($db->tableExists('order_states')) {
            foreach ($db->select('SELECT code, name FROM {order_states} ORDER BY position, id') as $row) {
                $out['order_state'][(string) $row['code']] = (string) $row['name'];
            }
        }
        if ($db->tableExists('tax_classes')) {
            foreach ($db->select('SELECT code, name FROM {tax_classes} ORDER BY position, id') as $row) {
                $out['tax_class'][(string) $row['code']] = (string) $row['name'];
            }
        }
        if ($db->tableExists('customer_groups')) {
            foreach ($db->select('SELECT id, name FROM {customer_groups} ORDER BY id') as $row) {
                $out['customer_group'][(string) $row['id']] = (string) $row['name'];
            }
        }
        if ($this->app->has('payments')) {
            foreach ($this->app->service('payments')->all() as $code => $method) {
                $info = $method->info();
                $out['payment_method'][(string) $code] = isset($info['title']) ? (string) $info['title'] : (string) $code;
            }
        }

        return $out;
    }

    /**
     * La propuesta: para cada valor del origen, el de aquí que le corresponde (solo los que se pueden proponer).
     *
     * @param array<string,array<int,array{id:string,name:string,meaning:string}>> $source
     * @param array<string,array<string,string>>                                   $local
     *
     * @return array<string,array<string,string>>
     */
    public function propose(array $source, array $local): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $out[$kind] = [];
            $options = isset($local[$kind]) ? $local[$kind] : [];
            foreach (isset($source[$kind]) ? $source[$kind] : [] as $value) {
                $match = $this->match($kind, $value, $options);
                if ($match !== null) {
                    $out[$kind][$value['id']] = $match;
                }
            }
        }

        return $out;
    }

    /**
     * Limpia lo que envía la persona: solo valores de origen conocidos hacia opciones de aquí que existen.
     *
     * @param array<string,mixed>                $input
     * @param array<string,array<string,string>> $local
     *
     * @return array<string,array<string,string>>
     */
    public function sanitize(array $input, array $local): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $rows = isset($input[$kind]) && is_array($input[$kind]) ? $input[$kind] : [];
            $options = isset($local[$kind]) ? $local[$kind] : [];
            foreach ($rows as $sourceValue => $chosen) {
                if (is_string($chosen) && $chosen !== '' && array_key_exists($chosen, $options) && strlen((string) $sourceValue) <= 64) {
                    $out[$kind][(string) $sourceValue] = $chosen;
                }
            }
        }

        return $out;
    }

    /**
     * @param array{id:string,name:string,meaning:string} $value
     * @param array<string,string>                        $options
     */
    private function match(string $kind, array $value, array $options): ?string
    {
        if ($kind === 'order_state') {
            $code = isset(self::STATE_FOR_MEANING[$value['meaning']]) ? self::STATE_FOR_MEANING[$value['meaning']] : null;

            return $code !== null && isset($options[$code]) ? $code : null;
        }
        if ($kind === 'tax_class' || $kind === 'payment_method') {
            $needle = strtolower($value['meaning'] !== '' ? $value['meaning'] : $value['id']);
            foreach (array_keys($options) as $code) {
                if (strtolower((string) $code) === $needle) {
                    return (string) $code;
                }
            }
            if ($kind === 'payment_method') {
                foreach (self::PAYMENT_ALIASES as $code => $aliases) {
                    if (isset($options[$code]) && in_array($needle, $aliases, true)) {
                        return $code;
                    }
                }
            }

            return null;
        }
        // Grupos de clientes: por nombre.
        foreach ($options as $id => $name) {
            if (strtolower(trim($name)) === strtolower(trim($value['name']))) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Lee hasta $batches tandas de un tipo (como mucho) sin comprobarlas: aquí solo hacen falta nombres.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws SourceError
     */
    private function readSome(SourceReader $reader, string $type, int $batches): array
    {
        $records = [];
        $cursor = '';
        for ($i = 0; $i < $batches; ++$i) {
            $batch = $reader->read($type, $cursor, 50);
            foreach (isset($batch['records']) && is_array($batch['records']) ? $batch['records'] : [] as $record) {
                if (is_array($record) && isset($record['id'])) {
                    $records[] = $record;
                }
            }
            $next = isset($batch['next']) && is_string($batch['next']) ? $batch['next'] : null;
            if ($next === null || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $records;
    }

    /**
     * @param mixed $value texto o [idioma => texto]
     */
    private function text($value, string $fallback): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            $main = $this->app->defaultLang();
            if (isset($value[$main]) && is_string($value[$main]) && $value[$main] !== '') {
                return $value[$main];
            }
            foreach ($value as $text) {
                if (is_string($text) && $text !== '') {
                    return $text;
                }
            }
        }

        return $fallback;
    }
}
