<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

use Aifec\Db\Db;

/**
 * Ajustes del sitio: pares clave-valor guardados en la base de datos (tabla settings).
 * Las claves llevan el área delante: «shop.name», «mail.enabled», «theme.active».
 * Los valores son cualquier cosa que se pueda pasar a JSON. Se leen todos de una vez al arrancar.
 *
 * Copia en fichero (`<cache>/settings.php`): una página de la tienda no consulta la base de datos para leer los ajustes,
 * hace un `require` que OPcache deja en memoria. La copia se rehace al guardar cualquier ajuste (leyendo la tabla
 * entera, para no pisar lo que haya guardado otro proceso) y al cambiar la versión del sistema. El panel, la consola
 * y el cron no se fían de ella: leen la tabla y, si la copia no coincide, la reponen (así una copia que se quedó vieja
 * por una carrera dura como mucho hasta el siguiente minuto del cron).
 *
 * La base de datos se abre solo cuando hace falta: el constructor acepta la conexión o una función que la da.
 */
final class Settings
{
    /** @var Db|null */
    private $db;

    /** @var callable|null da la conexión la primera vez que hace falta */
    private $dbFactory;

    /** @var array<string,mixed>|null lo que se ve en esta petición (guardado + lo cambiado solo en memoria) */
    private $values;

    /** @var array<string,mixed>|null lo guardado en la base de datos, tal como sale de ella */
    private $stored;

    /** @var array<string,mixed> valores por defecto declarados por los módulos */
    private $defaults = [];

    /** @var string fichero de la copia; vacío = sin copia */
    private $cacheFile;

    /** @var bool si se puede leer de la copia sin mirar la tabla (una página de la tienda) */
    private $trustCache;

    /** @var bool la próxima lectura va a la tabla (tras reload()) */
    private $forceDb = false;

    /** @var int cuántas claves están cambiadas solo en memoria (override()) */
    private $overrides = 0;

    /** @var bool aplazar la reescritura de la copia (setMany) */
    private $deferWrite = false;

