<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Kernel\App;
use Aifec\Search\Tokenizer;

/**
 * El registro de búsquedas (tabla search_log): una fila por día, idioma y consulta normalizada con las veces que se hizo.
 *
 * NUNCA guarda quién busca: ni persona, ni dirección IP, ni sesión. Y tampoco guarda lo que parezca un dato personal escrito en
 * el buscador (un correo, un teléfono con separadores).
 */
final class QueryLog
{
    /** Largo máximo de la consulta que se guarda. */
    private const MAX_PHRASE = 120;

    /** Días que se conserva el registro. */
    public const KEEP_DAYS = 90;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Lo que escribe alguien en el buscador y que parece un dato personal: no se guarda.
     */
    public static function looksPersonal(string $query): bool
    {
        if (strpos($query, '@') !== false) {
            return true;
        }

        return preg_match('/^\+?\d[\d\s().-]{7,}\d$/', trim($query)) === 1 && preg_match('/[\s().-]/', trim($query)) === 1;
    }

    /** Cómo puede acabar una búsqueda: con resultados exactos, con una propuesta relajada, con productos relacionados, con nada o con la respuesta de una pregunta de la tienda. */
    public const OUTCOMES = ['exact', 'relaxed', 'related', 'none', 'shop'];

    /**
     * Apunta una búsqueda. $missed: la tienda no encontró nada que encajara (0 resultados, o resultados que no convencen).
     * $outcome: cómo acabó (uno de OUTCOMES; vacío = no se sabe).
     */
    public function record(string $query, string $lang, int $found, bool $missed, string $outcome = ''): void
    {
        if (self::looksPersonal($query)) {
            return;
        }
        $phrase = mb_substr((new Tokenizer($lang))->normalize($query), 0, self::MAX_PHRASE, 'UTF-8');
        if ($phrase === '') {
            return;
        }
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : '';
        $db = $this->app->db();
        $day = $this->day(0);
        $where = [$day, $lang, $phrase];
        $sql = 'UPDATE {search_log} SET hits = hits + 1, found = ?, missed = ?, outcome = ? WHERE day = ? AND lang = ? AND phrase = ?';
        $set = [$found, $missed ? 1 : 0, $outcome];
        $changed = $db->run($sql, array_merge($set, $where));
        if ($changed > 0) {
            return;
        }
        try {
            $db->insert('search_log', ['day' => $day, 'lang' => $lang, 'phrase' => $phrase, 'hits' => 1, 'found' => $found, 'missed' => $missed ? 1 : 0, 'outcome' => $outcome]);
        } catch (\Throwable $e) {
            // Otra petición la dio de alta a la vez: se suma a la suya.
            $db->run($sql, array_merge($set, $where));
        }
    }

    /**
     * Corrige cómo acabó una búsqueda ya apuntada hoy, sin sumarla otra vez: la página la apuntó sin la ayuda de la IA y la
     * ayuda, que llega después (/buscar/significado.json), la ha mejorado. Si hoy no estaba apuntada, no hace nada.
     */
    public function amend(string $query, string $lang, int $found, bool $missed, string $outcome = ''): void
    {
        if (self::looksPersonal($query)) {
            return;
        }
        $phrase = mb_substr((new Tokenizer($lang))->normalize($query), 0, self::MAX_PHRASE, 'UTF-8');
        if ($phrase === '') {
            return;
        }
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : '';
        $this->app->db()->run(
            'UPDATE {search_log} SET found = ?, missed = ?, outcome = ? WHERE day = ? AND lang = ? AND phrase = ?',
            [$found, $missed ? 1 : 0, $outcome, $this->day(0), $lang, $phrase]
        );
    }

    /**
     * Cuántas búsquedas acabaron de cada manera en los últimos días: exact, relaxed, related y none (con 0 las que no tengan ninguna).
     * Las de antes de esta versión (sin dato) y las preguntas de la tienda no cuentan.
     *
     * @return array<string,int>
     */
    public function outcomeStats(int $days): array
    {
        $out = ['exact' => 0, 'relaxed' => 0, 'related' => 0, 'none' => 0];
        $rows = $this->app->db()->select(
            'SELECT outcome, SUM(hits) AS hits FROM {search_log} WHERE day >= ? AND outcome IN (?, ?, ?, ?) GROUP BY outcome',
            [$this->day(max(1, $days) * 86400), 'exact', 'relaxed', 'related', 'none']
        );
        foreach ($rows as $row) {
            $out[(string) $row['outcome']] = (int) $row['hits'];
        }

        return $out;
    }

    /**
     * Las consultas que acabaron sin nada (ni exacto, ni propuesta, ni relacionados) más repetidas de los últimos días.
     *
     * @return array<int,array{phrase:string,lang:string,hits:int}>
     */
    public function unanswered(int $days, int $limit = 20): array
    {
        $rows = $this->app->db()->select(
            "SELECT phrase, lang, SUM(hits) AS hits FROM {search_log} WHERE day >= ? AND outcome = 'none' GROUP BY lang, phrase ORDER BY SUM(hits) DESC, phrase ASC LIMIT ?",
            [$this->day(max(1, $days) * 86400), max(1, $limit)]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['phrase' => (string) $row['phrase'], 'lang' => (string) $row['lang'], 'hits' => (int) $row['hits']];
        }

        return $out;
    }

