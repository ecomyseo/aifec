<?php
/**
 * AIFEC — firmas de respuestas UCP según RFC 9421, sin persistir instrumentos de pago.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);
namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

final class UcpSignatures
{
    public static function keys(App $app): array
    {
        $pair = self::pair($app);
        if ($pair === '') {
            return [];
        }
        $x = self::encode(sodium_crypto_sign_publickey($pair));

        return [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x, 'kid' => WebBotAuth::thumbprint($x), 'use' => 'sig', 'alg' => 'EdDSA']];
    }

    public static function sign(App $app, Response $response): Response
    {
        $pair = self::pair($app);
        if ($pair === '') {
            return $response;
        }
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $response->body, true)) . ':';
        $kid = WebBotAuth::thumbprint(self::encode(sodium_crypto_sign_publickey($pair)));
        $params = '("@status" "content-type" "content-digest");created=' . $app->clock()->now() . ';keyid="' . $kid . '";alg="ed25519"';
        $contentType = $response->headers['Content-Type'] ?? 'application/json; charset=UTF-8';
        $base = '"@status": ' . $response->status . "\n" . '"content-type": ' . $contentType . "\n" . '"content-digest": ' . $digest . "\n" . '"@signature-params": ' . $params;
        $response->headers['Content-Digest'] = $digest;
        $response->headers['Signature-Input'] = 'sig1=' . $params;
        $response->headers['Signature'] = 'sig1=:' . base64_encode(sodium_crypto_sign_detached($base, sodium_crypto_sign_secretkey($pair))) . ':';

        return $response;
    }

    private static function pair(App $app): string
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            return '';
        }
        $seed = $app->secret('agentic.signing_seed');
        if ($seed === '') {
            // Derivación separada de la clave del sitio: estable sin escrituras ni carreras al descubrir el perfil.
            $seed = hash_hmac('sha256', 'aifec-ucp-response-signing-v1', $app->site()->secret());
        }
        if (strlen($seed) !== 64 || !ctype_xdigit($seed)) {
            return '';
        }

        return sodium_crypto_sign_seed_keypair((string) hex2bin($seed));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
