<?php
/**
 * AIFEC — ayudas de las pruebas de correo: un analizador de mensajes MIME independiente del código que se prueba.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Tests\Mail;

use Aifec\Mail\MimeMessage;

final class MailTestKit
{
    /** Un mensaje válido y mínimo, con la fecha y el identificador fijados. */
    public static function message(): MimeMessage
    {
        return (new MimeMessage())
            ->from('tienda@ejemplo.es', 'Tienda')
            ->to('ana@cliente.es')
            ->subject('Hola')
            ->text('Cuerpo')
            ->date(0)
            ->messageId('fijo123@ejemplo.es');
    }

    /**
     * Analiza un mensaje: cabeceras (nombre en minúsculas, valor desplegado), cuerpo, tipo y partes hijas.
     *
     * @return array{headers:array<string,string>,body:string,type:string,parts:array<int,array>}
     */
    public static function parse(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            throw new \RuntimeException('el mensaje no tiene línea en blanco entre cabeceras y cuerpo');
        }
        $head = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 4);
        $headers = [];
        $unfolded = (string) preg_replace('/\r\n[ \t]+/', ' ', $head);
        foreach (explode("\r\n", $unfolded) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new \RuntimeException('cabecera sin dos puntos: ' . $line);
            }
            $headers[strtolower(substr($line, 0, $colon))] = ltrim(substr($line, $colon + 1));
        }
        $type = isset($headers['content-type']) ? strtolower(trim(explode(';', $headers['content-type'])[0])) : 'text/plain';
        $parts = [];
        if (strpos($type, 'multipart/') === 0) {
            if (preg_match('/boundary="([^"]+)"/', $headers['content-type'], $m) !== 1) {
                throw new \RuntimeException('multipart sin boundary');
            }
            $segments = explode('--' . $m[1], $body);
            array_shift($segments);
            foreach ($segments as $seg) {
                if (substr($seg, 0, 2) === '--') {
                    break;
                }
                $parts[] = self::parse(substr($seg, 2, -2));
            }
        }

        return ['headers' => $headers, 'body' => $body, 'type' => $type, 'parts' => $parts];
    }

    /** Los tipos de un árbol de partes, en profundidad: p. ej. «multipart/mixed(multipart/alternative(text/plain,text/html),application/pdf)». */
    public static function shape(array $entity): string
    {
        if ($entity['parts'] === []) {
            return $entity['type'];
        }
        $children = [];
        foreach ($entity['parts'] as $p) {
            $children[] = self::shape($p);
        }

        return $entity['type'] . '(' . implode(',', $children) . ')';
    }

    /** El valor de una cabecera del mensaje sin analizar, desplegado y decodificado a UTF-8. */
    public static function decodedHeader(string $raw, string $name): string
    {
        $parsed = self::parse($raw);
        $value = $parsed['headers'][strtolower($name)] ?? '';

        return self::decodeWords($value);
    }

    public static function decodeWords(string $value): string
    {
        // iconv respeta la regla de la RFC 2047 (el blanco entre dos palabras codificadas contiguas no cuenta);
        // mb_decode_mimeheader lo conserva desde PHP 8.3 y parte «Ñandú» en «Ñand ú».
        if (function_exists('iconv_mime_decode')) {
            return (string) iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        }

        return mb_decode_mimeheader($value);
    }

    /** Cuerpo de texto decodificado (quoted-printable o base64) de una parte analizada. */
    public static function decodeBody(array $entity): string
    {
        $enc = strtolower($entity['headers']['content-transfer-encoding'] ?? '7bit');
        if ($enc === 'quoted-printable') {
            return quoted_printable_decode($entity['body']);
        }
        if ($enc === 'base64') {
            return (string) base64_decode($entity['body'], true);
        }

        return $entity['body'];
    }
}
