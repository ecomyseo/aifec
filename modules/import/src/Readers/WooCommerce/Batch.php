<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Modules\Import\SourceError;

/**
 * Piezas comunes de las tandas de los tres lectores: trocear una lista pequeña con un cursor de posición y convertir
 * cada elemento sin que uno roto corte la tanda.
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
                $records[] = $convert($item);
            } catch (SourceError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => self::reason($e)];
            }
        }

        return [$records, $skipped];
    }

    /**
     * @param array<int,array<string,mixed>>                $records
     * @param array<int,array{id:string,reason:string}>     $skipped
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
