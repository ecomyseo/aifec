<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;
use Aifec\Kernel\VisitLog;

/**
 * Pasa los registros de visitas que deja el núcleo (logs/visits-AAAA-MM-DD.tsv, ver Aifec\Kernel\VisitLog) a la tabla bot_visits,
 * sumados por día, robot y clase de página. Cada línea del registro es:
 *
 *   instante \t bot|ref \t nombre \t estado \t desde la caché (0|1) \t ruta
 *
 * Solo se leen los registros de ayer y de hoy, desde donde se quedó la vez anterior (tabla bot_offsets), así que ejecutarlo
 * dos veces no cuenta nada doble y nunca se recorre el histórico. No se guarda ninguna dirección IP ni ningún agente de usuario.
 */
final class Bots
{
    /** Lo más que se lee de un registro en una pasada (bytes). El resto se lee en la siguiente. */
    private const MAX_READ = 4194304;

    /** Largo máximo del nombre de un robot o de un asistente. */
    private const MAX_NAME = 60;

    /** Días que se conservan las sumas. */
    private const KEEP_DAYS = 400;

    /** Clase de VisitLog::BOTS => familia que se enseña. */
    private const FAMILY_OF = ['search' => 'search', 'ai_search' => 'assistant', 'ai_user' => 'assistant', 'ai_training' => 'training', 'seo' => 'seo', 'other' => 'other'];

    /**
     * Lee lo nuevo de los registros de ayer y de hoy y lo suma en bot_visits.
     *
     * @return array{files:int,lines:int,added:int} registros con algo nuevo, líneas válidas y visitas sumadas
     */
    public static function ingest(App $app): array
    {
        $db = $app->db();
        $now = $app->clock()->now();
        $dir = $app->site()->path('logs');
        $files = ['visits-' . gmdate('Y-m-d', $now - 86400) . '.tsv', 'visits-' . gmdate('Y-m-d', $now) . '.tsv'];
        $classes = self::classByName();
        $memo = [];
        $result = ['files' => 0, 'lines' => 0, 'added' => 0];

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $pos = (int) $db->value('SELECT pos FROM {bot_offsets} WHERE file = ?', [$file]);
            if ($pos > $size) {
                $pos = 0;
            }
            if ($pos === $size) {
                continue;
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            fseek($handle, $pos);
            $chunk = fread($handle, self::MAX_READ);
            fclose($handle);
            $chunk = is_string($chunk) ? $chunk : '';
            $end = strrpos($chunk, "\n");
            if ($end === false) {
                // Una línea a medio escribir: se espera a la siguiente pasada. Si es basura sin fin, se salta.
                if (strlen($chunk) < self::MAX_READ) {
                    continue;
                }
                $end = strlen($chunk) - 1;
            }
            $consumed = $end + 1;

            $sums = [];
            $lines = 0;
            foreach (explode("\n", substr($chunk, 0, $consumed)) as $line) {
                $visit = self::parseLine($line);
                if ($visit === null) {
                    continue;
                }
                ++$lines;
                $class = isset($memo[$visit['path']]) ? $memo[$visit['path']] : ($memo[$visit['path']] = self::pageClass($app, $visit['path']));
                $day = gmdate('Y-m-d', $visit['at']);
                $key = $day . '|' . $visit['kind'] . '|' . $visit['name'] . '|' . $class;
                if (!isset($sums[$key])) {
                    $sums[$key] = [
                        'day' => $day, 'kind' => $visit['kind'], 'name' => $visit['name'], 'class' => $class, 'visits' => 0, 'last' => 0,
                        'family' => $visit['kind'] === 'ref' ? 'assistant' : self::familyOf(isset($classes[$visit['name']]) ? $classes[$visit['name']] : 'other'),
                    ];
                }
                ++$sums[$key]['visits'];
                $sums[$key]['last'] = max($sums[$key]['last'], $visit['at']);
            }

            $db->transaction(static function () use ($app, $db, $sums, $file, $pos, $consumed): void {
                foreach ($sums as $sum) {
                    $row = $db->row(
                        'SELECT id, last_at FROM {bot_visits} WHERE day = ? AND kind = ? AND name = ? AND page_class = ?',
                        [$sum['day'], $sum['kind'], $sum['name'], $sum['class']]
                    );
                    $last = $app->clock()->sql((int) $sum['last']);
                    if ($row === null) {
                        $db->insert('bot_visits', [
                            'day' => $sum['day'], 'kind' => $sum['kind'], 'name' => $sum['name'], 'family' => $sum['family'],
                            'page_class' => $sum['class'], 'visits' => $sum['visits'], 'last_at' => $last,
                        ]);
                        continue;
                    }
                    $db->run(
                        'UPDATE {bot_visits} SET visits = visits + ?, last_at = ? WHERE id = ?',
                        [$sum['visits'], strcmp((string) $row['last_at'], $last) > 0 ? (string) $row['last_at'] : $last, (int) $row['id']]
                    );
                }
                $db->upsert('bot_offsets', ['file' => $file, 'pos' => $pos + $consumed, 'updated_at' => $app->clock()->sql()], ['file']);
            });
            ++$result['files'];
            $result['lines'] += $lines;
            foreach ($sums as $sum) {
                $result['added'] += $sum['visits'];
            }
        }

        // Lo viejo se olvida: los desplazamientos de otros días y las sumas de más de KEEP_DAYS días.
        $db->run('DELETE FROM {bot_offsets} WHERE file NOT IN (?, ?)', $files);
        $db->run('DELETE FROM {bot_visits} WHERE day < ?', [gmdate('Y-m-d', $now - self::KEEP_DAYS * 86400)]);

        return $result;
    }

