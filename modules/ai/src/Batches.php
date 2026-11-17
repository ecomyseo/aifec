<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Ai\AiException;
use Aifec\Kernel\App;

/**
 * Lotes: lanzar una tarea de redacción sobre un conjunto de productos, en segundo plano y por tandas de 10.
 *
 * Reglas que evitan los fallos caros:
 *  - antes de lanzar hay una estimación del coste, y un lote nuevo nunca recorre nada hasta que alguien lo lanza;
 *  - el cursor (last_id) avanza por lo CONSUMIDO (leído, haya salido bien o mal), no por lo producido: un producto que falla
 *    no se vuelve a leer, y el fin se detecta porque la fuente se agotó, no porque la tanda salió corta;
 *  - un fallo del proveedor en un producto cuenta como fallido y se sigue; tres seguidos pausan el lote;
 *  - si se alcanza el tope diario (o la IA se apaga) el lote queda «paused» con el motivo; el producto que no se pudo hacer no
 *    se consume;
 *  - un lote siempre termina en «done» o «cancelled» con sus números: cada tanda corre dentro de un try y el trabajo del lote
 *    se vuelve a encolar mientras queden productos.
 */
final class Batches
{
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    /** Productos por tanda. */
    public const CHUNK = 10;

    /** Fallos seguidos que pausan el lote. */
    public const MAX_STREAK = 3;

