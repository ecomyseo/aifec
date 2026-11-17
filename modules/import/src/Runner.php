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
 * El motor de la migración: trae una tienda entera por tandas, sin duplicar, sin atascarse y sin avisar a nadie.
 *
 * Fases: write (tipo a tipo, en Types::ORDER) → link (segunda pasada de los tipos enlazados) → finish (lo que hay que hacer
 * después de escribir: contadores, buscador, cachés y feeds) → verify (sumas de control y comprobación con muestras, del origen
 * contra lo de aquí) → done. Un simulacro (dry_run) hace la fase write con escritores que no escriben y se cierra sin pasar por
 * link, finish ni verify.
 *
 * Cada llamada a step() es una tanda: como mucho 50 registros o 20 segundos (lo que llegue antes), leídos de 10 en 10
 * para poder cortar a tiempo. El cursor guarda el «next» del último trozo ya procesado entero: avanza por lo LEÍDO del
 * origen (los descartados cuentan), y el final lo dice el origen con «next» a null, nunca una tanda corta.
 *
 * Toda tanda corre con App::silence(true) dentro de un try/finally: el correo ni se compone. Tres fallos seguidos del
 * origen pausan la migración; un «acceso denegado» la pausa en el acto. Solo puede haber una migración en curso por
 * sitio. Toda migración acaba cerrada: done, failed o cancelled.
 */
final class Runner
{
    /** Registros por tanda (un paso del trabajo import.step). */
    public const STEP_RECORDS = 50;

    /** Segundos por tanda. */
    public const STEP_SECONDS = 20;

    /** Registros que se piden al origen de una vez dentro de una tanda. */
    public const CHUNK = 10;

    /** Fallos seguidos del origen que pausan la migración. */
    public const MAX_SOURCE_ERRORS = 3;

    /** Minutos «en curso» sin latido tras los cuales el barrido da una migración por fallida. */
    public const STALE_MINUTES = 60;

    /** Horas que un borrador puede esperar antes de que el barrido lo cancele. */
    public const DRAFT_HOURS = 24;

    /** @var App */
    private $app;

    /** @var Runs */
    private $runs;

    /** @var Registry */
    private $registry;

    /** @var Connections */
    private $connections;

    /** @var PriceCheck|null los importes con impuestos del origen, para la comprobación final */
    private $prices;

    /** @var callable function (): float, segundos con decimales; las pruebas lo sustituyen */
    private $monotonic;

    public function __construct(App $app, Runs $runs, Registry $registry, Connections $connections)
    {
        $this->app = $app;
        $this->runs = $runs;
        $this->registry = $registry;
        $this->connections = $connections;
        $this->monotonic = static function (): float {
            return microtime(true);
        };
    }

    /** Cambia el reloj con el que se corta una tanda por tiempo. Solo para pruebas. */
    public function setMonotonic(callable $monotonic): void
    {
        $this->monotonic = $monotonic;
    }

    // ------------------------------------------------------------------ arranque, pausa, reanudación, cancelación

