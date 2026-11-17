<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Lo que comparten los controladores de la API: leer lo que llega, llamar a una herramienta del servicio «tools» y contestar con
 * la forma de siempre: {"data": …, "meta": …}.
 */
final class Agent
{
    /**
     * La respuesta de éxito. Los importes salen en céntimos y formateados (ver Present::amounts()).
     *
     * @param mixed               $data
     * @param array<string,mixed> $meta
     */
    public static function json($data, int $status = 200, array $meta = []): Response
    {
        $payload = ['data' => Present::amounts($data)];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return Response::json($payload, $status);
    }

    /**
     * El cuerpo de la petición como array. Un cuerpo vacío es un array vacío; uno que no es JSON es un error 400. Se lee aunque
     * falte la cabecera Content-Type: quien programa contra una API no siempre la manda.
     *
     * @return array<string,mixed>
     */
    public static function body(Request $request): array
    {
        $text = trim($request->body);
        if ($text === '') {
            return $request->post;
        }
        $data = json_decode($text, true);
        if (!is_array($data) || ($data !== [] && array_keys($data) === range(0, count($data) - 1))) {
            throw HttpError::badRequest('The body must be a JSON object');
        }

        return $data;
    }

    /**
     * Una herramienta del servicio «tools». Un error de la herramienta sale como 422 con su mensaje; una herramienta que esta
     * tienda no tiene (la compra en el chat apagada) sale como 404.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public static function tool(App $app, string $name, array $input = []): array
    {
        $tools = $app->service('tools');
        if (!isset($tools->all()[$name])) {
            throw new HttpError(404, 'This shop does not offer that: ' . $name);
        }
        $result = $tools->call($name, $input);
        if (isset($result['error'])) {
            throw new HttpError(422, (string) $result['error']);
        }

        return $result;
    }

    /** Un número entero de la dirección, entre dos límites; un valor que no es un número es un error 400. */
    public static function intParam(Request $request, string $name, int $default, int $min, int $max): int
    {
        $value = $request->get($name);
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_scalar($value) || !preg_match('/^-?\d{1,12}$/D', trim((string) $value))) {
            throw HttpError::badRequest('The parameter ' . $name . ' must be a whole number');
        }

        return max($min, min($max, (int) trim((string) $value)));
    }

    /** Verdadero para «1», «true», «yes» u «on». */
    public static function flag(Request $request, string $name): bool
    {
        $value = $request->get($name);

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Un texto de la dirección, recortado y sin pasar de $max caracteres. */
    public static function textParam(Request $request, string $name, int $max = 120): string
    {
        $value = $request->get($name);

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max, 'UTF-8') : '';
    }
}