    /** Fichas aproximadas de un encargo [entrada, salida] por tarea, para la estimación del coste (no es una medida). */
    private const ESTIMATE = [
        'description' => [900, 450],
        'summary' => [700, 120],
        'facts' => [800, 250],
        'faqs' => [900, 500],
        'meta_title' => [600, 60],
        'meta_description' => [600, 100],
        'translate' => [800, 500],
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Cuánto costaría lanzar una tarea sobre un conjunto, sin lanzar nada.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $options field y to (translate)
     *
     * @return array{count:int,tokens_in:int,tokens_out:int,cost_micro:int,cost_cents:int,tier:string,limit_micro:int,spent_micro:int}
     *
     * @throws \InvalidArgumentException
     */
    public function estimate(string $task, array $filter, array $options = []): array
    {
        $this->checkTask($task);
        $filter = BatchFilter::normalise($this->app, $filter);
        $count = BatchFilter::count($this->app, $filter);
        $tier = Generators::generator($task)->tier();
        $tokens = self::ESTIMATE[$task];
        $settings = $this->app->settings();
        $in = $count * $tokens[0];
        $out = $count * $tokens[1];
        $cost = $in * max(0, $settings->int('ai.' . $tier . '.price_in', 0)) + $out * max(0, $settings->int('ai.' . $tier . '.price_out', 0));

        return [
            'count' => $count,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'cost_micro' => $cost,
            'cost_cents' => intdiv($cost + Usage::MICRO - 1, Usage::MICRO),
            'tier' => $tier,
            'limit_micro' => Usage::limitMicro($this->app),
            'spent_micro' => Usage::spentMicro($this->app),
        ];
    }

    /**
     * Crea un lote y encola su primera tanda. Devuelve su id.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $options field y to (translate)
     *
     * @throws \InvalidArgumentException si la tarea o el conjunto no valen o no hay nada que recorrer
     */
    public function start(string $task, array $filter, array $options = [], ?int $userId = null): int
    {
        $this->checkTask($task);
        $filter = BatchFilter::normalise($this->app, $filter);
        $options = $this->options($task, $options);
        $total = BatchFilter::count($this->app, $filter);
        if ($total <= 0) {
            throw new \InvalidArgumentException('There is nothing to process');
        }
        $now = $this->app->clock()->sql();
        $id = $this->app->db()->insert('ai_batches', [
            'task' => $task,
            'filter' => (string) json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'options' => (string) json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'total' => $total,
            'done' => 0,
            'failed' => 0,
            'streak' => 0,
            'last_id' => 0,
            'status' => self::RUNNING,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->queue($id);

        return $id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {ai_batches} WHERE id = ?', [$id]);
    }

    /** Pausa un lote que corre. true = se pausó ahora. */
    public function pause(int $id, string $reason = ''): bool
    {
        return $this->app->db()->run(
            "UPDATE {ai_batches} SET status = 'paused', reason = ?, updated_at = ? WHERE id = ? AND status = 'running'",
            [$reason !== '' ? substr($reason, 0, 300) : null, $this->app->clock()->sql(), $id]
        ) === 1;
    }

    /** Reanuda un lote pausado: sin racha de fallos y con su tanda otra vez en la cola. true = se reanudó ahora. */
    public function resume(int $id): bool
    {
        $changed = $this->app->db()->run(
            "UPDATE {ai_batches} SET status = 'running', streak = 0, reason = NULL, updated_at = ? WHERE id = ? AND status = 'paused'",
            [$this->app->clock()->sql(), $id]
        ) === 1;
        if ($changed) {
            $this->queue($id);
        }

        return $changed;
    }

    /** Cancela un lote que corre o está pausado. Los textos ya propuestos se quedan. true = se canceló ahora. */
    public function cancel(int $id): bool
    {
        $now = $this->app->clock()->sql();

        return $this->app->db()->run(
            "UPDATE {ai_batches} SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('running', 'paused')",
            [$now, $now, $id]
        ) === 1;
    }

    /**
     * Corre UNA tanda de hasta CHUNK productos y devuelve el lote como queda. No hace nada si el lote no está corriendo.
     *
     * @return array<string,mixed>|null
     */
    public function runChunk(int $id, int $size = self::CHUNK): ?array
    {
        $batch = $this->find($id);
        if ($batch === null || (string) $batch['status'] !== self::RUNNING) {
            return $batch;
        }
        $filter = json_decode((string) $batch['filter'], true);
        $options = json_decode((string) $batch['options'], true);
        $filter = is_array($filter) ? $filter : [];
        $options = is_array($options) ? $options : [];
        $task = (string) $batch['task'];

        $ids = BatchFilter::nextIds($this->app, $filter, (int) $batch['last_id'], max(1, $size));
        if ($ids === []) {
            $this->finish($id);

            return $this->find($id);
        }

        $generators = new Generators($this->app);
        $done = 0;
        $failed = 0;
        $streak = (int) $batch['streak'];
        $last = (int) $batch['last_id'];
        $pause = '';
        foreach ($ids as $productId) {
            // Si alguien pausó o canceló el lote mientras tanto, se para sin tocar nada más.
            $status = (string) $this->app->db()->value('SELECT status FROM {ai_batches} WHERE id = ?', [$id]);
            if ($status !== self::RUNNING) {
                break;
            }
            try {
                $generators->run($task, $productId, $options + ['lang' => isset($filter['lang']) ? $filter['lang'] : '']);
                ++$done;
                $streak = 0;
            } catch (AiLimitReached $e) {
                $pause = 'Daily AI limit reached';
                break; // este producto no se ha hecho: no se consume
            } catch (AiDisabled $e) {
                $pause = 'AI is off';
                break;
            } catch (\Throwable $e) {
                ++$failed;
                ++$streak;
                $this->app->log()->exception($e, 'ai:batch');
            }
            $last = $productId;
            if ($streak >= self::MAX_STREAK) {
                $pause = 'Three failures in a row';
                break;
            }
        }

        $this->app->db()->run(
            'UPDATE {ai_batches} SET done = done + ?, failed = failed + ?, streak = ?, last_id = ?, updated_at = ? WHERE id = ?',
            [$done, $failed, $streak, $last, $this->app->clock()->sql(), $id]
        );
        if ($pause !== '') {
            $this->pause($id, $pause);
        } elseif ((string) $this->app->db()->value('SELECT status FROM {ai_batches} WHERE id = ?', [$id]) === self::RUNNING
            && BatchFilter::nextIds($this->app, $filter, $last, 1) === []) {
            $this->finish($id);
        }

        return $this->find($id);
    }

    /** Cierra un lote como terminado. */
    private function finish(int $id): void
    {
        $now = $this->app->clock()->sql();
        $this->app->db()->run(
            "UPDATE {ai_batches} SET status = 'done', finished_at = ?, updated_at = ?, reason = NULL WHERE id = ? AND status = 'running'",
            [$now, $now, $id]
        );
    }

    /** Encola la siguiente tanda del lote (una sola a la vez por tanda). */
    public function queue(int $id): void
    {
        $batch = $this->find($id);
        if ($batch === null) {
            return;
        }
        /** @var \Aifec\Modules\Jobs\Queue $jobs */
        $jobs = $this->app->service('jobs');
        $jobs->push('ai.batch', ['id' => $id], ['unique' => 'ai.batch:' . $id . ':' . ((int) $batch['done'] + (int) $batch['failed'])]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function checkTask(string $task): void
    {
        if (!in_array($task, Generators::PRODUCT_TASKS, true)) {
            throw new \InvalidArgumentException('A batch cannot run this task: ' . $task);
        }
    }

    /**
     * Las opciones de una tarea: solo field y to, y solo para translate.
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,string>
     *
     * @throws \InvalidArgumentException
     */
    private function options(string $task, array $options): array
    {
        if ($task !== 'translate') {
            return [];
        }
        $field = isset($options['field']) && is_string($options['field']) && $options['field'] !== '' ? $options['field'] : 'description';
        if (!ProductFields::isField($field)) {
            throw new \InvalidArgumentException('Unknown field: ' . $field);
        }
        $to = isset($options['to']) && is_string($options['to']) ? strtolower($options['to']) : '';
        if ($to === '' || !in_array($to, $this->app->languages(), true) || $to === $this->app->defaultLang()) {
            throw new \InvalidArgumentException('Choose a language to translate into');
        }

        return ['field' => $field, 'to' => $to];
    }
}
