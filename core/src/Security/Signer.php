<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Security;

/**
 * Fichas firmadas con caducidad, para enlaces de descarga, de acceso por correo y de restablecer
 * contraseña. Formato: `base64url(JSON) . '.' . base64url(HMAC-SHA256)`. El JSON lleva la caducidad
 * (`e`) y los datos (`p`), de modo que los datos del llamante no pueden chocar con ella.
 * La ficha va firmada, no cifrada: no se meten secretos dentro.
 */
final class Signer
{
    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('The signing key must not be empty');
        }
        $this->key = $key;
    }

    /**
     * @param array<mixed> $payload   datos serializables como JSON
     * @param int          $expiresAt instante Unix a partir del cual la ficha deja de valer
     */
    public function sign(array $payload, int $expiresAt): string
    {
        $json = json_encode(['e' => $expiresAt, 'p' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = Random::encodeUrl($json);

        return $body . '.' . $this->mac($body);
    }

    /**
     * Devuelve los datos si la firma es buena y `$now` es anterior a la caducidad; si no, `null`.
     * Una ficha caduca en el instante exacto de `expiresAt` (`$now >= $expiresAt`), como en JWT.
     * Con cualquier entrada rara (vacía, sin punto, binaria, enorme) devuelve `null`, nunca lanza.
     *
     * @return array<mixed>|null
     */
    public function verify(string $token, int $now): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        // Se compara la firma ya codificada: una variante de relleno del mismo binario no sirve.
        if (!hash_equals($this->mac($parts[0]), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true, 64);
        if (!is_array($data) || !isset($data['e'], $data['p']) || !is_int($data['e']) || !is_array($data['p'])) {
            return null;
        }
        if ($now >= $data['e']) {
            return null;
        }

        return $data['p'];
    }

    private function mac(string $body): string
    {
        return Random::encodeUrl(hash_hmac('sha256', $body, $this->key, true));
    }
}