    /**
     * Una línea del registro: instante, tipo, nombre y ruta. Una línea mal formada da null.
     *
     * @return array{at:int,kind:string,name:string,path:string}|null
     */
    public static function parseLine(string $line): ?array
    {
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (count($parts) < 6 || !ctype_digit($parts[0]) || ($parts[1] !== 'bot' && $parts[1] !== 'ref')) {
            return null;
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $parts[2]));
        if ($name === '') {
            return null;
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, self::MAX_NAME, 'UTF-8') : substr($name, 0, self::MAX_NAME);

        return ['at' => (int) $parts[0], 'kind' => $parts[1], 'name' => $name, 'path' => $parts[5]];
    }

    /**
     * La clase de página de una ruta: product, category, text (la versión «.md»), llms, feed, api u other.
     */
    public static function pageClass(App $app, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $lower = strtolower($path);
        if (substr($lower, -3) === '.md') {
            return 'text';
        }
        if ($lower === '/llms.txt' || $lower === '/llms-full.txt') {
            return 'llms';
        }
        if (strpos($lower, '/feeds/') === 0) {
            return 'feed';
        }
        if ($lower === '/api' || strpos($lower, '/api/') === 0 || $lower === '/mcp' || strpos($lower, '/.well-known/') === 0) {
            return 'api';
        }
        $lang = $app->defaultLang();
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $m) === 1 && $m[1] !== $lang && in_array($m[1], $app->languages(), true)) {
            $lang = $m[1];
            $path = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
        }
        $hit = $app->slugs()->resolve($lang, $path);
        if ($hit !== null && ($hit['type'] === 'product' || $hit['type'] === 'category')) {
            return $hit['type'];
        }

        return 'other';
    }

    /** La familia que se enseña de una clase de robot de VisitLog::BOTS. */
    public static function familyOf(string $botClass): string
    {
        return isset(self::FAMILY_OF[$botClass]) ? self::FAMILY_OF[$botClass] : 'other';
    }

    /**
     * Nombre con el que VisitLog apunta cada robot => su clase.
     *
     * @return array<string,string>
     */
    public static function classByName(): array
    {
        $out = [];
        foreach (VisitLog::BOTS as $info) {
            $out[$info[0]] = $info[2];
        }

        return $out;
    }
}