    /**
     * Apunta qué tipos de filtro se entendieron en una búsqueda (precio, color, categoría…; «ai» si ayudó la IA). Solo el tipo y
     * las veces: ni la frase ni nada de quien busca.
     *
     * @param string[] $kinds
     */
    public function recordFilters(string $lang, array $kinds): void
    {
        $clean = [];
        foreach (array_slice(array_unique($kinds), 0, 12) as $kind) {
            $kind = substr((string) preg_replace('/[^a-z_]/', '', strtolower((string) $kind)), 0, 20);
            if ($kind !== '') {
                $clean[$kind] = $kind;
            }
        }
        if ($clean === []) {
            return;
        }
        // Una sola consulta suma a todos los tipos que ya tienen fila de hoy; solo los que faltan cuestan algo más.
        $db = $this->app->db();
        $day = $this->day(0);
        $params = [$day, $lang];
        $in = $db->in(array_values($clean), $params);
        $changed = $db->run('UPDATE {search_filters} SET hits = hits + 1 WHERE day = ? AND lang = ? AND kind IN (' . $in . ')', $params);
        if ($changed >= count($clean)) {
            return;
        }
        $have = array_map('strval', $db->column('SELECT kind FROM {search_filters} WHERE day = ? AND lang = ?', [$day, $lang]));
        foreach ($clean as $kind) {
            if (in_array($kind, $have, true)) {
                continue;
            }
            try {
                $db->insert('search_filters', ['day' => $day, 'lang' => $lang, 'kind' => $kind, 'hits' => 1]);
            } catch (\Throwable $e) {
                // Otra petición la dio de alta a la vez: se suma a la suya.
                $db->run('UPDATE {search_filters} SET hits = hits + 1 WHERE day = ? AND lang = ? AND kind = ?', [$day, $lang, $kind]);
            }
        }
    }

    /**
     * Qué filtros se entienden más en los últimos días: tipo => veces.
     *
     * @return array<string,int>
     */
    public function filterStats(int $days): array
    {
        $out = [];
        $rows = $this->app->db()->select(
            'SELECT kind, SUM(hits) AS hits FROM {search_filters} WHERE day >= ? GROUP BY kind ORDER BY SUM(hits) DESC, kind ASC',
            [$this->day(max(1, $days) * 86400)]
        );
        foreach ($rows as $row) {
            $out[(string) $row['kind']] = (int) $row['hits'];
        }

        return $out;
    }

    /**
     * Las consultas más repetidas de los últimos días.
     *
     * @return array<int,array{phrase:string,lang:string,hits:int,found:int,missed:bool}>
     */
    public function top(int $days, bool $onlyMissed, int $limit = 50): array
    {
        $params = [$this->day(max(1, $days) * 86400)];
        $sql = 'SELECT phrase, lang, SUM(hits) AS hits, MAX(found) AS found, MAX(missed) AS missed FROM {search_log} WHERE day >= ?';
        if ($onlyMissed) {
            $sql .= ' AND missed = 1';
        }
        $sql .= ' GROUP BY lang, phrase ORDER BY SUM(hits) DESC, phrase ASC LIMIT ?';
        $params[] = max(1, $limit);
        $out = [];
        foreach ($this->app->db()->select($sql, $params) as $row) {
            $out[] = ['phrase' => (string) $row['phrase'], 'lang' => (string) $row['lang'], 'hits' => (int) $row['hits'], 'found' => (int) $row['found'], 'missed' => (int) $row['missed'] === 1];
        }

        return $out;
    }

    /**
     * Las consultas con resultado más repetidas del último mes que empiezan por un prefijo ya normalizado (sin comodines).
     *
     * @return string[]
     */
    public function popular(string $lang, string $prefix, int $limit): array
    {
        $params = [$lang, $this->day(30 * 86400)];
        $sql = 'SELECT phrase FROM {search_log} WHERE lang = ? AND day >= ? AND missed = 0';
        if ($prefix !== '') {
            $sql .= ' AND phrase LIKE ?';
            $params[] = $prefix . '%';
        }
        $sql .= ' GROUP BY phrase ORDER BY SUM(hits) DESC, phrase ASC LIMIT ?';
        $params[] = max(1, $limit);

        return array_map('strval', $this->app->db()->column($sql, $params));
    }

    /** Borra lo anterior a KEEP_DAYS días. Devuelve cuántas filas quitó. */
    public function purge(): int
    {
        $day = $this->day(self::KEEP_DAYS * 86400);
        $this->app->db()->run('DELETE FROM {search_filters} WHERE day < ?', [$day]);

        return $this->app->db()->run('DELETE FROM {search_log} WHERE day < ?', [$day]);
    }

    /** El día (Y-m-d, UTC) de hace tantos segundos. */
    private function day(int $secondsAgo): string
    {
        return substr($this->app->clock()->sql($this->app->clock()->now() - $secondsAgo), 0, 10);
    }
}
