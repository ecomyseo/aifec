<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Jobs;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Security\Random;

/**
 * Cola de trabajos en segundo plano y tareas periódicas (servicio «jobs»).
 *
 * Cualquier módulo declara en su jobs.php:
 *
 *   return [
 *       'handlers' => ['media.variants' => [MediaJobs::class, 'variants']],              // (array $payload, App $app): mixed
 *       'cron'     => ['mail.send' => ['every' => 60, 'run' => [MailTasks::class, 'send'], 'description' => '…']],  // (App $app): ?string
 *   ];
 *
 * y encola con $app->service('jobs')->push('media.variants', ['id' => 5]).
 *
 * Reglas que evitan los fallos caros:
 *  - reclamar un trabajo es UNA sentencia condicionada: dos procesos a la vez no pueden ejecutar el mismo;
 *  - todo trabajo acaba en «done» o en «failed» con su motivo (cualquier \Throwable se recoge);
 *  - lo que se quedó «running» sin nadie detrás se cierra solo a los 10 minutos;
 *  - una tarea periódica se reclama con una sentencia condicionada sobre cron_runs: sin doble ejecución;
 *  - nunca recorre tablas históricas: solo toca trabajos encolados.
 *
 * Los manejadores y las tareas de los módulos son métodos estáticos o funciones; también se pueden registrar a mano
 * con register() y schedule() (las pruebas lo hacen).
 */
final class Queue
{
    /** Segundos que un trabajo puede estar «running» antes de darlo por abandonado. */
    public const RECLAIM_AFTER = 600;

    /** Tope de caracteres que se guardan del resultado y del error. */
    private const TEXT_LIMIT = 2000;

    /** Nombre de la fila de cron_runs donde cron() apunta su propia marca. */
    private const HEARTBEAT = '_heartbeat';

    /** @var App */
    private $app;

    /** @var array<string,callable> */
    private $handlers = [];

    /** @var array<string,array<string,mixed>> */
    private $scheduled = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ registro a mano

