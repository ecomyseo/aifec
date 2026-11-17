<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * «Traducir lo que falta» con la IA: un trabajo en segundo plano por idioma y por fila de la tabla de cobertura (translation_jobs),
 * que corre por tandas (un encargo por cada ~40 textos) y se vuelve a encolar mientras quede algo.
 *
 * Reglas que evitan los fallos caros (las mismas que los lotes del módulo de IA):
 *  - un trabajo solo existe porque una persona lo lanza (panel o consola); nunca se crea solo;
 *  - el cursor avanza por lo CONSUMIDO (leído, saliera bien o mal), no por lo producido; el fin se detecta porque la fila ya no tiene
 *    nada detrás del cursor, no porque una tanda salga corta;
 *  - con el tope diario alcanzado (o la IA apagada) el trabajo queda «paused» con el motivo y esa tanda NO se consume: al reanudar
 *    sigue exactamente donde iba; tres tandas fallidas seguidas también lo pausan;
 *  - lo que vuelve se comprueba (marcadores, plurales, HTML, nombres que no se traducen) y entra como PROPUESTA: nada se publica
 *    hasta que alguien lo aprueba, salvo con el ajuste «publicar las traducciones de la IA sin revisar» (apagado de serie).
 */
final class Fill
{
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    /** Tipo del trabajo en la cola. */
    public const JOB = 'translations.fill';

    /** Textos por encargo, y caracteres de original por encargo (lo primero que se alcance). */
    public const CHUNK_UNITS = 40;
    public const CHUNK_CHARS = 6000;

    /** Tandas fallidas seguidas que pausan el trabajo. */
    public const MAX_STREAK = 3;

