<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * Los perfiles de exportación y de importación (tabla erp_exports): lo que se guarda, cómo se valida y cuándo toca la próxima pasada.
 *
 * Los formularios trabajan con valores planos («csv_delimiter», «dest_host»…); la base guarda tres columnas JSON (options, filter,
 * dest). Aquí se traduce de una forma a otra. Las contraseñas y claves (FTP, POST) no van en esas columnas: se guardan cifradas con
 * App::setSecret() («erp.ftp.<id>» y «erp.post.<id>»).
 */
final class Profiles
{
    public const FORMATS = ['csv' => 'CSV', 'json' => 'JSON', 'xml' => 'XML'];

    public const SCHEDULES = ['manual' => 'By hand', 'minutes' => 'Every N minutes', 'hourly' => 'Every hour', 'daily' => 'Every day at a time'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<string,mixed>|null el perfil con columns, options, filter y dest_data descodificados
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->find('erp_exports', ['id' => $id]);

        return $row === null ? null : self::decorate($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(string $direction): array
    {
        $rows = $this->app->db()->select('SELECT * FROM {erp_exports} WHERE direction = ? ORDER BY id', [$direction]);

        return array_map([self::class, 'decorate'], $rows);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function decorate(array $row): array
    {
        foreach (['columns' => 'columns', 'options' => 'options', 'filter' => 'filter', 'dest' => 'dest_data'] as $column => $key) {
            $data = json_decode((string) ($row[$column] ?? ''), true);
            $row[$key] = is_array($data) ? $data : [];
        }
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (int) $row['enabled'] === 1;

        return $row;
    }

    /**
     * Los errores de un formulario de perfil: campo => mensaje (en inglés). Vacío = vale.
     *
     * @param array<string,mixed> $v valores planos
     *
     * @return array<string,string>
     */
    public function check(array $v, string $direction): array
    {
        $errors = [];
        $import = $direction === 'import';
        $name = isset($v['name']) ? trim((string) $v['name']) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            $errors['name'] = 'The name must have between 1 and 120 characters';
        }
        $kind = isset($v['kind']) ? (string) $v['kind'] : 'products';
        if (!$import && !isset(Sources::KINDS[$kind])) {
            $errors['kind'] = 'Choose what to export';
        }
        $format = isset($v['format']) ? (string) $v['format'] : '';
        if (!isset(self::FORMATS[$format]) || ($import && $format === 'xml')) {
            $errors['format'] = 'Choose a format';
        }
        if (!$import && !$errors && isset($v['columns']) && is_array($v['columns'])) {
            $fields = Sources::fields($kind);
            foreach ($v['columns'] as $column) {
                $field = is_array($column) && isset($column['field']) ? (string) $column['field'] : '';
                if ($field !== '_fixed' && !isset($fields[$field])) {
                    $errors['columns'] = 'The field «' . $field . '» does not exist for this kind of data';
                    break;
                }
            }
        }
        $destination = isset($v['destination']) ? (string) $v['destination'] : '';
        if (!isset(Destinations::available($this->app, $import)[$destination])) {
            $errors['destination'] = 'This destination is not available here';
        } else {
            $errors += $this->checkDestination($v, $destination, $import);
        }
        if ($import) {
            if (trim((string) ($v['col_sku'] ?? '')) === '') {
                $errors['col_sku'] = 'Write the name of the column that holds the SKU';
            }
            if (trim((string) ($v['col_stock'] ?? '')) === '' && trim((string) ($v['col_price'] ?? '')) === '' && trim((string) ($v['col_sale_price'] ?? '')) === '') {
                $errors['col_stock'] = 'Write at least one column to read: stock, price or offer price';
            }
        }
        $schedule = isset($v['schedule']) ? (string) $v['schedule'] : 'manual';
        if (!isset(self::SCHEDULES[$schedule])) {
            $errors['schedule'] = 'Choose when it runs';
        } elseif ($schedule === 'minutes' && ((int) ($v['schedule_every'] ?? 0) < 5 || (int) ($v['schedule_every'] ?? 0) > 1440)) {
            $errors['schedule_every'] = 'Write a number of minutes from 5 to 1440';
        } elseif ($schedule === 'daily' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($v['schedule_at'] ?? '')) !== 1) {
            $errors['schedule_at'] = 'Write the time as HH:MM';
        }

        return $errors;
    }

    /**
     * Guarda un perfil (nuevo si $id es null). Los valores ya tienen que haber pasado check().
     *
     * @param array<string,mixed> $v
     */
    public function save(array $v, string $direction, ?int $id): int
    {
        $db = $this->app->db();
        $import = $direction === 'import';
        $now = $this->app->clock()->sql();
        $current = $id !== null ? $this->find($id) : null;
        $onlyNew = !$import && !empty($v['only_new']);
        $columns = [];
        if (!$import) {
            foreach (isset($v['columns']) && is_array($v['columns']) ? $v['columns'] : [] as $column) {
                if (is_array($column) && isset($column['field'])) {
                    $columns[] = ['field' => (string) $column['field'], 'header' => trim((string) ($column['header'] ?? '')), 'value' => (string) ($column['value'] ?? '')];
                }
            }
            if ($columns === []) {
                $columns = Sources::defaultColumns((string) $v['kind']);
            }
        }
        $options = $import ? [
            'sku_col' => trim((string) ($v['col_sku'] ?? '')), 'stock_col' => trim((string) ($v['col_stock'] ?? '')), 'price_col' => trim((string) ($v['col_price'] ?? '')),
            'sale_price_col' => trim((string) ($v['col_sale_price'] ?? '')), 'stock_mode' => (string) ($v['stock_mode'] ?? 'absolute') === 'delta' ? 'delta' : 'absolute',
            'prices_include_tax' => !empty($v['prices_include_tax']),
        ] : Formats::options([
            'delimiter' => $v['csv_delimiter'] ?? null, 'quote' => $v['csv_quote'] ?? null, 'encoding' => $v['csv_encoding'] ?? null, 'decimal' => $v['csv_decimal'] ?? null,
            'date_format' => $v['csv_date_format'] ?? null, 'eol' => $v['csv_eol'] ?? null, 'header' => !empty($v['csv_header']), 'safe_cells' => !empty($v['csv_safe']),
            'layout' => $v['json_layout'] ?? null, 'root' => $v['xml_root'] ?? '', 'item' => $v['xml_item'] ?? '',
        ]);
        $dest = [
            'folder' => trim((string) ($v['dest_folder'] ?? '')), 'host' => trim((string) ($v['dest_host'] ?? '')), 'port' => (int) ($v['dest_port'] ?? 21),
            'user' => trim((string) ($v['dest_user'] ?? '')), 'path' => trim((string) ($v['dest_path'] ?? '')), 'passive' => !empty($v['dest_passive']), 'fingerprint' => trim((string) ($v['dest_fingerprint'] ?? '')),
            'url' => trim((string) ($v['dest_url'] ?? '')), 'email' => trim((string) ($v['dest_email'] ?? '')), 'pattern' => trim((string) ($v['dest_pattern'] ?? '')),
        ];
        $schedule = (string) ($v['schedule'] ?? 'manual');
        $row = [
            'name' => trim((string) $v['name']), 'direction' => $direction, 'kind' => $import ? 'products' : (string) $v['kind'], 'enabled' => !empty($v['enabled']) ? 1 : 0,
            'format' => (string) $v['format'], 'columns' => (string) json_encode($columns), 'options' => (string) json_encode($options),
            'filter' => (string) json_encode(['statuses' => array_values(array_map('strval', isset($v['statuses']) && is_array($v['statuses']) ? $v['statuses'] : [])), 'only_new' => $onlyNew]),
            'destination' => (string) $v['destination'], 'dest' => (string) json_encode($dest), 'schedule' => $schedule,
            'schedule_every' => $schedule === 'minutes' ? (int) $v['schedule_every'] : null, 'schedule_at' => $schedule === 'daily' ? (string) $v['schedule_at'] : null,
            'updated_at' => $now,
        ];
        $row['next_run_at'] = $row['enabled'] === 1 ? self::nextRun($this->app, $schedule, $row['schedule_every'], $row['schedule_at'], $this->app->clock()->now()) : null;
        if ($current === null) {
            $row['created_at'] = $now;
            $id = $db->insert('erp_exports', $row);
        } else {
            $id = (int) $current['id'];
            $db->update('erp_exports', $row, ['id' => $id]);
        }
        // «Solo lo nuevo»: el cursor se siembra en «ahora» al crear el perfil (o al encender la opción): nunca recorre lo antiguo.
        $cursors = new Cursors($this->app);
        $wasNew = $current !== null && !empty($current['filter']['only_new']);
        if ($onlyNew && !$wasNew) {
            $cursors->set(Cursors::scope($id), $this->app->service('erp')->sources()->nowCursor((string) $row['kind']));
        } elseif (!$onlyNew) {
            $cursors->forget(Cursors::scope($id));
        }
        foreach (['ftp_password' => 'erp.ftp.', 'post_token' => 'erp.post.'] as $field => $prefix) {
            if (isset($v[$field]) && is_string($v[$field]) && $v[$field] !== '') {
                $this->app->setSecret($prefix . $id, $v[$field]);
            }
        }

        return $id;
    }

    /**
     * Los valores planos de un formulario a partir de un perfil guardado.
     *
     * @param array<string,mixed> $profile
     *
     * @return array<string,mixed>
     */
    public static function toForm(array $profile): array
    {
        $o = $profile['options'];
        $d = $profile['dest_data'];
        $f = isset($profile['filter']) && is_array($profile['filter']) ? $profile['filter'] : [];

        return [
            'name' => $profile['name'], 'kind' => $profile['kind'], 'enabled' => $profile['enabled'] ? 1 : 0, 'format' => $profile['format'], 'columns' => $profile['columns'],
            'csv_delimiter' => $o['delimiter'] ?? ';', 'csv_quote' => $o['quote'] ?? '"', 'csv_encoding' => $o['encoding'] ?? 'utf8', 'csv_decimal' => $o['decimal'] ?? 'dot',
            'csv_date_format' => $o['date_format'] ?? 'iso', 'csv_eol' => $o['eol'] ?? 'lf', 'csv_header' => !isset($o['header']) || !empty($o['header']) ? 1 : 0,
            'csv_safe' => !isset($o['safe_cells']) || !empty($o['safe_cells']) ? 1 : 0, 'json_layout' => $o['layout'] ?? 'list', 'xml_root' => $o['root'] ?? 'items', 'xml_item' => $o['item'] ?? 'item',
            'statuses' => $f['statuses'] ?? [], 'only_new' => !empty($f['only_new']) ? 1 : 0, 'destination' => $profile['destination'],
            'dest_folder' => $d['folder'] ?? '', 'dest_host' => $d['host'] ?? '', 'dest_port' => $d['port'] ?? 21, 'dest_user' => $d['user'] ?? '', 'dest_path' => $d['path'] ?? '',
            'dest_passive' => !isset($d['passive']) || !empty($d['passive']) ? 1 : 0, 'dest_fingerprint' => $d['fingerprint'] ?? '', 'dest_url' => $d['url'] ?? '', 'dest_email' => $d['email'] ?? '', 'dest_pattern' => $d['pattern'] ?? '',
            'schedule' => $profile['schedule'], 'schedule_every' => $profile['schedule_every'], 'schedule_at' => $profile['schedule_at'],
            'col_sku' => $o['sku_col'] ?? '', 'col_stock' => $o['stock_col'] ?? '', 'col_price' => $o['price_col'] ?? '', 'col_sale_price' => $o['sale_price_col'] ?? '',
            'stock_mode' => $o['stock_mode'] ?? 'absolute', 'prices_include_tax' => !empty($o['prices_include_tax']) ? 1 : 0,
        ];
    }

    /**
     * Cuándo toca la próxima pasada, a partir de $from (nunca de una pasada que no corrió: no se recupera lo perdido). null = a mano.
     *
     * @param int|null $every minutos
     */
    public static function nextRun(App $app, string $schedule, ?int $every, ?string $at, int $from): ?string
    {
        switch ($schedule) {
            case 'minutes':
                return $app->clock()->sql($from + max(5, (int) $every) * 60);
            case 'hourly':
                return $app->clock()->sql($from + 3600);
            case 'daily':
                if ($at === null || preg_match('/^(\d{2}):(\d{2})$/', $at, $m) !== 1) {
                    return null;
                }
                $zone = new \DateTimeZone($app->site()->timezone());
                $day = (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime((int) $m[1], (int) $m[2], 0);
                if ($day->getTimestamp() <= $from) {
                    $day = $day->modify('+1 day');
                }

                return $app->clock()->sql($day->getTimestamp());
        }

        return null;
    }

    /**
     * @param array<string,mixed> $v
     *
     * @return array<string,string>
     */
    private function checkDestination(array $v, string $destination, bool $import): array
    {
        $errors = [];
        if (in_array($destination, ['ftp', 'ftps'], true)) {
            if (trim((string) ($v['dest_host'] ?? '')) === '') {
                $errors['dest_host'] = 'Write the address of the server';
            }
            if (trim((string) ($v['dest_user'] ?? '')) === '') {
                $errors['dest_user'] = 'Write the user';
            }
        } elseif ($destination === 'post' || ($import && $destination === 'url')) {
            $problem = $this->app->service('erp')->webhooks()->urlProblem(trim((string) ($v['dest_url'] ?? '')));
            if ($problem !== null) {
                $errors['dest_url'] = $problem;
            }
        } elseif ($destination === 'email' && filter_var(trim((string) ($v['dest_email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) {
            $errors['dest_email'] = 'Write a valid email address';
        } elseif ($destination === 'folder' && trim((string) ($v['dest_folder'] ?? '')) !== '' && preg_match('/^[A-Za-z0-9._-]{1,60}$/', trim((string) $v['dest_folder'])) !== 1) {
            $errors['dest_folder'] = 'Use only letters, numbers, dots, dashes and underscores';
        }

        return $errors;
    }
}
