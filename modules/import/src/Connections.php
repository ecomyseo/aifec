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
 * Los datos de conexión de una migración y la forma de abrir el origen.
 *
 * Nunca van en import_runs ni en import_log: se guardan cifrados con App::setSecret('import.conn.<id>') y se borran
 * al cerrar la migración. Para el modo «db» abre un \PDO de solo lectura (ReadOnlyPdo) con los datos que escribe quien
 * migra; para «api» y «public» entrega el cliente HTTP de la aplicación. Ningún fallo de conexión deja pasar el
 * servidor, el usuario ni la contraseña: los mensajes son fijos.
 */
final class Connections
{
    private const PREFIX = 'import.conn.';

    /** Segundos máximos para conectar con la base de datos de origen. */
    private const CONNECT_TIMEOUT = 8;

    /** @var App */
    private $app;

    /** @var Registry */
    private $registry;

    /** @var callable|null fábrica de conexiones de base de datos (las pruebas la sustituyen) */
    private $pdoFactory;

    public function __construct(App $app, Registry $registry)
    {
        $this->app = $app;
        $this->registry = $registry;
    }

    /** Cambia cómo se abre la base de datos de origen: function (array $db): \PDO. Solo para pruebas. */
    public function setPdoFactory(?callable $factory): void
    {
        $this->pdoFactory = $factory;
    }