    /** Motivos de pausa (en inglés; se traducen al enseñarlos con reason()). */
    public const LIMIT = 'Daily AI limit reached';
    public const MONTHLY = 'Monthly AI limit reached';
    public const TOKENS = 'Daily AI token limit reached';
    public const OFF = 'AI is off';
    public const STREAK = 'Three failures in a row';
    public const GONE = 'This row is not available any more';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Lanza (o devuelve, si ya hay uno vivo para ese idioma y esa fila) el trabajo que traduce lo que falta.
     *
     * @throws \InvalidArgumentException con un código: no_ai, language, unknown, personal, blocked, nothing
     */
    public function start(string $lang, string $code, ?int $userId = null): int
    {
        if (!AiGate::ready($this->app)) {
            throw new \InvalidArgumentException('no_ai');
        }
        if (!in_array($lang, $this->app->languages(), true)) {
            throw new \InvalidArgumentException('language');
        }
        $scope = Scopes::get($this->app, $code);
        if ($scope === null) {
            throw new \InvalidArgumentException('unknown');
        }
        if (!Scopes::allowed($scope)) {
            throw new \InvalidArgumentException('personal');
        }
        if ($scope->blocked($lang) !== '') {
            throw new \InvalidArgumentException('blocked');
        }
        $db = $this->app->db();
        $alive = $db->value("SELECT id FROM {translation_jobs} WHERE lang = ? AND scope = ? AND status IN ('running', 'paused') ORDER BY id DESC", [$lang, $code]);
        if ($alive !== null) {
            return (int) $alive;
        }
        $missing = $scope->coverage($lang)['missing'];
        if ($missing <= 0) {
            throw new \InvalidArgumentException('nothing');
        }
        $now = $this->app->clock()->sql();
        $id = $db->insert('translation_jobs', [
            'lang' => $lang,
            'scope' => $code,
            'status' => self::RUNNING,
            'total' => $missing,
            'done' => 0,
            'failed' => 0,
            'streak' => 0,
            'chunks' => 0,
            'created_by' => $userId !== null && $userId > 0 ? $userId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->queue($id);

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {translation_jobs} WHERE id = ?', [$id]);
    }

    /**
     * Los últimos trabajos de un idioma.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(string $lang, int $limit = 20): array
    {
        return $this->app->db()->select('SELECT * FROM {translation_jobs} WHERE lang = ? ORDER BY id DESC LIMIT ?', [$lang, max(1, $limit)]);
    }

    /** Pausa un trabajo que corre. true = se pausó ahora. */
    public function pause(int $id, string $reason = ''): bool
    {
        return $this->app->db()->run(
            "UPDATE {translation_jobs} SET status = 'paused', reason = ?, updated_at = ? WHERE id = ? AND status = 'running'",
            [$reason !== '' ? substr($reason, 0, 300) : null, $this->app->clock()->sql(), $id]
        ) === 1;
    }

    /** Reanuda un trabajo pausado, sin racha de fallos y con su tanda otra vez en la cola. true = se reanudó ahora. */
    public function resume(int $id): bool
    {
        $changed = $this->app->db()->run(
            "UPDATE {translation_jobs} SET status = 'running', streak = 0, reason = NULL, updated_at = ? WHERE id = ? AND status = 'paused'",
            [$this->app->clock()->sql(), $id]
        ) === 1;
        if ($changed) {
            $this->queue($id);
        }

        return $changed;
    }

    /** Cancela un trabajo que corre o está pausado. Lo ya propuesto se queda. true = se canceló ahora. */
    public function cancel(int $id, string $reason = ''): bool
    {
        $now = $this->app->clock()->sql();

        return $this->app->db()->run(
            "UPDATE {translation_jobs} SET status = 'cancelled', reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('running', 'paused')",
            [$reason !== '' ? substr($reason, 0, 300) : null, $now, $now, $id]
        ) === 1;
    }

    /**
     * Corre UNA tanda y devuelve el trabajo como queda. No hace nada si el trabajo no está corriendo.
     *
     * @return array<string,mixed>|null
     */
    public function runChunk(int $id): ?array
    {
        $job = $this->find($id);
        if ($job === null || (string) $job['status'] !== self::RUNNING) {
            return $job;
        }
        $lang = (string) $job['lang'];
        $scope = Scopes::get($this->app, (string) $job['scope']);
        if ($scope === null || !Scopes::allowed($scope) || $scope->blocked($lang) !== '') {
            $this->cancel($id, self::GONE);

            return $this->find($id);
        }
        $batch = $scope->next($lang, (string) $job['cursor'], self::CHUNK_UNITS, self::CHUNK_CHARS);
        if ($batch['units'] === []) {
            $this->advance($id, $batch['cursor'], 0, 0, (int) $job['streak']);
            if ($batch['done']) {
                $this->finish($id);
            }

            return $this->find($id);
        }

        try {
            $answer = Brief::ask($this->app, $scope, $lang, $batch['units']);
        } catch (\Throwable $e) {
            if ($e instanceof \Aifec\Modules\Ai\AiLimitReached) {
                // Esta tanda no se ha hecho: no se consume, y al reanudar sigue aquí.
                $reason = in_array($e->getMessage(), [self::LIMIT, self::MONTHLY, self::TOKENS], true) ? $e->getMessage() : self::LIMIT;
                $this->pause($id, $reason);

                return $this->find($id);
            }
            if ($e instanceof \Aifec\Modules\Ai\AiDisabled) {
                $this->pause($id, self::OFF);

                return $this->find($id);
            }
            $this->app->log()->exception($e, 'translations:fill');
            $streak = (int) $job['streak'] + 1;
            $this->advance($id, $batch['cursor'], 0, count($batch['units']), $streak);
            if ($streak >= self::MAX_STREAK) {
                $this->pause($id, self::STREAK);
            } elseif ($batch['done']) {
                $this->finish($id);
            }

            return $this->find($id);
        }

        $keep = Glossary::parse(Glossary::get($this->app, $lang))['keep'];
        $good = [];
        $units = [];
        $bad = 0;
        foreach ($batch['units'] as $unit) {
            $units[$unit['key']] = $unit;
            $text = isset($answer[$unit['key']]) ? Check::clean($answer[$unit['key']]) : '';
            if ($text === '' || Check::problem($unit['text'], $text, $unit['format']) !== null || Glossary::lostName($keep, $unit['text'], $text) !== '') {
                ++$bad;
                continue;
            }
            $good[$unit['key']] = $text;
        }
        $failedKeys = [];
        if ($good !== []) {
            $publish = $this->app->settings()->bool('translations.publish_unreviewed', false);
            $failedKeys = $scope->save($lang, $good, $units, $publish, $id);
        }
        $this->advance($id, $batch['cursor'], count($good) - count($failedKeys), $bad + count($failedKeys), 0);
        if ($batch['done']) {
            $this->finish($id);
        }

        return $this->find($id);
    }

    /** Encola la siguiente tanda del trabajo (una sola a la vez). */
    public function queue(int $id): void
    {
        $job = $this->find($id);
        if ($job === null || !$this->app->has('jobs')) {
            return;
        }
        $this->app->service('jobs')->push(self::JOB, ['id' => $id], ['unique' => self::JOB . ':' . $id . ':' . (int) $job['chunks']]);
    }

    /** El motivo de una pausa o de una cancelación, en el idioma de quien mira. */
    public static function reason(string $reason): string
    {
        switch ($reason) {
            case self::LIMIT:
                return t('Daily AI limit reached');
            case self::MONTHLY:
                return t('Monthly AI limit reached');
            case self::TOKENS:
                return t('Daily AI token limit reached');
            case self::OFF:
                return t('AI is off');
            case self::STREAK:
                return t('Three failures in a row');
            case self::GONE:
                return t('This row is not available any more');
        }

        return $reason;
    }

    /** Por qué no se pudo lanzar, en el idioma de quien mira. */
    public static function message(string $code): string
    {
        switch ($code) {
            case 'no_ai':
                return t('The AI is off or has no provider');
            case 'language':
                return t('Choose a language of the shop');
            case 'unknown':
                return t('Unknown row');
            case 'personal':
                return t('This row reads personal data and is never translated');
            case 'blocked':
                return t('This row cannot be translated with AI');
            case 'nothing':
                return t('There is nothing missing to translate');
        }

        return t('The translation could not be launched');
    }

    // ------------------------------------------------------------------ interior

    private function advance(int $id, string $cursor, int $done, int $failed, int $streak): void
    {
        // «cursor» es palabra reservada en MariaDB/MySQL: sin comillas la sentencia no se ejecuta y la traducción se queda a medias
        // (14/11/2026: el catálogo en inglés parado en 517 de 2.936; en SQLite, el de las pruebas, sí funciona).
        $db = $this->app->db();
        $db->run(
            'UPDATE {translation_jobs} SET done = done + ?, failed = failed + ?, streak = ?, ' . $db->ident('cursor') . ' = ?, chunks = chunks + 1, updated_at = ? WHERE id = ?',
            [max(0, $done), max(0, $failed), $streak, $cursor, $this->app->clock()->sql(), $id]
        );
    }

    private function finish(int $id): void
    {
        $now = $this->app->clock()->sql();
        $this->app->db()->run(
            "UPDATE {translation_jobs} SET status = 'done', reason = NULL, finished_at = ?, updated_at = ? WHERE id = ? AND status = 'running'",
            [$now, $now, $id]
        );
    }
}