    /**
     * @param Db|callable $db         la conexión o una función que la devuelve (para no abrirla si no hace falta)
     * @param string      $cacheFile  fichero de la copia de los ajustes; vacío = sin copia
     * @param bool        $trustCache true = leer la copia sin mirar la tabla (tienda); false = leer la tabla (panel, consola)
     */
    public function __construct($db, string $cacheFile = '', bool $trustCache = true)
    {
        if ($db instanceof Db) {
            $this->db = $db;
        } elseif (is_callable($db)) {
            $this->dbFactory = $db;
        } else {
            throw new \InvalidArgumentException('Settings needs a Db or a callable that returns one');
        }
        $this->cacheFile = $cacheFile;
        $this->trustCache = $trustCache;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $this->load();
        if (array_key_exists($key, (array) $this->values)) {
            return $this->values[$key];
        }
        if (array_key_exists($key, $this->defaults)) {
            return $this->defaults[$key];
        }

        return $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<mixed>
     */
    public function arr(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->load();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db()->upsert('settings', ['k' => $key, 'v' => $json], ['k']);
        $this->values[$key] = $value;
        $this->stored[$key] = json_decode($json, true);
        $this->rewriteCache();
    }

    /**
     * @param array<string,mixed> $values
     */
    public function setMany(array $values): void
    {
        $this->deferWrite = true;
        try {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value);
            }
        } finally {
            $this->deferWrite = false;
        }
        $this->rewriteCache();
    }

    public function delete(string $key): void
    {
        $this->load();
        $this->db()->delete('settings', ['k' => $key]);
        unset($this->values[$key], $this->stored[$key]);
        $this->rewriteCache();
    }

    public function has(string $key): bool
    {
        $this->load();

        return array_key_exists($key, (array) $this->values);
    }

    /**
     * Ajustes cuya clave empieza por un prefijo: all('mail.').
     *
     * @return array<string,mixed>
     */
    public function all(string $prefix = ''): array
    {
        $this->load();
        $all = (array) $this->values + $this->defaults;
        if ($prefix === '') {
            return $all;
        }
        $out = [];
        foreach ($all as $key => $value) {
            if (strpos((string) $key, $prefix) === 0) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * Valores por defecto de un módulo (los declara en settings.php). No se guardan hasta que alguien los cambia.
     *
     * @param array<string,mixed> $defaults
     */
    public function defaults(array $defaults): void
    {
        $this->defaults = $defaults + $this->defaults;
    }

    /**
     * Todos los valores por defecto declarados (para la caché de arranque).
     *
     * @return array<string,mixed>
     */
    public function declaredDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * Cambia valores SOLO EN MEMORIA, para lo que queda de esta petición: no escribe nada en la base de datos.
     * Es para las vistas previas (un tema o unos ajustes sin publicar) y para las comprobaciones que montan un sitio de
     * prueba. Devuelve lo que había, para deshacerlo con restore(). Un set() posterior de la misma clave sí guarda.
     *
     * @param array<string,mixed> $values clave => valor
     *
     * @return array<string,array{0:bool,1:mixed}> clave => [existía, valor anterior]
     */
    public function override(array $values): array
    {
        $this->load();
        $saved = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $existed = array_key_exists($key, (array) $this->values);
            $saved[$key] = [$existed, $existed ? $this->values[$key] : null];
            $this->values[$key] = $value;
        }
        if ($values !== []) {
            ++$this->overrides;
        }

        return $saved;
    }

    /**
     * Deshace un override(). Si los ajustes se han vuelto a leer de la base de datos mientras tanto (reload()), ya valen
     * los guardados y no hay nada que deshacer.
     *
     * @param array<string,array{0:bool,1:mixed}> $saved lo que devolvió override()
     */
    public function restore(array $saved): void
    {
        if ($this->values === null) {
            return;
        }
        foreach ($saved as $key => $was) {
            if (is_array($was) && !empty($was[0])) {
                $this->values[(string) $key] = $was[1];
            } else {
                unset($this->values[(string) $key]);
            }
        }
        if ($saved !== [] && $this->overrides > 0) {
            --$this->overrides;
        }
    }

    /**
     * ¿Hay algo cambiado solo en memoria (una vista previa)? Mientras lo haya, lo que se calcule con estos ajustes no se
     * guarda en las cachés compartidas por todas las peticiones.
     */
    public function overridden(): bool
    {
        return $this->overrides > 0;
    }

    /** Vuelve a leer de la base de datos (pruebas y tareas largas). */
    public function reload(): void
    {
        $this->values = null;
        $this->stored = null;
        $this->overrides = 0;
        $this->forceDb = true;
    }

    /** Si ya se ha leído (de la copia o de la tabla). */
    public function loaded(): bool
    {
        return $this->values !== null;
    }

    private function db(): Db
    {
        if ($this->db === null) {
            $db = ($this->dbFactory)();
            if (!$db instanceof Db) {
                throw new \LogicException('The settings database factory did not return a Db');
            }
            $this->db = $db;
        }

        return $this->db;
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }
        $useFile = $this->cacheFile !== '' && $this->trustCache && !$this->forceDb;
        if ($useFile) {
            $copy = Cache::import($this->cacheFile);
            if (is_array($copy) && isset($copy['version'], $copy['values']) && $copy['version'] === App::VERSION && is_array($copy['values'])) {
                $this->stored = $copy['values'];
                $this->values = $copy['values'];

                return;
            }
        }
        $this->forceDb = false;
        $this->stored = $this->readTable();
        $this->values = $this->stored;
        if ($this->cacheFile === '') {
            return;
        }
        // Se repone la copia si falta, está dañada o no coincide con la tabla (sin escribir si ya es igual).
        if (!$useFile) {
            $copy = Cache::import($this->cacheFile);
            if (is_array($copy) && isset($copy['version'], $copy['values']) && $copy['version'] === App::VERSION && $copy['values'] === $this->stored) {
                return;
            }
        }
        Cache::export($this->cacheFile, ['version' => App::VERSION, 'values' => $this->stored]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readTable(): array
    {
        $values = [];
        foreach ($this->db()->select('SELECT k, v FROM {settings}') as $row) {
            $values[(string) $row['k']] = json_decode((string) $row['v'], true);
        }

        return $values;
    }

    /** Rehace la copia leyendo la tabla entera: otro proceso puede haber guardado otras claves mientras tanto. */
    private function rewriteCache(): void
    {
        if ($this->cacheFile === '' || $this->deferWrite) {
            return;
        }
        $fresh = $this->readTable();
        if (!Cache::export($this->cacheFile, ['version' => App::VERSION, 'values' => $fresh])) {
            // Sin poder escribir la copia nueva, la vieja no puede quedarse: mentiría.
            @unlink($this->cacheFile);
        }
    }
}