    /**
     * Pone en marcha una migración en borrador.
     *
     * @param bool $enqueue false = no encola el primer paso (la consola lo ejecuta ella misma)
     *
     * @return array{ok:bool,reason:string} reason, en inglés
     */
    public function start(int $id, bool $enqueue = true): array
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return ['ok' => false, 'reason' => 'Unknown migration'];
        }
        if ($run['status'] !== 'draft') {
            return ['ok' => false, 'reason' => 'This migration was already started'];
        }
        $open = $this->runs->open();
        if ($open !== null && (int) $open['id'] !== $id) {
            return ['ok' => false, 'reason' => 'Another migration is in progress'];
        }
        try {
            $reader = $this->connections->build($run);
        } catch (SourceError $e) {
            return ['ok' => false, 'reason' => Redactor::scrub($e->getMessage(), $this->connections->secrets($id), 191)];
        }
        $skipped = [];
        $plan = $this->plan($run, $reader, $skipped);
        if ($plan === []) {
            return ['ok' => false, 'reason' => 'There is nothing to bring: choose at least one type that can be written'];
        }
        $now = $this->app->clock()->sql();
        $state = [
            'plan' => $plan, 'pos' => 0, 'read' => '', 'link_pos' => 0, 'link_read' => '', 'totals' => [], 'skipped_types' => $skipped,
            'finish' => ['task' => 0, 'after' => 0, 'types' => null], 'ev' => Verify::blank(),
        ];
        $won = $this->runs->update($id, [
            'options' => $this->startOptions($run), 'source_key' => substr($reader->key(), 0, 60), 'status' => 'running', 'phase' => 'write', 'current_type' => $plan[0], 'cursor' => $state, 'counters' => [],
            'unmapped' => [], 'checksums' => [], 'pause_reason' => null, 'source_errors' => 0, 'started_at' => $now,
            'heartbeat_at' => $now, 'finished_at' => null,
        ], 'draft');
        if ($won !== 1) {
            return ['ok' => false, 'reason' => 'This migration was already started'];
        }
        // Dos a la vez: gana la de menor identificador y la otra vuelve a borrador.
        if ((int) $this->app->db()->value("SELECT MIN(id) FROM {import_runs} WHERE status IN ('running', 'paused')") !== $id) {
            $this->runs->update($id, ['status' => 'draft', 'started_at' => null, 'heartbeat_at' => null], 'running');

            return ['ok' => false, 'reason' => 'Another migration is in progress'];
        }
        if ($enqueue) {
            $this->enqueue($id);
        }

        return ['ok' => true, 'reason' => ''];
    }

    /** Pausa una migración en curso. $reason va tal cual (en inglés) a pause_reason. */
    public function pause(int $id, string $reason = 'Paused by a person'): bool
    {
        return $this->runs->update($id, ['status' => 'paused', 'pause_reason' => mb_substr($reason, 0, 191)], 'running') === 1;
    }

    /** Reanuda una migración en pausa: el contador de fallos vuelve a cero. */
    public function resume(int $id, bool $enqueue = true): bool
    {
        $changed = $this->runs->update($id, [
            'status' => 'running', 'pause_reason' => null, 'source_errors' => 0, 'heartbeat_at' => $this->app->clock()->sql(),
        ], 'paused') === 1;
        if ($changed && $enqueue) {
            $this->enqueue($id);
        }

        return $changed;
    }

    /** Cancela una migración que no ha terminado: queda cerrada y se borran sus datos de conexión. */
    public function cancel(int $id): bool
    {
        $changed = $this->runs->update($id, [
            'status' => 'cancelled', 'current_type' => null, 'finished_at' => $this->app->clock()->sql(),
        ], ['draft', 'running', 'paused']) === 1;
        if ($changed) {
            $this->connections->delete($id);
            $this->app->hooks()->fire('import.finished', $id, 'cancelled');
        }

        return $changed;
    }

    /** Encola el siguiente paso de una migración. */
    public function enqueue(int $id, int $delay = 0): void
    {
        $options = ['queue' => 'import'];
        if ($delay > 0) {
            $options['delay'] = $delay;
        }
        $this->app->service('jobs')->push('import.step', ['run' => $id], $options);
    }

    /**
     * El barrido: cierra como «failed» la migración que lleva 60 minutos «en curso» sin latido y cancela los borradores
     * abandonados. Devuelve cuántas cerró.
     */
    public function sweep(): int
    {
        $clock = $this->app->clock();
        $closed = 0;
        $stale = $clock->sql($clock->now() - self::STALE_MINUTES * 60);
        $rows = $this->app->db()->select("SELECT id FROM {import_runs} WHERE status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < ?)", [$stale]);
        foreach ($rows as $row) {
            if ($this->fail((int) $row['id'], 'No activity for ' . self::STALE_MINUTES . ' minutes', [])) {
                ++$closed;
            }
        }
        $old = $clock->sql($clock->now() - self::DRAFT_HOURS * 3600);
        $rows = $this->app->db()->select("SELECT id FROM {import_runs} WHERE status = 'draft' AND updated_at < ?", [$old]);
        foreach ($rows as $row) {
            if ($this->cancel((int) $row['id'])) {
                ++$closed;
            }
        }

        return $closed;
    }

    // ------------------------------------------------------------------ una tanda

    /**
     * Una tanda de trabajo. Devuelve cómo acabó:
     *   continue  hay más que hacer ahora        retry   el origen falló, se reintenta más tarde
     *   paused    se pausó (a la tercera o por un acceso denegado)
     *   done, failed, cancelled   la migración está cerrada
     *   idle      no estaba en curso: no se hizo nada
     */
    public function step(int $id): string
    {
        $run = $this->runs->find($id);
        if ($run === null || $run['status'] !== 'running') {
            return 'idle';
        }
        $before = $this->app->silence(true);
        try {
            return $this->guarded($run);
        } finally {
            $this->app->silence($before);
        }
    }

    /**
     * @param array<string,mixed> $run
     */
    private function guarded(array $run): string
    {
        $id = (int) $run['id'];
        $conn = $this->connections->load($id);
        $secrets = $conn !== null ? Redactor::connectionSecrets($conn) : [];
        try {
            $reader = $this->connections->build($run);
            $filesDir = $conn !== null && isset($conn['config']['files']) && is_string($conn['config']['files']) ? $conn['config']['files'] : '';
            $ctx = new Context($this->app, $this->runs, $this->withHiddenOptions($run, $conn), $secrets, $filesDir);

            return $this->work($run, $reader, $ctx, $secrets);
        } catch (SourceDenied $e) {
            $this->pause($id, Redactor::scrub($e->getMessage(), $secrets, 191));

            return 'paused';
        } catch (SourceError $e) {
            return $this->sourceFailed($id, Redactor::scrub($e->getMessage(), $secrets, 150));
        } catch (\Throwable $e) {
            $this->app->log()->exception(new \RuntimeException(Redactor::scrub(get_class($e) . ': ' . $e->getMessage(), $secrets, 500)), 'import.step');
            $this->fail($id, Redactor::scrub(get_class($e) . ': ' . $e->getMessage(), $secrets, 191), $secrets);

            return 'failed';
        }
    }

    /**
     * Un fallo del origen: se cuenta y se reintenta; al tercero seguido, pausa con el motivo.
     */
    private function sourceFailed(int $id, string $message): string
    {
        $run = $this->runs->find($id);
        if ($run === null || $run['status'] !== 'running') {
            return 'idle';
        }
        $errors = (int) $run['source_errors'] + 1;
        if ($errors >= self::MAX_SOURCE_ERRORS) {
            $this->runs->update($id, ['source_errors' => $errors], 'running');
            $this->pause($id, 'The source failed ' . $errors . ' times in a row: ' . $message);

            return 'paused';
        }
        $this->runs->update($id, ['source_errors' => $errors, 'heartbeat_at' => $this->app->clock()->sql()], 'running');

        return 'retry';
    }

    /**
     * El trabajo de la tanda.
     *
     * @param array<string,mixed> $run
     * @param string[]            $secrets
     */
    private function work(array $run, SourceReader $reader, Context $ctx, array $secrets): string
    {
        $id = (int) $run['id'];
        $started = $this->now();
        $maxRecords = max(1, min(500, $this->app->settings()->int('import.step_records', self::STEP_RECORDS)));
        $maxSeconds = max(1, min(300, $this->app->settings()->int('import.step_seconds', self::STEP_SECONDS)));
        $dry = (int) $run['dry_run'] === 1;
        $options = $run['options'];
        $since = isset($options['since']) && is_string($options['since']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $options['since']) === 1 ? substr($options['since'], 0, 10) : '';

        $state = $run['cursor'] + [
            'plan' => [], 'pos' => 0, 'read' => '', 'link_pos' => 0, 'link_read' => '', 'totals' => [], 'skipped_types' => [],
            'finish' => ['task' => 0, 'after' => 0, 'types' => null], 'ev' => Verify::blank(),
        ];
        $counters = $run['counters'];
        $unmapped = $run['unmapped'] + ['reader' => [], 'writers' => []];
        $phase = (string) $run['phase'];
        $consumed = 0;

        while (true) {
            // Otra persona pudo pausar o cancelar mientras se trabajaba.
            if ((string) $this->app->db()->value('SELECT status FROM {import_runs} WHERE id = ?', [$id]) !== 'running') {
                return 'idle';
            }
            $type = null;
            if ($phase === 'write') {
                $type = isset($state['plan'][$state['pos']]) ? (string) $state['plan'][$state['pos']] : null;
                if ($type === null) {
                    $phase = $dry ? 'verify' : 'link';
                    $state['link_pos'] = 0;
                    $state['link_read'] = '';
                } else {
                    $consumed += $this->chunk($id, $type, $reader, $ctx, $state, 'read', 'pos', $counters, $since, true, $this->room($maxRecords, $consumed));
                }
            } elseif ($phase === 'link') {
                $linked = array_values(array_intersect((array) $state['plan'], Types::LINKED));
                $type = isset($linked[$state['link_pos']]) ? (string) $linked[$state['link_pos']] : null;
                if ($type === null) {
                    $phase = 'finish';
                } else {
                    $consumed += $this->chunk($id, $type, $reader, $ctx, $state, 'link_read', 'link_pos', $counters, $since, false, $this->room($maxRecords, $consumed));
                }
            } elseif ($phase === 'finish') {
                $consumed += $this->finish($ctx, $state, $phase);
            } else {
                $this->closeUp($id, $reader, $ctx, $state, $counters, $unmapped, $dry);

                return 'done';
            }
            foreach ($ctx->drainUnmapped() as $item) {
                $key = $item['kind'] . "\n" . $item['name'];
                if (isset($unmapped['writers'][$key])) {
                    $unmapped['writers'][$key]['count'] += $item['count'];
                } else {
                    $unmapped['writers'][$key] = $item;
                }
            }
            $this->runs->update($id, [
                'phase' => $phase, 'current_type' => $phase === 'verify' ? null : $type, 'cursor' => $state, 'counters' => $counters,
                'unmapped' => $unmapped, 'source_errors' => 0, 'heartbeat_at' => $this->app->clock()->sql(),
            ], 'running');
            if ($consumed >= $maxRecords || ($this->now() - $started) >= $maxSeconds) {
                return 'continue';
            }
        }
    }

    /**
     * Procesa un trozo de un tipo: lo lee, lo comprueba y lo escribe (o lo enlaza). Deja en $state el cursor del
     * trozo ya procesado entero y, si el origen se agotó, pasa al tipo siguiente. Devuelve cuántos registros del origen
     * consumió.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $counters
     */
    private function chunk(int $id, string $type, SourceReader $reader, Context $ctx, array &$state, string $cursorKey, string $posKey, array &$counters, string $since, bool $write, int $limit): int
    {
        $writer = $this->registry->writer($type);
        if ($writer === null) {
            throw new \LogicException('No writer for ' . $type);
        }
        if ($write && !array_key_exists($type, $state['totals'])) {
            $state['totals'][$type] = $this->total($reader, $type);
        }
        if (!isset($counters[$type]) || !is_array($counters[$type])) {
            $counters[$type] = ['read' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'skipped' => 0];
        }
        $cursor = (string) $state[$cursorKey];
        $batch = $this->batch($reader->read($type, $cursor, max(1, min(self::CHUNK, $limit))));
        $consumed = count($batch['records']) + count($batch['skipped']);
        // Un origen que devuelve el mismo cursor sin haber consumido nada no avanzará nunca.
        if ($batch['next'] !== null && $batch['next'] === $cursor && $consumed === 0) {
            throw new SourceError('The source did not move forward');
        }

        if ($write) {
            foreach ($batch['skipped'] as $skipped) {
                $this->bump($counters, $type, TypeWriter::SKIPPED);
                $ctx->guard([]);
                $ctx->note($type, $skipped['id'], 'skipped', $skipped['reason']);
            }
        }
        foreach ($batch['records'] as $record) {
            $this->handle($type, $record, $writer, $ctx, $counters, $since, $write, $id, $state['ev']);
        }
        $ctx->guard([]);

        if ($batch['next'] === null) {
            $state[$posKey] = (int) $state[$posKey] + 1;
            $state[$cursorKey] = '';
        } else {
            $state[$cursorKey] = $batch['next'];
        }

        return $consumed;
    }

    /**
     * Un registro: se comprueba contra el formato neutro y se escribe (o se enlaza).
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $counters
     * @param array<string,mixed> $evidence lo que se apunta del origen para la comprobación final (Verify::collect)
     */
    private function handle(string $type, array $record, TypeWriter $writer, Context $ctx, array &$counters, string $since, bool $write, int $seed, array &$evidence): void
    {
        $sourceId = isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '';
        $secrets = Redactor::secretsOf($type, $record);
        $ctx->guard($secrets);
        $problems = Neutral::check($type, $record);
        if ($problems !== []) {
            if ($write) {
                $this->bump($counters, $type, TypeWriter::SKIPPED);
                $ctx->note($type, $sourceId, 'skipped', (string) $problems[0]);
            }

            return;
        }
        if ($write) {
            // Antes del filtro «since»: lo que no se vuelve a escribir sigue siendo del origen y cuenta en la comprobación final.
            Verify::collect($type, $record, $evidence, $seed);
        }
        if ($since !== '' && isset($record['updated_at']) && is_string($record['updated_at']) && substr($record['updated_at'], 0, 10) < $since) {
            if ($write) {
                $this->bump($counters, $type, TypeWriter::UNCHANGED);
            }

            return;
        }
        if (!$write) {
            try {
                $writer->link($record, $ctx);
            } catch (\Throwable $e) {
                $ctx->note($type, $sourceId, 'error', 'Could not link the record: ' . $e->getMessage());
            }

            return;
        }
        try {
            $outcome = $writer->write($record, $ctx);
        } catch (\Throwable $e) {
            $outcome = ['result' => TypeWriter::SKIPPED, 'id' => null, 'reason' => 'The writer failed: ' . $e->getMessage()];
        }
        $result = isset($outcome['result']) && is_string($outcome['result']) ? $outcome['result'] : '';
        if (!in_array($result, [TypeWriter::CREATED, TypeWriter::UPDATED, TypeWriter::UNCHANGED, TypeWriter::CONFLICT, TypeWriter::SKIPPED], true)) {
            $result = TypeWriter::SKIPPED;
            $outcome = ['reason' => 'The writer gave an unknown result'];
        }
        $this->bump($counters, $type, $result);
        if ($result === TypeWriter::CONFLICT || $result === TypeWriter::SKIPPED) {
            $ctx->note($type, $sourceId, $result, isset($outcome['reason']) && is_string($outcome['reason']) ? $outcome['reason'] : '');
        }
        if ($type === 'products' && in_array($result, [TypeWriter::CREATED, TypeWriter::UPDATED, TypeWriter::UNCHANGED], true) && isset($outcome['id']) && is_int($outcome['id'])) {
            $this->rememberPrices($record, $evidence, $ctx, $outcome['id']);
        }
    }

    /**
     * Apunta lo que cobraba el origen con impuestos por el producto (y sus variantes) para la comprobación final. Nunca tumba la migración.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $evidence
     */
    private function rememberPrices(array $record, array $evidence, Context $ctx, int $localId): void
    {
        try {
            // Solo si el producto lo escribe un escritor de este módulo: es el único que guarda donde la comprobación lo busca.
            $class = $this->registry->writerClass('products');
            if ($class === null || strpos($class, __NAMESPACE__ . '\\Writers\\') !== 0) {
                return;
            }
            if ($this->prices === null) {
                $this->prices = new PriceCheck($this->app);
            }
            $this->prices->remember($record, $evidence, $ctx, $localId);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'import.prices');
        }
    }

    /**
     * Cuenta un registro leído y su resultado: así «read» es siempre la suma de los resultados.
     *
     * @param array<string,mixed> $counters
     */
    private function bump(array &$counters, string $type, string $result): void
    {
        $counters[$type]['read'] = (int) $counters[$type]['read'] + 1;
        $counters[$type][$result] = (int) $counters[$type][$result] + 1;
    }

    /**
     * Comprueba la forma de lo que devuelve un lector.
     *
     * @param mixed $batch
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,skipped:array<int,array{id:string,reason:string}>}
     */
    private function batch($batch): array
    {
        if (!is_array($batch) || !isset($batch['records']) || !is_array($batch['records']) || !array_key_exists('next', $batch)) {
            throw new SourceError('The source returned an answer that cannot be read');
        }
        $records = [];
        foreach ($batch['records'] as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        $skipped = [];
        foreach (isset($batch['skipped']) && is_array($batch['skipped']) ? $batch['skipped'] : [] as $item) {
            if (is_array($item)) {
                $skipped[] = [
                    'id' => isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '',
                    'reason' => isset($item['reason']) && is_scalar($item['reason']) ? (string) $item['reason'] : 'Could not be read',
                ];
            }
        }

        return ['records' => $records, 'next' => $batch['next'] === null ? null : (string) $batch['next'], 'skipped' => $skipped];
    }

    /**
     * El final de la migración: sumas de control (solo si es de verdad), lo que no se pudo traer y el cierre.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $counters
     * @param array<string,mixed> $unmapped
     */
    private function closeUp(int $id, SourceReader $reader, Context $ctx, array $state, array $counters, array $unmapped, bool $dry): void
    {
        $values = [];
        if (!$dry) {
            $source = [];
            foreach ($reader->checksums() as $key => $value) {
                $source[(string) $key] = (int) $value;
            }
            $dest = $this->measure($ctx, (array) $state['plan']);
            $values['checksums'] = ['source' => $source, 'dest' => $dest, 'diffs' => $this->diffs($source, $dest), 'verify' => $this->verify($id, $ctx, $state, $counters)];
        }
        try {
            $list = [];
            foreach ($reader->unmapped() as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $list[] = [
                        'kind' => isset($item['kind']) ? (string) $item['kind'] : '', 'name' => (string) $item['name'],
                        'count' => isset($item['count']) ? (int) $item['count'] : 0, 'note' => isset($item['note']) ? (string) $item['note'] : '',
                    ];
                }
            }
            $unmapped['reader'] = $list;
        } catch (SourceError $e) {
            $unmapped['reader'] = [];
        }
        $values['unmapped'] = $unmapped;
        $values['phase'] = 'done';
        $values['current_type'] = null;
        $values['status'] = 'done';
        $values['finished_at'] = $this->app->clock()->sql();
        $values['heartbeat_at'] = $values['finished_at'];
        if ($this->runs->update($id, $values, 'running') === 1) {
            $this->connections->delete($id);
            $this->app->hooks()->fire('import.finished', $id, 'done');
        }
    }

    // ------------------------------------------------------------------ opciones que no llegan por el asistente

    /**
     * El dominio de la tienda antigua de unos datos de conexión (el de la dirección de la tienda, o el de la dirección base de las
     * imágenes si es una copia de la base de datos); cadena vacía si no hay.
     *
     * @param array<string,mixed> $config
     */
    public static function hostOf(array $config): string
    {
        foreach (['url', 'base_url'] as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '') {
                $host = parse_url(trim($config[$key]), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    return strtolower($host);
                }
            }
        }

        return '';
    }

    /**
     * Un dominio escrito a mano limpio (sin esquema, sin camino, sin puerto), o cadena vacía si no lo es.
     */
    public static function cleanHost(string $text): string
    {
        $text = strtolower(trim($text));
        $text = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $text);
        $text = (string) preg_replace('#[/?\#].*$#', '', $text);
        $text = (string) preg_replace('#:\d+$#', '', $text);

        return preg_match('/^[a-z0-9]([a-z0-9.\-]{0,188}[a-z0-9])?$/', $text) === 1 ? $text : '';
    }

    /**
     * Las opciones de la migración con lo que se deduce al empezar: el dominio de la tienda antigua (si no se escribió) y el
     * prefijo de los números de pedido limpio. «prices_include_tax_here» NO se congela aquí: se lee del sitio cada vez, porque la
     * migración de los ajustes puede cambiar cómo se escriben los precios y los productos que vienen detrás tienen que seguirla.
     *
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    private function startOptions(array $run): array
    {
        $options = isset($run['options']) && is_array($run['options']) ? $run['options'] : [];
        $host = isset($options['source_host']) && is_string($options['source_host']) ? self::cleanHost($options['source_host']) : '';
        if ($host === '') {
            $conn = $this->connections->load((int) $run['id']);
            $host = $conn !== null ? self::hostOf($conn['config']) : '';
        }
        if ($host !== '') {
            $options['source_host'] = $host;
        } else {
            unset($options['source_host']);
        }
        if (isset($options['order_prefix'])) {
            $prefix = is_string($options['order_prefix']) ? substr((string) preg_replace('/[^A-Za-z0-9._\-\/]/', '', $options['order_prefix']), 0, 20) : '';
            if ($prefix === '') {
                unset($options['order_prefix']);
            } else {
                $options['order_prefix'] = $prefix;
            }
        }

        return $options;
    }

    /**
     * La fila de la migración tal como la ve el contexto de los escritores: con la clave de cookies de la tienda antigua entre las
     * opciones. Esa clave se guarda cifrada con el resto de la conexión y solo vive en memoria durante la tanda: nunca vuelve a la fila.
     *
     * @param array<string,mixed>                                       $run
     * @param array{config:array<string,mixed>,db:array<string,mixed>}|null $conn
     *
     * @return array<string,mixed>
     */
    private function withHiddenOptions(array $run, ?array $conn): array
    {
        $options = isset($run['options']) && is_array($run['options']) ? $run['options'] : [];
        if ($conn !== null && isset($conn['config']['cookie_key']) && is_string($conn['config']['cookie_key']) && $conn['config']['cookie_key'] !== '') {
            $options['cookie_key'] = $conn['config']['cookie_key'];
        }
        $run['options'] = $options;

        return $run;
    }

    // ------------------------------------------------------------------ después de escribir

    /**
     * Lo que se hace al acabar de escribir, una pieza por llamada y reanudable: los contadores (productos por categoría, valoraciones),
     * el índice del buscador, las cachés y los feeds. Los escritores no disparan «content.saved» ni los ganchos de guardado del panel
     * (eso avisaría a quien escuche), así que esto se hace aquí, una vez, de golpe.
     *
     * @param array<string,mixed> $state
     */
    private function finish(Context $ctx, array &$state, string &$phase): int
    {
        $f = isset($state['finish']) && is_array($state['finish']) ? $state['finish'] : [];
        $f += ['task' => 0, 'after' => 0, 'types' => null];
        $plan = array_values(array_map('strval', (array) $state['plan']));
        $tasks = $this->finishTasks($plan);
        $task = isset($tasks[(int) $f['task']]) ? $tasks[(int) $f['task']] : null;
        if ($task === null) {
            $phase = 'verify';
            $state['finish'] = $f;

            return 0;
        }
        $consumed = 1;
        try {
            if ($task === 'categories') {
                \Aifec\Modules\Catalog\Admin\CategoryAdmin::recount($this->app);
                $this->nextTask($f);
            } elseif ($task === 'reviews') {
                $ids = array_map('intval', $this->app->db()->column('SELECT DISTINCT product_id FROM {reviews} WHERE product_id > ? ORDER BY product_id LIMIT 50', [(int) $f['after']]));
                foreach ($ids as $productId) {
                    $this->app->service('reviews')->recalculate($productId);
                }
                $consumed = max(1, count($ids));
                if (count($ids) < 50) {
                    $this->nextTask($f);
                } else {
                    $f['after'] = max($ids);
                }
            } elseif ($task === 'reindex') {
                $index = $this->app->service('search')->indexer();
                $types = is_array($f['types']) ? array_values(array_map('strval', $f['types'])) : $this->reindexTypes($plan);
                $step = $index->reindexBatch($types, (int) $f['after']);
                $consumed = max(1, (int) $step['read']);
                $f['types'] = $step['types'];
                $f['after'] = $step['after'];
                if ($step['types'] === []) {
                    $this->nextTask($f);
                }
            } elseif ($task === 'caches') {
                $this->app->cache()->flush();
                $this->nextTask($f);
            } else {
                \Aifec\Modules\Semantic\Feeds::generate($this->app->service('semantic'));
                $this->nextTask($f);
            }
        } catch (\Throwable $e) {
            // Un contador, el buscador o un feed que no se puede rehacer no deshace una migración ya escrita: se apunta y se sigue.
            $ctx->note('finish', $task, 'note', 'This last step could not be completed: ' . $e->getMessage());
            $this->nextTask($f);
        }
        $state['finish'] = $f;

        return $consumed;
    }

    /**
     * @param array<string,mixed> $f
     */
    private function nextTask(array &$f): void
    {
        $f['task'] = (int) $f['task'] + 1;
        $f['after'] = 0;
        $f['types'] = null;
    }

    /**
     * Las piezas que corresponden a esta migración y a este sitio, en su orden.
     *
     * @param string[] $plan
     *
     * @return string[]
     */
    private function finishTasks(array $plan): array
    {
        $has = array_flip($plan);
        $tasks = [];
        if ($this->app->has('catalog') && (isset($has['categories']) || isset($has['products'])) && class_exists('Aifec\\Modules\\Catalog\\Admin\\CategoryAdmin')) {
            $tasks[] = 'categories';
        }
        if ($this->app->has('reviews') && isset($has['reviews'])) {
            $tasks[] = 'reviews';
        }
        if ($this->app->has('search') && $this->reindexTypes($plan) !== []) {
            $tasks[] = 'reindex';
        }
        $tasks[] = 'caches';
        if ($this->app->has('semantic') && class_exists('Aifec\\Modules\\Semantic\\Feeds')
            && (isset($has['products']) || isset($has['categories']) || isset($has['pages']) || isset($has['posts']))) {
            $tasks[] = 'feeds';
        }

        return $tasks;
    }

    /**
     * Qué tipos del buscador cambian con lo que se trajo (productos, páginas, entradas): lo que comparten muchos (categorías, marcas,
     * atributos) cambia el texto de todos los productos.
     *
     * @param string[] $plan
     *
     * @return string[]
     */
    private function reindexTypes(array $plan): array
    {
        $types = [];
        if (array_intersect($plan, ['products', 'categories', 'brands', 'attributes']) !== []) {
            $types[] = 'product';
        }
        if (in_array('pages', $plan, true)) {
            $types[] = 'page';
        }
        if (array_intersect($plan, ['posts', 'post_categories']) !== []) {
            $types[] = 'post';
        }

        return array_values(array_intersect($this->app->service('search')->indexer()->types(), $types));
    }

    /**
     * Vuelve a comprobar una migración terminada contra lo que hay AHORA aquí: las cifras de los escritores contra las del origen que se
     * guardaron al acabar, y las muestras (precios, pedidos, direcciones antiguas…) contra lo que el origen tenía. No necesita el origen: nada
     * de lo que compara sale de él después de la migración. Guarda el resultado nuevo. Es lo que hace «import:verify».
     *
     * @return array<string,mixed>|null la fila ya actualizada; null si no se puede (no existe, no terminó, o es un simulacro)
     */
    public function reverify(int $id): ?array
    {
        $run = $this->runs->find($id);
        if ($run === null || $run['status'] !== 'done' || (int) $run['dry_run'] === 1 || !isset($run['checksums']['source']) || !is_array($run['checksums']['source'])) {
            return null;
        }
        $before = $this->app->silence(true);
        try {
            $state = $run['cursor'];
            $ctx = new Context($this->app, $this->runs, $run, [], '');
            $source = [];
            foreach ($run['checksums']['source'] as $key => $value) {
                $source[(string) $key] = (int) $value;
            }
            $dest = $this->measure($ctx, isset($state['plan']) && is_array($state['plan']) ? $state['plan'] : []);
            $this->runs->update($id, ['checksums' => [
                'source' => $source, 'dest' => $dest, 'diffs' => $this->diffs($source, $dest), 'verify' => $this->verify($id, $ctx, $state, $run['counters']),
            ]]);
        } finally {
            $this->app->silence($before);
        }

        return $this->runs->find($id);
    }

    /**
     * Las cifras de lo que hay aquí de los tipos que se trajeron, según cada escritor.
     *
     * @param array<int,mixed> $plan
     *
     * @return array<string,int>
     */
    private function measure(Context $ctx, array $plan): array
    {
        $dest = [];
        foreach ($plan as $type) {
            $writer = $this->registry->writer((string) $type);
            if ($writer === null) {
                continue;
            }
            foreach ($writer->checksums($ctx) as $key => $value) {
                $dest[(string) $key] = (isset($dest[(string) $key]) ? $dest[(string) $key] : 0) + (int) $value;
            }
        }

        return $this->sameMeasure($ctx, $dest);
    }

    /**
     * Las cifras que no cuadran: solo se comparan las que están en los dos lados.
     *
     * @param array<string,int> $source
     * @param array<string,int> $dest
     *
     * @return array<int,array{key:string,source:int,dest:int}>
     */
    private function diffs(array $source, array $dest): array
    {
        $diffs = [];
        foreach ($source as $key => $value) {
            if (isset($dest[$key]) && $dest[$key] !== $value) {
                $diffs[] = ['key' => $key, 'source' => $value, 'dest' => $dest[$key]];
            }
        }

        return $diffs;
    }

    /**
     * Pone las cifras del destino en la misma medida que las del origen cuando los dos cuentan cosas distintas con el mismo nombre.
     * PrestaShop cuenta como «línea de pedido» solo las líneas de detalle (productos); el escritor cuenta también el envío, el recargo
     * y el descuento. Se compara producto con producto.
     *
     * @param array<string,int> $dest
     *
     * @return array<string,int>
     */
    private function sameMeasure(Context $ctx, array $dest): array
    {
        if ($ctx->source() === 'prestashop' && isset($dest['order_items_count'])) {
            $dest['order_items_count'] = (int) $this->app->db()->value(
                "SELECT COUNT(*) FROM {import_map} m JOIN {order_items} i ON i.order_id = m.local_id WHERE m.type = 'orders' AND i.kind = 'product'"
            );
        }

        return $dest;
    }

    /**
     * La comprobación con muestras. Nunca tumba el cierre: si falla, queda dicho y la migración acaba igual.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $counters
     *
     * @return array<string,mixed>
     */
    private function verify(int $id, Context $ctx, array $state, array $counters): array
    {
        try {
            $run = $this->runs->find($id);
            if ($run === null) {
                throw new \RuntimeException('The migration disappeared');
            }

            return (new Verify($this->app, $this->runs, $this->registry))->run(
                $run,
                isset($state['ev']) && is_array($state['ev']) ? $state['ev'] : [],
                $counters,
                $ctx,
                array_values(array_map('strval', (array) $state['plan']))
            );
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'import.verify');

            return ['at' => $this->app->clock()->sql(), 'ok' => false, 'checked' => 0, 'wrong' => 0, 'rows' => [], 'error' => 'The final check could not be completed'];
        }
    }

    /**
     * Cierra como fallida una migración en curso, con su motivo.
     *
     * @param string[] $secrets
     */
    private function fail(int $id, string $reason, array $secrets): bool
    {
        $changed = $this->runs->update($id, [
            'status' => 'failed', 'pause_reason' => Redactor::scrub($reason, $secrets, 191), 'current_type' => null,
            'finished_at' => $this->app->clock()->sql(),
        ], 'running') === 1;
        if ($changed) {
            $this->connections->delete($id);
            $this->app->hooks()->fire('import.finished', $id, 'failed');
        }

        return $changed;
    }

    /**
     * Qué tipos se van a traer y por qué se saltan los demás.
     *
     * @param array<string,mixed>                                           $run
     * @param array<string,array{text:string,vars:array<string,string>}>    $skipped se rellena
     *
     * @return string[]
     */
    private function plan(array $run, SourceReader $reader, array &$skipped): array
    {
        $available = $reader->types();
        $options = $run['options'];
        $asked = [];
        foreach (isset($options['types']) && is_array($options['types']) ? $options['types'] : [] as $type) {
            if (is_string($type) && Types::valid($type)) {
                $asked[] = $type;
            }
        }
        $wanted = Types::sorted($asked === [] ? $available : $asked);
        $plan = [];
        foreach ($wanted as $type) {
            if (!in_array($type, $available, true)) {
                $skipped[$type] = ['text' => 'This source does not provide this type', 'vars' => []];
                continue;
            }
            $why = $this->registry->unavailable($type);
            if ($why !== null) {
                $skipped[$type] = $why;
                continue;
            }
            $plan[] = $type;
        }

        return $plan;
    }

    private function total(SourceReader $reader, string $type): ?int
    {
        try {
            return $reader->count($type);
        } catch (SourceError $e) {
            return null;
        }
    }

    private function room(int $maxRecords, int $consumed): int
    {
        return max(1, $maxRecords - $consumed);
    }

    private function now(): float
    {
        return (float) ($this->monotonic)();
    }
}
