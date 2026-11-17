<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\Activity;
use Aifec\Modules\Erp\ApiException;

/**
 * Lo común de los manejadores de la API: la conexión, el cuerpo, los cursores y la forma de las respuestas.
 */
abstract class ApiBase
{
    /** @var App */
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<string,mixed>
     */
    protected function connection(Request $request): array
    {
        $connection = $request->attr('erp.connection');

        return is_array($connection) ? $connection : [];
    }

    /**
     * @return array<string,mixed>
     */
    protected function body(Request $request): array
    {
        $body = $request->attr('erp.body');

        return is_array($body) ? $body : [];
    }

    /**
     * @param mixed $data
     */
    protected function ok($data, int $status = 200): Response
    {
        return Response::json(['data' => $data], $status);
    }

    /**
     * Apunta lo que ha escrito el ERP en el registro de actividad, con la conexión que lo hizo.
     *
     * @param array<string,mixed> $changes campo => [antes, después]
     */
    protected function activity(Request $request, string $action, string $entity, string $id, string $summary, array $changes = []): void
    {
        Activity::log($this->app, $this->connection($request), $action, $entity, $id, $summary, $changes);
    }

    protected function limit(Request $request): int
    {
        $raw = $request->get('limit');
        if ($raw === null || $raw === '') {
            return 50;
        }
        if (!is_scalar($raw) || preg_match('/^\d{1,4}$/', (string) $raw) !== 1 || (int) $raw < 1 || (int) $raw > 200) {
            throw new ApiException(400, 'invalid_limit', 'The limit must be a whole number from 1 to 200', 'limit');
        }

        return (int) $raw;
    }

    /** El cursor de la petición (0 = desde el principio). */
    protected function cursor(Request $request): int
    {
        $raw = $request->get('cursor');
        if ($raw === null || $raw === '') {
            return 0;
        }
        $text = is_string($raw) ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        if ($text === false || preg_match('/^c:(\d{1,15})$/', $text, $m) !== 1) {
            throw new ApiException(400, 'invalid_cursor', 'The cursor is not valid: use the next_cursor of a previous page', 'cursor');
        }

        return (int) $m[1];
    }

    public static function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('c:' . $id), '+/', '-_'), '=');
    }

    /**
     * La página de una lista por cursor. El cursor avanza por lo ENTREGADO (el último identificador que ha salido), nunca por un
     * recuento: si entran registros nuevos a mitad, van al final y no se salta ni se repite ninguno.
     *
     * @param int[]    $ids    identificadores pedidos con limit + 1, en orden
     * @param callable $present (int $id): array|null
     */
    protected function page(Request $request, array $ids, int $limit, int $after, callable $present): Response
    {
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        $data = [];
        foreach ($ids as $id) {
            $item = $present($id);
            if ($item !== null) {
                $data[] = $item;
            }
        }
        $last = $ids === [] ? $after : (int) $ids[count($ids) - 1];

        return Response::json(['data' => $data, 'next_cursor' => $last > 0 ? self::encodeCursor($last) : null, 'has_more' => $more]);
    }

    /** @return string[] */
    protected function textList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static function (string $v): bool {
            return $v !== '';
        }));
    }

    /**
     * @param mixed $value
     */
    protected function requireString($value, string $field, int $max = 191): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw ApiException::invalid('The field ' . $field . ' is required', $field);
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw ApiException::invalid('The field ' . $field . ' is too long (' . $max . ' characters at most)', $field);
        }

        return $value;
    }
}
