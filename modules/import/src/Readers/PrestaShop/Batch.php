<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Modules\Import\SourceError;

/**
 * Piezas comunes de las tandas de los dos lectores de PrestaShop: trocear una lista pequeña con un cursor de posición,
 * recorrer varias fuentes seguidas con un cursor de «fase:clave» y convertir cada elemento sin que uno roto corte la tanda.
 */
final class Batch
{
    /**
     * Trocea una lista que ya está entera en memoria (tipos de configuración, pequeños). El cursor es la posición.
     *
     * @param array<int|string,mixed> $items
     *
     * @return array{0:array<int,mixed>,1:?string} la tanda y el cursor siguiente (null = se acabó)
     */
    public static function slice(array $items, string $cursor, int $limit): array
    {
        if ($cursor !== '' && preg_match('/^\d+$/', $cursor) !== 1) {
            throw new SourceError('Invalid cursor');
        }
        $items = array_values($items);
        $offset = $cursor === '' ? 0 : (int) $cursor;
        $page = array_slice($items, $offset, max(1, $limit));
        $end = $offset + count($page);

        return [$page, $end < count($items) ? (string) $end : null];
    }

    /**
     * Recorre varias fuentes una detrás de otra (los grupos de atributos y luego las características; los suscritos
     * del módulo y luego los clientes con boletín). El cursor es «fase:última clave». Una tanda lee de una sola fase;
     * al agotarla, el cursor pasa a la siguiente (aunque la tanda venga corta: el final se dice con null).
     *
     * @param array<int,callable> $stages cada una recibe (última clave, cuántas filas pedir) y devuelve filas con «id»
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    public static function stages(array $stages, string $cursor, int $limit): array
    {
        $stage = 0;
        $last = 0;
        if ($cursor !== '') {
            if (preg_match('/^(\d+):(\d+)$/', $cursor, $m) !== 1) {
                throw new SourceError('Invalid cursor');
            }
            $stage = (int) $m[1];
            $last = (int) $m[2];
        }
        if (!isset($stages[$stage])) {
            return [[], null];
        }
        $limit = max(1, $limit);
        $all = $stages[$stage]($last, $limit + 1);
        $rows = array_slice($all, 0, $limit);
        if (count($all) > $limit && $rows !== []) {
            $tail = $rows[count($rows) - 1];

            return [$rows, $stage . ':' . (string) $tail['id']];
        }

        return [$rows, isset($stages[$stage + 1]) ? ($stage + 1) . ':0' : null];
    }

    /**
     * Convierte cada elemento. Uno que no se pueda convertir va a «skipped» con su motivo y no corta la tanda; un fallo
     * del origen (SourceError, SourceDenied) sí sube.
     *
     * @param array<int,mixed> $items
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array{id:string,reason:string}>}
     */
    public static function map(array $items, callable $convert, string $idKey = 'id'): array
    {
        $records = [];
        $skipped = [];
        foreach ($items as $item) {
            $id = is_array($item) && isset($item[$idKey]) && is_scalar($item[$idKey]) && (string) $item[$idKey] !== '' ? (string) $item[$idKey] : '?';
            if (!is_array($item)) {
                $skipped[] = ['id' => $id, 'reason' => 'not an object'];
                continue;
            }
            try {
                $record = $convert($item);
            } catch (SourceError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => self::reason($e)];
                continue;
            }
            if ($record === null) {
                $skipped[] = ['id' => $id, 'reason' => 'nothing to import'];
            } else {
                $records[] = $record;
            }
        }

        return [$records, $skipped];
    }

    /**
     * @param array<int,array<string,mixed>>            $records
     * @param array<int,array{id:string,reason:string}> $skipped
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    public static function result(array $records, ?string $next, int $read, array $skipped): array
    {
        return ['records' => $records, 'next' => $next, 'read' => $read, 'skipped' => $skipped];
    }

    public static function reason(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if (!$e instanceof \InvalidArgumentException) {
            $class = get_class($e);
            $cut = strrpos($class, '\\');
            $message = 'unexpected ' . ($cut === false ? $class : substr($class, $cut + 1)) . ': ' . $message;
        }

        return mb_substr($message, 0, 200);
    }
}