    /**
     * Guarda (cifrados) los datos de conexión de una migración.
     *
     * @param array<string,mixed> $config los campos de la ficha del lector, con los secretos en claro
     * @param array<string,mixed> $db     host, port, name, user, password (solo en modo «db»)
     */
    public function save(int $runId, array $config, array $db = []): void
    {
        $json = json_encode(['config' => $config, 'db' => $db], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->app->setSecret(self::PREFIX . $runId, is_string($json) ? $json : '');
    }

    /**
     * @return array{config:array<string,mixed>,db:array<string,mixed>}|null
     */
    public function load(int $runId): ?array
    {
        $plain = $this->app->secret(self::PREFIX . $runId);
        if ($plain === '') {
            return null;
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'config' => isset($data['config']) && is_array($data['config']) ? $data['config'] : [],
            'db' => isset($data['db']) && is_array($data['db']) ? $data['db'] : [],
        ];
    }

    public function has(int $runId): bool
    {
        return $this->app->settings()->has(self::PREFIX . $runId);
    }

    public function delete(int $runId): void
    {
        $this->app->settings()->delete(self::PREFIX . $runId);
    }

    /** Copia los datos de una migración a otra (el simulacro usa los mismos que la migración que prepara). */
    public function copy(int $fromId, int $toId): bool
    {
        $conn = $this->load($fromId);
        if ($conn === null) {
            return false;
        }
        $this->save($toId, $conn['config'], $conn['db']);

        return true;
    }

    /**
     * Los secretos de una migración, para tapar sus textos. Vacío si ya no hay datos guardados.
     *
     * @return string[]
     */
    public function secrets(int $runId): array
    {
        $conn = $this->load($runId);

        return $conn === null ? [] : Redactor::connectionSecrets($conn);
    }

    /**
     * Crea el lector de una migración con sus datos de conexión.
     *
     * @param array<string,mixed> $run fila de import_runs (source, mode e id)
     *
     * @throws SourceError  si no hay lector, no hay datos o no se puede conectar
     * @throws SourceDenied si la base de datos rechaza al usuario
     */
    public function build(array $run): SourceReader
    {
        $card = $this->registry->reader((string) $run['source'], (string) $run['mode']);
        if ($card === null) {
            throw new SourceError('There is no reader for this source');
        }
        $conn = $this->load((int) $run['id']);
        if ($conn === null) {
            throw new SourceError('The connection details of this migration are no longer available');
        }
        $config = $conn['config'];
        $options = isset($run['options']) && is_array($run['options']) ? $run['options'] : [];
        if (!isset($config['shop']) && isset($options['shop']) && is_string($options['shop']) && $options['shop'] !== '') {
            $config['shop'] = $options['shop'];
        }
        $connection = $card['mode'] === 'db' ? $this->openPdo($conn['db']) : $this->app->http();
        $class = (string) $card['class'];

        return new $class($connection, $config);
    }

    /**
     * Abre la copia de la base de datos de origen, de solo lectura.
     *
     * @param array<string,mixed> $db host, port, name, user, password
     *
     * @throws SourceError
     * @throws SourceDenied
     */
    public function openPdo(array $db): \PDO
    {
        if ($this->pdoFactory !== null) {
            return ($this->pdoFactory)($db);
        }
        $host = isset($db['host']) && is_string($db['host']) ? trim($db['host']) : '';
        $name = isset($db['name']) && is_string($db['name']) ? trim($db['name']) : '';
        $user = isset($db['user']) && is_string($db['user']) ? $db['user'] : '';
        $password = isset($db['password']) && is_string($db['password']) ? $db['password'] : '';
        $port = isset($db['port']) ? (int) $db['port'] : 3306;
        if (!self::validHost($host) || !self::validDatabase($name) || $port < 1 || $port > 65535) {
            throw new SourceError('The database connection details are not valid');
        }
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }
        try {
            $pdo = new ReadOnlyPdo('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user, $password, $options);
        } catch (\PDOException $e) {
            // El mensaje del driver trae el servidor y el usuario: no sale. Solo se distingue «no me dejan entrar».
            if (in_array((string) $e->getCode(), ['1044', '1045', '28000'], true) || stripos($e->getMessage(), 'access denied') !== false) {
                throw new SourceDenied('The database refused the user name or the password');
            }

            throw new SourceError('Could not connect to the database');
        }
        $pdo->hardenSession();

        return $pdo;
    }

    /**
     * Valida la dirección de la tienda de origen. Devuelve el motivo (en inglés) o null si vale.
     *
     * Solo https, o http si el anfitrión es de la propia máquina (localhost, loopback, .local, .test). Sin usuario ni
     * contraseña dentro de la dirección, y que no sea este mismo sitio.
     */
    public function validateUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'Enter the full address of the shop, for example https://shop.example';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The address cannot contain a user name or a password';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($scheme !== 'https' && !($scheme === 'http' && self::isLocalHost($host))) {
            return 'Only https addresses are allowed (http only for a local address)';
        }
        $own = parse_url($this->app->site()->url());
        if (is_array($own) && isset($own['host'])) {
            $ownPort = isset($own['port']) ? (int) $own['port'] : (isset($own['scheme']) && strtolower((string) $own['scheme']) === 'http' ? 80 : 443);
            $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'http' ? 80 : 443);
            if (strtolower(trim((string) $own['host'], '[]')) === $host && $ownPort === $port) {
                return 'That address is this same shop';
            }
        }

        return null;
    }

    /**
     * Pasa lo que escribió la persona por la ficha del lector: devuelve los campos limpios y los errores.
     *
     * @param array<string,mixed>      $card     describe() del lector
     * @param array<string,mixed>      $input    lo enviado
     * @param array<string,mixed>|null $existing lo ya guardado (un secreto vacío conserva el guardado)
     *
     * @return array{config:array<string,mixed>,errors:array<string,string>}
     */
    public function normalize(array $card, array $input, ?array $existing = null): array
    {
        $config = [];
        $errors = [];
        $fields = isset($card['fields']) && is_array($card['fields']) ? $card['fields'] : [];
        foreach ($fields as $name => $field) {
            $name = (string) $name;
            $field = is_array($field) ? $field : [];
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $raw = isset($input[$name]) && is_string($input[$name]) ? trim($input[$name]) : '';
            if ($raw === '' && $type === 'secret' && $existing !== null && isset($existing[$name]) && is_string($existing[$name])) {
                $raw = $existing[$name];
            }
            if ($raw === '' && isset($field['default']) && is_scalar($field['default'])) {
                $raw = (string) $field['default'];
            }
            if ($raw === '') {
                if (!empty($field['required'])) {
                    $errors[$name] = 'This field is required';
                }
                continue;
            }
            if ($type === 'url') {
                $problem = $this->validateUrl($raw);
                if ($problem !== null) {
                    $errors[$name] = $problem;
                    continue;
                }
                $raw = rtrim($raw, '/');
            } elseif ($type === 'path') {
                $raw = rtrim(str_replace('\\', '/', $raw), '/');
                if (!is_dir($raw) || !is_readable($raw)) {
                    $errors[$name] = 'That folder does not exist or cannot be read';
                    continue;
                }
            } elseif ($type === 'select') {
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                if (!array_key_exists($raw, $options)) {
                    $errors[$name] = 'Choose one of the options';
                    continue;
                }
            }
            $config[$name] = $raw;
        }

        return ['config' => $config, 'errors' => $errors];
    }

    /**
     * Los datos de la base de datos de una copia, limpios.
     *
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing
     *
     * @return array{db:array<string,mixed>,errors:array<string,string>}
     */
    public function normalizeDb(array $input, ?array $existing = null): array
    {
        $errors = [];
        $get = static function (string $key) use ($input): string {
            return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
        };
        $host = $get('db_host');
        $name = $get('db_name');
        $user = $get('db_user');
        $password = isset($input['db_password']) && is_string($input['db_password']) ? $input['db_password'] : '';
        if ($password === '' && $existing !== null && isset($existing['password']) && is_string($existing['password'])) {
            $password = $existing['password'];
        }
        $port = $get('db_port') === '' ? 3306 : (ctype_digit($get('db_port')) ? (int) $get('db_port') : 0);
        if (!self::validHost($host)) {
            $errors['db_host'] = 'Enter the name or the address of the database server';
        }
        if ($port < 1 || $port > 65535) {
            $errors['db_port'] = 'Enter a port between 1 and 65535';
        }
        if (!self::validDatabase($name)) {
            $errors['db_name'] = 'Enter the name of the database';
        }
        if ($user === '') {
            $errors['db_user'] = 'This field is required';
        }

        return ['db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password], 'errors' => $errors];
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1) {
            return true;
        }

        return preg_match('/\.(localhost|local|test)$/', $host) === 1;
    }

    private static function validHost(string $host): bool
    {
        return $host !== '' && strlen($host) <= 190 && preg_match('/^[A-Za-z0-9._:\-]+$/', $host) === 1;
    }

    private static function validDatabase(string $name): bool
    {
        return $name !== '' && strlen($name) <= 64 && preg_match('/^[A-Za-z0-9_$.\-]+$/', $name) === 1;
    }
}