    /** Registra un manejador (además de los que declaran los módulos en su jobs.php). */
    public function register(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Registra una tarea periódica (además de las de los módulos).
     *
     * @param int|string $every segundos (entero >= 30), 'hourly' o 'daily@HH:MM' (hora del sitio)
     */
    public function schedule(string $task, $every, callable $run, string $description = ''): void
    {
        $task = trim($task);
        if ($task === '' || strlen($task) > 80 || $task[0] === '_') {
            throw new \InvalidArgumentException('Invalid task name');
        }
        $this->scheduled[$task] = [
            'every' => (string) $every,
            'spec' => self::parseEvery($every),
            'run' => $run,
            'description' => $description,
        ];
    }

    // ------------------------------------------------------------------ cola

    /**
     * Encola un trabajo y devuelve su id.
     *
     * $options: delay (segundos), unique (cadena: si ya hay uno pendiente o en curso con esa clave, devuelve su id y
     * no crea otro), max_attempts (3 por defecto), queue ('default').
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function push(string $type, array $payload = [], array $options = []): int
    {
        $type = trim($type);
        if ($type === '' || strlen($type) > 80) {
            throw new \InvalidArgumentException('Invalid job type');
        }
        $db = $this->app->db();
        $unique = null;
        if (isset($options['unique']) && is_string($options['unique']) && $options['unique'] !== '') {
            $unique = self::fit($options['unique'], 120);
            $existing = (int) $db->value("SELECT MIN(id) FROM {jobs} WHERE unique_key = ? AND status IN ('pending', 'running')", [$unique]);
            if ($existing > 0) {
                return $existing;
            }
        }
        $now = $this->app->clock()->now();
        $delay = isset($options['delay']) ? max(0, (int) $options['delay']) : 0;
        $queue = isset($options['queue']) && is_string($options['queue']) && $options['queue'] !== '' ? self::fit($options['queue'], 32) : 'default';
        $id = $db->insert('jobs', [
            'queue' => $queue,
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => isset($options['max_attempts']) ? max(1, (int) $options['max_attempts']) : 3,
            'run_at' => $this->app->clock()->sql($now + $delay),
            'unique_key' => $unique,
            'created_at' => $this->app->clock()->sql($now),
        ]);
        if ($unique !== null) {
            // Dos pushes a la vez pueden haber entrado los dos: se queda el de menor id y el otro se retira.
            $first = (int) $db->value("SELECT MIN(id) FROM {jobs} WHERE unique_key = ? AND status IN ('pending', 'running')", [$unique]);
            if ($first > 0 && $first !== $id) {
                $db->delete('jobs', ['id' => $id]);

                return $first;
            }
        }

        return $id;
    }

    /**
     * Trabaja como mucho $seconds segundos (reloj real) o $max trabajos. No empieza otro si ya gastó su tiempo.
     *
     * @return array{done:int,failed:int,retried:int,reclaimed:int}
     */
    public function work(int $seconds = 20, int $max = 50, ?string $queue = null): array
    {
        $started = microtime(true);
        $db = $this->app->db();
        $out = ['done' => 0, 'failed' => 0, 'retried' => 0, 'reclaimed' => $this->reclaim()];
        for ($n = 0, $limit = max(0, $max); $n < $limit; ++$n) {
            if ((microtime(true) - $started) >= $seconds) {
                break;
            }
            $sql = "SELECT id FROM {jobs} WHERE status = 'pending' AND run_at <= ?";
            $params = [$this->app->clock()->sql()];
            if ($queue !== null && $queue !== '') {
                $sql .= ' AND queue = ?';
                $params[] = $queue;
            }
            $id = $db->value($sql . ' ORDER BY run_at, id LIMIT 1', $params);
            if ($id === null) {
                break;
            }
            if (!$this->claim((int) $id)) {
                continue; // otro proceso lo tomó entre medias
            }
            try {
                $outcome = $this->execute((int) $id);
            } catch (\Throwable $e) {
                // Ni siquiera se pudo apuntar el resultado: se queda «running» y a los 10 minutos se recupera.
                $this->app->log()->exception($e, 'jobs:finish');
                $outcome = '';
            }
            if (isset($out[$outcome])) {
                ++$out[$outcome];
            }
        }

        return $out;
    }

    /**
     * Reclama un trabajo pendiente: una sola sentencia condicionada. true = es nuestro; false = ya lo tiene otro.
     */
    public function claim(int $id): bool
    {
        return $this->app->db()->run(
            "UPDATE {jobs} SET status = 'running', locked_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'",
            [$this->app->clock()->sql(), $id]
        ) === 1;
    }

    /** Un trabajo fallido vuelve a pendiente con los intentos a cero. */
    public function retry(int $id): bool
    {
        return $this->app->db()->run(
            "UPDATE {jobs} SET status = 'pending', attempts = 0, run_at = ?, locked_at = NULL, finished_at = NULL WHERE id = ? AND status = 'failed'",
            [$this->app->clock()->sql(), $id]
        ) === 1;
    }

    // ------------------------------------------------------------------ tareas periódicas

    /**
     * Ejecuta las tareas periódicas que tocan. Una que lanza no impide las demás.
     *
     * @return array{ran:string[],failed:array<string,string>,skipped:int}
     */
    public function cron(): array
    {
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $db->insertIgnore('cron_runs', ['task' => self::HEARTBEAT, 'runs' => 0]);
        $db->run('UPDATE {cron_runs} SET last_run_at = ?, runs = runs + 1 WHERE task = ?', [$this->app->clock()->sql($now), self::HEARTBEAT]);

        $known = [];
        foreach ($db->select('SELECT task, last_run_at FROM {cron_runs}') as $row) {
            $known[(string) $row['task']] = (string) $row['last_run_at'];
        }
        $out = ['ran' => [], 'failed' => [], 'skipped' => 0];
        foreach ($this->taskMap() as $name => $task) {
            $limit = self::threshold($task['spec'], $now, $this->app->site()->timezone());
            if ($limit === null || (isset($known[$name]) && $known[$name] !== '' && Clock::parse($known[$name]) > $limit)) {
                ++$out['skipped'];
                continue;
            }
            $db->insertIgnore('cron_runs', ['task' => $name, 'runs' => 0]);
            $claimed = $db->run(
                'UPDATE {cron_runs} SET last_run_at = ? WHERE task = ? AND (last_run_at IS NULL OR last_run_at <= ?)',
                [$this->app->clock()->sql($now), $name, $this->app->clock()->sql($limit)]
            ) === 1;
            if (!$claimed) {
                ++$out['skipped']; // otro proceso la acaba de reclamar
                continue;
            }
            try {
                $this->execTask($name, $task);
                $out['ran'][] = $name;
            } catch (\Throwable $e) {
                $out['failed'][$name] = self::clip(self::message($e));
            }
        }

        return $out;
    }

    /**
     * «Ejecutar ahora»: corre la tarea sin esperar a su hora y apunta el resultado. Devuelve su nota o lanza.
     */
    public function runTask(string $task): ?string
    {
        $map = $this->taskMap();
        if (!isset($map[$task])) {
            throw new \InvalidArgumentException('Unknown task: ' . $task);
        }
        $db = $this->app->db();
        $db->insertIgnore('cron_runs', ['task' => $task, 'runs' => 0]);
        $db->run('UPDATE {cron_runs} SET last_run_at = ? WHERE task = ?', [$this->app->clock()->sql(), $task]);

        return $this->execTask($task, $map[$task]);
    }

    /**
     * Todas las tareas periódicas con su último resultado, para el panel.
     *
     * @return array<int,array<string,mixed>> task, description, every, last_run_at, last_ok_at, last_error, runs
     */
    public function tasks(): array
    {
        $rows = [];
        foreach ($this->app->db()->select('SELECT task, last_run_at, last_ok_at, last_error, runs FROM {cron_runs}') as $row) {
            $rows[(string) $row['task']] = $row;
        }
        $out = [];
        foreach ($this->taskMap() as $name => $task) {
            $row = isset($rows[$name]) ? $rows[$name] : [];
            $out[] = [
                'task' => $name,
                'description' => (string) $task['description'],
                'every' => (string) $task['every'],
                'last_run_at' => isset($row['last_run_at']) ? $row['last_run_at'] : null,
                'last_ok_at' => isset($row['last_ok_at']) ? $row['last_ok_at'] : null,
                'last_error' => isset($row['last_error']) ? $row['last_error'] : null,
                'runs' => isset($row['runs']) ? (int) $row['runs'] : 0,
            ];
        }

        return $out;
    }

    /** Marca de tiempo de la última vez que corrió cron(), o null si nunca. */
    public function lastRun(): ?int
    {
        $value = $this->app->db()->value('SELECT last_run_at FROM {cron_runs} WHERE task = ?', [self::HEARTBEAT]);
        $time = is_string($value) ? Clock::parse($value) : 0;

        return $time > 0 ? $time : null;
    }

    /**
     * Puerta con tiempo mínimo entre pasos (la usa la dirección web de cron): true = puede pasar y queda apuntado.
     * El nombre empieza por «_» para que no salga entre las tareas.
     */
    public function gate(string $name, int $seconds): bool
    {
        $db = $this->app->db();
        $db->insertIgnore('cron_runs', ['task' => $name, 'runs' => 0]);
        $now = $this->app->clock()->now();

        return $db->run(
            'UPDATE {cron_runs} SET last_run_at = ? WHERE task = ? AND (last_run_at IS NULL OR last_run_at <= ?)',
            [$this->app->clock()->sql($now), $name, $this->app->clock()->sql($now - max(0, $seconds))]
        ) === 1;
    }

    /** Clave de la dirección web de cron. Se genera la primera vez que hace falta y se guarda. */
    public function webKey(): string
    {
        $key = $this->app->settings()->str('jobs.web_key');
        if ($key === '') {
            $key = Random::token(24);
            $this->app->settings()->set('jobs.web_key', $key);
        }

        return $key;
    }

    // ------------------------------------------------------------------ interior

    /** Vuelve a pendiente (o cierra) lo que lleva más de 10 minutos «running». Devuelve cuántos. */
    private function reclaim(): int
    {
        $db = $this->app->db();
        $clock = $this->app->clock();
        $cut = $clock->sql($clock->now() - self::RECLAIM_AFTER);
        $count = 0;
        $rows = $db->select("SELECT id, attempts, max_attempts FROM {jobs} WHERE status = 'running' AND locked_at < ?", [$cut]);
        foreach ($rows as $row) {
            if ((int) $row['attempts'] < (int) $row['max_attempts']) {
                $changed = $db->run(
                    "UPDATE {jobs} SET status = 'pending', locked_at = NULL, run_at = ?, error = ? WHERE id = ? AND status = 'running' AND locked_at < ?",
                    [$clock->sql(), 'Timed out', (int) $row['id'], $cut]
                );
            } else {
                $changed = $db->run(
                    "UPDATE {jobs} SET status = 'failed', locked_at = NULL, finished_at = ?, error = ? WHERE id = ? AND status = 'running' AND locked_at < ?",
                    [$clock->sql(), 'Timed out', (int) $row['id'], $cut]
                );
            }
            $count += $changed === 1 ? 1 : 0;
        }

        return $count;
    }

    /**
     * Ejecuta un trabajo ya reclamado y lo deja en «done», «pending» (reintento) o «failed».
     *
     * @return string done, failed o retried
     */
    private function execute(int $id): string
    {
        $db = $this->app->db();
        $clock = $this->app->clock();
        $job = $db->row('SELECT id, type, payload, attempts, max_attempts FROM {jobs} WHERE id = ?', [$id]);
        if ($job === null) {
            return '';
        }
        $attempts = (int) $job['attempts'];
        $failed = false;
        $permanent = false;
        $message = '';
        $result = null;
        try {
            $handler = $this->handler((string) $job['type']);
            if ($handler === null) {
                throw new PermanentFailure('No handler for ' . $job['type']);
            }
            $payload = json_decode((string) $job['payload'], true);
            $result = $handler(is_array($payload) ? $payload : [], $this->app);
        } catch (PermanentFailure $e) {
            $failed = true;
            $permanent = true;
            $message = self::message($e);
        } catch (\Throwable $e) {
            $failed = true;
            $message = self::message($e);
        }

        if (!$failed) {
            $db->update('jobs', [
                'status' => 'done',
                'finished_at' => $clock->sql(),
                'locked_at' => null,
                'result' => self::resultText($result),
                'error' => null,
            ], ['id' => $id]);

            return 'done';
        }
        $this->app->log()->warning('Job failed', ['job' => $id, 'type' => (string) $job['type'], 'attempt' => $attempts, 'error' => $message]);
        if (!$permanent && $attempts < max(1, (int) $job['max_attempts'])) {
            $db->update('jobs', [
                'status' => 'pending',
                'run_at' => $clock->sql($clock->now() + 60 * $attempts * $attempts),
                'locked_at' => null,
                'error' => self::clip($message),
            ], ['id' => $id]);

            return 'retried';
        }
        $db->update('jobs', [
            'status' => 'failed',
            'finished_at' => $clock->sql(),
            'locked_at' => null,
            'error' => self::clip($message),
        ], ['id' => $id]);

        return 'failed';
    }

    /**
     * Ejecuta una tarea ya reclamada y apunta cómo acabó. Lanza de nuevo lo que lance la tarea.
     *
     * @param array<string,mixed> $task
     */
    private function execTask(string $name, array $task): ?string
    {
        $db = $this->app->db();
        try {
            $note = $task['run']($this->app);
        } catch (\Throwable $e) {
            $db->run('UPDATE {cron_runs} SET last_error = ?, runs = runs + 1 WHERE task = ?', [self::clip(self::message($e)), $name]);
            $this->app->log()->warning('Scheduled task failed', ['task' => $name, 'error' => self::message($e)]);

            throw $e;
        }
        $db->run('UPDATE {cron_runs} SET last_ok_at = ?, last_error = NULL, runs = runs + 1 WHERE task = ?', [$this->app->clock()->sql(), $name]);

        return is_string($note) && $note !== '' ? $note : null;
    }

    /** @return callable|null */
    private function handler(string $type)
    {
        if (isset($this->handlers[$type])) {
            return $this->handlers[$type];
        }
        foreach ($this->app->modules()->definitions('jobs') as $definition) {
            if (isset($definition['handlers'][$type]) && is_callable($definition['handlers'][$type])) {
                return $definition['handlers'][$type];
            }
        }

        return null;
    }

    /**
     * Las tareas periódicas de los módulos y las registradas a mano (estas mandan), por nombre.
     *
     * @return array<string,array<string,mixed>>
     */
    private function taskMap(): array
    {
        $map = [];
        foreach ($this->app->modules()->definitions('jobs') as $module => $definition) {
            $cron = isset($definition['cron']) && is_array($definition['cron']) ? $definition['cron'] : [];
            foreach ($cron as $name => $task) {
                if (!is_array($task) || !isset($task['every'], $task['run']) || !is_callable($task['run'])) {
                    $this->app->log()->warning('Invalid scheduled task', ['module' => (string) $module, 'task' => (string) $name]);
                    continue;
                }
                try {
                    $spec = self::parseEvery($task['every']);
                } catch (\InvalidArgumentException $e) {
                    $this->app->log()->warning('Invalid scheduled task', ['module' => (string) $module, 'task' => (string) $name, 'error' => $e->getMessage()]);
                    continue;
                }
                $map[(string) $name] = [
                    'every' => (string) $task['every'],
                    'spec' => $spec,
                    'run' => $task['run'],
                    'description' => isset($task['description']) ? (string) $task['description'] : '',
                ];
            }
        }
        foreach ($this->scheduled as $name => $task) {
            $map[$name] = $task;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param mixed $every
     *
     * @return array{kind:string,seconds:int,hour:int,minute:int}
     */
    public static function parseEvery($every): array
    {
        if (is_int($every) || (is_string($every) && ctype_digit($every))) {
            if ((int) $every < 30) {
                throw new \InvalidArgumentException('A task cannot run more often than every 30 seconds');
            }

            return ['kind' => 'seconds', 'seconds' => (int) $every, 'hour' => 0, 'minute' => 0];
        }
        if ($every === 'hourly') {
            return ['kind' => 'hourly', 'seconds' => 3600, 'hour' => 0, 'minute' => 0];
        }
        if (is_string($every) && preg_match('/^daily@([01]\d|2[0-3]):([0-5]\d)$/', $every, $m) === 1) {
            return ['kind' => 'daily', 'seconds' => 86400, 'hour' => (int) $m[1], 'minute' => (int) $m[2]];
        }

        throw new \InvalidArgumentException('Invalid schedule: use seconds (30 or more), "hourly" or "daily@HH:MM"');
    }

    /**
     * Última marca de ejecución con la que la tarea ya toca (inclusive), o null si hoy aún no es su hora.
     *
     * @param array{kind:string,seconds:int,hour:int,minute:int} $spec
     */
    private static function threshold(array $spec, int $now, string $timezone): ?int
    {
        if ($spec['kind'] !== 'daily') {
            return $now - $spec['seconds'];
        }
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $zone = new \DateTimeZone('UTC');
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        $at = $local->setTime($spec['hour'], $spec['minute'], 0)->getTimestamp();

        return $now >= $at ? $at - 1 : null;
    }

    /** @param mixed $result */
    private static function resultText($result): ?string
    {
        if ($result === null) {
            return null;
        }
        if (is_string($result)) {
            return self::clip($result);
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? self::clip($json) : null;
    }

    private static function message(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : get_class($e);
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > self::TEXT_LIMIT ? mb_substr($text, 0, self::TEXT_LIMIT) : $text;
    }

    /** Cabe en una columna de $max caracteres: si no, se recorta y se le añade un resumen para que siga siendo única. */
    private static function fit(string $text, int $max): string
    {
        return strlen($text) <= $max ? $text : mb_strcut($text, 0, $max - 33, 'UTF-8') . ':' . md5($text);
    }
}
